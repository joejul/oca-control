<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Validator;

final class ConsumptionController
{
    /** @param array<string,mixed> $user */
    public function create(Request $r, array $user): Response
    {
        $consumerId = Validator::requireInt($r->input('consumer_id'), 'consumer_id');
        $amount     = Validator::requireDecimal($r->input('amount'), 'amount', 1, 1_000_000);
        $detail     = Validator::requireString($r->input('detail'), 'detail', 2, 500);

        if ($consumerId === (int) $user['id']) {
            throw HttpException::unprocessable('No podes registrar tu propio consumo. Pedile a un companero que lo haga.');
        }

        $stmt = Database::pdo()->prepare(
            "SELECT id, display_name FROM users
             WHERE id = :id AND active = 1 AND role = 'collaborator' LIMIT 1"
        );
        $stmt->execute(['id' => $consumerId]);
        $consumer = $stmt->fetch();
        if (!$consumer) {
            throw HttpException::unprocessable('El companero seleccionado no es valido.', ['field' => 'consumer_id']);
        }

        $pdo = Database::pdo();
        $pdo->prepare(
            'INSERT INTO consumption_entries (consumer_id, registered_by, amount, detail)
             VALUES (:c, :rb, :a, :d)'
        )->execute([
            'c'  => $consumerId,
            'rb' => $user['id'],
            'a'  => $amount,
            'd'  => $detail,
        ]);
        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'consumption.create', 'consumption', $id, [
            'consumer_id' => $consumerId,
            'amount'      => $amount,
        ]);

        return Response::json([
            'id'            => $id,
            'consumer_name' => $consumer['display_name'],
        ], 201);
    }

    /** @param array<string,mixed> $user */
    public function mine(array $user): Response
    {
        $stmt = Database::pdo()->prepare(
            "SELECT c.id, c.amount, c.detail, c.created_at,
                    c.consumer_id, cu.display_name AS consumer_name,
                    c.registered_by, ru.display_name AS registered_by_name,
                    (c.voided_at IS NOT NULL) AS voided, c.void_reason
             FROM consumption_entries c
             JOIN users cu ON cu.id = c.consumer_id
             JOIN users ru ON ru.id = c.registered_by
             WHERE (c.consumer_id = :u OR c.registered_by = :u2) AND c.closure_id IS NULL
             ORDER BY c.id DESC"
        );
        $stmt->execute(['u' => $user['id'], 'u2' => $user['id']]);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $admin */
    public function void(array $admin, int $id, Request $r): Response
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM consumption_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $entry = $stmt->fetch();
        if (!$entry) {
            throw HttpException::notFound('Consumo no encontrado.');
        }

        $reason = Validator::requireString($r->input('reason'), 'reason', 3, 500);

        if ($entry['closure_id'] !== null) {
            throw HttpException::unprocessable('Este consumo ya fue cerrado, no se puede anular.');
        }
        if ($entry['voided_at'] !== null) {
            throw HttpException::unprocessable('Este consumo ya estaba anulado.');
        }

        Database::pdo()->prepare(
            'UPDATE consumption_entries
             SET voided_at = NOW(), voided_by = :vb, void_reason = :vr
             WHERE id = :id'
        )->execute(['vb' => $admin['id'], 'vr' => $reason, 'id' => $id]);

        Audit::log((int) $admin['id'], 'consumption.void', 'consumption', $id, ['reason' => $reason]);

        return Response::json(['id' => $id, 'voided' => true]);
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function castRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id']            = (int) $row['id'];
            $row['amount']        = (float) $row['amount'];
            $row['consumer_id']   = (int) $row['consumer_id'];
            $row['registered_by'] = (int) $row['registered_by'];
            $row['voided']        = (bool) $row['voided'];
        }
        return $rows;
    }
}
