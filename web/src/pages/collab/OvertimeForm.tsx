import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../api';
import BackLink from '../../components/BackLink';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { dateOnly, hours as fmtHours, today } from '../../format';

export default function OvertimeForm() {
  const maxDate = today();
  const [workDate, setWorkDate] = useState(maxDate);
  const [hours, setHours] = useState('');
  const [note, setNote] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const hoursNum = useMemo(() => Number(hours.replace(',', '.')), [hours]);
  const valid =
    workDate !== '' &&
    workDate <= maxDate &&
    hoursNum >= 1 &&
    hoursNum <= 24 &&
    note.trim().length >= 3;

  function openConfirm(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (valid) setConfirming(true);
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      await api('/overtime', {
        method: 'POST',
        body: { work_date: workDate, hours: hoursNum, note: note.trim() },
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
          <h2>✅ Horas extra registradas</h2>
          <p>
            {fmtHours(hoursNum)} del {dateOnly(workDate)}. Quedan <strong>pendientes de aprobación</strong>.
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
      <h1>Registrar horas extra</h1>

      <form className="card" onSubmit={openConfirm}>
        <label>
          Fecha <Req />
          <input
            type="date"
            value={workDate}
            max={maxDate}
            onChange={(e) => setWorkDate(e.target.value)}
            required
          />
        </label>

        <label>
          Cantidad de horas <Req />
          <input
            type="text"
            inputMode="decimal"
            placeholder="ej. 2 o 6,5"
            value={hours}
            onChange={(e) => setHours(e.target.value.replace(/[^0-9.,]/g, ''))}
            required
          />
        </label>
        <p className="help">No se aprueban registros de menos de 1 hora.</p>

        <label>
          Detalle <Req />
          <input
            type="text"
            maxLength={200}
            list="ot-note-suggestions"
            placeholder="ej. Cierre de caja, Evento especial, Jornada extendida…"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            required
          />
        </label>
        <datalist id="ot-note-suggestions">
          <option value="Cierre de caja" />
          <option value="Inventario" />
          <option value="Evento especial" />
          <option value="Jornada extendida" />
          <option value="Cobertura de compañero" />
          <option value="Apertura anticipada" />
        </datalist>

        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={!valid}>
          Continuar
        </button>
      </form>

      <ConfirmDialog
        open={confirming}
        title="Confirmá las horas extra"
        rows={[
          ['Fecha', dateOnly(workDate)],
          ['Horas', fmtHours(hoursNum)],
          ['Detalle', note.trim()],
        ]}
        busy={busy}
        onConfirm={submit}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
