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

final class OvertimeController
{
    public function create(Request $r, array $user): Response
    {
        $workDate = Validator::requireDate($r->input('work_date'), 'work_date');
        $hours    = Validator::requireDecimal($r->input('hours'), 'hours', 1, 24);
        $note     = Validator::requireString($r->input('note'), 'note', 3, 500);

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($workDate > $today) {
            throw HttpException::unprocessable('La fecha no puede ser futura.', ['field' => 'work_date']);
        }
        $limit = (new \DateTimeImmutable('today -60 days'))->format('Y-m-d');
        if ($workDate < $limit) {
            throw HttpException::unprocessable(
                'La fecha es muy antigua (mas de 60 dias). Avisale al administrador.',
                ['field' => 'work_date']
            );
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO overtime_entries (user_id, work_date, hours, note)
             VALUES (:u, :d, :h, :n)'
        );
        $stmt->execute([
            'u' => $user['id'],
            'd' => $workDate,
            'h' => $hours,
            'n' => $note,
        ]);
        $id = (int) $pdo->lastInsertId();

        Audit::log((int) $user['id'], 'overtime.create', 'overtime', $id, [
            'work_date' => $workDate,
            'hours'     => $hours,
        ]);

        Mailer::notifyAdmin(
            'Nueva solicitud de horas extra - ' . $user['display_name'],
            sprintf(
                '<p><strong>%s</strong> registro <strong>%s h</strong> el %s.</p><p>%s</p><p>Revisala en el panel de aprobaciones.</p>',
                htmlspecialchars((string) $user['display_name'], ENT_QUOTES),
                htmlspecialchars((string) $hours, ENT_QUOTES),
                htmlspecialchars($workDate, ENT_QUOTES),
                htmlspecialchars($note, ENT_QUOTES)
            )
        );

        return Response::json(['id' => $id, 'status' => 'pending'], 201);
    }

    /** @param array<string,mixed> $user */
    public function mine(array $user): Response
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, work_date, hours, note, status, review_note, created_at,
                    (voided_at IS NOT NULL) AS voided
             FROM overtime_entries
             WHERE user_id = :u AND closure_id IS NULL
             ORDER BY work_date DESC, id DESC'
        );
        $stmt->execute(['u' => $user['id']]);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    public function adminList(Request $r): Response
    {
        $status = $r->queryParam('status', 'pending');
        $where  = 'o.closure_id IS NULL';
        $params = [];

        if ($status === 'pending') {
            $where .= " AND o.status = 'pending' AND o.voided_at IS NULL";
        } elseif ($status !== 'all') {
            $where .= ' AND o.status = :st AND o.voided_at IS NULL';
            $params['st'] = $status;
        }

        $stmt = Database::pdo()->prepare(
            "SELECT o.id, o.user_id, u.display_name, o.work_date, o.hours, o.note,
                    o.status, o.review_note, o.created_at,
                    (o.voided_at IS NOT NULL) AS voided, o.void_reason
             FROM overtime_entries o
             JOIN users u ON u.id = o.user_id
             WHERE $where
             ORDER BY o.status = 'pending' DESC, o.work_date DESC, o.id DESC"
        );
        $stmt->execute($params);

        return Response::json(['entries' => self::castRows($stmt->fetchAll())]);
    }

    /** @param array<string,mixed> $admin */
    public function review(array $admin, int $id, string $newStatus, Request $r): Response
    {
        $note = Validator::optionalString($r->input('note'), 'note', 500);

        // El estado 'pending' se exige dentro del propio UPDATE (no en un SELECT
        // previo) para que dos admins aprobando/rechazando a la vez no pasen
        // ambos la validación antes de que el otro escriba.
        $stmt = Database::pdo()->prepare(
            "UPDATE overtime_entries
             SET status = :s, reviewed_by = :rb, reviewed_at = NOW(), review_note = :rn
             WHERE id = :id AND status = 'pending' AND voided_at IS NULL AND closure_id IS NULL"
        );
        $stmt->execute([
            's'  => $newStatus,
            'rb' => $admin['id'],
            'rn' => $note,
            'id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            $entry = self::find($id);
            if ($entry['closure_id'] !== null) {
                throw HttpException::unprocessable('Este registro ya fue cerrado, no se puede cambiar.');
            }
            if ($entry['voided_at'] !== null) {
                throw HttpException::unprocessable('Este registro esta anulado.');
            }
            throw HttpException::unprocessable('Este registro ya fue revisado.');
        }

        Audit::log((int) $admin['id'], 'overtime.' . $newStatus, 'overtime', $id, ['note' => $note]);

        return Response::json(['id' => $id, 'status' => $newStatus]);
    }

    /** @param array<string,mixed> $admin */
    public function void(array $admin, int $id, Request $r): Response
    {
        $reason = Validator::requireString($r->input('reason'), 'reason', 3, 500);

        $stmt = Database::pdo()->prepare(
            'UPDATE overtime_entries
             SET voided_at = NOW(), voided_by = :vb, void_reason = :vr
             WHERE id = :id AND closure_id IS NULL AND voided_at IS NULL'
        );
        $stmt->execute(['vb' => $admin['id'], 'vr' => $reason, 'id' => $id]);

        if ($stmt->rowCount() === 0) {
            $entry = self::find($id);
            if ($entry['closure_id'] !== null) {
                throw HttpException::unprocessable('Este registro ya fue cerrado, no se puede anular.');
            }
            throw HttpException::unprocessable('Este registro ya estaba anulado.');
        }

        Audit::log((int) $admin['id'], 'overtime.void', 'overtime', $id, ['reason' => $reason]);

        return Response::json(['id' => $id, 'voided' => true]);
    }

    /** @return array<string,mixed> */
    private static function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM overtime_entries WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Registro de horas extra no encontrado.');
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
            $row['hours']  = (float) $row['hours'];
            $row['voided'] = (bool) ($row['voided'] ?? false);
        }
        return $rows;
    }
}
