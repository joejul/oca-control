<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;

/**
 * Novedades del colaborador: lo que el administrador aprobo, rechazo o anulo de lo suyo.
 *
 * No hay tabla de eventos: se derivan de reviewed_at / voided_at de cada modulo. Solo se
 * guarda cuando el colaborador las vio por ultima vez (user_notification_state).
 */
final class NotificationController
{
    private const WINDOW_DAYS = 30;
    private const MAX_ITEMS   = 30;

    /** @param array<string,mixed> $user */
    public function mine(array $user): Response
    {
        $pdo = Database::pdo();
        $uid = (int) $user['id'];

        // Primera vez: se marca todo lo anterior como visto para no inundar con historial.
        $pdo->prepare('INSERT IGNORE INTO user_notification_state (user_id, seen_at) VALUES (?, NOW())')
            ->execute([$uid]);

        $seenStmt = $pdo->prepare('SELECT seen_at FROM user_notification_state WHERE user_id = ?');
        $seenStmt->execute([$uid]);
        $seenAt = (string) $seenStmt->fetchColumn();

        // kind, id, event, ref_date, amount, note, event_at. amount: horas (overtime) o minutos.
        $sql = "
            SELECT * FROM (
                SELECT 'overtime' AS kind, id, status AS event, work_date AS ref_date,
                       CAST(hours AS DECIMAL(8,2)) AS amount, review_note AS note, reviewed_at AS event_at
                FROM overtime_entries WHERE user_id = ? AND reviewed_at IS NOT NULL
              UNION ALL
                SELECT 'overtime', id, 'voided', work_date,
                       CAST(hours AS DECIMAL(8,2)), void_reason, voided_at
                FROM overtime_entries WHERE user_id = ? AND voided_at IS NOT NULL
              UNION ALL
                SELECT 'vacation', id, status, request_date,
                       NULL, review_note, reviewed_at
                FROM vacation_requests WHERE user_id = ? AND reviewed_at IS NOT NULL
              UNION ALL
                SELECT 'vacation', id, 'voided', request_date,
                       NULL, void_reason, voided_at
                FROM vacation_requests WHERE user_id = ? AND voided_at IS NOT NULL
              UNION ALL
                SELECT 'makeup', id, status, makeup_date,
                       CAST(minutes AS DECIMAL(8,2)), review_note, reviewed_at
                FROM makeup_hours WHERE user_id = ? AND reviewed_at IS NOT NULL
              UNION ALL
                SELECT 'makeup', id, 'voided', makeup_date,
                       CAST(minutes AS DECIMAL(8,2)), void_reason, voided_at
                FROM makeup_hours WHERE user_id = ? AND voided_at IS NOT NULL
              UNION ALL
                SELECT 'early_leave', id, 'voided', leave_date,
                       CAST(minutes_owed AS DECIMAL(8,2)), void_reason, voided_at
                FROM early_leaves WHERE user_id = ? AND voided_at IS NOT NULL
            ) e
            WHERE e.event_at >= (NOW() - INTERVAL " . self::WINDOW_DAYS . " DAY)
            ORDER BY e.event_at DESC, e.id DESC
            LIMIT " . self::MAX_ITEMS;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_fill(0, 7, $uid));

        $items  = [];
        $unread = 0;
        foreach ($stmt->fetchAll() as $row) {
            $isUnread = (string) $row['event_at'] > $seenAt;
            $unread  += $isUnread ? 1 : 0;
            $items[]  = [
                'kind'     => $row['kind'],
                'id'       => (int) $row['id'],
                'event'    => $row['event'],
                'ref_date' => $row['ref_date'],
                'amount'   => $row['amount'] === null ? null : (float) $row['amount'],
                'note'     => $row['note'],
                'event_at' => $row['event_at'],
                'unread'   => $isUnread,
            ];
        }

        return Response::json(['unread' => $unread, 'items' => $items]);
    }

    /**
     * Marca como vistas las novedades hasta `up_to` (la fecha de la ultima que el cliente
     * mostro). Asi lo que llegue mientras tanto no se marca por accidente.
     *
     * @param array<string,mixed> $user
     */
    public function markSeen(Request $r, array $user): Response
    {
        $pdo = Database::pdo();
        $uid = (int) $user['id'];

        $upTo = $r->input('up_to');
        if ($upTo !== null && (!is_string($upTo) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $upTo))) {
            throw HttpException::unprocessable('Fecha invalida.', ['field' => 'up_to']);
        }

        $pdo->prepare('INSERT IGNORE INTO user_notification_state (user_id, seen_at) VALUES (?, NOW())')
            ->execute([$uid]);

        if ($upTo === null) {
            $pdo->prepare('UPDATE user_notification_state SET seen_at = NOW() WHERE user_id = ?')
                ->execute([$uid]);
        } else {
            $pdo->prepare('UPDATE user_notification_state SET seen_at = GREATEST(seen_at, ?) WHERE user_id = ?')
                ->execute([$upTo, $uid]);
        }

        return Response::json(['ok' => true]);
    }
}
