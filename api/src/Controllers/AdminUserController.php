<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Auth;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Validator;

final class AdminUserController
{
    /** Lista para el selector de "companero" (colaboradores activos). */
    public function collaborators(): Response
    {
        $rows = Database::pdo()->query(
            "SELECT id, display_name FROM users
             WHERE active = 1 AND role = 'collaborator'
             ORDER BY display_name"
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        return Response::json(['collaborators' => $rows]);
    }

    /** Lista completa para el panel de admin. */
    public function list(): Response
    {
        $rows = Database::pdo()->query(
            'SELECT id, username, display_name, role, active, locked_until, created_at
             FROM users ORDER BY role = \'admin\' DESC, display_name'
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['id']     = (int) $row['id'];
            $row['active'] = (bool) $row['active'];
            $row['locked'] = $row['locked_until'] !== null
                && strtotime((string) $row['locked_until']) > time();
            unset($row['locked_until']);
        }
        return Response::json(['users' => $rows]);
    }

    /** @param array<string,mixed> $admin */
    public function create(array $admin, Request $r): Response
    {
        $username = Validator::requireUsername($r->input('username'));
        $display  = Validator::requireString($r->input('display_name'), 'display_name', 2, 80);
        $pin      = Validator::requirePin($r->input('pin'));
        $role     = $r->input('role', 'collaborator');
        if (!in_array($role, ['collaborator', 'admin'], true)) {
            throw HttpException::unprocessable('Rol invalido.', ['field' => 'role']);
        }

        $exists = Database::pdo()->prepare('SELECT 1 FROM users WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetchColumn()) {
            throw HttpException::unprocessable('Ese usuario ya existe.', ['field' => 'username']);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO users (username, display_name, role, credential_hash)
             VALUES (:u, :d, :r, :h)'
        )->execute([
            'u' => $username,
            'd' => $display,
            'r' => $role,
            'h' => Auth::hashSecret($pin),
        ]);
        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $admin['id'], 'user.create', 'user', $id, [
            'username' => $username,
            'role'     => $role,
        ]);

        return Response::json([
            'id'           => $id,
            'username'     => $username,
            'display_name' => $display,
            'role'         => $role,
            'active'       => true,
        ], 201);
    }

    /** @param array<string,mixed> $admin */
    public function update(array $admin, int $id, Request $r): Response
    {
        $user = self::find($id);

        $sets   = [];
        $params = ['id' => $id];

        if ($r->input('active') !== null) {
            $active = (bool) $r->input('active');
            if (!$active && (int) $user['id'] === (int) $admin['id']) {
                throw HttpException::unprocessable('No podes desactivarte a vos mismo.');
            }
            $sets[] = 'active = :active';
            $params['active'] = $active ? 1 : 0;
        }

        if ($r->input('display_name') !== null) {
            $sets[] = 'display_name = :dn';
            $params['dn'] = Validator::requireString($r->input('display_name'), 'display_name', 2, 80);
        }

        if ($r->input('unlock') === true) {
            $sets[] = 'locked_until = NULL, failed_attempts = 0';
        }

        if ($sets === []) {
            throw HttpException::badRequest('Nada que actualizar.');
        }

        Database::pdo()
            ->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);

        Audit::log((int) $admin['id'], 'user.update', 'user', $id, $r->body);

        return Response::json(['id' => $id, 'ok' => true]);
    }

    /** @param array<string,mixed> $admin */
    public function resetPin(array $admin, int $id, Request $r): Response
    {
        self::find($id);
        $pin = Validator::requirePin($r->input('pin'));

        Database::pdo()->prepare(
            'UPDATE users SET credential_hash = :h, failed_attempts = 0, locked_until = NULL
             WHERE id = :id'
        )->execute(['h' => Auth::hashSecret($pin), 'id' => $id]);

        // Cierra sesiones abiertas de ese usuario.
        Database::pdo()->prepare('DELETE FROM auth_tokens WHERE user_id = :id')->execute(['id' => $id]);

        Audit::log((int) $admin['id'], 'user.reset_pin', 'user', $id);

        return Response::json(['id' => $id, 'ok' => true]);
    }

    /** @return array<string,mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Usuario no encontrado.');
        }
        return $row;
    }
}
