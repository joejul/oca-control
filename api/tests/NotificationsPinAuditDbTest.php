<?php

declare(strict_types=1);

namespace App\Tests;

use App\Auth;
use App\Config;
use App\Controllers\AuditController;
use App\Controllers\AuthController;
use App\Controllers\NotificationController;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

/**
 * Novedades del colaborador, cambio de PIN propio y consulta de la bitacora, contra la
 * base local. Crea usuarios temporales y los borra. Se omite si no hay base o falta la 007.
 */
final class NotificationsPinAuditDbTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $collab;
    /** @var array<string,mixed> */
    private array $other;
    /** @var array<string,mixed> */
    private array $admin;
    private \PDO $pdo;
    private Config $config;

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
            $this->config = Config::load($configFile);
            $this->pdo    = Database::init($this->config);
            $this->pdo->query('SELECT 1 FROM user_notification_state LIMIT 1');
            $this->pdo->query('SELECT 1 FROM makeup_hours LIMIT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Base de datos no disponible o sin migraciones 006/007: ' . $e->getMessage());
        }

        $suffix = bin2hex(random_bytes(4));
        $this->collab = $this->makeUser("zz_test_c_$suffix", 'collaborator', 'a1234');
        $this->other  = $this->makeUser("zz_test_o_$suffix", 'collaborator', 'b5678');
        $this->admin  = $this->makeUser("zz_test_a_$suffix", 'admin', 'c9999');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo) || !isset($this->collab)) {
            return;
        }
        $in = implode(',', [(int) $this->collab['id'], (int) $this->other['id'], (int) $this->admin['id']]);
        $this->pdo->exec("DELETE FROM overtime_entries WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM vacation_requests WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM makeup_hours WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM early_leaves WHERE user_id IN ($in)");
        $this->pdo->exec("DELETE FROM audit_log WHERE actor_id IN ($in)");
        $this->pdo->exec("DELETE FROM users WHERE id IN ($in)"); // borra tokens y estado por cascada
        Database::reset();
    }

    /** @return array<string,mixed> fila completa de users */
    private function makeUser(string $username, string $role, string $pin): array
    {
        $this->pdo->prepare(
            'INSERT INTO users (username, display_name, role, credential_hash) VALUES (:u, :d, :r, :h)'
        )->execute(['u' => $username, 'd' => $username, 'r' => $role, 'h' => Auth::hashSecret($pin)]);

        return $this->freshUser((int) $this->pdo->lastInsertId());
    }

    /** @return array<string,mixed> */
    private function freshUser(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /** @param array<string,mixed> $body @param array<string,string> $query */
    private function req(array $body = [], array $query = [], string $token = ''): Request
    {
        return new Request('POST', '/x', $body, $query, $token !== '' ? $token : null);
    }

    /** @return array<string,mixed> */
    private function json(\App\Http\Response $res): array
    {
        return json_decode(json_encode($res->data), true);
    }

    private function overtime(int $userId, string $status, ?string $reviewedAt, ?string $voidedAt = null, ?string $note = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO overtime_entries (user_id, work_date, hours, note, status, reviewed_at, review_note, voided_at, void_reason)
             VALUES (?, CURDATE(), 2.5, "x", ?, ?, ?, ?, ?)'
        )->execute([$userId, $status, $reviewedAt, $reviewedAt ? $note : null, $voidedAt, $voidedAt ? $note : null]);
        return (int) $this->pdo->lastInsertId();
    }

    // ---------------------------------------------------------------- novedades

    public function testPrimeraVezNoMuestraHistorialComoSinLeer(): void
    {
        $this->overtime((int) $this->collab['id'], 'approved', date('Y-m-d H:i:s', time() - 3600));

        $res = $this->json((new NotificationController())->mine($this->collab));

        self::assertSame(0, $res['unread']);
        self::assertCount(1, $res['items']);
        self::assertFalse($res['items'][0]['unread']);
    }

    public function testNovedadNuevaCuentaComoSinLeerYSeMarcaVista(): void
    {
        $c = new NotificationController();
        $c->mine($this->collab); // inicializa "visto hasta ahora"
        $this->pdo->prepare('UPDATE user_notification_state SET seen_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 600), $this->collab['id']]);

        $this->overtime((int) $this->collab['id'], 'rejected', date('Y-m-d H:i:s'), null, 'No aplica');
        $this->overtime((int) $this->other['id'], 'approved', date('Y-m-d H:i:s')); // de otro: no debe salir

        $res = $this->json($c->mine($this->collab));
        self::assertSame(1, $res['unread']);
        self::assertSame('rejected', $res['items'][0]['event']);
        self::assertSame('No aplica', $res['items'][0]['note']);
        self::assertSame(2.5, $res['items'][0]['amount']);
        self::assertCount(1, $res['items']);

        $c->markSeen($this->req(['up_to' => $res['items'][0]['event_at']]), $this->collab);
        self::assertSame(0, $this->json($c->mine($this->collab))['unread']);
    }

    public function testAnulacionYReposicionGeneranNovedades(): void
    {
        $c = new NotificationController();
        $c->mine($this->collab);
        $this->pdo->prepare('UPDATE user_notification_state SET seen_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 600), $this->collab['id']]);

        $uid = (int) $this->collab['id'];
        $now = date('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO early_leaves (user_id, leave_date, scheduled_end, actual_end, minutes_owed, note, voided_at, void_reason)
             VALUES (?, CURDATE(), '17:00', '16:00', 60, 'x', ?, 'Duplicada')"
        )->execute([$uid, $now]);
        $this->pdo->prepare(
            "INSERT INTO makeup_hours (user_id, makeup_date, minutes, status, reviewed_at)
             VALUES (?, CURDATE(), 30, 'approved', ?)"
        )->execute([$uid, $now]);

        $res   = $this->json($c->mine($this->collab));
        $kinds = array_map(static fn ($i) => $i['kind'] . ':' . $i['event'], $res['items']);
        sort($kinds);
        self::assertSame(['early_leave:voided', 'makeup:approved'], $kinds);
        self::assertSame(2, $res['unread']);
    }

    public function testNoMarcaVistoLoQueLlegoDespuesDelCorte(): void
    {
        $c = new NotificationController();
        $c->mine($this->collab);
        $this->pdo->prepare('UPDATE user_notification_state SET seen_at = ? WHERE user_id = ?')
            ->execute([date('Y-m-d H:i:s', time() - 600), $this->collab['id']]);
        $this->overtime((int) $this->collab['id'], 'approved', date('Y-m-d H:i:s', time() - 60));

        // El cliente solo habia mostrado hasta hace 5 minutos: lo nuevo sigue sin leer.
        $c->markSeen($this->req(['up_to' => date('Y-m-d H:i:s', time() - 300)]), $this->collab);

        self::assertSame(1, $this->json($c->mine($this->collab))['unread']);
    }

    public function testMarcarVistoRechazaFechaInvalida(): void
    {
        $this->expectException(HttpException::class);
        (new NotificationController())->markSeen($this->req(['up_to' => 'ayer']), $this->collab);
    }

    // ---------------------------------------------------------------- cambio de PIN

    private function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 DAY)'
        )->execute([$userId, hash('sha256', $token)]);
        return $token;
    }

    public function testCambioDePinFuncionaYCierraLasOtrasSesiones(): void
    {
        $auth  = new Auth($this->config);
        $ctrl  = new AuthController($auth);
        $mine  = $this->issueToken((int) $this->collab['id']);
        $other = $this->issueToken((int) $this->collab['id']);

        $res = $ctrl->changePin($this->req(['current_pin' => 'a1234', 'new_pin' => 'z4321'], [], $mine), $this->collab);
        self::assertTrue($this->json($res)['ok']);

        // El PIN nuevo entra; el viejo ya no.
        self::assertSame($this->collab['id'], $auth->login((string) $this->collab['username'], 'z4321')['user']['id']);
        try {
            $auth->login((string) $this->collab['username'], 'a1234');
            self::fail('El PIN viejo no deberia servir.');
        } catch (HttpException $e) {
            self::assertSame(401, $e->getCode());
        }

        // La sesion desde donde se cambio sigue viva; la otra se cerro.
        self::assertNotNull($auth->userFromToken($mine));
        self::assertNull($auth->userFromToken($other));

        $n = $this->pdo->prepare("SELECT COUNT(*) FROM audit_log WHERE actor_id = ? AND action = 'user.change_pin'");
        $n->execute([$this->collab['id']]);
        self::assertSame(1, (int) $n->fetchColumn());
    }

    public function testCambioDePinConActualIncorrectoSumaIntentosYBloquea(): void
    {
        $ctrl = new AuthController(new Auth($this->config));
        $max  = $this->config->maxLoginAttempts();

        for ($i = 1; $i <= $max; $i++) {
            try {
                $ctrl->changePin(
                    $this->req(['current_pin' => 'x0000', 'new_pin' => 'z4321']),
                    $this->freshUser((int) $this->collab['id'])
                );
                self::fail('Debio fallar.');
            } catch (HttpException $e) {
                self::assertSame(422, $e->getCode());
            }
        }

        $locked = $this->freshUser((int) $this->collab['id']);
        self::assertNotNull($locked['locked_until']);

        // Bloqueado: ni siquiera con el PIN correcto.
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(429);
        $ctrl->changePin($this->req(['current_pin' => 'a1234', 'new_pin' => 'z4321']), $locked);
    }

    public function testCambioDePinValidaFormatoYQueSeaDistinto(): void
    {
        $ctrl = new AuthController(new Auth($this->config));

        foreach ([['a1234', 'abc'], ['a1234', '12345'], ['a1234', 'a1234']] as [$cur, $new]) {
            try {
                $ctrl->changePin($this->req(['current_pin' => $cur, 'new_pin' => $new]), $this->collab);
                self::fail("Debio rechazar $new");
            } catch (HttpException $e) {
                self::assertSame(422, $e->getCode());
            }
        }
        // Nada cambio: el PIN original sigue sirviendo.
        self::assertTrue(password_verify('a1234', (string) $this->freshUser((int) $this->collab['id'])['credential_hash']));
    }

    // ---------------------------------------------------------------- bitacora

    private function audit(int $actor, string $action, ?string $type = null, ?int $id = null, array $details = []): void
    {
        $this->pdo->prepare(
            'INSERT INTO audit_log (actor_id, action, entity_type, entity_id, details_json) VALUES (?, ?, ?, ?, ?)'
        )->execute([$actor, $action, $type, $id, $details === [] ? null : json_encode($details)]);
    }

    public function testBitacoraFiltraYMuestraElDuenoDelRegistro(): void
    {
        $otId = $this->overtime((int) $this->collab['id'], 'approved', date('Y-m-d H:i:s'));
        $aid  = (int) $this->admin['id'];
        $this->audit($aid, 'overtime.approved', 'overtime', $otId, ['note' => 'ok']);
        $this->audit($aid, 'overtime.void', 'overtime', $otId, ['reason' => 'error']);
        $this->audit($aid, 'user.reset_pin', 'user', (int) $this->other['id']);
        $this->audit((int) $this->collab['id'], 'login', 'user', (int) $this->collab['id']);

        $ctrl = new AuditController();
        $get  = fn (array $q) => $this->json($ctrl->list($this->req([], $q)));

        // Por actor: 3 acciones del admin, sin el login (otro actor).
        $res = $get(['actor' => (string) $aid]);
        self::assertSame(3, $res['total']);
        self::assertSame('overtime.approved', $res['rows'][2]['action']);
        self::assertSame($this->collab['display_name'], $res['rows'][2]['subject_name']);
        self::assertSame($this->other['display_name'], $res['rows'][0]['subject_name']); // user.reset_pin

        // Por modulo y por accion exacta.
        self::assertSame(2, $get(['actor' => (string) $aid, 'action' => 'overtime'])['total']);
        self::assertSame(1, $get(['actor' => (string) $aid, 'action' => 'overtime.void'])['total']);
        self::assertSame(['reason' => 'error'], $get(['actor' => (string) $aid, 'action' => 'overtime.void'])['rows'][0]['details']);

        // Los login no salen por defecto, pero si se piden.
        self::assertSame(0, $get(['actor' => (string) $this->collab['id']])['total']);
        self::assertSame(1, $get(['actor' => (string) $this->collab['id'], 'include_login' => '1'])['total']);
        self::assertSame(1, $get(['actor' => (string) $this->collab['id'], 'action' => 'login'])['total']);

        // Rango de fechas: mañana no hay nada.
        self::assertSame(0, $get(['actor' => (string) $aid, 'from' => date('Y-m-d', strtotime('+1 day'))])['total']);
        self::assertSame(3, $get(['actor' => (string) $aid, 'from' => date('Y-m-d'), 'to' => date('Y-m-d')])['total']);
    }

    public function testBitacoraRechazaFiltrosInvalidosSinInyeccion(): void
    {
        $ctrl = new AuditController();

        // Una "accion" con comillas se descarta (no se interpola en el SQL).
        $res = $this->json($ctrl->list($this->req([], ['action' => "x' OR '1'='1"])));
        self::assertArrayHasKey('rows', $res);

        $this->expectException(HttpException::class);
        $ctrl->list($this->req([], ['actor' => '1 OR 1=1']));
    }
}
