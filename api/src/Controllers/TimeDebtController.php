<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Database;
use App\Http\Response;
use App\TimeDebt;

/** Consultas de saldo "por reponer". */
final class TimeDebtController
{
    /**
     * Saldo y movimientos del colaborador logueado. No depende de los cierres:
     * la deuda arrastra hasta que se reponga, asi que se muestra todo el historial.
     *
     * @param array<string,mixed> $user
     */
    public function mine(array $user): Response
    {
        $pdo = Database::pdo();

        $leaves = $pdo->prepare(
            'SELECT id, leave_date, scheduled_end, actual_end, minutes_owed, note, created_at,
                    (voided_at IS NOT NULL) AS voided, void_reason
             FROM early_leaves
             WHERE user_id = :u
             ORDER BY leave_date DESC, id DESC
             LIMIT 200'
        );
        $leaves->execute(['u' => $user['id']]);

        $makeups = $pdo->prepare(
            'SELECT id, makeup_date, minutes, note, status, review_note, created_at,
                    (voided_at IS NOT NULL) AS voided, void_reason
             FROM makeup_hours
             WHERE user_id = :u
             ORDER BY makeup_date DESC, id DESC
             LIMIT 200'
        );
        $makeups->execute(['u' => $user['id']]);

        return Response::json([
            'balance'     => TimeDebt::balanceFor((int) $user['id']),
            'early_leaves' => EarlyLeaveController::castRows($leaves->fetchAll()),
            'makeups'      => MakeupController::castRows($makeups->fetchAll()),
        ]);
    }

    /** Saldos de todos los colaboradores (admin). */
    public function adminBalances(): Response
    {
        $rows  = TimeDebt::balances();
        $total = array_sum(array_column($rows, 'due_minutes'));

        return Response::json([
            'balances'      => $rows,
            'total_minutes' => (int) $total,
        ]);
    }
}
