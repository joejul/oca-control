<?php

declare(strict_types=1);

namespace App\Tests;

use App\Config;
use App\Controllers\EarlyLeaveController;
use App\Controllers\MakeupController;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\TimeDebt;
use PHPUnit\Framework\TestCase;

/**
 * Flujo completo de salidas anticipadas y reposiciones contra la base local.
 * Crea sus propios usuarios temporales y los borra al terminar. Se omite si no
 * hay base disponible (por ejemplo fuera de ddev).
 */
final class TimeDebtDbTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $collab;
    /** @var array<string,mixed> */
    private array $admin;
    private \PDO $pdo;
    private string $yesterday;
    private string $today;

    protected function setUp(): void
    {
        $configFile = dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) {
            self::markTestSkipped('No hay config.php (prueba de base de datos).');
        }
        // Igual que en produccion (bootstrap.php): PHP y la sesion de la base en la misma zona horaria.
        date_default_timezone_set('America/Costa_Rica');
        try {
            Database::reset();
            $this->pdo = Database::init(Config::load($configFile));
            $this->pdo->query('SELECT 1 FROM early_leaves LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base de datos no disponible o sin migracion 006: ' . $e->getMessage());
        }

        $suffix = bin2hex(random_bytes(4));
        $this->collab = $this->makeUser("zz_test_c_$suffix", 'collaborator');
        $this->admin  = $this->makeUser("zz_test_a_$suffix", 'admin');
        $this->today     = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $this->yesterday = (new \DateTimeImmutable('yesterday'))->format('Y-m-d');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo) || !isset($this->collab)) {
            return;
        }
        $ids = [(int) $this->collab['id'], (int) $this->admin['id']];
        $in  = implode(',', $ids);
        $this->pdo->exec("DELETE FROM makeup_hours WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM early_leaves WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM audit_log WHERE actor_id IN ($in)");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($in)");
        Database::reset();
    }

    /** @return array<string,mixed> */
    private function makeUser(string $username, string $role): array
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, display_name, role, credential_hash) VALUES (:u, :d, :r, :h)'
        )->execute(['u' => $username, 'd' => $username, 'r' => $role, 'h' => 'x']);
        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'username' => $username,
            'display_name' => $username,
            'role' => $role,
        ];
    }

    /** @param array<string,mixed> $body */
    private function req(array $body = []): Request
    {
        return new Request('POST', '/x', $body, [], null);
    }

    private function leave(string $date, string $scheduled, string $actual): array
    {
        $res = (new EarlyLeaveController())->create($this->req([
            'leave_date' => $date, 'scheduled_end' => $scheduled, 'actual_end' => $actual,
            'note' => 'cita medica',
        ]), $this->collab);
        return $res->data;
    }

    private function makeup(int $minutes): array
    {
        $res = (new MakeupController())->create($this->req([
            'makeup_date' => $this->today, 'minutes' => $minutes,
        ]), $this->collab);
        return $res->data;
    }

    private function assert422(callable $fn): HttpException
    {
        try {
            $fn();
        } catch (HttpException $e) {
            self::assertSame(422, $e->getCode(), $e->getMessage());
            return $e;
        }
        self::fail('Debio lanzar HttpException 422');
    }

    private function balance(): array
    {
        return TimeDebt::balanceFor((int) $this->collab['id']);
    }

    public function testEarlyLeaveCreatesDebt(): void
    {
        $res = $this->leave($this->yesterday, '17:00', '15:30');

        self::assertSame(90, $res['minutes_owed']);
        self::assertSame(90, $res['balance']['due_minutes']);
        self::assertSame(90, $res['balance']['available_minutes']);
    }

    public function testDuplicateEarlyLeaveSameDateIsRejectedUntilVoided(): void
    {
        $first = $this->leave($this->yesterday, '17:00', '16:00');
        $this->assert422(fn () => $this->leave($this->yesterday, '17:00', '15:00'));

        (new EarlyLeaveController())->void($this->admin, $first['id'], $this->req(['reason' => 'error de captura']));
        self::assertSame(0, $this->balance()['due_minutes']);

        $again = $this->leave($this->yesterday, '17:00', '15:00');
        self::assertSame(120, $again['minutes_owed']);
    }

    public function testEarlyLeaveRejectsFutureAndInvalidTimes(): void
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');
        $this->assert422(fn () => $this->leave($tomorrow, '17:00', '15:00'));
        $this->assert422(fn () => $this->leave($this->yesterday, '15:00', '17:00'));
        $this->assert422(fn () => $this->leave($this->yesterday, '15:00', '15:00'));
    }

    public function testMakeupRequiresDebt(): void
    {
        $this->assert422(fn () => $this->makeup(30));
    }

    public function testPendingMakeupsCountAgainstAvailableButNotAgainstDue(): void
    {
        $this->leave($this->yesterday, '17:00', '15:30'); // 90 min

        $this->makeup(60);
        $b = $this->balance();
        self::assertSame(90, $b['due_minutes']);        // pendiente: aun no resta
        self::assertSame(60, $b['pending_minutes']);
        self::assertSame(30, $b['available_minutes']);

        $this->assert422(fn () => $this->makeup(40));   // excede lo disponible
        $this->makeup(30);                              // justo lo que queda
        self::assertSame(0, $this->balance()['available_minutes']);
        $this->assert422(fn () => $this->makeup(1));
    }

    public function testApproveReducesDueAndRejectFreesAvailability(): void
    {
        $this->leave($this->yesterday, '17:00', '15:30'); // 90
        $m1 = $this->makeup(60);
        $m2 = $this->makeup(30);

        $makeupC = new MakeupController();
        $makeupC->review($this->admin, $m1['id'], 'approved', $this->req());
        $makeupC->review($this->admin, $m2['id'], 'rejected', $this->req(['note' => 'no se hizo']));

        $b = $this->balance();
        self::assertSame(30, $b['due_minutes']);        // 90 - 60 aprobados
        self::assertSame(0, $b['pending_minutes']);
        self::assertSame(30, $b['available_minutes']);  // la rechazada libera cupo

        // No se puede revisar dos veces.
        $this->assert422(fn () => $makeupC->review($this->admin, $m1['id'], 'rejected', $this->req()));
    }

    public function testVoidingApprovedMakeupRestoresDebt(): void
    {
        $this->leave($this->yesterday, '17:00', '16:00'); // 60
        $m = $this->makeup(60);
        $makeupC = new MakeupController();
        $makeupC->review($this->admin, $m['id'], 'approved', $this->req());
        self::assertSame(0, $this->balance()['due_minutes']);

        $makeupC->void($this->admin, $m['id'], $this->req(['reason' => 'no se hizo']));
        self::assertSame(60, $this->balance()['due_minutes']);
    }

    public function testDueNeverGoesNegative(): void
    {
        $leave = $this->leave($this->yesterday, '17:00', '16:00'); // 60
        $m = $this->makeup(60);
        (new MakeupController())->review($this->admin, $m['id'], 'approved', $this->req());

        // Se anula la salida despues de aprobar la reposicion: queda a favor, no negativo.
        (new EarlyLeaveController())->void($this->admin, $leave['id'], $this->req(['reason' => 'duplicada']));
        self::assertSame(0, $this->balance()['due_minutes']);
    }

    public function testDueSubqueryMatchesBalances(): void
    {
        $this->leave($this->yesterday, '17:00', '15:30'); // 90
        $m = $this->makeup(45);
        (new MakeupController())->review($this->admin, $m['id'], 'approved', $this->req());

        $stmt = $this->pdo->prepare('SELECT due FROM (' . TimeDebt::dueSubquery() . ') t WHERE t.user_id = :u');
        $stmt->execute(['u' => $this->collab['id']]);

        self::assertSame(45, (int) $stmt->fetchColumn());
        self::assertSame(45, $this->balance()['due_minutes']);
    }

    public function testMakeupRejectsFutureDateAndZeroMinutes(): void
    {
        $this->leave($this->yesterday, '17:00', '16:00');
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        $this->assert422(fn () => (new MakeupController())->create(
            $this->req(['makeup_date' => $tomorrow, 'minutes' => 30]),
            $this->collab
        ));
        $this->assert422(fn () => $this->makeup(0));
    }
}
