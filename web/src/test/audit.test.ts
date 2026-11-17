import { describe, expect, it } from 'vitest';
import { actionLabel, detailText } from '../audit';

describe('audit', () => {
  it('traduce acciones conocidas y deja las desconocidas tal cual', () => {
    expect(actionLabel('overtime.void')).toBe('Anuló horas extra');
    expect(actionLabel('user.change_pin')).toBe('Cambió su propio PIN');
    expect(actionLabel('algo.nuevo')).toBe('algo.nuevo');
  });

  it('resume los detalles con etiquetas y fechas legibles', () => {
    expect(detailText({ work_date: '2026-11-07', hours: 3 })).toBe('Fecha: 07/11/2026 · Horas: 3');
    expect(detailText({ reason: 'Duplicada' })).toBe('Motivo: Duplicada');
    expect(detailText({ active: false })).toBe('Activo: no');
  });

  it('devuelve null si no hay nada que mostrar', () => {
    expect(detailText(null)).toBeNull();
    expect(detailText({})).toBeNull();
    expect(detailText({ note: null, reason: '' })).toBeNull();
  });
});
