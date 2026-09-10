import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import { money, hours as fmtHours } from '../../format';
import type { ClosurePreview, OvertimeEntry, VacationRequest } from '../../types';

export default function Dashboard() {
  const [pending, setPending] = useState<number | null>(null);
  const [preview, setPreview] = useState<ClosurePreview | null>(null);

  useEffect(() => {
    Promise.all([
      api<{ entries: OvertimeEntry[] }>('/admin/overtime?status=pending'),
      api<{ entries: VacationRequest[] }>('/admin/vacation?status=pending'),
    ])
      .then(([ot, vac]) => setPending(ot.entries.length + vac.entries.length))
      .catch(() => setPending(null));
    api<ClosurePreview>('/admin/closure/preview')
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

      {preview && (
        <section className="card">
          <h2>Período abierto</h2>
          <p className="muted">
            {preview.start} → {preview.end}
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
