import { useEffect, useState } from 'react';
import { api } from '../api';
import { dateTime } from '../format';
import { NEWS_ICON, newsNote, newsText } from '../news';
import type { NewsItem } from '../types';

/**
 * Novedades sin leer del colaborador (lo que el administrador aprobó, rechazó o anuló).
 * No muestra nada si no hay, o si el servidor no responde.
 */
export default function News() {
  const [items, setItems] = useState<NewsItem[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    api<{ unread: number; items: NewsItem[] }>('/notifications')
      .then((r) => {
        if (!cancelled) setItems(r.items.filter((i) => i.unread));
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, []);

  if (items.length === 0) return null;

  async function dismiss() {
    setBusy(true);
    // Se marca solo hasta la novedad más reciente que se mostró.
    const upTo = items.reduce((max, i) => (i.event_at > max ? i.event_at : max), items[0].event_at);
    try {
      await api('/notifications/seen', { method: 'POST', body: { up_to: upTo } });
      setItems([]);
    } catch {
      /* si falla se vuelve a mostrar la próxima vez */
    } finally {
      setBusy(false);
    }
  }

  return (
    <section className="card news" aria-label="Novedades">
      <h2>🔔 Novedades</h2>
      {items.map((i) => {
        const note = newsNote(i);
        return (
          <div key={`${i.kind}-${i.id}-${i.event}`} className="news-item">
            <span aria-hidden="true">{NEWS_ICON[i.event]}</span>
            <div>
              <span>{newsText(i)}</span>
              {note && <small className="block">{note}</small>}
              <small className="block muted">{dateTime(i.event_at)}</small>
            </div>
          </div>
        );
      })}
      <button type="button" className="btn ghost block" onClick={dismiss} disabled={busy}>
        Entendido
      </button>
    </section>
  );
}
