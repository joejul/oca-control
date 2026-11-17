import { dateOnly, dateTime } from './format';

/** Módulos para el filtro de la bitácora. `value` va tal cual al API (?action=). */
export const AUDIT_MODULES: { value: string; label: string }[] = [
  { value: '', label: 'Todo' },
  { value: 'overtime', label: 'Horas extra' },
  { value: 'consumption', label: 'Consumos' },
  { value: 'vacation', label: 'Vacaciones' },
  { value: 'early_leave', label: 'Salidas anticipadas' },
  { value: 'makeup', label: 'Reposiciones' },
  { value: 'closure', label: 'Cierres' },
  { value: 'event', label: 'Agenda (módulo anterior)' },
  { value: 'user', label: 'Usuarios y PIN' },
  { value: 'login', label: 'Inicios de sesión' },
];

const ACTIONS: Record<string, string> = {
  login: 'Inició sesión',
  'overtime.create': 'Registró horas extra',
  'overtime.approved': 'Aprobó horas extra',
  'overtime.rejected': 'Rechazó horas extra',
  'overtime.void': 'Anuló horas extra',
  'consumption.create': 'Registró un consumo',
  'consumption.void': 'Anuló un consumo',
  'vacation.create': 'Solicitó vacaciones',
  'vacation.approved': 'Aprobó vacaciones',
  'vacation.rejected': 'Rechazó vacaciones',
  'vacation.void': 'Anuló vacaciones',
  'early_leave.create': 'Registró una salida anticipada',
  'early_leave.void': 'Anuló una salida anticipada',
  'makeup.create': 'Registró una reposición',
  'makeup.approved': 'Aprobó una reposición',
  'makeup.rejected': 'Rechazó una reposición',
  'makeup.void': 'Anuló una reposición',
  'closure.generate': 'Generó un cierre',
  'event.create': 'Creó un evento de Agenda',
  'event.update': 'Modificó un evento de Agenda',
  'event.delete': 'Eliminó un evento de Agenda',
  'event.generate': 'Generó los eventos de un mes en Agenda',
  'event.delete_month': 'Eliminó los eventos de un mes en Agenda',
  'user.create': 'Creó un usuario',
  'user.update': 'Modificó un usuario',
  'user.reset_pin': 'Restableció el PIN de un usuario',
  'user.change_pin': 'Cambió su propio PIN',
};

export function actionLabel(action: string): string {
  return ACTIONS[action] ?? action;
}

const KEYS: Record<string, string> = {
  note: 'Nota',
  reason: 'Motivo',
  hours: 'Horas',
  minutes: 'Minutos',
  minutes_owed: 'Minutos a reponer',
  work_date: 'Fecha',
  request_date: 'Fecha',
  leave_date: 'Fecha',
  makeup_date: 'Fecha',
  scheduled_end: 'Hora oficial',
  actual_end: 'Hora real',
  start: 'Desde',
  end: 'Hasta',
  label: 'Cierre',
  username: 'Usuario',
  role: 'Rol',
  display_name: 'Nombre',
  active: 'Activo',
  unlock: 'Desbloqueo',
  amount: 'Monto',
  month: 'Mes',
  date: 'Fecha',
  created: 'Creados',
  detail: 'Detalle',
};

function formatValue(value: unknown): string {
  if (typeof value === 'boolean') return value ? 'sí' : 'no';
  if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/.test(value)) {
    return value.includes(' ') ? dateTime(value) : dateOnly(value);
  }
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

/** Resumen legible de details_json, o null si no hay nada que mostrar. */
export function detailText(details: Record<string, unknown> | null): string | null {
  if (!details) return null;
  const parts = Object.entries(details)
    .filter(([, v]) => v !== null && v !== '' && v !== undefined)
    .map(([k, v]) => `${KEYS[k] ?? k}: ${formatValue(v)}`);
  return parts.length > 0 ? parts.join(' · ') : null;
}
