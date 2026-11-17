import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../api';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { dateOnly, dateTime, minutes as fmtMinutes } from '../../format';
import type { EarlyLeave, TimeDebtBalance } from '../../types';

/** Vista del admin: saldo por reponer de cada colaborador y salidas anticipadas registradas. */
export default function TimeDebtAdmin() {
  const [balances, setBalances] = useState<TimeDebtBalance[]>([]);
  const [total, setTotal] = useState(0);
  const [leaves, setLeaves] = useState<EarlyLeave[]>([]);
  const [loading, setLoading] = useState(true);
  const [voiding, setVoiding] = useState<EarlyLeave | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    Promise.all([
      api<{ balances: TimeDebtBalance[]; total_minutes: number }>('/admin/time-debt'),
      api<{ entries: EarlyLeave[] }>('/admin/early-leave?status=all'),
    ])
      .then(([b, l]) => {
        setBalances(b.balances);
        setTotal(b.total_minutes);
        setLeaves(l.entries);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  function startVoid(l: EarlyLeave) {
    setReason('');
    setError(null);
    setVoiding(l);
  }

  async function confirmVoid() {
    if (!voiding) return;
    setBusy(true);
    setError(null);
    try {
      await api(`/admin/early-leave/${voiding.id}/void`, {
        method: 'POST',
        body: { reason: reason.trim() },
      });
      setVoiding(null);
      load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo anular.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) return <div className="center muted">Cargando…</div>;

  const withActivity = balances.filter(
    (b) => b.due_minutes > 0 || b.pending_minutes > 0 || b.owed_minutes > 0,
  );

  return (
    <div className="stack">
      <h1>Horas por reponer</h1>

      <div className="totals-row">
        <div className="total-card">
          <span className="total-label">Total por reponer</span>
          <span className="total-value">{fmtMinutes(total)}</span>
        </div>
        <div className="total-card">
          <span className="total-label">Con saldo</span>
          <span className="total-value">{balances.filter((b) => b.due_minutes > 0).length}</span>
        </div>
      </div>

      <section className="card">
        <h2>Saldo por colaborador</h2>
        {withActivity.length === 0 && <p className="muted">Nadie tiene horas por reponer.</p>}
        {withActivity.length > 0 && (
          <table className="report">
            <thead>
              <tr>
                <th>Colaborador</th>
                <th>Por reponer</th>
                <th>En revisión</th>
              </tr>
            </thead>
            <tbody>
              {withActivity.map((b) => (
                <tr key={b.user_id}>
                  <td>
                    {b.display_name}
                    {!b.active && <small className="muted"> (inactivo)</small>}
                  </td>
                  <td>{b.due_minutes > 0 ? fmtMinutes(b.due_minutes) : '—'}</td>
                  <td>{b.pending_minutes > 0 ? fmtMinutes(b.pending_minutes) : '—'}</td>
                </tr>
              ))}
            </tbody>
            <tfoot>
              <tr>
                <td>Total</td>
                <td>{fmtMinutes(total)}</td>
                <td />
              </tr>
            </tfoot>
          </table>
        )}
      </section>

      <section className="card">
        <h2>Salidas anticipadas</h2>
        {leaves.length === 0 && <p className="muted">Sin registros.</p>}
        {leaves.map((l) => (
          <div key={l.id} className={`row ${l.voided ? 'is-voided' : ''}`}>
            <div>
              <strong>{l.display_name}</strong> · {fmtMinutes(l.minutes_owed)}
              <small className="block">
                {dateOnly(l.leave_date)} · salió {l.actual_end} (oficial {l.scheduled_end})
              </small>
              <small className="block">{l.note}</small>
              <small className="block muted">Ingresada {dateTime(l.created_at)}</small>
              {l.void_reason && <small className="block muted">Motivo anulación: {l.void_reason}</small>}
            </div>
            {l.voided ? (
              <span className="pill voided">Anulada</span>
            ) : (
              <button className="btn ghost sm" onClick={() => startVoid(l)}>
                Anular
              </button>
            )}
          </div>
        ))}
      </section>

      <ConfirmDialog
        open={voiding !== null}
        title="Anular salida anticipada"
        rows={[
          ['Colaborador', voiding?.display_name ?? ''],
          ['Fecha', voiding ? dateOnly(voiding.leave_date) : ''],
          ['Horas a reponer', voiding ? fmtMinutes(voiding.minutes_owed) : ''],
        ]}
        warning="El registro queda anulado (no se borra, permanece para auditoría) y deja de sumar al saldo."
        confirmLabel="Anular"
        busy={busy}
        confirmDisabled={reason.trim().length < 3}
        onConfirm={confirmVoid}
        onCancel={() => setVoiding(null)}
      >
        <label>
          Motivo de la anulación <Req />
          <input
            value={reason}
            onChange={(e) => setReason(e.target.value)}
            placeholder="ej. registro duplicado"
          />
        </label>
        {error && <p className="error-box">{error}</p>}
      </ConfirmDialog>
    </div>
  );
}
