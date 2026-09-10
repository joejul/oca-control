import { describe, expect, it } from 'vitest';
import { dateOnly, hours, money } from '../format';

describe('format', () => {
  it('money formatea colones sin decimales', () => {
    expect(money(3500)).toMatch(/3[.,\s]?500/);
    expect(money(3500)).toMatch(/₡|CRC/);
  });

  it('hours muestra enteros y decimales', () => {
    expect(hours(2)).toBe('2 h');
    expect(hours(2.5)).toBe('2.50 h');
  });

  it('dateOnly convierte a dd/mm/aaaa', () => {
    expect(dateOnly('2026-09-07')).toBe('07/09/2026');
    expect(dateOnly('2026-09-07 16:21:16')).toBe('07/09/2026');
  });
});
