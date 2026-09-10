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
