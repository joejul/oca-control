<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Validator;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ClosureController
{
    public function preview(Request $r): Response
    {
        [$start, $end] = $this->resolveRange(
            $r->queryParam('start'),
            $r->queryParam('end')
        );

        $data = $this->computeTotals($start, $end);

        return Response::json([
            'start'         => $start,
            'end'           => $end,
            'rows'          => $data['rows'],
            'totals'        => $data['totals'],
            'pending_count' => $data['pending_count'],
        ]);
    }

    /** @param array<string,mixed> $admin */
    public function generate(array $admin, Request $r): Response
    {
        $start = Validator::requireDate($r->input('start'), 'start');
        $end   = Validator::requireDate($r->input('end'), 'end');
        if ($start > $end) {
            throw HttpException::unprocessable('La fecha inicial no puede ser mayor que la final.');
        }
        $label = Validator::optionalString($r->input('label'), 'label', 120)
            ?? ('Cierre ' . $start . ' a ' . $end);

        $pdo = Database::pdo();
        $data = $this->computeTotals($start, $end);

        if ($data['totals']['overtime_hours'] === 0.0 && $data['totals']['consumption_total'] === 0.0) {
            throw HttpException::unprocessable('No hay registros aprobados en ese rango para cerrar.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO closures (label, start_date, end_date, generated_by, totals_json)
                 VALUES (:l, :s, :e, :by, :t)'
            )->execute([
                'l'  => $label,
                's'  => $start,
                'e'  => $end,
                'by' => $admin['id'],
                't'  => json_encode($data, JSON_UNESCAPED_UNICODE),
            ]);
            $closureId = (int) $pdo->lastInsertId();

            // Horas extra: se archivan las ya resueltas (aprobadas/rechazadas) o anuladas.
            // Las pendientes quedan abiertas para el proximo cierre.
            $pdo->prepare(
                "UPDATE overtime_entries
                 SET closure_id = :cid
                 WHERE closure_id IS NULL
                   AND work_date BETWEEN :s AND :e
                   AND (status IN ('approved','rejected') OR voided_at IS NOT NULL)"
            )->execute(['cid' => $closureId, 's' => $start, 'e' => $end]);

            // Consumos: se archivan todos los del rango (anulados o no).
            $pdo->prepare(
                'UPDATE consumption_entries
                 SET closure_id = :cid
                 WHERE closure_id IS NULL
                   AND DATE(created_at) BETWEEN :s AND :e'
            )->execute(['cid' => $closureId, 's' => $start, 'e' => $end]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        Audit::log((int) $admin['id'], 'closure.generate', 'closure', $closureId, [
            'start' => $start,
            'end'   => $end,
            'label' => $label,
        ]);

        return Response::json([
            'id'     => $closureId,
            'label'  => $label,
            'start'  => $start,
            'end'    => $end,
            'rows'   => $data['rows'],
            'totals' => $data['totals'],
        ], 201);
    }

    public function list(): Response
    {
        $rows = Database::pdo()->query(
            'SELECT c.id, c.label, c.start_date, c.end_date, c.generated_at,
                    u.display_name AS generated_by_name, c.totals_json
             FROM closures c JOIN users u ON u.id = c.generated_by
             ORDER BY c.id DESC'
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['id']     = (int) $row['id'];
            $totals        = json_decode((string) $row['totals_json'], true);
            $row['totals'] = $totals['totals'] ?? null;
            unset($row['totals_json']);
        }

        return Response::json(['closures' => $rows]);
    }

    public function show(int $id): Response
    {
        $closure = $this->find($id);
        $snapshot = json_decode((string) $closure['totals_json'], true) ?: [];
        $detail = $this->fetchDetail($id);

        return Response::json([
            'closure' => [
                'id'         => (int) $closure['id'],
                'label'      => $closure['label'],
                'start'      => $closure['start_date'],
                'end'        => $closure['end_date'],
                'generated_at' => $closure['generated_at'],
            ],
            'rows'        => $snapshot['rows'] ?? [],
            'totals'      => $snapshot['totals'] ?? null,
            'overtime'    => $detail['overtime'],
            'consumption' => $detail['consumption'],
        ]);
    }

    public function exportCsv(int $id): Response
    {
        $closure  = $this->find($id);
        $snapshot = json_decode((string) $closure['totals_json'], true) ?: [];
        $rows     = $snapshot['rows'] ?? [];

        $out = fopen('php://temp', 'r+');
        fprintf($out, "\xEF\xBB\xBF"); // BOM para Excel
        fputcsv($out, ['Cierre', $closure['label']]);
        fputcsv($out, ['Rango', $closure['start_date'] . ' a ' . $closure['end_date']]);
        fputcsv($out, []);
        fputcsv($out, ['Colaborador', 'Horas extra', 'Consumos (CRC)']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['display_name'],
                $row['overtime_hours'],
                $row['consumption_total'],
            ]);
        }
        $totals = $snapshot['totals'] ?? ['overtime_hours' => 0, 'consumption_total' => 0];
        fputcsv($out, []);
        fputcsv($out, ['TOTAL', $totals['overtime_hours'], $totals['consumption_total']]);

        rewind($out);
        $csv = stream_get_contents($out) ?: '';
        fclose($out);

        return Response::csv($csv, 'cierre-' . $id . '.csv');
    }

    public function exportPdf(int $id): Response
    {
        $closure  = $this->find($id);
        $snapshot = json_decode((string) $closure['totals_json'], true) ?: [];
        $detail   = $this->fetchDetail($id);

        $html = $this->renderPdfHtml(
            $closure,
            $snapshot['rows'] ?? [],
            $snapshot['totals'] ?? ['overtime_hours' => 0, 'consumption_total' => 0],
            $detail['overtime'],
            $detail['consumption']
        );

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return Response::pdf($dompdf->output(), 'cierre-' . $id . '.pdf');
    }

    // ---------------------------------------------------------------------

    /** @return array{0:string,1:string} */
    private function resolveRange(?string $start, ?string $end): array
    {
        $end = $end !== null && $end !== ''
            ? Validator::requireDate($end, 'end')
            : (new \DateTimeImmutable('today'))->format('Y-m-d');

        if ($start !== null && $start !== '') {
            $start = Validator::requireDate($start, 'start');
            return [min($start, $end), $end];
        }

        $pdo = Database::pdo();
        $lastEnd = $pdo->query('SELECT MAX(end_date) FROM closures')->fetchColumn();
        if ($lastEnd) {
            $start = (new \DateTimeImmutable($lastEnd . ' +1 day'))->format('Y-m-d');
        } else {
            $minOt = $pdo->query('SELECT MIN(work_date) FROM overtime_entries')->fetchColumn();
            $minCo = $pdo->query('SELECT MIN(DATE(created_at)) FROM consumption_entries')->fetchColumn();
            $candidates = array_filter([$minOt, $minCo]);
            $start = $candidates ? min($candidates) : $end;
        }

        // Nunca devolver un rango invertido (p. ej. si el ultimo cierre llega hasta hoy).
        return [min($start, $end), $end];
    }

    /** @return array{rows:array<int,array<string,mixed>>, totals:array<string,float>, pending_count:int} */
    private function computeTotals(string $start, string $end): array
    {
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT u.id AS user_id, u.display_name,
                    COALESCE(ot.hours, 0)  AS overtime_hours,
                    COALESCE(co.total, 0)  AS consumption_total
             FROM users u
             LEFT JOIN (
                 SELECT user_id, SUM(hours) AS hours
                 FROM overtime_entries
                 WHERE closure_id IS NULL AND status = 'approved' AND voided_at IS NULL
                   AND work_date BETWEEN :s1 AND :e1
                 GROUP BY user_id
             ) ot ON ot.user_id = u.id
             LEFT JOIN (
                 SELECT consumer_id, SUM(amount) AS total
                 FROM consumption_entries
                 WHERE closure_id IS NULL AND voided_at IS NULL
                   AND DATE(created_at) BETWEEN :s2 AND :e2
                 GROUP BY consumer_id
             ) co ON co.consumer_id = u.id
             WHERE u.role = 'collaborator'
               AND (ot.hours IS NOT NULL OR co.total IS NOT NULL OR u.active = 1)
             ORDER BY u.display_name"
        );
        $stmt->execute(['s1' => $start, 'e1' => $end, 's2' => $start, 'e2' => $end]);

        $rows = [];
        $sumHours = 0.0;
        $sumConsumption = 0.0;
        foreach ($stmt->fetchAll() as $row) {
            $hours = (float) $row['overtime_hours'];
            $cons  = (float) $row['consumption_total'];
            $sumHours += $hours;
            $sumConsumption += $cons;
            $rows[] = [
                'user_id'           => (int) $row['user_id'],
                'display_name'      => $row['display_name'],
                'overtime_hours'    => $hours,
                'consumption_total' => $cons,
            ];
        }

        $pending = $pdo->prepare(
            "SELECT COUNT(*) FROM overtime_entries
             WHERE closure_id IS NULL AND status = 'pending' AND voided_at IS NULL
               AND work_date BETWEEN :s AND :e"
        );
        $pending->execute(['s' => $start, 'e' => $end]);

        return [
            'rows'          => $rows,
            'totals'        => [
                'overtime_hours'    => round($sumHours, 2),
                'consumption_total' => round($sumConsumption, 2),
            ],
            'pending_count' => (int) $pending->fetchColumn(),
        ];
    }

    /** @return array{overtime:array<int,array<string,mixed>>, consumption:array<int,array<string,mixed>>} */
    private function fetchDetail(int $id): array
    {
        $overtime = Database::pdo()->prepare(
            'SELECT o.id, u.display_name, o.work_date, o.hours, o.note, o.status,
                    (o.voided_at IS NOT NULL) AS voided
             FROM overtime_entries o JOIN users u ON u.id = o.user_id
             WHERE o.closure_id = :cid
             ORDER BY u.display_name, o.work_date'
        );
        $overtime->execute(['cid' => $id]);

        $consumption = Database::pdo()->prepare(
            'SELECT c.id, cu.display_name AS consumer_name, ru.display_name AS registered_by_name,
                    c.amount, c.detail, c.created_at,
                    (c.voided_at IS NOT NULL) AS voided
             FROM consumption_entries c
             JOIN users cu ON cu.id = c.consumer_id
             JOIN users ru ON ru.id = c.registered_by
             WHERE c.closure_id = :cid
             ORDER BY cu.display_name, c.created_at'
        );
        $consumption->execute(['cid' => $id]);

        return [
            'overtime'    => $this->castOvertime($overtime->fetchAll()),
            'consumption' => $this->castConsumption($consumption->fetchAll()),
        ];
    }

    /**
     * @param array<string,mixed> $closure
     * @param array<int,array<string,mixed>> $rows
     * @param array<string,mixed> $totals
     * @param array<int,array<string,mixed>> $overtime
     * @param array<int,array<string,mixed>> $consumption
     */
    private function renderPdfHtml(array $closure, array $rows, array $totals, array $overtime, array $consumption): string
    {
        $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $money = static fn (float $v): string => 'CRC ' . number_format($v, 0, ',', '.');
        $hours = static fn (float $v): string => (floor($v) === $v ? (string) (int) $v : number_format($v, 2)) . ' h';
        $dateOnly = static fn (string $v): string => implode('/', array_reverse(explode('-', substr($v, 0, 10))));
        $dateTime = static function (string $v) use ($dateOnly): string {
            [$d, $t] = array_pad(explode(' ', $v, 2), 2, null);
            return $t ? $dateOnly($d) . ' ' . substr($t, 0, 5) : $dateOnly($d);
        };

        $rowsHtml = '';
        foreach ($rows as $r) {
            $rowsHtml .= '<tr>'
                . '<td>' . $e($r['display_name']) . '</td>'
                . '<td>' . ($r['overtime_hours'] > 0 ? $hours((float) $r['overtime_hours']) : '—') . '</td>'
                . '<td>' . ($r['consumption_total'] > 0 ? $money((float) $r['consumption_total']) : '—') . '</td>'
                . '</tr>';
        }

        $overtimeHtml = '';
        foreach ($overtime as $o) {
            $overtimeHtml .= '<tr' . ($o['voided'] ? ' class="voided"' : '') . '>'
                . '<td>' . $e($o['display_name']) . '</td>'
                . '<td>' . $dateOnly($o['work_date']) . '</td>'
                . '<td>' . $hours((float) $o['hours']) . '</td>'
                . '<td>' . ($o['voided'] ? 'anulada' : $e($o['status'])) . '</td>'
                . '</tr>';
        }
        if (!$overtime) {
            $overtimeHtml = '<tr><td colspan="4" class="muted">Sin registros.</td></tr>';
        }

        $consumptionHtml = '';
        foreach ($consumption as $c) {
            $consumptionHtml .= '<tr' . ($c['voided'] ? ' class="voided"' : '') . '>'
                . '<td>' . $e($c['consumer_name']) . '</td>'
                . '<td>' . $money((float) $c['amount']) . ($c['voided'] ? ' (anulado)' : '') . '</td>'
                . '<td>' . $e($c['registered_by_name']) . '</td>'
                . '<td>' . $dateTime($c['created_at']) . '</td>'
                . '</tr>';
        }
        if (!$consumption) {
            $consumptionHtml = '<tr><td colspan="4" class="muted">Sin registros.</td></tr>';
        }

        $totalsHtml = '<tr class="total-row">'
            . '<td>Total</td>'
            . '<td>' . $hours((float) ($totals['overtime_hours'] ?? 0)) . '</td>'
            . '<td>' . $money((float) ($totals['consumption_total'] ?? 0)) . '</td>'
            . '</tr>';

        $label = $e($closure['label']);
        $range = $dateOnly($closure['start_date']) . ' – ' . $dateOnly($closure['end_date']);
        $generated = $dateTime($closure['generated_at']);

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #1c3144; }
    h1 { font-size: 18px; margin: 0 0 2px; }
    h2 { font-size: 13px; margin: 18px 0 6px; color: #1c3144; }
    .muted { color: #667; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
    th, td { text-align: left; padding: 4px 6px; border-bottom: 1px solid #dde2e7; }
    th { background: #1c3144; color: #fff; font-weight: normal; }
    tr.voided td { color: #a2aebb; text-decoration: line-through; }
    tr.total-row td { font-weight: bold; border-top: 2px solid #1c3144; border-bottom: none; }
</style>
</head>
<body>
    <h1>{$label}</h1>
    <p class="muted">{$range} &middot; generado {$generated}</p>

    <h2>Resumen por colaborador</h2>
    <table>
        <thead><tr><th>Colaborador</th><th>Horas extra</th><th>Consumos</th></tr></thead>
        <tbody>{$rowsHtml}</tbody>
        <tfoot>{$totalsHtml}</tfoot>
    </table>

    <h2>Detalle de horas extra</h2>
    <table>
        <thead><tr><th>Colaborador</th><th>Fecha</th><th>Horas</th><th>Estado</th></tr></thead>
        <tbody>{$overtimeHtml}</tbody>
    </table>

    <h2>Detalle de consumos</h2>
    <table>
        <thead><tr><th>Consumidor</th><th>Monto</th><th>Registró</th><th>Fecha</th></tr></thead>
        <tbody>{$consumptionHtml}</tbody>
    </table>
</body>
</html>
HTML;
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM closures WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw HttpException::notFound('Cierre no encontrado.');
        }
        return $row;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function castOvertime(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id']     = (int) $row['id'];
            $row['hours']  = (float) $row['hours'];
            $row['voided'] = (bool) $row['voided'];
        }
        return $rows;
    }

    /** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private function castConsumption(array $rows): array
    {
        foreach ($rows as &$row) {
            $row['id']     = (int) $row['id'];
            $row['amount'] = (float) $row['amount'];
            $row['voided'] = (bool) $row['voided'];
        }
        return $rows;
    }
}
