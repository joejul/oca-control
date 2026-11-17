import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '../../api';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { dateOnly, hours as fmtHours, minutes as fmtMinutes, money, today } from '../../format';
import type { ClosurePreview } from '../../types';

export default function ClosureNew() {
  const navigate = useNavigate();
  const [preview, setPreview] = useState<ClosurePreview | null>(null);
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [label, setLabel] = useState('');
  const [loading, setLoading] = useState(true);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const loadPreview = useCallback((s?: string, e?: string) => {
    setLoading(true);
    setError(null);
    const qs = new URLSearchParams();
    if (s) qs.set('start', s);
    if (e) qs.set('end', e);
    api<ClosurePreview>(`/admin/closure/preview${qs.toString() ? `?${qs}` : ''}`)
      .then((p) => {
        setPreview(p);
        setStart(p.start);
        setEnd(p.end);
      })
      .catch((err) => setError(err instanceof ApiError ? err.message : 'No se pudo cargar.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    loadPreview();
  }, [loadPreview]);

  const rows =
    preview?.rows.filter(
      (r) => r.overtime_hours > 0 || r.consumption_total > 0 || (r.makeup_due_minutes ?? 0) > 0,
    ) ?? [];
  const empty = !preview || (preview.totals.overtime_hours === 0 && preview.totals.consumption_total === 0);
  const todayStr = today();
  const notYet = end > todayStr;
  const finalLabel = label.trim() || preview?.suggested_label || '';

  function changeEnd(value: string) {
    if (!value) return;
    setEnd(value);
    // Con cierre anterior el inicio es fijo y lo decide el servidor.
    loadPreview(preview?.start_locked ? undefined : start, value);
  }

  async function generate() {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ id: number }>('/admin/closure/generate', {
        method: 'POST',
        body: { start, end, label: label.trim() || undefined },
      });
      navigate(`/admin/cierres/${res.id}`, { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo generar el cierre.');
      setConfirming(false);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="stack">
      <h1>Generar cierre</h1>

      <div className="card">
        <div className="field-row">
          <label>
            Desde <Req />
            <input
              type="date"
              value={start}
              max={end}
              disabled={preview?.start_locked}
              onChange={(e) => setStart(e.target.value)}
              onBlur={() => !preview?.start_locked && start && loadPreview(start, end)}
            />
          </label>
          <label>
            Hasta <Req />
            <input
              type="date"
              value={end}
              min={start}
              max={todayStr}
              onChange={(e) => changeEnd(e.target.value)}
            />
          </label>
        </div>
        {preview?.start_locked && preview.last_end && (
          <small className="help">
            El inicio es fijo: continúa desde el cierre anterior (terminó el {dateOnly(preview.last_end)}),
            así no se salta ningún día.
          </small>
        )}
        <label>
          Nombre del cierre
          <input
            value={label}
            maxLength={120}
            placeholder={preview?.suggested_label ?? 'Cierre'}
            onChange={(e) => setLabel(e.target.value)}
          />
          <small className="help">Si lo deja vacío se usa: {preview?.suggested_label ?? '…'}</small>
        </label>
      </div>

      {error && <p className="error-box">{error}</p>}
      {loading && <div className="center muted">Cargando…</div>}

      {preview && !loading && (
        <>
          {notYet && (
            <p className="warn-inline">
              ⚠️ Ese período termina el {dateOnly(end)} y todavía no ha llegado. Podrá generarlo ese día,
              o elija una fecha final de hoy o anterior.
            </p>
          )}
          {preview.carried_count > 0 && (
            <p className="warn-inline">
              ℹ️ Este cierre incluye {preview.carried_count} registro(s) de fechas anteriores a {dateOnly(start)} que
              nunca se habían cerrado (por ejemplo, horas aprobadas después del cierre de su quincena).
            </p>
          )}
          {preview.pending_count > 0 && (
            <p className="warn-inline">
              ⚠️ Hay {preview.pending_count} hora(s) extra <strong>pendientes</strong> en este rango.
              No se incluirán en el cierre; quedan para el próximo. Revíselas primero si quiere
              incluirlas.
            </p>
          )}

          <section className="card">
            <h2>Vista previa</h2>
            {rows.length === 0 && (
              <p className="muted">
                Sin registros para cerrar en este rango. Aun así puede generar el cierre para mantener
                la secuencia de fechas.
              </p>
            )}
            {rows.length > 0 && (
              <table className="report">
                <thead>
                  <tr>
                    <th>Colaborador</th>
                    <th>Horas</th>
                    <th>Consumos</th>
                    <th>Por reponer</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <tr key={r.user_id}>
                      <td>{r.display_name}</td>
                      <td>{r.overtime_hours > 0 ? fmtHours(r.overtime_hours) : '—'}</td>
                      <td>{r.consumption_total > 0 ? money(r.consumption_total) : '—'}</td>
                      <td>{r.makeup_due_minutes ? fmtMinutes(r.makeup_due_minutes) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
                <tfoot>
                  <tr>
                    <td>Total</td>
                    <td>{fmtHours(preview.totals.overtime_hours)}</td>
                    <td>{money(preview.totals.consumption_total)}</td>
                    <td>
                      {preview.totals.makeup_due_minutes
                        ? fmtMinutes(preview.totals.makeup_due_minutes)
                        : '—'}
                    </td>
                  </tr>
                </tfoot>
              </table>
            )}
          </section>

          <button className="btn primary block" disabled={notYet} onClick={() => setConfirming(true)}>
            Generar cierre y archivar
          </button>
        </>
      )}

      <ConfirmDialog
        open={confirming}
        title="Confirme el cierre"
        rows={[
          ['Nombre', finalLabel],
          ['Rango', `${dateOnly(start)} a ${dateOnly(end)}`],
          ['Horas extra', preview ? fmtHours(preview.totals.overtime_hours) : ''],
          ['Consumos', preview ? money(preview.totals.consumption_total) : ''],
          ['Por reponer (informativo)', preview ? fmtMinutes(preview.totals.makeup_due_minutes ?? 0) : ''],
        ]}
        warning={
          empty
            ? 'No hay registros en este rango: se generará un cierre vacío solo para mantener la secuencia. Los registros incluidos quedarán archivados y ya no se podrán tocar.'
            : 'Los registros incluidos quedarán archivados y ya no se podrán tocar. Haga un respaldo de la base antes si es un cierre importante.'
        }
        confirmLabel="Generar cierre"
        busy={busy}
        onConfirm={generate}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
