import { dateOnly, hours as fmtHours, minutes as fmtMinutes } from './format';
import type { NewsEvent, NewsItem } from './types';

export const NEWS_ICON: Record<NewsEvent, string> = {
  approved: '✅',
  rejected: '❌',
  voided: '🚫',
};

const PARTICIPLE: Record<NewsEvent, string> = {
  approved: 'aprobada',
  rejected: 'rechazada',
  voided: 'anulada',
};

/** "Tus horas extra del 07/11/2026 (3 h)" — sujeto de la frase y si va en plural. */
function subject(item: NewsItem): { text: string; plural: boolean } {
  const date = dateOnly(item.ref_date);
  switch (item.kind) {
    case 'overtime':
      return {
        text: `Tus horas extra del ${date}${item.amount !== null ? ` (${fmtHours(item.amount)})` : ''}`,
        plural: true,
      };
    case 'vacation':
      return { text: `Tu solicitud de vacaciones del ${date}`, plural: false };
    case 'makeup':
      return {
        text: `Tu reposición${item.amount !== null ? ` de ${fmtMinutes(item.amount)}` : ''} del ${date}`,
        plural: false,
      };
    case 'early_leave':
      return {
        text: `Tu salida anticipada del ${date}${item.amount !== null ? ` (${fmtMinutes(item.amount)})` : ''}`,
        plural: false,
      };
  }
}

/** Frase completa de la novedad, p. ej. "Tus horas extra del 07/11/2026 (3 h) fueron aprobadas." */
export function newsText(item: NewsItem): string {
  const s = subject(item);
  const verb = s.plural ? 'fueron' : 'fue';
  const part = PARTICIPLE[item.event] + (s.plural ? 's' : '');
  return `${s.text} ${verb} ${part}.`;
}

/** Nota del administrador, con la etiqueta que corresponde al evento. */
export function newsNote(item: NewsItem): string | null {
  const note = item.note?.trim();
  if (!note) return null;
  return `${item.event === 'voided' ? 'Motivo' : 'Nota'}: ${note}`;
}
