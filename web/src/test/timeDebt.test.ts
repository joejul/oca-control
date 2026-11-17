import { describe, expect, it } from 'vitest';
import { addDays, minutes, minutesBetween } from '../format';

describe('minutes', () => {
  it('formatea horas y minutos', () => {
    expect(minutes(150)).toBe('2 h 30 min');
    expect(minutes(60)).toBe('1 h');
    expect(minutes(45)).toBe('45 min');
    expect(minutes(0)).toBe('0 min');
  });

  it('no muestra negativos ni basura', () => {
    expect(minutes(-5)).toBe('0 min');
    expect(minutes(Number.NaN)).toBe('0 min');
  });
});

describe('minutesBetween (misma regla que el servidor)', () => {
  it('calcula oficial - real', () => {
    expect(minutesBetween('17:00', '15:30')).toBe(90);
    expect(minutesBetween('18:00', '16:40')).toBe(80);
    expect(minutesBetween('15:00', '14:59')).toBe(1);
  });

  it('devuelve null si falta una hora o la real no es anterior', () => {
    expect(minutesBetween('', '15:00')).toBeNull();
    expect(minutesBetween('17:00', '')).toBeNull();
    expect(minutesBetween('15:00', '15:00')).toBeNull();
    expect(minutesBetween('15:00', '16:00')).toBeNull();
  });
});

describe('addDays', () => {
  it('suma y resta días respetando meses y años', () => {
    expect(addDays('2026-11-17', -60)).toBe('2026-09-18');
    expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
    expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
  });
});
