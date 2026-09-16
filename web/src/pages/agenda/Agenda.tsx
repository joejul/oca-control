import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '../../auth';
import { ApiError } from '../../api';
import { today } from '../../format';
import { createEvent, deleteMonth, generateWeek, listEvents } from '../../agenda-api';
import type { AgendaEvent } from '../../types';
import ConfirmDialog from '../../components/ConfirmDialog';
import EventCard from './EventCard';
import {
  countMissingTheme, currentMonth, defaultCollapsedKeys, groupByWeek, monthLabel, shiftMonth, weekSummary,
} from './agenda-utils';

export default function Agenda() {
  const { user } = useAuth();
  const isEditor = user?.username === 'ocaso';
  const isAdmin = user?.role === 'admin';

  const [month, setMonth] = useState(currentMonth);
  const [events, setEvents] = useState<AgendaEvent[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [generating, setGenerating] = useState(false);
  const [adding, setAdding] = useState(false);
  const [justCreatedId, setJustCreatedId] = useState<number | null>(null);
  const [collapsedWeeks, setCollapsedWeeks] = useState<Set<string>>(new Set());
  const [confirmingDeleteMonth, setConfirmingDeleteMonth] = useState(false);
  const [deletingMonth, setDeletingMonth] = useState(false);

  const load = useCallback(async (m: string) => {
    setLoading(true);
    setError(null);
    try {
      const res = await listEvents(m);
      setEvents(res.events);
      setCollapsedWeeks(defaultCollapsedKeys(groupByWeek(res.events), today()));
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'No se pudo cargar la agenda.');
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load(month);
  }, [month, load]);

  async function handleGenerate() {
    setGenerating(true);
    setError(null);
    try {
      await generateWeek(month);
      await load(month);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'No se pudo generar la semana base.');
    } finally {
      setGenerating(false);
    }
  }

  async function handleDeleteMonth() {
    setDeletingMonth(true);
    setError(null);
    try {
      await deleteMonth(month);
      setEvents([]);
      setConfirmingDeleteMonth(false);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'No se pudo borrar el mes.');
    } finally {
      setDeletingMonth(false);
    }
  }

  async function handleAddLoose() {
    setAdding(true);
    setError(null);
    try {
      const created = await createEvent({ month, type: 'Especial' });
      setEvents((prev) => [...prev, created]);
      setJustCreatedId(created.id);
    } catch (e) {
      setError(e instanceof ApiError ? e.message : 'No se pudo crear el evento.');
    } finally {
      setAdding(false);
    }
  }

  function handleUpdated(updated: AgendaEvent) {
    setEvents((prev) => prev.map((ev) => (ev.id === updated.id ? updated : ev)));
  }

  function handleDeleted(id: number) {
    setEvents((prev) => prev.filter((ev) => ev.id !== id));
  }

  function toggleWeek(key: string) {
    setCollapsedWeeks((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      return next;
    });
  }

  const groups = groupByWeek(events);
  const datedCount = events.filter((e) => e.date).length;
  const missingCount = countMissingTheme(events);

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
          {datedCount > 0 && (
            <span className={`agenda-completeness ${missingCount > 0 ? 'is-incomplete' : 'is-complete'}`}>
              {datedCount - missingCount}/{datedCount} con temática
            </span>
          )}
        </div>
        <div className="agenda-actions">
          {isEditor && (
            <>
              <button type="button" className="btn ghost sm" onClick={handleAddLoose} disabled={adding}>
                + Día suelto
              </button>
              <button type="button" className="btn primary sm" onClick={handleGenerate} disabled={generating}>
                {generating ? 'Generando…' : 'Generar Mes'}
              </button>
            </>
          )}
          {isAdmin && events.length > 0 && (
            <button
              type="button"
              className="btn ghost sm agenda-delete"
              onClick={() => setConfirmingDeleteMonth(true)}
            >
              🗑 Borrar mes
            </button>
          )}
        </div>
      </div>

      {!isEditor && (
        <p className="agenda-readonly-note">
          Vista de solo lectura. La edición la maneja Fernanda desde la cuenta compartida.
        </p>
      )}

      {error && <p className="error-box">{error}</p>}

      {loading ? (
        <p className="muted">Cargando…</p>
      ) : events.length === 0 ? (
        <p className="muted">
          Sin eventos para {monthLabel(month)}.
          {isEditor && ' Usá "Generar Mes" para arrancar.'}
        </p>
      ) : (
        (() => {
          let weekNumber = 0;
          return groups.map((g) => {
            const isCollapsed = collapsedWeeks.has(g.key);
            const isUndated = g.key === '_none';
            const summary = weekSummary(g.items);
            if (!isUndated) weekNumber += 1;
            return (
              <section key={g.key} className="agenda-week">
                <button
                  type="button"
                  className="agenda-week-header"
                  onClick={() => toggleWeek(g.key)}
                  aria-expanded={!isCollapsed}
                >
                  <span className="agenda-week-title">
                    {isUndated ? 'Sin fecha' : `Semana ${weekNumber} · del ${g.label}`}
                  </span>
                  <span className="agenda-week-meta">
                    → {summary.totalText}
                    {summary.alertText && <span className="agenda-week-alert"> · {summary.alertText}</span>}
                  </span>
                  <span className="agenda-week-chevron">{isCollapsed ? '▸' : '▾'}</span>
                </button>
                {!isCollapsed && (
                  <div className="agenda-grid">
                    {g.items.map((ev) => (
                      <EventCard
                        key={ev.id}
                        event={ev}
                        onUpdated={handleUpdated}
                        onDeleted={handleDeleted}
                        autoExpand={ev.id === justCreatedId}
                        readOnly={!isEditor}
                      />
                    ))}
                  </div>
                )}
              </section>
            );
          });
        })()
      )}

      <ConfirmDialog
        open={confirmingDeleteMonth}
        title={`¿Borrar todos los eventos de ${monthLabel(month)}?`}
        rows={[
          ['Mes', monthLabel(month)],
          ['Eventos que se van a borrar', String(events.length)],
        ]}
        warning="Esta acción no se puede deshacer."
        confirmLabel="Borrar mes"
        busy={deletingMonth}
        onConfirm={handleDeleteMonth}
        onCancel={() => setConfirmingDeleteMonth(false)}
      />
    </div>
  );
}
