import { useEffect, useState } from 'react';
import { api } from '../../api';
import { actionLabel, AUDIT_MODULES, detailText } from '../../audit';
import { dateTime } from '../../format';
import type { AuditPage } from '../../types';

type UserOption = { id: number; display_name: string };

/** Bitácora: quién hizo qué y cuándo (solo lectura). */
export default function AuditLog() {
  const [users, setUsers] = useState<UserOption[]>([]);
  const [actor, setActor] = useState('');
  const [module, setModule] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [data, setData] = useState<AuditPage | null>(null);
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    api<{ users: UserOption[] }>('/admin/users')
      .then((r) => setUsers(r.users))
      .catch(() => undefined);
  }, []);

  useEffect(() => {
    const q = new URLSearchParams();
    if (actor) q.set('actor', actor);
    if (module) q.set('action', module);
    if (from) q.set('from', from);
    if (to) q.set('to', to);
    q.set('page', String(page));

    let cancelled = false;
    setLoading(true);
    setFailed(false);
    api<AuditPage>(`/admin/audit?${q.toString()}`)
      .then((r) => {
        if (!cancelled) setData(r);
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [actor, module, from, to, page]);

  // Cualquier cambio de filtro vuelve a la primera página.
  function filter<T>(set: (v: T) => void) {
    return (v: T) => {
      set(v);
      setPage(1);
    };
  }

  const totalPages = data ? Math.max(1, Math.ceil(data.total / data.per_page)) : 1;

  return (
    <div className="stack">
      <h1>Bitácora</h1>

      <section className="card">
        <div className="field-row">
          <label>
            Quién
            <select value={actor} onChange={(e) => filter(setActor)(e.target.value)}>
              <option value="">Todos</option>
              {users.map((u) => (
                <option key={u.id} value={u.id}>
                  {u.display_name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Qué
            <select value={module} onChange={(e) => filter(setModule)(e.target.value)}>
              {AUDIT_MODULES.map((m) => (
                <option key={m.value} value={m.value}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
        </div>
        <div className="field-row">
          <label>
            Desde
            <input type="date" value={from} max={to || undefined} onChange={(e) => filter(setFrom)(e.target.value)} />
          </label>
          <label>
            Hasta
            <input type="date" value={to} min={from || undefined} onChange={(e) => filter(setTo)(e.target.value)} />
          </label>
        </div>
      </section>

      {loading && !data && <div className="center muted">Cargando…</div>}
      {failed && <p className="error-box">No se pudo cargar la bitácora.</p>}

      {data && (
        <section className="card" aria-busy={loading}>
          <p className="muted">
            {data.total === 0
              ? 'Sin movimientos con esos filtros.'
              : `${data.total} movimiento${data.total === 1 ? '' : 's'}`}
          </p>
          {data.rows.map((r) => {
            const detail = detailText(r.details);
            return (
              <div key={r.id} className="row audit-row">
                <div>
                  <strong>{actionLabel(r.action)}</strong>
                  <small className="block">
                    {r.actor_name ?? 'Sistema'}
                    {r.subject_name && r.subject_name !== r.actor_name ? ` → ${r.subject_name}` : ''}
                  </small>
                  {detail && <small className="block">{detail}</small>}
                </div>
                <small className="muted audit-when">{dateTime(r.created_at)}</small>
              </div>
            );
          })}

          {totalPages > 1 && (
            <div className="pager">
              <button className="btn ghost sm" disabled={page <= 1 || loading} onClick={() => setPage(page - 1)}>
                ← Anterior
              </button>
              <span className="muted">
                Página {page} de {totalPages}
              </span>
              <button
                className="btn ghost sm"
                disabled={page >= totalPages || loading}
                onClick={() => setPage(page + 1)}
              >
                Siguiente →
              </button>
            </div>
          )}
        </section>
      )}
    </div>
  );
}
