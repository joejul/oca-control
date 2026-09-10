import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../api';
import { useAuth } from '../../auth';
import BackLink from '../../components/BackLink';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { money } from '../../format';
import type { Collaborator } from '../../types';

export default function ConsumptionForm() {
  const { user } = useAuth();
  const [people, setPeople] = useState<Collaborator[]>([]);
  const [consumerId, setConsumerId] = useState('');
  const [amount, setAmount] = useState('');
  const [detail, setDetail] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<string | null>(null);

  useEffect(() => {
    api<{ collaborators: Collaborator[] }>('/collaborators')
      .then((r) => setPeople(r.collaborators.filter((c) => c.id !== user?.id)))
      .catch(() => setError('No se pudo cargar la lista de compañeros.'));
  }, [user?.id]);

  const amountNum = useMemo(() => Math.round(Number(amount.replace(',', '.'))), [amount]);
  const consumer = people.find((p) => String(p.id) === consumerId);
  const valid =
    !!consumer && amountNum >= 1 && amountNum <= 1_000_000 && detail.trim().length >= 2;

  function openConfirm(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (valid) setConfirming(true);
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ consumer_name: string }>('/consumption', {
        method: 'POST',
        body: { consumer_id: Number(consumerId), amount: amountNum, detail: detail.trim() || undefined },
      });
      setDone(res.consumer_name);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo guardar.');
      setConfirming(false);
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className="stack">
        <div className="card success">
          <h2>✅ Consumo registrado</h2>
          <p>
            {money(amountNum)} a nombre de <strong>{done}</strong>.
          </p>
        </div>
        <Link to="/" className="btn primary block">
          Volver al inicio
        </Link>
      </div>
    );
  }

  return (
    <div className="stack">
      <BackLink />
      <h1>Registrar consumo</h1>
      <p className="muted">Estás registrando el consumo de otra persona, no el tuyo.</p>

      <form className="card" onSubmit={openConfirm}>
        <label>
          Compañero <Req />
          <select value={consumerId} onChange={(e) => setConsumerId(e.target.value)} required>
            <option value="">Seleccioná…</option>
            {people.map((p) => (
              <option key={p.id} value={p.id}>
                {p.display_name}
              </option>
            ))}
          </select>
        </label>

        <label>
          Monto total (₡) <Req />
          <input
            type="text"
            inputMode="numeric"
            placeholder="ej. 3500"
            value={amount}
            onChange={(e) => setAmount(e.target.value.replace(/[^0-9]/g, ''))}
            required
          />
        </label>

        <label>
          Detalle <Req />
          <textarea
            rows={2}
            maxLength={500}
            required
            placeholder="ej. 2 cervezas + 1 café"
            value={detail}
            onChange={(e) => setDetail(e.target.value)}
          />
        </label>

        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={!valid}>
          Continuar
        </button>
      </form>

      <ConfirmDialog
        open={confirming}
        title="Confirmá el consumo"
        rows={[
          ['Compañero', consumer?.display_name ?? '—'],
          ['Monto', money(amountNum)],
          ['Detalle', detail.trim()],
        ]}
        warning="Este consumo NO se puede modificar ni borrar después. Solo el administrador puede anularlo."
        onConfirm={submit}
        onCancel={() => setConfirming(false)}
        busy={busy}
      />
    </div>
  );
}
