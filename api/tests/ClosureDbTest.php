<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\ClosurePeriod;
use App\Config;
use App\Controllers\ClosureController;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Cierres consecutivos contra la base local. TODO ocurre dentro de una transaccion que se
 * deshace al final, asi que no se tocan los datos locales (generate() archiva todo lo abierto).
 */
final class ClosureDbTest extends TestCase
{
    private \PDO $pdo;
    /** @var array<string,mixed> */
    private array $admin;
    private int $collabId;
    private string $lastEnd;
    private string $today;

    protected function setUp(): void
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            self::markTestSkipped('No hay config.php (prueba de base de datos).');
        }
        try {
            Database::reset();
            $this->pdo = Database::init(Config::load($configFile));
            $this->pdo->query('SELECT 1 FROM closures LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base de datos no disponible: ' . $e->getMessage());
        }

        $this->pdo->beginTransaction();
        $this->today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->admin    = ['id' => $this->makeUser('zz_test_a_' . bin2hex(random_bytes(3)), 'admin')];
        $this->collabId = $this->makeUser('zz_test_c_' . bin2hex(random_bytes(3)), 'collaborator');

        $last = $this->pdo->query('SELECT MAX(end_date) FROM closures')->fetchColumn();
        if (!$last) {
            // Sin cierres previos: se crea uno de referencia (se deshace con la transaccion).
            $ref = (new \DateTimeImmutable('today -40 days'))->format('Y-m-d');
            $this->pdo->prepare(
                'INSERT INTO closures (label, start_date, end_date, generated_by) VALUES (?, ?, ?, ?)'
            )->execute(['ref', $ref, $ref, $this->admin['id']]);
            $last = $ref;
        }
        $this->lastEnd = (string) $last;
        if (ClosurePeriod::nextStart($this->lastEnd) > $this->today) {
            self::markTestSkipped('El ultimo cierre local ya llega hasta hoy.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
        Database::reset();
    }

    private function makeUser(string $username, string $role): int
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, display_name, role, credential_hash) VALUES (?, ?, ?, ?)'
        )->execute([$username, $username, $role, Auth::hashSecret('a1234')]);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $body @param array<string,string> $query */
    private function req(array $body = [], array $query = []): Request
    {
        return new Request('POST', '/x', $body, $query, null);
    }

    /** @return array<string,mixed> */
    private function json(\App\Http\Response $res): array
    {
        return json_decode(json_encode($res->data), true);
    }

    private function approvedOvertime(string $date, float $hours): int
    {
        $this->pdo->prepare(
            "INSERT INTO overtime_entries (user_id, work_date, hours, note, status, reviewed_at)
             VALUES (?, ?, ?, 'x', 'approved', NOW())"
        )->execute([$this->collabId, $date, $hours]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testPreviewContinuaDelCierreAnteriorYIgnoraElInicioEnviado(): void
    {
        $expected = ClosurePeriod::nextStart($this->lastEnd);
        $p = $this->json((new ClosureController())->preview($this->req([], ['start' => '2001-01-01'])));

        self::assertSame($expected, $p['start']);
        self::assertTrue($p['start_locked']);
        self::assertSame($this->lastEnd, $p['last_end']);
        self::assertSame(ClosurePeriod::suggestedEnd($expected), $p['end']);
        self::assertSame(ClosurePeriod::label($p['start'], $p['end']), $p['suggested_label']);
    }

    public function testNoPermiteSaltarseDias(): void
    {
        $skipped = (new \DateTimeImmutable(ClosurePeriod::nextStart($this->lastEnd) . ' +1 day'))->format('Y-m-d');
        if ($skipped > $this->today) {
            self::markTestSkipped('No hay espacio para saltarse un dia.');
        }
        try {
            (new ClosureController())->generate($this->admin, $this->req(['start' => $skipped, 'end' => $this->today]));
            self::fail('Debio rechazar el cierre con hueco.');
        } catch (HttpException $e) {
            self::assertSame(422, $e->getCode());
            self::assertStringContainsString('justo despues del cierre anterior', $e->getMessage());
        }
    }

    public function testNoPermiteCerrarElFuturoNiRangoInvertido(): void
    {
        $start  = ClosurePeriod::nextStart($this->lastEnd);
        $future = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        foreach ([[$start, $future], [$this->today, $start === $this->today ? '2000-01-01' : $start]] as [$s, $e]) {
            try {
                (new ClosureController())->generate($this->admin, $this->req(['start' => $s, 'end' => $e]));
                self::fail("Debio rechazar $s a $e");
            } catch (HttpException $ex) {
                self::assertSame(422, $ex->getCode());
            }
        }
    }

    public function testGenerarArchivaLoAtrasadoYElSiguienteContinuaSinHuecos(): void
    {
        $start = ClosurePeriod::nextStart($this->lastEnd);

        // Una hora extra de ANTES del inicio que nunca se cerro (aprobada tarde) no se pierde...
        $late = $this->approvedOvertime((new \DateTimeImmutable($this->lastEnd . ' -3 days'))->format('Y-m-d'), 2.0);
        // ...y una dentro del periodo.
        $inside = $this->approvedOvertime($start, 3.0);

        $ctrl    = new ClosureController();
        $preview = $this->json($ctrl->preview($this->req([], ['end' => $this->today])));
        self::assertGreaterThanOrEqual(1, $preview['carried_count']);

        $res = $this->json($ctrl->generate($this->admin, $this->req(['start' => $start, 'end' => $this->today])));
        self::assertSame(ClosurePeriod::label($start, $this->today), $res['label']);

        foreach ([$late, $inside] as $id) {
            $cid = $this->pdo->query("SELECT closure_id FROM overtime_entries WHERE id = $id")->fetchColumn();
            self::assertSame($res['id'], (int) $cid, 'Todo lo abierto hasta el fin debe quedar archivado.');
        }
        $hours = 0.0;
        foreach ($res['rows'] as $row) {
            if ($row['user_id'] === $this->collabId) {
                $hours = $row['overtime_hours'];
            }
        }
        self::assertEqualsWithDelta(5.0, $hours, 0.001);

        // El siguiente cierre arranca exactamente al dia siguiente.
        $next = $this->json($ctrl->preview($this->req()));
        self::assertSame(ClosurePeriod::nextStart($this->today), $next['start']);
    }

    public function testUnCierreVacioSePuedeGenerarParaMantenerLaSecuencia(): void
    {
        // Rango de un solo dia sin movimientos de este usuario; el cierre no debe fallar por estar "vacio".
        $start = ClosurePeriod::nextStart($this->lastEnd);
        $res = $this->json((new ClosureController())->generate($this->admin, $this->req(['start' => $start, 'end' => $start])));
        self::assertSame($start, $res['start']);
        self::assertSame($start, $res['end']);
    }

    public function testLaEtiquetaPersonalizadaSeRespeta(): void
    {
        $start = ClosurePeriod::nextStart($this->lastEnd);
        $res = $this->json((new ClosureController())->generate(
            $this->admin,
            $this->req(['start' => $start, 'end' => $start, 'label' => 'Cierre especial'])
        ));
        self::assertSame('Cierre especial', $res['label']);
    }
}
