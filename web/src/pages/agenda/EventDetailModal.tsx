import { useEffect } from 'react';
import type { AgendaEvent, ArtStatus } from '../../types';

const ART_STATUS_LABEL: Record<ArtStatus, string> = {
  pending: 'Pendiente',
  completed: 'Completado',
};

const WEEKDAY_NAMES = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

function typeClass(type: string): string {
  return `type-${type.replace(/\s+/g, '-').toLowerCase()}`;
}

function dayLabel(dateStr: string | null): string {
  if (!dateStr) return 'Sin fecha';
  const [y, m, d] = dateStr.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  return `${WEEKDAY_NAMES[date.getDay()]} ${d}`;
}

type Props = {
  event: AgendaEvent;
  onUpdateStatus: (status: ArtStatus) => void;
  onClose: () => void;
  busy?: boolean;
};

export default function EventDetailModal({ event, onUpdateStatus, onClose, busy = false }: Props) {
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') onClose();
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  return (
    <div className="overlay" role="presentation" onClick={onClose}>
      <div
        className={`dialog agenda-detail-dialog ${typeClass(event.type)}`}
        role="dialog"
        aria-modal="true"
        aria-label={event.type}
        onClick={(e) => e.stopPropagation()}
      >
        <button type="button" className="agenda-detail-close" onClick={onClose} aria-label="Cerrar">
          ✕
        </button>

        <div className="agenda-detail-head">
          <span className="agenda-day-chip">{dayLabel(event.date)}</span>
          <span className="agenda-type-label">{event.type}</span>
        </div>

        <h2 className="agenda-detail-theme">{event.theme || 'Sin temática'}</h2>

        <dl className="confirm-rows">
          <div>
            <dt>Horario</dt>
            <dd>{event.schedule || '—'}</dd>
          </div>
          {event.cover && (
            <div>
              <dt>Cover</dt>
              <dd>{event.cover}</dd>
            </div>
          )}
          {event.price && (
            <div>
              <dt>Descripción de la Promo</dt>
              <dd>{event.price}</dd>
            </div>
          )}
          {event.artists && (
            <div>
              <dt>DJs / Artistas</dt>
              <dd>{event.artists}</dd>
            </div>
          )}
        </dl>

        {event.promo && (
          <div className="agenda-detail-promo">
            <span className="agenda-status-caption">Detalles de la promo</span>
            <p>{event.promo}</p>
          </div>
        )}

        <div className="agenda-detail-status">
          <span className="agenda-status-caption">Estado de arte</span>
          <div className="agenda-detail-status-buttons">
            {(['pending', 'completed'] as ArtStatus[]).map((status) => (
              <button
                key={status}
                type="button"
                className={`agenda-status-toggle agenda-status-${status} ${event.art_status === status ? 'is-active' : ''}`}
                onClick={() => onUpdateStatus(status)}
                disabled={busy || event.art_status === status}
              >
                {ART_STATUS_LABEL[status]}
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
