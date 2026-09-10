import { useCallback, useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, ApiError } from '../../api';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { hours as fmtHours, money } from '../../format';
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

  const rows = preview?.rows.filter((r) => r.overtime_hours > 0 || r.consumption_total > 0) ?? [];
  const empty = !preview || (preview.totals.overtime_hours === 0 && preview.totals.consumption_total === 0);

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
            <input type="date" value={start} max={end} onChange={(e) => setStart(e.target.value)} />
          </label>
          <label>
            Hasta <Req />
            <input type="date" value={end} min={start} onChange={(e) => setEnd(e.target.value)} />
          </label>
        </div>
        <label>
          Nombre del cierre
          <input
            value={label}
            maxLength={120}
            placeholder="ej. Quincena 1 setiembre"
            onChange={(e) => setLabel(e.target.value)}
          />
        </label>
        <button className="btn ghost block" onClick={() => loadPreview(start, end)} disabled={loading}>
          Actualizar vista previa
        </button>
      </div>

      {error && <p className="error-box">{error}</p>}
      {loading && <div className="center muted">Cargando…</div>}

      {preview && !loading && (
        <>
          {preview.pending_count > 0 && (
            <p className="warn-inline">
              ⚠️ Hay {preview.pending_count} hora(s) extra <strong>pendientes</strong> en este rango.
              No se incluirán en el cierre; quedan para el próximo. Revisalas primero si querés
              incluirlas.
            </p>
          )}

          <section className="card">
            <h2>Vista previa</h2>
            {rows.length === 0 && <p className="muted">Sin registros para cerrar en este rango.</p>}
            {rows.length > 0 && (
              <table className="report">
                <thead>
                  <tr>
                    <th>Colaborador</th>
                    <th>Horas</th>
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
                <tfoot>
                  <tr>
                    <td>Total</td>
                    <td>{fmtHours(preview.totals.overtime_hours)}</td>
                    <td>{money(preview.totals.consumption_total)}</td>
                  </tr>
                </tfoot>
              </table>
            )}
          </section>

          <button
            className="btn primary block"
            disabled={empty}
            onClick={() => setConfirming(true)}
          >
            Generar cierre y archivar
          </button>
        </>
      )}

      <ConfirmDialog
        open={confirming}
        title="Confirmá el cierre"
        rows={[
          ['Rango', `${start} a ${end}`],
          ['Nombre', label.trim() || `Cierre ${start} a ${end}`],
          ['Horas extra', preview ? fmtHours(preview.totals.overtime_hours) : ''],
          ['Consumos', preview ? money(preview.totals.consumption_total) : ''],
        ]}
        warning="Los registros incluidos quedarán archivados y ya no se podrán tocar. Hacé un respaldo de la base antes si es un cierre importante."
        confirmLabel="Generar cierre"
        busy={busy}
        onConfirm={generate}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
