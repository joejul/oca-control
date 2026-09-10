import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../api';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { dateOnly, dateTime, hours as fmtHours } from '../../format';
import type { OvertimeEntry, VacationRequest } from '../../types';

type Resource = 'overtime' | 'vacation';
type Entry = OvertimeEntry | VacationRequest;
type ActionKind = 'approve' | 'reject' | 'void';
type Action = { kind: ActionKind; entry: Entry };

const RESOURCE_LABEL: Record<Resource, string> = {
  overtime: 'horas extra',
  vacation: 'vacaciones',
};

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendiente',
  approved: 'Aprobada',
  rejected: 'Rechazada',
};

function isOvertime(e: Entry): e is OvertimeEntry {
  return 'work_date' in e;
}

export default function Approvals() {
  const [resource, setResource] = useState<Resource>('overtime');
  const [entries, setEntries] = useState<Entry[]>([]);
  const [filter, setFilter] = useState<'pending' | 'all'>('pending');
  const [loading, setLoading] = useState(true);
  const [action, setAction] = useState<Action | null>(null);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    api<{ entries: Entry[] }>(`/admin/${resource}?status=${filter}`)
      .then((r) => setEntries(r.entries))
      .finally(() => setLoading(false));
  }, [resource, filter]);

  useEffect(load, [load]);

  function start(kind: ActionKind, entry: Entry) {
    setReason('');
    setError(null);
    setAction({ kind, entry });
  }

  async function confirm() {
    if (!action) return;
    setBusy(true);
    setError(null);
    const { kind, entry } = action;
    const path = `/admin/${resource}/${entry.id}/${kind}`;
    const body = kind === 'void' ? { reason: reason.trim() } : { note: reason.trim() || undefined };
    try {
      await api(path, { method: 'POST', body });
      setAction(null);
      load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo completar.');
    } finally {
      setBusy(false);
    }
  }

  const voidNeedsReason = action?.kind === 'void' && reason.trim().length < 3;
  const titles: Record<ActionKind, string> = {
    approve: `Aprobar ${RESOURCE_LABEL[resource]}`,
    reject: `Rechazar ${RESOURCE_LABEL[resource]}`,
    void: 'Anular registro',
  };

  return (
    <div className="stack">
      <h1>Aprobaciones</h1>

      <div className="segmented">
        <button className={resource === 'overtime' ? 'on' : ''} onClick={() => setResource('overtime')}>
          Horas extra
        </button>
        <button className={resource === 'vacation' ? 'on' : ''} onClick={() => setResource('vacation')}>
          Vacaciones
        </button>
      </div>

      <div className="segmented">
        <button className={filter === 'pending' ? 'on' : ''} onClick={() => setFilter('pending')}>
          Pendientes
        </button>
        <button className={filter === 'all' ? 'on' : ''} onClick={() => setFilter('all')}>
          Todas
        </button>
      </div>

      {loading && <div className="center muted">Cargando…</div>}
      {!loading && entries.length === 0 && <p className="muted">Nada por aquí.</p>}

      {entries.map((e) => (
        <div key={e.id} className={`card entry ${e.voided ? 'is-voided' : ''}`}>
          <div className="entry-head">
            <strong>{e.display_name}</strong>
            <span className={`pill ${e.voided ? 'voided' : e.status}`}>
              {e.voided ? 'Anulada' : STATUS_LABEL[e.status] ?? e.status}
            </span>
          </div>
          {isOvertime(e) ? (
            <>
              <p>
                {fmtHours(e.hours)} · {dateOnly(e.work_date)}
              </p>
              {e.note && <p className="muted">{e.note}</p>}
            </>
          ) : (
            <>
              <p>{dateOnly(e.request_date)}</p>
              {e.description && <p className="muted">{e.description}</p>}
            </>
          )}
          {e.void_reason && <p className="muted">Motivo anulación: {e.void_reason}</p>}
          <small className="muted">Ingresada {dateTime(e.created_at)}</small>

          {!e.voided && e.status === 'pending' && (
            <div className="entry-actions">
              <button className="btn ok sm" onClick={() => start('approve', e)}>
                Aprobar
              </button>
              <button className="btn danger sm" onClick={() => start('reject', e)}>
                Rechazar
              </button>
            </div>
          )}
          {!e.voided && e.status !== 'pending' && (
            <div className="entry-actions">
              <button className="btn ghost sm" onClick={() => start('void', e)}>
                Anular
              </button>
            </div>
          )}
        </div>
      ))}

      <ConfirmDialog
        open={action !== null}
        title={action ? titles[action.kind] : ''}
        rows={
          action
            ? isOvertime(action.entry)
              ? [
                  ['Colaborador', action.entry.display_name ?? ''],
                  ['Horas', fmtHours(action.entry.hours)],
                  ['Fecha', dateOnly(action.entry.work_date)],
                ]
              : [
                  ['Colaborador', action.entry.display_name ?? ''],
                  ['Fecha', dateOnly(action.entry.request_date)],
                  ['Descripción', action.entry.description ?? '—'],
                ]
            : []
        }
        warning={
          action?.kind === 'void'
            ? 'El registro queda anulado (no se borra, permanece para auditoría).'
            : 'La decisión no se puede cambiar después.'
        }
        confirmLabel={
          action?.kind === 'approve' ? 'Aprobar' : action?.kind === 'reject' ? 'Rechazar' : 'Anular'
        }
        busy={busy}
        confirmDisabled={voidNeedsReason}
        onConfirm={confirm}
        onCancel={() => setAction(null)}
      >
        <label>
          {action?.kind === 'void' ? <>Motivo de la anulación <Req /></> : 'Nota'}
          <input
            value={reason}
            onChange={(ev) => setReason(ev.target.value)}
            placeholder={action?.kind === 'void' ? 'ej. registro duplicado' : ''}
          />
        </label>
        {error && <p className="error-box">{error}</p>}
      </ConfirmDialog>
    </div>
  );
}
