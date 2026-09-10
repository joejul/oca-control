import { api } from './api';
import type { AgendaEvent } from './types';

export function listEvents(month: string) {
  return api<{ events: AgendaEvent[] }>(`/agenda/events?month=${encodeURIComponent(month)}`);
}

export function createEvent(payload: {
  month?: string;
  date?: string | null;
  type: string;
  theme?: string | null;
}) {
  return api<AgendaEvent>('/agenda/events', { method: 'POST', body: payload });
}

export function updateEvent(id: number, payload: Partial<AgendaEvent>) {
  return api<AgendaEvent>(`/agenda/events/${id}`, { method: 'PATCH', body: payload });
}

export function deleteEvent(id: number) {
  return api<{ id: number; deleted: boolean }>(`/agenda/events/${id}/delete`, { method: 'POST' });
}

export function generateWeek(month: string) {
  return api<{ created: number }>('/agenda/generate', { method: 'POST', body: { month } });
}
