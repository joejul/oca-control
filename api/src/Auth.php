<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpException;
use PDO;

final class Auth
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Verifica credenciales, aplica rate limit y devuelve [token, user].
     *
     * @return array{token:string, user:array<string,mixed>}
     */
    public function login(string $username, string $secret): array
    {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => strtolower(trim($username))]);
        $user = $stmt->fetch();

        $genericError = HttpException::unauthorized('Usuario o PIN incorrecto.');

        if (!$user) {
            throw $genericError;
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            throw HttpException::tooManyRequests(
                'Cuenta bloqueada temporalmente por intentos fallidos. Intenta mas tarde.'
            );
        }

        if ((int) $user['active'] !== 1) {
            throw HttpException::forbidden('Este usuario esta inactivo. Habla con el administrador.');
        }

        if (!password_verify($secret, (string) $user['credential_hash'])) {
            $this->registerFailedAttempt($user);
            throw $genericError;
        }

        // Exito: limpia contadores.
        $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id')
            ->execute(['id' => $user['id']]);

        $token = $this->issueToken((int) $user['id']);
        Audit::log((int) $user['id'], 'login', 'user', (int) $user['id']);

        return ['token' => $token, 'user' => $this->publicUser($user)];
    }

    private function registerFailedAttempt(array $user): void
    {
        $attempts = (int) $user['failed_attempts'] + 1;
        $lockUntil = null;
        if ($attempts >= $this->config->maxLoginAttempts()) {
            $lockUntil = (new \DateTimeImmutable("+{$this->config->lockoutMinutes()} minutes"))
                ->format('Y-m-d H:i:s');
            $attempts = 0;
        }
        Database::pdo()
            ->prepare('UPDATE users SET failed_attempts = :a, locked_until = :l WHERE id = :id')
            ->execute(['a' => $attempts, 'l' => $lockUntil, 'id' => $user['id']]);
    }

    private function issueToken(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $exp   = (new \DateTimeImmutable("+{$this->config->tokenTtlDays()} days"))->format('Y-m-d H:i:s');

        Database::pdo()
            ->prepare('INSERT INTO auth_tokens (user_id, token_hash, expires_at) VALUES (:u, :h, :e)')
            ->execute(['u' => $userId, 'h' => $hash, 'e' => $exp]);

        return $token;
    }

    /** @return array<string,mixed>|null */
    public function userFromToken(?string $token): ?array
    {
        if ($token === null || $token === '') {
            return null;
        }
        $hash = hash('sha256', $token);
        $pdo  = Database::pdo();
        $stmt = $pdo->prepare(
            'SELECT u.* FROM auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :h AND t.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => $hash]);
        $user = $stmt->fetch();
        if (!$user || (int) $user['active'] !== 1) {
            return null;
        }
        $pdo->prepare('UPDATE auth_tokens SET last_used_at = NOW() WHERE token_hash = :h')
            ->execute(['h' => $hash]);

        return $user;
    }

    public function logout(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }
        Database::pdo()
            ->prepare('DELETE FROM auth_tokens WHERE token_hash = :h')
            ->execute(['h' => hash('sha256', $token)]);
    }

    public static function hashSecret(string $secret): string
    {
        return password_hash($secret, PASSWORD_BCRYPT);
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    public function publicUser(array $user): array
    {
        return [
            'id'           => (int) $user['id'],
            'username'     => $user['username'],
            'display_name' => $user['display_name'],
            'role'         => $user['role'],
        ];
    }
}
