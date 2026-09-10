<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mailer;
use App\Validator;

final class VacationController
{
    public function create(Request $r, array $user): Response
    {
        $requestDate = Validator::requireDate($r->input('request_date'), 'request_date');
        $description = Validator::optionalString($r->input('description'), 'description', 500);

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO vacation_requests (user_id, request_date, description)
             VALUES (:u, :d, :desc)'
        );
        $stmt->execute([
            'u'    => $user['id'],
            'd'    => $requestDate,
            'desc' => $description,
        ]);
        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'vacation.create', 'vacation', $id, [
            'request_date' => $requestDate,
        ]);

        Mailer::notifyAdmin(
            'Nueva solicitud de vacaciones - ' . $user['display_name'],
            sprintf(
                '<p><strong>%s</strong> solicito vacaciones para el %s.</p><p>%s</p><p>Revisala en el panel de aprobaciones.</p>',
                htmlspecialchars((string) $user['display_name'], ENT_QUOTES),
                htmlspecialchars($requestDate, ENT_QUOTES),
                htmlspecialchars((string) ($description ?? ''), ENT_QUOTES)
            )
        );

        return Response::json(['id' => $id, 'status' => 'pending'], 201);
    }

    /** @param array<string,mixed> $user */
    public function mine(array $user): Response
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, request_date, description, status, review_note, created_at,
                    (voided_at IS NOT NULL) AS voided, void_reason
             FROM vacation_requests
             WHERE user_id = :u
             ORDER BY request_date DESC, id DESC'
        );
        $stmt->execute(['u' => $user['id']]);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    public function adminList(Request $r): Response
    {
        $status = $r->queryParam('status', 'pending');
        $where  = '1=1';
        $params = [];

        if ($status === 'pending') {
            $where .= " AND v.status = 'pending' AND v.voided_at IS NULL";
        } elseif ($status !== 'all') {
            $where .= ' AND v.status = :st AND v.voided_at IS NULL';
            $params['st'] = $status;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT v.id, v.user_id, u.display_name, v.request_date, v.description,
                    v.status, v.review_note, v.created_at,
                    (v.voided_at IS NOT NULL) AS voided, v.void_reason
             FROM vacation_requests v
             JOIN users u ON u.id = v.user_id
             WHERE $where
             ORDER BY v.status = 'pending' DESC, v.request_date DESC, v.id DESC"
        );
        $stmt->execute($params);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $admin */
    public function review(array $admin, int $id, string $newStatus, Request $r): Response
    {
        $entry = self::find($id);

        if ($entry['voided_at'] !== null) {
            throw HttpException::unprocessable('Esta solicitud esta anulada.');
        }
        if ($entry['status'] !== 'pending') {
            throw HttpException::unprocessable('Esta solicitud ya fue revisada.');
        }

        $note = Validator::optionalString($r->input('note'), 'note', 500);

        Database::pdo()->prepare(
            'UPDATE vacation_requests
             SET status = :s, reviewed_by = :rb, reviewed_at = NOW(), review_note = :rn
             WHERE id = :id'
        )->execute([
            's'  => $newStatus,
            'rb' => $admin['id'],
            'rn' => $note,
            'id' => $id,
        ]);

        Audit::log((int) $admin['id'], 'vacation.' . $newStatus, 'vacation', $id, ['note' => $note]);

        return Response::json(['id' => $id, 'status' => $newStatus]);
    }

    /** @param array<string,mixed> $admin */
    public function void(array $admin, int $id, Request $r): Response
    {
        $entry  = self::find($id);
        $reason = Validator::requireString($r->input('reason'), 'reason', 3, 500);

        if ($entry['voided_at'] !== null) {
            throw HttpException::unprocessable('Esta solicitud ya estaba anulada.');
        }

        Database::pdo()->prepare(
            'UPDATE vacation_requests
             SET voided_at = NOW(), voided_by = :vb, void_reason = :vr
             WHERE id = :id'
        )->execute(['vb' => $admin['id'], 'vr' => $reason, 'id' => $id]);

        Audit::log((int) $admin['id'], 'vacation.void', 'vacation', $id, ['reason' => $reason]);

        return Response::json(['id' => $id, 'voided' => true]);
    }

    /** @return array<string,mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM vacation_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Solicitud de vacaciones no encontrada.');
        }
        return $row;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function castRows(array $rows): array
    {
        foreach ($rows as &$row) {
            if (isset($row['id'])) {
                $row['id'] = (int) $row['id'];
            }
            if (isset($row['user_id'])) {
                $row['user_id'] = (int) $row['user_id'];
            }
            $row['voided'] = (bool) ($row['voided'] ?? false);
        }
        return $rows;
    }
}
