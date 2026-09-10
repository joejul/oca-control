import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../api';
import BackLink from '../../components/BackLink';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { dateOnly, today } from '../../format';

export default function VacationForm() {
  const minDate = today();
  const [requestDate, setRequestDate] = useState(minDate);
  const [description, setDescription] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const valid = useMemo(() => requestDate !== '' && requestDate >= minDate, [requestDate, minDate]);

  function openConfirm(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (valid) setConfirming(true);
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api('/vacation', {
        method: 'POST',
        body: { request_date: requestDate, description: description.trim() || undefined },
      });
      setDone(true);
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
          <h2>✅ Solicitud de vacaciones enviada</h2>
          <p>
            {dateOnly(requestDate)}. Queda <strong>pendiente de aprobación</strong>.
          </p>
        </div>
        <Link to="/mis-registros" className="btn primary block">
          Ver mis registros
        </Link>
        <BackLink label="Ir al inicio" />
      </div>
    );
  }

  return (
    <div className="stack">
      <BackLink />
      <h1>Solicitar vacaciones</h1>

      <form className="card" onSubmit={openConfirm}>
        <label>
          Fecha <Req />
          <input
            type="date"
            value={requestDate}
            min={minDate}
            onChange={(e) => setRequestDate(e.target.value)}
            required
          />
        </label>

        <label>
          Descripción
          <textarea
            rows={2}
            maxLength={500}
            placeholder="ej. Viaje familiar (opcional)"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
          />
        </label>

        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={!valid}>
          Continuar
        </button>
      </form>

      <ConfirmDialog
        open={confirming}
        title="Confirmá la solicitud de vacaciones"
        rows={[
          ['Fecha', dateOnly(requestDate)],
          ['Descripción', description.trim() || '—'],
        ]}
        busy={busy}
        onConfirm={submit}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
