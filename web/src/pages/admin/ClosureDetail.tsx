import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api, downloadFile } from '../../api';
import BackLink from '../../components/BackLink';
import { dateOnly, dateTime, hours as fmtHours, money } from '../../format';
import type { ClosureDetail as Detail } from '../../types';

export default function ClosureDetail() {
  const { id } = useParams<{ id: string }>();
  const [data, setData] = useState<Detail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    api<Detail>(`/admin/closures/${id}`)
      .then(setData)
      .catch(() => setError('No se pudo cargar el cierre.'))
      .finally(() => setLoading(false));
  }, [id]);

  if (loading) return <div className="center muted">Cargando…</div>;
  if (error || !data) return <div className="stack"><BackLink to="/admin/cierres" /><p className="error-box">{error}</p></div>;

  const { closure, rows, totals, overtime, consumption } = data;

  return (
    <div className="stack" id="closure-print">
      <div className="no-print">
        <BackLink to="/admin/cierres" label="Historial" />
      </div>

      <h1>{closure.label}</h1>
      <p className="muted">
        {dateOnly(closure.start)} – {dateOnly(closure.end)} · generado {dateTime(closure.generated_at)}
      </p>

      <div className="no-print row-actions">
        <button
          className="btn primary sm"
          onClick={() => downloadFile(`/admin/closures/${closure.id}/export.csv`, `cierre-${closure.id}.csv`)}
        >
          Descargar CSV (Excel)
        </button>
        <button
          className="btn primary sm"
          onClick={() => downloadFile(`/admin/closures/${closure.id}/export.pdf`, `cierre-${closure.id}.pdf`)}
        >
          Descargar PDF
        </button>
        <button className="btn ghost sm" onClick={() => window.print()}>
          Imprimir
        </button>
      </div>

      <section className="card">
        <h2>Resumen por colaborador</h2>
        <table className="report">
          <thead>
            <tr>
              <th>Colaborador</th>
              <th>Horas extra</th>
              <th>Consumos</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.user_id}>
                <td>{r.display_name}</td>
                <td>{r.overtime_hours > 0 ? fmtHours(r.overtime_hours) : '—'}</td>
                <td>{r.consumption_total > 0 ? money(r.consumption_total) : '—'}</td>
              </tr>
            ))}
          </tbody>
          {totals && (
            <tfoot>
              <tr>
                <td>Total</td>
                <td>{fmtHours(totals.overtime_hours)}</td>
                <td>{money(totals.consumption_total)}</td>
              </tr>
            </tfoot>
          )}
        </table>
      </section>

      <section className="card">
        <h2>Detalle de horas extra</h2>
        <table className="report">
          <thead>
            <tr>
              <th>Colaborador</th>
              <th>Fecha</th>
              <th>Horas</th>
              <th>Estado</th>
            </tr>
          </thead>
          <tbody>
            {overtime.map((o) => (
              <tr key={o.id} className={o.voided ? 'is-voided' : ''}>
                <td>{o.display_name}</td>
                <td>{dateOnly(o.work_date)}</td>
                <td>{fmtHours(o.hours)}</td>
                <td>{o.voided ? 'anulada' : o.status}</td>
              </tr>
            ))}
            {overtime.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">Sin registros.</td>
              </tr>
            )}
          </tbody>
        </table>
      </section>

      <section className="card">
        <h2>Detalle de consumos</h2>
        <table className="report">
          <thead>
            <tr>
              <th>Consumidor</th>
              <th>Monto</th>
              <th>Registró</th>
              <th>Fecha</th>
            </tr>
          </thead>
          <tbody>
            {consumption.map((c) => (
              <tr key={c.id} className={c.voided ? 'is-voided' : ''}>
                <td>{c.consumer_name}</td>
                <td>{money(c.amount)}{c.voided ? ' (anulado)' : ''}</td>
                <td>{c.registered_by_name}</td>
                <td>{dateTime(c.created_at)}</td>
              </tr>
            ))}
            {consumption.length === 0 && (
              <tr>
                <td colSpan={4} className="muted">Sin registros.</td>
              </tr>
            )}
          </tbody>
        </table>
      </section>
    </div>
  );
}
