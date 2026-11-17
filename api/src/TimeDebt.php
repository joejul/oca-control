<?php

declare(strict_types=1);

namespace App;

use App\Http\HttpException;

/**
 * Logica de salidas anticipadas y reposicion de horas.
 *
 *   por reponer (due) = SUM(minutes_owed de early_leaves no anuladas)
 *                     - SUM(minutes de makeup_hours aprobadas y no anuladas)   (nunca < 0)
 *
 * El saldo se calcula siempre al consultar (no se guarda) para que no se
 * descuadre. Las reposiciones pendientes de revision no restan del saldo, pero
 * si cuentan contra lo que todavia se puede solicitar (available).
 */
final class TimeDebt
{
    /** Minutos entre la hora oficial de salida y la hora real ('HH:MM'). */
    public static function minutesBetween(string $scheduledEnd, string $actualEnd): int
    {
        $toMinutes = static function (string $hhmm): int {
            [$h, $m] = array_map('intval', explode(':', $hhmm));
            return $h * 60 + $m;
        };

        $diff = $toMinutes($scheduledEnd) - $toMinutes($actualEnd);
        if ($diff <= 0) {
            throw HttpException::unprocessable(
                'La hora real de salida debe ser anterior a la hora oficial de salida.',
                ['field' => 'actual_end']
            );
        }
        return $diff;
    }

    /** 150 -> "2 h 30 min", 60 -> "1 h", 45 -> "45 min", 0 -> "0 min". */
    public static function format(int $minutes): string
    {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        if ($h > 0 && $m > 0) {
            return "$h h $m min";
        }
        if ($h > 0) {
            return "$h h";
        }
        return "$m min";
    }

    /**
     * Saldo por colaborador activo (o por un colaborador concreto aunque este
     * inactivo). Los inactivos solo aparecen si todavia deben horas.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function balances(?int $userId = null): array
    {
        $where  = "u.role = 'collaborator'";
        $params = [];
        if ($userId !== null) {
            $where .= ' AND u.id = :uid';
            $params['uid'] = $userId;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT u.id AS user_id, u.display_name, u.active,
                    COALESCE(ow.owed, 0)     AS owed_minutes,
                    COALESCE(mk.approved, 0) AS approved_minutes,
                    COALESCE(mk.pending, 0)  AS pending_minutes
             FROM users u
             LEFT JOIN (
                 SELECT user_id, SUM(minutes_owed) AS owed
                 FROM early_leaves
                 WHERE voided_at IS NULL
                 GROUP BY user_id
             ) ow ON ow.user_id = u.id
             LEFT JOIN (
                 SELECT user_id,
                        SUM(CASE WHEN status = 'approved' THEN minutes ELSE 0 END) AS approved,
                        SUM(CASE WHEN status = 'pending'  THEN minutes ELSE 0 END) AS pending
                 FROM makeup_hours
                 WHERE voided_at IS NULL
                 GROUP BY user_id
             ) mk ON mk.user_id = u.id
             WHERE $where
             ORDER BY u.display_name"
        );
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $owed     = (int) $r['owed_minutes'];
            $approved = (int) $r['approved_minutes'];
            $pending  = (int) $r['pending_minutes'];
            $due      = max(0, $owed - $approved);

            // Para la lista general se ocultan inactivos sin deuda.
            if ($userId === null && !(bool) $r['active'] && $due === 0) {
                continue;
            }

            $rows[] = [
                'user_id'           => (int) $r['user_id'],
                'display_name'      => $r['display_name'],
                'active'            => (bool) $r['active'],
                'owed_minutes'      => $owed,
                'approved_minutes'  => $approved,
                'pending_minutes'   => $pending,
                'due_minutes'       => $due,
                'available_minutes' => max(0, $due - $pending),
            ];
        }
        return $rows;
    }

    /** @return array<string,mixed> */
    public static function balanceFor(int $userId): array
    {
        $rows = self::balances($userId);
        return $rows[0] ?? [
            'user_id'           => $userId,
            'display_name'      => '',
            'active'            => true,
            'owed_minutes'      => 0,
            'approved_minutes'  => 0,
            'pending_minutes'   => 0,
            'due_minutes'       => 0,
            'available_minutes' => 0,
        ];
    }

    /**
     * Fragmento SQL (tabla derivada con columnas user_id, due) con el saldo por
     * reponer de cada usuario. Misma formula que balances(); lo usa el cierre.
     */
    public static function dueSubquery(): string
    {
        return "SELECT x.user_id, GREATEST(0, SUM(x.owed) - SUM(x.made)) AS due
                FROM (
                    SELECT user_id, minutes_owed AS owed, 0 AS made
                    FROM early_leaves WHERE voided_at IS NULL
                    UNION ALL
                    SELECT user_id, 0 AS owed, minutes AS made
                    FROM makeup_hours WHERE status = 'approved' AND voided_at IS NULL
                ) x
                GROUP BY x.user_id";
    }

    /**
     * Bloquea la fila del usuario hasta el commit. Serializa registros
     * simultaneos del mismo colaborador (doble toque, dos dispositivos) para
     * que no se salten las validaciones de duplicado o de tope de saldo.
     * Debe llamarse dentro de una transaccion.
     */
    public static function lockUser(int $userId): void
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $userId]);
    }
}
