import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import News from '../components/News';
import { newsNote, newsText } from '../news';
import type { NewsItem } from '../types';

const apiMock = vi.hoisted(() => vi.fn());
vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');
  return { ...actual, api: apiMock };
});

const item = (over: Partial<NewsItem>): NewsItem => ({
  kind: 'overtime',
  id: 1,
  event: 'approved',
  ref_date: '2026-11-07',
  amount: 3,
  note: null,
  event_at: '2026-11-10 09:00:00',
  unread: true,
  ...over,
});

describe('newsText / newsNote', () => {
  it('concuerda género y número en cada tipo de registro', () => {
    expect(newsText(item({}))).toBe('Tus horas extra del 07/11/2026 (3 h) fueron aprobadas.');
    expect(newsText(item({ kind: 'vacation', event: 'rejected', amount: null }))).toBe(
      'Tu solicitud de vacaciones del 07/11/2026 fue rechazada.',
    );
    expect(newsText(item({ kind: 'makeup', amount: 90 }))).toBe(
      'Tu reposición de 1 h 30 min del 07/11/2026 fue aprobada.',
    );
    expect(newsText(item({ kind: 'early_leave', event: 'voided', amount: 45 }))).toBe(
      'Tu salida anticipada del 07/11/2026 (45 min) fue anulada.',
    );
  });

  it('etiqueta la nota según el evento y omite las vacías', () => {
    expect(newsNote(item({ note: 'Falta detalle' }))).toBe('Nota: Falta detalle');
    expect(newsNote(item({ event: 'voided', note: 'Duplicada' }))).toBe('Motivo: Duplicada');
    expect(newsNote(item({ note: '   ' }))).toBeNull();
    expect(newsNote(item({ note: null }))).toBeNull();
  });
});

describe('News', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it('no muestra nada si no hay novedades sin leer', async () => {
    apiMock.mockResolvedValue({ unread: 0, items: [item({ unread: false })] });
    const { container } = render(<News />);
    await waitFor(() => expect(apiMock).toHaveBeenCalledWith('/notifications'));
    expect(container).toBeEmptyDOMElement();
  });

  it('no muestra nada si el servidor falla', async () => {
    apiMock.mockImplementation(async () => {
      throw new Error('boom');
    });
    const { container } = render(<News />);
    await waitFor(() => expect(apiMock).toHaveBeenCalled());
    expect(container).toBeEmptyDOMElement();
  });

  it('muestra solo las sin leer y "Entendido" marca hasta la más reciente', async () => {
    apiMock.mockImplementation((path: string) =>
      path === '/notifications'
        ? Promise.resolve({
            unread: 2,
            items: [
              item({ id: 3, event_at: '2026-11-12 10:00:00' }),
              item({ id: 2, kind: 'vacation', amount: null, event_at: '2026-11-11 08:00:00' }),
              item({ id: 1, unread: false, event_at: '2026-11-01 08:00:00' }),
            ],
          })
        : Promise.resolve({ ok: true }),
    );
    render(<News />);

    expect(await screen.findByText(/tus horas extra del/i)).toBeInTheDocument();
    expect(screen.getByText(/tu solicitud de vacaciones/i)).toBeInTheDocument();
    expect(screen.getAllByText(/fue/i)).toHaveLength(2); // la ya leída no aparece

    await userEvent.click(screen.getByRole('button', { name: /entendido/i }));

    expect(apiMock).toHaveBeenCalledWith('/notifications/seen', {
      method: 'POST',
      body: { up_to: '2026-11-12 10:00:00' },
    });
    await waitFor(() => expect(screen.queryByRole('button', { name: /entendido/i })).toBeNull());
  });
});
