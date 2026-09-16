import {useEffect, useRef, useState} from 'react';
import type {AgendaEvent, ArtStatus, EventType} from '../../types';
import {deleteEvent, updateEvent} from '../../agenda-api';
import ConfirmDialog from '../../components/ConfirmDialog';
import {dayInfo} from './agenda-utils';

const TYPES: EventType[] = [
    'Happy Hour', 'Karaoke', 'Viernes de Ocaso', 'DJ Night', 'Especial', 'Cerrado', 'Otro',
];

/** Tipos donde aplica registrar un costo para OCASO (banda, DJ, alquiler...). */
const INTERNAL_COST_TYPES: EventType[] = ['DJ Night', 'Viernes de Ocaso', 'Karaoke', 'Especial', 'Otro'];

type FieldKey = 'cover' | 'price' | 'artists';

/**
 * Miércoles y jueves son formato fijo: menos campos, ya autorellenados al
 * generar el mes. El resto de tipos muestra el formulario completo.
 */
const REDUCED_FIELDS: Partial<Record<EventType, FieldKey[]>> = {
    'Happy Hour': [],
    'Karaoke': ['artists'],
};

function visibleFields(type: EventType): FieldKey[] {
    return REDUCED_FIELDS[type] ?? ['cover', 'price', 'artists'];
}

const ART_STATUS_LABEL: Record<ArtStatus, string> = {
    pending: 'Pendiente',
    completed: 'Completado',
};

function typeClass(type: string): string {
    return `type-${type.replace(/\s+/g, '-').toLowerCase()}`;
}

function DayBadge({dateStr}: { dateStr: string | null }) {
    if (!dateStr) {
        return <span className="agenda-day-chip agenda-day-chip-muted">Sin fecha</span>;
    }
    const day = dayInfo(dateStr);
    return (
        <span className="agenda-day-chip">
      {day.abbr} <strong>{day.num}</strong>
    </span>
    );
}

type Props = {
    event: AgendaEvent;
    onUpdated: (event: AgendaEvent) => void;
    onDeleted: (id: number) => void;
    /** La tarjeta arranca expandida (ej. recién creada con "+ Día suelto"). */
    autoExpand?: boolean;
    /** Sin edición: solo la tarjeta resumen, sin click ni acciones. */
    readOnly?: boolean;
};

export default function EventCard({event, onUpdated, onDeleted, autoExpand, readOnly}: Props) {
    const [draft, setDraft] = useState(event);
    const [expanded, setExpanded] = useState(Boolean(autoExpand));
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const timers = useRef<Partial<Record<keyof AgendaEvent, ReturnType<typeof setTimeout>>>>({});
    /**
     * Campos que este componente ya editó alguna vez. El server normaliza
     * texto (trim de espacios) al guardar, así que su respuesta nunca vuelve
     * a pisar el valor local de estos campos: si lo hiciera, un espacio
     * escrito justo antes de que el guardado debounced se dispare desaparece
     * solo (el server lo recorta y esa respuesta se refleja en el input).
     */
    const editedKeys = useRef<Set<keyof AgendaEvent>>(new Set());

    useEffect(() => {
        setDraft((prev) => {
            const next = {...event};
            for (const key of editedKeys.current) {
                (next as Record<string, unknown>)[key] = (prev as Record<string, unknown>)[key];
            }
            return next;
        });
    }, [event]);

    useEffect(() => () => {
        Object.values(timers.current).forEach((t) => t && clearTimeout(t));
    }, []);

    function commit<K extends keyof AgendaEvent>(key: K, value: AgendaEvent[K]) {
        void updateEvent(event.id, {[key]: value} as Partial<AgendaEvent>).then(onUpdated);
    }

    function fieldDebounced<K extends keyof AgendaEvent>(key: K, value: AgendaEvent[K]) {
        editedKeys.current.add(key);
        setDraft((prev) => ({...prev, [key]: value}));
        const existing = timers.current[key];
        if (existing) clearTimeout(existing);
        timers.current[key] = setTimeout(() => commit(key, value), 500);
    }

    function fieldNow<K extends keyof AgendaEvent>(key: K, value: AgendaEvent[K]) {
        editedKeys.current.add(key);
        setDraft((prev) => ({...prev, [key]: value}));
        const existing = timers.current[key];
        if (existing) clearTimeout(existing);
        commit(key, value);
    }

    async function handleDelete() {
        setDeleting(true);
        try {
            await deleteEvent(event.id);
            onDeleted(event.id);
        } finally {
            setDeleting(false);
            setConfirmingDelete(false);
        }
    }

    if (readOnly) {
        return (
            <article className={`agenda-card agenda-card-summary agenda-card-readonly ${typeClass(draft.type)}`}>
                <div className="agenda-card-summary-top">
                    <DayBadge dateStr={draft.date}/>
                    <span className="agenda-type-label">{draft.type}</span>
                </div>
                <p className="agenda-card-summary-theme">
                    {draft.theme || <span className="muted">Sin temática</span>}
                </p>
                <span className={`agenda-status agenda-status-${draft.art_status}`}>
          {ART_STATUS_LABEL[draft.art_status]}
        </span>
            </article>
        );
    }

    if (!expanded) {
        return (
            <article
                className={`agenda-card agenda-card-summary ${typeClass(draft.type)}`}
                role="button"
                tabIndex={0}
                onClick={() => setExpanded(true)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setExpanded(true);
                    }
                }}
            >
                <div className="agenda-card-summary-top">
                    <DayBadge dateStr={draft.date}/>
                    <span className="agenda-type-label">{draft.type}</span>
                </div>
                <p className="agenda-card-summary-theme">
                    {draft.theme || <span className="muted">Sin temática</span>}
                </p>
                <span className={`agenda-status agenda-status-${draft.art_status}`}>
          {ART_STATUS_LABEL[draft.art_status]}
        </span>
            </article>
        );
    }

    return (
        <article className={`agenda-card agenda-card-expanded ${typeClass(draft.type)}`}>
            <header className={`agenda-card-head ${!draft.date ? 'agenda-card-head-stacked' : ''}`}>
                {draft.date ? (
                    <DayBadge dateStr={draft.date}/>
                ) : (
                    <input
                        type="date"
                        className="agenda-date-input"
                        value={draft.date ?? ''}
                        onChange={(e) => fieldNow('date', e.target.value || null)}
                    />
                )}
                <select value={draft.type} onChange={(e) => fieldNow('type', e.target.value as EventType)}>
                    {TYPES.map((t) => (
                        <option key={t} value={t}>{t}</option>
                    ))}
                </select>
                <button
                    type="button"
                    className="agenda-collapse-btn"
                    onClick={() => setExpanded(false)}
                    aria-label="Colapsar tarjeta"
                    title="Colapsar"
                >
                    ▴
                </button>
            </header>

            <div className="agenda-card-body">
                <label>
                    Temática
                    <input
                        value={draft.theme ?? ''}
                        onChange={(e) => fieldDebounced('theme', e.target.value || null)}
                        placeholder="Ej. Noche retro"
                    />
                </label>
                <label>
                    Horario
                    <input
                        value={draft.schedule ?? ''}
                        onChange={(e) => fieldDebounced('schedule', e.target.value || null)}
                        placeholder="Ej. 7pm - 11pm"
                    />
                </label>
                {visibleFields(draft.type).includes('cover') && (
                    <label>
                        Cover
                        <input
                            value={draft.cover ?? ''}
                            onChange={(e) => fieldDebounced('cover', e.target.value || null)}
                            placeholder="Ej. 3.000"
                        />
                    </label>
                )}
                {visibleFields(draft.type).includes('price') && (
                    <label>
                        Descripción de la Promo
                        <input
                            value={draft.price ?? ''}
                            onChange={(e) => fieldDebounced('price', e.target.value || null)}
                            placeholder="Ej. 2x1 en cócteles"
                        />
                    </label>
                )}
                {visibleFields(draft.type).includes('artists') && (
                    <label>
                        DJs / Artistas
                        <input
                            value={draft.artists ?? ''}
                            onChange={(e) => fieldDebounced('artists', e.target.value || null)}
                            placeholder="Ej. Paul Alfaro, Tosty..."
                        />
                    </label>
                )}
                {INTERNAL_COST_TYPES.includes(draft.type) && (
                    <label>
                        Costo para Ocaso (₡)
                        <input
                            type="text"
                            inputMode="numeric"
                            value={draft.internal_cost ?? ''}
                            onChange={(e) => {
                                const digits = e.target.value.replace(/[^0-9]/g, '');
                                fieldDebounced('internal_cost', digits === '' ? null : Number(digits));
                            }}
                            placeholder="Ej. 45.000"
                        />
                    </label>
                )}
                <label>
                    Detalles de la promo
                    <textarea
                        rows={2}
                        value={draft.promo ?? ''}
                        onChange={(e) => fieldDebounced('promo', e.target.value || null)}
                        placeholder="Ej. Presona #100, Grupo mas animado, Shots de cortesía."
                    />
                </label>
            </div>

            <footer className="agenda-card-foot">
                <div className="agenda-status-field">
                    <span className="agenda-status-caption">Estado de arte (lo actualiza Julian)</span>
                    <span className={`agenda-status agenda-status-${draft.art_status}`}>
                        {ART_STATUS_LABEL[draft.art_status]}
                    </span>
                </div>
                <button
                    type="button"
                    className="btn ghost sm agenda-delete"
                    onClick={() => setConfirmingDelete(true)}
                    aria-label="Eliminar evento"
                    title="Eliminar evento"
                >
                    🗑
                </button>
            </footer>

            <ConfirmDialog
                open={confirmingDelete}
                title="¿Eliminar este evento?"
                rows={[
                    ['Fecha', draft.date ?? 'Sin fecha'],
                    ['Tipo', draft.type],
                    ['Temática', draft.theme ?? '—'],
                ]}
                warning="Esta acción no se puede deshacer."
                confirmLabel="Eliminar"
                busy={deleting}
                onConfirm={handleDelete}
                onCancel={() => setConfirmingDelete(false)}
            />
        </article>
    );
}
