import {useEffect, useState} from 'react';
import {ApiError} from '../../api';
import {listEvents, updateEvent} from '../../agenda-api';
import type {AgendaEvent, ArtStatus} from '../../types';
import EventDetailModal from './EventDetailModal';
import {buildCalendarWeeks, currentMonth, monthLabel, shiftMonth, WEEKDAY_HEADERS} from './agenda-utils';

const ART_STATUS_LABEL: Record<ArtStatus, string> = {
    pending: 'Pendiente',
    completed: 'Completado',
};

function typeClass(type: string): string {
    return `type-${type.replace(/\s+/g, '-').toLowerCase()}`;
}

export default function Resumen() {
    const [month, setMonth] = useState(currentMonth);
    const [events, setEvents] = useState<AgendaEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [updating, setUpdating] = useState(false);

    useEffect(() => {
        let cancelled = false;
        setLoading(true);
        setError(null);
        listEvents(month)
            .then((res) => {
                if (!cancelled) setEvents(res.events);
            })
            .catch((e) => {
                if (!cancelled) setError(e instanceof ApiError ? e.message : 'No se pudo cargar el resumen.');
            })
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [month]);

    const weeks = buildCalendarWeeks(month, events);
    const selected = events.find((e) => e.id === selectedId) ?? null;

    async function handleStatusChange(status: ArtStatus) {
        if (!selected) return;
        setUpdating(true);
        try {
            const updated = await updateEvent(selected.id, {art_status: status});
            setEvents((prev) => prev.map((e) => (e.id === updated.id ? updated : e)));
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'No se pudo actualizar el estado.');
        } finally {
            setUpdating(false);
        }
    }

    return (
        <div className="stack">
            <div className="agenda-toolbar">
                <div className="agenda-month-nav">
                    <button type="button" className="btn ghost sm" onClick={() => setMonth((m) => shiftMonth(m, -1))}>
                        ‹
                    </button>
                    <strong>{monthLabel(month)}</strong>
                    <button type="button" className="btn ghost sm" onClick={() => setMonth((m) => shiftMonth(m, 1))}>
                        ›
                    </button>
                </div>
            </div>

            <p className="muted">Hacé clic en un día para ver el detalle y marcar el estado de arte.</p>

            {error && <p className="error-box">{error}</p>}

            {loading ? (
                <p className="muted">Cargando…</p>
            ) : (
                <div className="agenda-cal-scroll">
                    <div className="agenda-calendar">
                        {WEEKDAY_HEADERS.map((d) => (
                            <div key={d} className="agenda-cal-head">{d}</div>
                        ))}
                        {weeks.map((week) => week.map((cell) => (
                            <div
                                key={cell.date}
                                className={`agenda-cal-cell ${cell.inMonth ? '' : 'agenda-cal-cell-out'}`}
                            >
                                <span className="agenda-cal-day-num">{cell.day}</span>
                                {cell.items.map((ev) => (
                                    <button
                                        key={ev.id}
                                        type="button"
                                        className={`agenda-cal-event ${typeClass(ev.type)}`}
                                        onClick={() => setSelectedId(ev.id)}
                                    >
                                        <span className="agenda-cal-event-type">{ev.type}</span>
                                        <span className="agenda-cal-event-theme">
                      {ev.theme || 'Sin temática'}
                    </span>
                                        <span className={`agenda-status agenda-status-${ev.art_status}`}>
                      {ART_STATUS_LABEL[ev.art_status]}
                    </span>
                                    </button>
                                ))}
                            </div>
                        )))}
                    </div>
                </div>
            )}

            {selected && (
                <EventDetailModal
                    event={selected}
                    busy={updating}
                    onUpdateStatus={handleStatusChange}
                    onClose={() => setSelectedId(null)}
                />
            )}
        </div>
    );
}
