import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import BackLink from '../../components/BackLink';
import { dateOnly, minutes as fmtMinutes } from '../../format';
import type { TimeDebtMine } from '../../types';

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendiente',
  approved: 'Aprobada',
  rejected: 'Rechazada',
};

export default function TimeDebt() {
  const [data, setData] = useState<TimeDebtMine | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(false);

  useEffect(() => {
    api<TimeDebtMine>('/time-debt/mine')
      .then(setData)
      .catch(() => setError(true))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="center muted">Cargando…</div>;
  if (error || !data) {
    return (
      <div className="stack">
        <BackLink />
        <p className="error-box">No se pudo cargar tu saldo.</p>
      </div>
    );
  }

  const { balance, early_leaves: leaves, makeups } = data;

  return (
    <div className="stack">
      <BackLink />
      <h1>Horas por reponer</h1>

      <div className="totals-row">
        <div className="total-card">
          <span className="total-label">Por reponer</span>
          <span className="total-value">{fmtMinutes(balance.due_minutes)}</span>
        </div>
        <div className="total-card">
          <span className="total-label">En revisión</span>
          <span className="total-value">{fmtMinutes(balance.pending_minutes)}</span>
        </div>
      </div>

      <Link to="/salida-anticipada" className="big-btn">
        <span className="big-btn-icon">🚪</span>
        <span>
          <strong>Registrar salida anticipada</strong>
          <small>Fecha, hora oficial y hora real de salida</small>
        </span>
      </Link>

      {balance.available_minutes > 0 ? (
        <Link to="/reposicion" className="big-btn">
          <span className="big-btn-icon">🔁</span>
          <span>
            <strong>Registrar reposición</strong>
            <small>Podés solicitar hasta {fmtMinutes(balance.available_minutes)}</small>
          </span>
        </Link>
      ) : (
        <div className="big-btn is-disabled" aria-disabled="true">
          <span className="big-btn-icon">🔁</span>
          <span>
            <strong>Registrar reposición</strong>
            <small>
              {balance.due_minutes === 0
                ? 'No tenés horas pendientes por reponer'
                : 'Tu saldo ya está cubierto por reposiciones en revisión'}
            </small>
          </span>
        </div>
      )}

      <section className="card">
        <h2>Salidas anticipadas</h2>
        {leaves.length === 0 && <p className="muted">Sin registros.</p>}
        {leaves.map((l) => (
          <div key={l.id} className={`row ${l.voided ? 'is-voided' : ''}`}>
            <div>
              <strong>{fmtMinutes(l.minutes_owed)}</strong> · {dateOnly(l.leave_date)}
              <small className="block">
                Salió {l.actual_end} (oficial {l.scheduled_end})
              </small>
              <small className="block">{l.note}</small>
              {l.void_reason && <small className="block muted">Anulada: {l.void_reason}</small>}
            </div>
            {l.voided && <span className="pill voided">Anulada</span>}
          </div>
        ))}
      </section>

      <section className="card">
        <h2>Reposiciones</h2>
        {makeups.length === 0 && <p className="muted">Sin registros.</p>}
        {makeups.map((m) => (
          <div key={m.id} className={`row ${m.voided ? 'is-voided' : ''}`}>
            <div>
              <strong>{fmtMinutes(m.minutes)}</strong> · {dateOnly(m.makeup_date)}
              {m.note && <small className="block">{m.note}</small>}
              {m.review_note && <small className="block muted">Nota admin: {m.review_note}</small>}
              {m.void_reason && <small className="block muted">Anulada: {m.void_reason}</small>}
            </div>
            <span className={`pill ${m.voided ? 'voided' : m.status}`}>
              {m.voided ? 'Anulada' : STATUS_LABEL[m.status]}
            </span>
          </div>
        ))}
      </section>
    </div>
  );
}
