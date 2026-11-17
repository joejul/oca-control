<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Mailer;
use App\TimeDebt;
use App\Validator;

/** Reposicion de horas: la registra el colaborador y la aprueba el admin. */
final class MakeupController
{
    /** @param array<string,mixed> $user */
    public function create(Request $r, array $user): Response
    {
        $makeupDate = Validator::requireDate($r->input('makeup_date'), 'makeup_date');
        $minutes    = Validator::requireInt($r->input('minutes'), 'minutes', 1, 1440);
        $note       = Validator::optionalString($r->input('note'), 'note', 500);

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($makeupDate > $today) {
            throw HttpException::unprocessable('La fecha no puede ser futura.', ['field' => 'makeup_date']);
        }
        $limit = (new \DateTimeImmutable('today -60 days'))->format('Y-m-d');
        if ($makeupDate < $limit) {
            throw HttpException::unprocessable(
                'La fecha es muy antigua (mas de 60 dias). Avisale al administrador.',
                ['field' => 'makeup_date']
            );
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            // Serializa por colaborador: el tope de saldo se valida con el saldo
            // ya confirmado, no con uno que otra peticion este por cambiar.
            TimeDebt::lockUser((int) $user['id']);

            $balance = TimeDebt::balanceFor((int) $user['id']);
            if ($balance['due_minutes'] === 0) {
                throw HttpException::unprocessable('No tenes horas pendientes por reponer.', ['field' => 'minutes']);
            }
            if ($minutes > $balance['available_minutes']) {
                $msg = $balance['available_minutes'] === 0
                    ? 'Ya tenes reposiciones pendientes de aprobacion que cubren todo tu saldo.'
                    : 'No podes reponer mas de ' . TimeDebt::format($balance['available_minutes'])
                        . ' (tu saldo menos lo que ya esta pendiente de aprobacion).';
                throw HttpException::unprocessable($msg, ['field' => 'minutes']);
            }

            $pdo->prepare(
                'INSERT INTO makeup_hours (user_id, makeup_date, minutes, note)
                 VALUES (:u, :d, :m, :n)'
            )->execute([
                'u' => $user['id'],
                'd' => $makeupDate,
                'm' => $minutes,
                'n' => $note,
            ]);
            $id = (int) $pdo->lastInsertId();

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        Audit::log((int) $user['id'], 'makeup.create', 'makeup', $id, [
            'makeup_date' => $makeupDate,
            'minutes'     => $minutes,
        ]);

        Mailer::notifyAdmin(
            'Nueva reposicion de horas - ' . $user['display_name'],
            sprintf(
                '<p><strong>%s</strong> registro una reposicion de <strong>%s</strong> el %s.</p><p>%s</p><p>Revisala en el panel de aprobaciones.</p>',
                htmlspecialchars((string) $user['display_name'], ENT_QUOTES),
                htmlspecialchars(TimeDebt::format($minutes), ENT_QUOTES),
                htmlspecialchars($makeupDate, ENT_QUOTES),
                htmlspecialchars((string) ($note ?? ''), ENT_QUOTES)
            )
        );

        return Response::json([
            'id'      => $id,
            'status'  => 'pending',
            'balance' => TimeDebt::balanceFor((int) $user['id']),
        ], 201);
    }

    public function adminList(Request $r): Response
    {
        $status = $r->queryParam('status', 'pending');
        $where  = '1=1';
        $params = [];

        if ($status === 'pending') {
            $where .= " AND m.status = 'pending' AND m.voided_at IS NULL";
        } elseif ($status !== 'all') {
            $where .= ' AND m.status = :st AND m.voided_at IS NULL';
            $params['st'] = $status;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT m.id, m.user_id, u.display_name, m.makeup_date, m.minutes, m.note,
                    m.status, m.review_note, m.created_at,
                    (m.voided_at IS NOT NULL) AS voided, m.void_reason
             FROM makeup_hours m
             JOIN users u ON u.id = m.user_id
             WHERE $where
             ORDER BY m.status = 'pending' DESC, m.makeup_date DESC, m.id DESC
             LIMIT 300"
        );
        $stmt->execute($params);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $admin */
    public function review(array $admin, int $id, string $newStatus, Request $r): Response
    {
        $note = Validator::optionalString($r->input('note'), 'note', 500);

        // El estado 'pending' se exige dentro del propio UPDATE para que dos
        // admins revisando a la vez no pasen ambos la validacion.
        $stmt = Database::pdo()->prepare(
            "UPDATE makeup_hours
             SET status = :s, reviewed_by = :rb, reviewed_at = NOW(), review_note = :rn
             WHERE id = :id AND status = 'pending' AND voided_at IS NULL"
        );
        $stmt->execute(['s' => $newStatus, 'rb' => $admin['id'], 'rn' => $note, 'id' => $id]);

        if ($stmt->rowCount() === 0) {
            $entry = self::find($id);
            if ($entry['voided_at'] !== null) {
                throw HttpException::unprocessable('Esta reposicion esta anulada.');
            }
            throw HttpException::unprocessable('Esta reposicion ya fue revisada.');
        }

        Audit::log((int) $admin['id'], 'makeup.' . $newStatus, 'makeup', $id, ['note' => $note]);

        return Response::json(['id' => $id, 'status' => $newStatus]);
    }

    /** @param array<string,mixed> $admin */
    public function void(array $admin, int $id, Request $r): Response
    {
        $reason = Validator::requireString($r->input('reason'), 'reason', 3, 500);

        $stmt = Database::pdo()->prepare(
            'UPDATE makeup_hours
             SET voided_at = NOW(), voided_by = :vb, void_reason = :vr
             WHERE id = :id AND voided_at IS NULL'
        );
        $stmt->execute(['vb' => $admin['id'], 'vr' => $reason, 'id' => $id]);

        if ($stmt->rowCount() === 0) {
            self::find($id); // 404 si no existe
            throw HttpException::unprocessable('Esta reposicion ya estaba anulada.');
        }

        Audit::log((int) $admin['id'], 'makeup.void', 'makeup', $id, ['reason' => $reason]);

        return Response::json(['id' => $id, 'voided' => true]);
    }

    /** @return array<string,mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM makeup_hours WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Reposicion no encontrada.');
        }
        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function castRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            if (isset($row['user_id'])) {
                $row['user_id'] = (int) $row['user_id'];
            }
            $row['minutes'] = (int) $row['minutes'];
            $row['voided']  = (bool) ($row['voided'] ?? false);
        }
        return $rows;
    }
}
