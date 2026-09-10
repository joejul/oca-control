const MONTH_NAMES = [
  'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
  'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

const DAY_ABBR = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
const DAY_ABBR_MON_FIRST = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];

function fmt(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** Lunes=0 ... Domingo=6 (a diferencia de Date#getDay, donde Domingo=0). */
function mondayIndex(date: Date): number {
  return (date.getDay() + 6) % 7;
}

export function currentMonth(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
}

export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export function monthLabel(month: string): string {
  const [y, m] = month.split('-').map(Number);
  return `${MONTH_NAMES[m - 1]} ${y}`;
}

export function dayInfo(dateStr: string): { abbr: string; num: number } {
  const [y, m, d] = dateStr.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  return { abbr: DAY_ABBR[date.getDay()], num: d };
}

/** Lunes de la semana (lunes-domingo) que contiene esa fecha. */
export function weekStartOf(dateStr: string): string {
  const [y, m, d] = dateStr.split('-').map(Number);
  const date = new Date(y, m - 1, d);
  date.setDate(date.getDate() - mondayIndex(date));
  return fmt(date);
}

/** "1 al 7" — rango de días de la semana lunes-domingo que contiene esa fecha. */
function weekDayRange(dateStr: string): string {
  const [y, m, d] = dateStr.split('-').map(Number);
  const start = new Date(y, m - 1, d);
  start.setDate(start.getDate() - mondayIndex(start));
  const end = new Date(start);
  end.setDate(start.getDate() + 6);
  return `${start.getDate()} al ${end.getDate()}`;
}

export type WeekGroup<T> = { key: string; label: string; items: T[] };

/** Agrupa eventos por semana lunes-domingo. Sin fecha va primero. */
export function groupByWeek<T extends { date: string | null }>(events: T[]): WeekGroup<T>[] {
  const withDate: T[] = [];
  const withoutDate: T[] = [];
  for (const e of events) {
    (e.date ? withDate : withoutDate).push(e);
  }

  const groups = new Map<string, WeekGroup<T>>();
  for (const ev of withDate) {
    const key = weekStartOf(ev.date as string);
    if (!groups.has(key)) {
      groups.set(key, { key, label: weekDayRange(ev.date as string), items: [] });
    }
    groups.get(key)!.items.push(ev);
  }

  const sorted = [...groups.values()].sort((a, b) => a.key.localeCompare(b.key));
  for (const g of sorted) {
    g.items.sort((a, b) => (a.date as string).localeCompare(b.date as string));
  }

  if (withoutDate.length > 0) {
    sorted.unshift({ key: '_none', label: 'Sin fecha', items: withoutDate });
  }
  return sorted;
}

/**
 * Claves de semana que deben arrancar colapsadas: todas menos la semana
 * actual (si el mes que se ve la contiene) o, si no, la primera con datos.
 * "Sin fecha" nunca arranca colapsada.
 */
export function defaultCollapsedKeys<T>(groups: WeekGroup<T>[], todayStr: string): Set<string> {
  const dated = groups.filter((g) => g.key !== '_none');
  if (dated.length === 0) return new Set();

  const todayKey = weekStartOf(todayStr);
  const keepOpen = dated.some((g) => g.key === todayKey) ? todayKey : dated[0].key;

  return new Set(dated.filter((g) => g.key !== keepOpen).map((g) => g.key));
}

export type WeekSummaryInfo = { totalText: string; alertText: string | null };

/** Resumen de una semana: total de eventos + texto de alerta (sin temática / pendiente de arte), si hay. */
export function weekSummary(items: Array<{ art_status: string; theme: string | null }>): WeekSummaryInfo {
  const total = items.length;
  const missingTheme = items.filter((e) => !e.theme).length;
  const pendingArt = items.filter((e) => e.art_status === 'pending').length;

  const alertParts: string[] = [];
  if (missingTheme > 0) alertParts.push(`${missingTheme} sin temática`);
  if (pendingArt > 0) alertParts.push(`${pendingArt} pendiente${pendingArt === 1 ? '' : 's'} de arte`);

  return {
    totalText: `${total} evento${total === 1 ? '' : 's'}`,
    alertText: alertParts.length > 0 ? alertParts.join(' · ') : null,
  };
}

/** Cuántos eventos con fecha (excluye "sin fecha") todavía no tienen temática. */
export function countMissingTheme(events: Array<{ date: string | null; theme: string | null }>): number {
  return events.filter((e) => e.date && !e.theme).length;
}

export type CalendarCell<T> = { date: string; day: number; inMonth: boolean; items: T[] };

/**
 * Arma la grilla tipo calendario del mes (lunes a domingo), con celdas de
 * relleno del mes anterior/siguiente para completar semanas de 7 días.
 */
export function buildCalendarWeeks<T extends { date: string | null }>(
  month: string,
  events: T[],
): CalendarCell<T>[][] {
  const [y, m] = month.split('-').map(Number);
  const first = new Date(y, m - 1, 1);
  const daysInMonth = new Date(y, m, 0).getDate();
  const leading = mondayIndex(first);

  const byDate = new Map<string, T[]>();
  for (const e of events) {
    if (!e.date) continue;
    if (!byDate.has(e.date)) byDate.set(e.date, []);
    byDate.get(e.date)!.push(e);
  }

  const cells: CalendarCell<T>[] = [];

  for (let i = leading; i > 0; i--) {
    const d = new Date(y, m - 1, 1 - i);
    cells.push({ date: fmt(d), day: d.getDate(), inMonth: false, items: [] });
  }
  for (let day = 1; day <= daysInMonth; day++) {
    const dateStr = `${month}-${String(day).padStart(2, '0')}`;
    cells.push({ date: dateStr, day, inMonth: true, items: byDate.get(dateStr) ?? [] });
  }
  while (cells.length % 7 !== 0) {
    const [ly, lm, ld] = cells[cells.length - 1].date.split('-').map(Number);
    const d = new Date(ly, lm - 1, ld + 1);
    cells.push({ date: fmt(d), day: d.getDate(), inMonth: false, items: [] });
  }

  const weeks: CalendarCell<T>[][] = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));
  return weeks;
}

export const WEEKDAY_HEADERS = DAY_ABBR_MON_FIRST;
