import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ClosureNew from '../pages/admin/ClosureNew';
import type { ClosurePreview } from '../types';

const apiMock = vi.hoisted(() => vi.fn());
vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');
  return { ...actual, api: apiMock };
});

const preview = (over: Partial<ClosurePreview> = {}): ClosurePreview => ({
  start: '2026-11-16',
  end: '2026-11-30',
  start_locked: true,
  last_end: '2026-11-15',
  suggested_label: 'Cierre 16-30 Noviembre',
  rows: [
    { user_id: 1, display_name: 'Nela', overtime_hours: 3, consumption_total: 0, makeup_due_minutes: 0 },
  ],
  totals: { overtime_hours: 3, consumption_total: 0, makeup_due_minutes: 0 },
  pending_count: 0,
  carried_count: 0,
  ...over,
});

function setup() {
  render(
    <MemoryRouter>
      <ClosureNew />
    </MemoryRouter>,
  );
}

describe('ClosureNew', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it('con un cierre anterior el inicio es fijo y se explica por qué', async () => {
    apiMock.mockResolvedValue(preview({ end: '2026-11-17' }));
    setup();

    expect(await screen.findByText(/el inicio es fijo/i)).toHaveTextContent('15/11/2026');
    expect(screen.getByLabelText(/desde/i)).toBeDisabled();
    expect(screen.getByLabelText(/desde/i)).toHaveValue('2026-11-16');
  });

  it('sin cierres previos el inicio se puede editar', async () => {
    apiMock.mockResolvedValue(preview({ start_locked: false, last_end: null, end: '2026-11-17' }));
    setup();

    await screen.findByText(/vista previa/i);
    expect(screen.getByLabelText(/desde/i)).toBeEnabled();
    expect(screen.queryByText(/el inicio es fijo/i)).toBeNull();
  });

  it('propone el nombre automático y lo usa si no se escribe otro', async () => {
    apiMock.mockImplementation((path: string) =>
      path.startsWith('/admin/closure/generate')
        ? Promise.resolve({ id: 9 })
        : Promise.resolve(preview({ end: '2026-11-17' })),
    );
    setup();

    expect(await screen.findByText(/si lo deja vacío se usa: cierre 16-30 noviembre/i)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /generar cierre y archivar/i }));
    expect(screen.getByText('Cierre 16-30 Noviembre')).toBeInTheDocument(); // en el diálogo de confirmación

    await userEvent.click(screen.getByRole('button', { name: /^generar cierre$/i }));
    await waitFor(() =>
      expect(apiMock).toHaveBeenCalledWith('/admin/closure/generate', {
        method: 'POST',
        body: { start: '2026-11-16', end: '2026-11-17', label: undefined },
      }),
    );
  });

  it('no deja generar un período que todavía no termina', async () => {
    apiMock.mockResolvedValue(preview({ end: '2099-12-30' }));
    setup();

    expect(await screen.findByText(/todavía no ha llegado/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /generar cierre y archivar/i })).toBeDisabled();
  });

  it('avisa cuando el cierre incluye registros atrasados', async () => {
    apiMock.mockResolvedValue(preview({ end: '2026-11-17', carried_count: 2 }));
    setup();

    expect(await screen.findByText(/incluye 2 registro/i)).toBeInTheDocument();
  });

  it('un período sin registros igual se puede cerrar (mantiene la secuencia)', async () => {
    apiMock.mockResolvedValue(
      preview({
        end: '2026-11-17',
        rows: [],
        totals: { overtime_hours: 0, consumption_total: 0, makeup_due_minutes: 0 },
      }),
    );
    setup();

    expect(await screen.findByText(/aun así puede generar el cierre/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /generar cierre y archivar/i })).toBeEnabled();
  });
});
