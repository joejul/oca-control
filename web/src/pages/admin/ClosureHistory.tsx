import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import { dateOnly, dateTime, hours as fmtHours, money } from '../../format';
import type { ClosureSummary } from '../../types';

export default function ClosureHistory() {
  const [closures, setClosures] = useState<ClosureSummary[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api<{ closures: ClosureSummary[] }>('/admin/closures')
      .then((r) => setClosures(r.closures))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <div className="center muted">Cargando…</div>;

  return (
    <div className="stack">
      <h1>Historial de cierres</h1>
      {closures.length === 0 && <p className="muted">Todavía no hay cierres generados.</p>}

      {closures.map((c) => (
        <Link key={c.id} to={`/admin/cierres/${c.id}`} className="card link-card">
          <div className="entry-head">
            <strong>{c.label}</strong>
            <span className="muted">{dateOnly(c.start_date)} – {dateOnly(c.end_date)}</span>
          </div>
          {c.totals && (
            <p className="muted">
              {fmtHours(c.totals.overtime_hours)} · {money(c.totals.consumption_total)}
            </p>
          )}
          <small className="muted">
            Generado {dateTime(c.generated_at)} por {c.generated_by_name}
          </small>
        </Link>
      ))}
    </div>
  );
}
