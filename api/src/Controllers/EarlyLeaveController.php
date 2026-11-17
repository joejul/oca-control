<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\TimeDebt;
use App\Validator;

/** Salidas anticipadas: el colaborador las registra; no requieren aprobacion. */
final class EarlyLeaveController
{
    /** @param array<string,mixed> $user */
    public function create(Request $r, array $user): Response
    {
        $leaveDate    = Validator::requireDate($r->input('leave_date'), 'leave_date');
        $scheduledEnd = Validator::requireTime($r->input('scheduled_end'), 'scheduled_end');
        $actualEnd    = Validator::requireTime($r->input('actual_end'), 'actual_end');
        $note         = Validator::requireString($r->input('note'), 'note', 3, 500);

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($leaveDate > $today) {
            throw HttpException::unprocessable('La fecha no puede ser futura.', ['field' => 'leave_date']);
        }
        $limit = (new \DateTimeImmutable('today -60 days'))->format('Y-m-d');
        if ($leaveDate < $limit) {
            throw HttpException::unprocessable(
                'La fecha es muy antigua (mas de 60 dias). Avisale al administrador.',
                ['field' => 'leave_date']
            );
        }

        $minutes = TimeDebt::minutesBetween($scheduledEnd, $actualEnd);

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            TimeDebt::lockUser((int) $user['id']);

            $dup = $pdo->prepare(
                'SELECT 1 FROM early_leaves
                 WHERE user_id = :u AND leave_date = :d AND voided_at IS NULL LIMIT 1'
            );
            $dup->execute(['u' => $user['id'], 'd' => $leaveDate]);
            if ($dup->fetchColumn()) {
                throw HttpException::unprocessable(
                    'Ya registraste una salida anticipada para esa fecha. Si hay un error, pedile al administrador que la anule.',
                    ['field' => 'leave_date']
                );
            }

            $pdo->prepare(
                'INSERT INTO early_leaves (user_id, leave_date, scheduled_end, actual_end, minutes_owed, note)
                 VALUES (:u, :d, :se, :ae, :m, :n)'
            )->execute([
                'u'  => $user['id'],
                'd'  => $leaveDate,
                'se' => $scheduledEnd . ':00',
                'ae' => $actualEnd . ':00',
                'm'  => $minutes,
                'n'  => $note,
            ]);
            $id = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Audit::log((int) $user['id'], 'early_leave.create', 'early_leave', $id, [
            'leave_date'    => $leaveDate,
            'scheduled_end' => $scheduledEnd,
            'actual_end'    => $actualEnd,
            'minutes_owed'  => $minutes,
        ]);

        return Response::json([
            'id'           => $id,
            'minutes_owed' => $minutes,
            'balance'      => TimeDebt::balanceFor((int) $user['id']),
        ], 201);
    }

    /** Lista para el admin (todas las no anuladas por defecto, o 'all' para incluir anuladas). */
    public function adminList(Request $r): Response
    {
        $status = $r->queryParam('status', 'all');
        $where  = $status === 'all' ? '1=1' : 'e.voided_at IS NULL';

        $stmt = Database::pdo()->prepare(
            "SELECT e.id, e.user_id, u.display_name, e.leave_date, e.scheduled_end, e.actual_end,
                    e.minutes_owed, e.note, e.created_at,
                    (e.voided_at IS NOT NULL) AS voided, e.void_reason
             FROM early_leaves e
             JOIN users u ON u.id = e.user_id
             WHERE $where
             ORDER BY e.leave_date DESC, e.id DESC
             LIMIT 300"
        );
        $stmt->execute();

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $admin */
    public function void(array $admin, int $id, Request $r): Response
    {
        $reason = Validator::requireString($r->input('reason'), 'reason', 3, 500);

        $stmt = Database::pdo()->prepare(
            'UPDATE early_leaves
             SET voided_at = NOW(), voided_by = :vb, void_reason = :vr
             WHERE id = :id AND voided_at IS NULL'
        );
        $stmt->execute(['vb' => $admin['id'], 'vr' => $reason, 'id' => $id]);

        if ($stmt->rowCount() === 0) {
            $exists = Database::pdo()->prepare('SELECT 1 FROM early_leaves WHERE id = :id');
            $exists->execute(['id' => $id]);
            if (!$exists->fetchColumn()) {
                throw HttpException::notFound('Salida anticipada no encontrada.');
            }
            throw HttpException::unprocessable('Esta salida anticipada ya estaba anulada.');
        }

        Audit::log((int) $admin['id'], 'early_leave.void', 'early_leave', $id, ['reason' => $reason]);

        return Response::json(['id' => $id, 'voided' => true]);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function castRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id']            = (int) $row['id'];
            if (isset($row['user_id'])) {
                $row['user_id'] = (int) $row['user_id'];
            }
            $row['scheduled_end'] = substr((string) $row['scheduled_end'], 0, 5);
            $row['actual_end']    = substr((string) $row['actual_end'], 0, 5);
            $row['minutes_owed']  = (int) $row['minutes_owed'];
            $row['voided']        = (bool) ($row['voided'] ?? false);
        }
        return $rows;
    }
}
