import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import { dateOnly, money, hours as fmtHours, minutes as fmtMinutes, today } from '../../format';
import type { ClosurePreview, MakeupHours, OvertimeEntry, VacationRequest } from '../../types';

export default function Dashboard() {
  const [pending, setPending] = useState<number | null>(null);
  const [preview, setPreview] = useState<ClosurePreview | null>(null);
  const [due, setDue] = useState<number | null>(null);

  useEffect(() => {
    Promise.all([
      api<{ entries: OvertimeEntry[] }>('/admin/overtime?status=pending'),
      api<{ entries: VacationRequest[] }>('/admin/vacation?status=pending'),
      api<{ entries: MakeupHours[] }>('/admin/makeup?status=pending'),
    ])
      .then(([ot, vac, mk]) => setPending(ot.entries.length + vac.entries.length + mk.entries.length))
      .catch(() => setPending(null));
    api<{ total_minutes: number }>('/admin/time-debt')
      .then((r) => setDue(r.total_minutes))
      .catch(() => setDue(null));
    // Todo lo abierto hasta hoy (el cierre sugerido llega al próximo 15 o 30).
    api<ClosurePreview>(`/admin/closure/preview?end=${today()}`)
      .then(setPreview)
      .catch(() => setPreview(null));
  }, []);

  return (
    <div className="stack">
      <h1>Panel</h1>

      <Link to="/admin/aprobaciones" className="big-btn">
        <span className="big-btn-icon">📝</span>
        <span>
          <strong>Aprobaciones pendientes</strong>
          <small>{pending === null ? 'Cargando…' : `${pending} por revisar`}</small>
        </span>
        {pending ? <span className="badge">{pending}</span> : null}
      </Link>

      <Link to="/admin/por-reponer" className="big-btn">
        <span className="big-btn-icon">🚪</span>
        <span>
          <strong>Horas por reponer</strong>
          <small>
            {due === null ? 'Cargando…' : due > 0 ? `${fmtMinutes(due)} pendientes entre todos` : 'Nadie debe horas'}
          </small>
        </span>
      </Link>

      {preview && (
        <section className="card">
          <h2>Período abierto</h2>
          <p className="muted">
            {dateOnly(preview.start)} → hoy
          </p>
          <div className="totals-row">
            <div className="total-card">
              <span className="total-label">Horas extra aprobadas</span>
              <span className="total-value">{fmtHours(preview.totals.overtime_hours)}</span>
            </div>
            <div className="total-card">
              <span className="total-label">Consumos</span>
              <span className="total-value">{money(preview.totals.consumption_total)}</span>
            </div>
          </div>
          {preview.pending_count > 0 && (
            <p className="warn-inline">
              ⚠️ Hay {preview.pending_count} hora(s) extra pendientes en este rango.
            </p>
          )}
          <Link to="/admin/cierre" className="btn primary block">
            Ir a generar cierre
          </Link>
        </section>
      )}
    </div>
  );
}
