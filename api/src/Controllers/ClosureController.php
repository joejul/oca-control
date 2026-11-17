<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Audit;
use App\ClosurePeriod;
use App\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\TimeDebt;
use App\Validator;
use Dompdf\Dompdf;
use Dompdf\Options;

final class ClosureController
{
    public function preview(Request $r): Response
    {
        $period = $this->resolvePeriod($r->queryParam('start'), $r->queryParam('end'));
        $start  = $period['start'];
        $end    = $period['end'];

        $data = $this->computeTotals($start, $end);

        return Response::json([
            'start'           => $start,
            'end'             => $end,
            // Con un cierre anterior, el inicio es fijo (dia siguiente) para no dejar dias sin cerrar.
            'start_locked'    => $period['last_end'] !== null,
            'last_end'        => $period['last_end'],
            'suggested_label' => ClosurePeriod::label($start, $end),
            'rows'            => $data['rows'],
            'totals'          => $data['totals'],
            'pending_count'   => $data['pending_count'],
            'carried_count'   => $data['carried_count'],
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
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if ($end > $today) {
            throw HttpException::unprocessable(
                'Ese periodo todavia no termina: el cierre puede llegar como maximo hasta hoy (' . self::fmt($today) . ').'
            );
        }
        $label = Validator::optionalString($r->input('label'), 'label', 120)
            ?? ClosurePeriod::label($start, $end);

        $pdo = Database::pdo();

        // Si quien llama ya abrio una transaccion (las pruebas la deshacen al final), se usa esa.
        $ownTx = !$pdo->inTransaction();
        if ($ownTx) {
            $pdo->beginTransaction();
        }
        try {
            // Se bloquea el ultimo cierre mientras se valida y se inserta: dos cierres
            // simultaneos no pueden quedar con el mismo inicio.
            $last = $pdo->query('SELECT MAX(end_date) FROM closures FOR UPDATE')->fetchColumn();
            if ($last) {
                $expected = ClosurePeriod::nextStart((string) $last);
                if ($start !== $expected) {
                    throw HttpException::unprocessable(
                        'El cierre debe empezar el ' . self::fmt($expected)
                        . ', justo despues del cierre anterior, para no dejar dias sin cerrar.'
                    );
                }
            }

            $data = $this->computeTotals($start, $end);

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

            // Horas extra: se archivan las ya resueltas (aprobadas/rechazadas) o anuladas, de
            // cualquier fecha hasta $end: asi lo que se aprobo tarde de un periodo anterior no se
            // pierde. Las pendientes quedan abiertas para el proximo cierre.
            $pdo->prepare(
                "UPDATE overtime_entries
                 SET closure_id = :cid
                 WHERE closure_id IS NULL
                   AND work_date <= :e
                   AND (status IN ('approved','rejected') OR voided_at IS NOT NULL)"
            )->execute(['cid' => $closureId, 'e' => $end]);

            // Consumos: se archivan todos los no archivados hasta $end (anulados o no).
            $pdo->prepare(
                'UPDATE consumption_entries
                 SET closure_id = :cid
                 WHERE closure_id IS NULL
                   AND DATE(created_at) <= :e'
            )->execute(['cid' => $closureId, 'e' => $end]);

            if ($ownTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
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
        // "Por reponer": en horas decimales para que Excel lo sume. Los cierres
        // anteriores a este modulo no traen el dato, se dejan en blanco.
        $dueHours = static fn (array $src): string|float => isset($src['makeup_due_minutes'])
            ? round(((int) $src['makeup_due_minutes']) / 60, 2)
            : '';

        fputcsv($out, ['Colaborador', 'Horas extra', 'Consumos (CRC)', 'Por reponer (h)']);
        foreach ($rows as $row) {
            fputcsv($out, [
                $row['display_name'],
                $row['overtime_hours'],
                $row['consumption_total'],
                $dueHours($row),
            ]);
        }
        $totals = $snapshot['totals'] ?? ['overtime_hours' => 0, 'consumption_total' => 0];
        fputcsv($out, []);
        fputcsv($out, ['TOTAL', $totals['overtime_hours'], $totals['consumption_total'], $dueHours($totals)]);

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

    /**
     * Periodo a cerrar. Con un cierre anterior el inicio SIEMPRE es el dia siguiente a su
     * fin (se ignora cualquier `start` que llegue). Sin cierres previos, es el parametro o
     * la fecha mas antigua con registros. El fin por defecto es el proximo dia de pago.
     *
     * @return array{start:string, end:string, last_end:?string}
     */
    private function resolvePeriod(?string $startParam, ?string $endParam): array
    {
        $pdo     = Database::pdo();
        $lastEnd = $pdo->query('SELECT MAX(end_date) FROM closures')->fetchColumn() ?: null;

        if ($lastEnd) {
            $start = ClosurePeriod::nextStart((string) $lastEnd);
        } elseif ($startParam !== null && $startParam !== '') {
            $start = Validator::requireDate($startParam, 'start');
        } else {
            $minOt = $pdo->query('SELECT MIN(work_date) FROM overtime_entries')->fetchColumn();
            $minCo = $pdo->query('SELECT MIN(DATE(created_at)) FROM consumption_entries')->fetchColumn();
            $candidates = array_filter([$minOt, $minCo]);
            $start = $candidates ? (string) min($candidates) : (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        $end = $endParam !== null && $endParam !== ''
            ? Validator::requireDate($endParam, 'end')
            : ClosurePeriod::suggestedEnd($start);

        // Nunca devolver un rango invertido.
        return ['start' => $start, 'end' => max($start, $end), 'last_end' => $lastEnd ? (string) $lastEnd : null];
    }

    private static function fmt(string $isoDate): string
    {
        [$y, $m, $d] = explode('-', $isoDate);
        return "$d/$m/$y";
    }

    /** @return array{rows:array<int,array<string,mixed>>, totals:array<string,float>, pending_count:int, carried_count:int} */
    private function computeTotals(string $start, string $end): array
    {
        $pdo = Database::pdo();

        // "Por reponer" es el saldo vigente al generar el cierre (arrastra de un
        // cierre a otro; los cierres no archivan salidas anticipadas ni reposiciones).
        $dueSql = TimeDebt::dueSubquery();

        $stmt = $pdo->prepare(
            "SELECT u.id AS user_id, u.display_name,
                    COALESCE(ot.hours, 0)  AS overtime_hours,
                    COALESCE(co.total, 0)  AS consumption_total,
                    COALESCE(td.due, 0)    AS makeup_due_minutes
             FROM users u
             LEFT JOIN (
                 SELECT user_id, SUM(hours) AS hours
                 FROM overtime_entries
                 WHERE closure_id IS NULL AND status = 'approved' AND voided_at IS NULL
                   AND work_date <= :e1
                 GROUP BY user_id
             ) ot ON ot.user_id = u.id
             LEFT JOIN (
                 SELECT consumer_id, SUM(amount) AS total
                 FROM consumption_entries
                 WHERE closure_id IS NULL AND voided_at IS NULL
                   AND DATE(created_at) <= :e2
                 GROUP BY consumer_id
             ) co ON co.consumer_id = u.id
             LEFT JOIN ($dueSql) td ON td.user_id = u.id
             WHERE u.role = 'collaborator'
               AND (ot.hours IS NOT NULL OR co.total IS NOT NULL OR u.active = 1 OR td.due > 0)
             ORDER BY u.display_name"
        );
        $stmt->execute(['e1' => $end, 'e2' => $end]);

        $rows = [];
        $sumHours = 0.0;
        $sumConsumption = 0.0;
        $sumDue = 0;
        foreach ($stmt->fetchAll() as $row) {
            $hours = (float) $row['overtime_hours'];
            $cons  = (float) $row['consumption_total'];
            $due   = (int) $row['makeup_due_minutes'];
            $sumHours += $hours;
            $sumConsumption += $cons;
            $sumDue += $due;
            $rows[] = [
                'user_id'            => (int) $row['user_id'],
                'display_name'       => $row['display_name'],
                'overtime_hours'     => $hours,
                'consumption_total'  => $cons,
                'makeup_due_minutes' => $due,
            ];
        }

        $pending = $pdo->prepare(
            "SELECT COUNT(*) FROM overtime_entries
             WHERE closure_id IS NULL AND status = 'pending' AND voided_at IS NULL
               AND work_date <= :e"
        );
        $pending->execute(['e' => $end]);

        // Registros de fechas anteriores al inicio que nunca se habian cerrado (p. ej. horas
        // aprobadas despues del cierre de su quincena): entran en este cierre.
        $carriedOt = $pdo->prepare(
            "SELECT COUNT(*) FROM overtime_entries
             WHERE closure_id IS NULL AND status = 'approved' AND voided_at IS NULL AND work_date < :s"
        );
        $carriedOt->execute(['s' => $start]);
        $carriedCo = $pdo->prepare(
            'SELECT COUNT(*) FROM consumption_entries
             WHERE closure_id IS NULL AND voided_at IS NULL AND DATE(created_at) < :s'
        );
        $carriedCo->execute(['s' => $start]);

        return [
            'rows'          => $rows,
            'totals'        => [
                'overtime_hours'     => round($sumHours, 2),
                'consumption_total'  => round($sumConsumption, 2),
                'makeup_due_minutes' => $sumDue,
            ],
            'pending_count' => (int) $pending->fetchColumn(),
            'carried_count' => (int) $carriedOt->fetchColumn() + (int) $carriedCo->fetchColumn(),
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

        // Los cierres anteriores al modulo de reposiciones no traen este dato: se muestra "—".
        $due = static fn (array $src): string => isset($src['makeup_due_minutes']) && (int) $src['makeup_due_minutes'] > 0
            ? TimeDebt::format((int) $src['makeup_due_minutes'])
            : '—';

        $rowsHtml = '';
        foreach ($rows as $r) {
            $rowsHtml .= '<tr>'
                . '<td>' . $e($r['display_name']) . '</td>'
                . '<td>' . ($r['overtime_hours'] > 0 ? $hours((float) $r['overtime_hours']) : '—') . '</td>'
                . '<td>' . ($r['consumption_total'] > 0 ? $money((float) $r['consumption_total']) : '—') . '</td>'
                . '<td>' . $due($r) . '</td>'
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
            . '<td>' . $due($totals) . '</td>'
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
        <thead><tr><th>Colaborador</th><th>Horas extra</th><th>Consumos</th><th>Por reponer</th></tr></thead>
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
