const crc = new Intl.NumberFormat('es-CR', {
  style: 'currency',
  currency: 'CRC',
  maximumFractionDigits: 0,
});

export function money(value: number): string {
  return crc.format(value);
}

export function hours(value: number): string {
  const n = Number(value);
  return `${Number.isInteger(n) ? n : n.toFixed(2)} h`;
}

export function dateOnly(value: string): string {
  // value: 'YYYY-MM-DD' o 'YYYY-MM-DD HH:MM:SS'
  const [d] = value.split(' ');
  const [y, m, day] = d.split('-');
  if (!y || !m || !day) return value;
  return `${day}/${m}/${y}`;
}

export function dateTime(value: string): string {
  const [d, t] = value.split(' ');
  return t ? `${dateOnly(d)} ${t.slice(0, 5)}` : dateOnly(d);
}

export function today(): string {
  const now = new Date();
  const tz = now.getTimezoneOffset() * 60000;
  return new Date(now.getTime() - tz).toISOString().slice(0, 10);
}

/** 150 -> "2 h 30 min", 60 -> "1 h", 45 -> "45 min", 0 -> "0 min". */
export function minutes(total: number): string {
  const t = Math.max(0, Math.round(Number(total) || 0));
  const h = Math.floor(t / 60);
  const m = t % 60;
  if (h > 0 && m > 0) return `${h} h ${m} min`;
  if (h > 0) return `${h} h`;
  return `${m} min`;
}

/**
 * Minutos entre la hora oficial de salida y la hora real ('HH:MM').
 * Devuelve null si falta alguna hora o la real no es anterior a la oficial.
 * Misma regla que el servidor (TimeDebt::minutesBetween).
 */
export function minutesBetween(scheduledEnd: string, actualEnd: string): number | null {
  const toMin = (v: string): number | null => {
    const m = /^(\d{2}):(\d{2})$/.exec(v);
    return m ? Number(m[1]) * 60 + Number(m[2]) : null;
  };
  const s = toMin(scheduledEnd);
  const a = toMin(actualEnd);
  if (s === null || a === null || s - a <= 0) return null;
  return s - a;
}

/** Suma (o resta) días a una fecha 'YYYY-MM-DD' y devuelve 'YYYY-MM-DD'. */
export function addDays(isoDate: string, days: number): string {
  const [y, m, d] = isoDate.split('-').map(Number);
  const dt = new Date(Date.UTC(y, m - 1, d + days));
  return dt.toISOString().slice(0, 10);
}
