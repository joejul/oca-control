import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../api';
import BackLink from '../../components/BackLink';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { addDays, dateOnly, minutes as fmtMinutes, minutesBetween, today } from '../../format';
import type { TimeDebtBalance } from '../../types';

export default function EarlyLeaveForm() {
  const maxDate = today();
  const minDate = addDays(maxDate, -60);

  const [leaveDate, setLeaveDate] = useState(maxDate);
  const [scheduledEnd, setScheduledEnd] = useState('');
  const [actualEnd, setActualEnd] = useState('');
  const [note, setNote] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<{ owed: number; balance: TimeDebtBalance } | null>(null);

  const owed = useMemo(() => minutesBetween(scheduledEnd, actualEnd), [scheduledEnd, actualEnd]);
  const timesFilled = scheduledEnd !== '' && actualEnd !== '';
  const timesInvalid = timesFilled && owed === null;

  const valid =
    leaveDate !== '' &&
    leaveDate <= maxDate &&
    leaveDate >= minDate &&
    owed !== null &&
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
      const res = await api<{ minutes_owed: number; balance: TimeDebtBalance }>('/early-leave', {
        method: 'POST',
        body: {
          leave_date: leaveDate,
          scheduled_end: scheduledEnd,
          actual_end: actualEnd,
          note: note.trim(),
        },
      });
      setDone({ owed: res.minutes_owed, balance: res.balance });
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
          <h2>✅ Salida anticipada registrada</h2>
          <p>
            {dateOnly(leaveDate)}: debés reponer <strong>{fmtMinutes(done.owed)}</strong>.
          </p>
          <p className="muted">
            Saldo total por reponer: <strong>{fmtMinutes(done.balance.due_minutes)}</strong>
          </p>
        </div>
        <Link to="/reposicion" className="btn primary block">
          Registrar una reposición
        </Link>
        <Link to="/horas-por-reponer" className="btn ghost block">
          Ver mi saldo
        </Link>
      </div>
    );
  }

  return (
    <div className="stack">
      <BackLink to="/horas-por-reponer" label="Horas por reponer" />
      <h1>Registrar salida anticipada</h1>

      <form className="card" onSubmit={openConfirm}>
        <label>
          Fecha <Req />
          <input
            type="date"
            value={leaveDate}
            min={minDate}
            max={maxDate}
            onChange={(e) => setLeaveDate(e.target.value)}
            required
          />
        </label>

        <div className="field-row">
          <label>
            Hora oficial de salida <Req />
            <input
              type="time"
              value={scheduledEnd}
              onChange={(e) => setScheduledEnd(e.target.value)}
              required
            />
          </label>
          <label>
            Hora real de salida <Req />
            <input
              type="time"
              value={actualEnd}
              onChange={(e) => setActualEnd(e.target.value)}
              required
            />
          </label>
        </div>

        {timesInvalid && (
          <p className="error-box">La hora real de salida debe ser anterior a la hora oficial.</p>
        )}
        {owed !== null && (
          <p className="warn-inline">
            Horas a reponer: <strong>{fmtMinutes(owed)}</strong>
          </p>
        )}

        <label>
          Detalle <Req />
          <input
            type="text"
            maxLength={200}
            list="early-leave-suggestions"
            placeholder="ej. Cita médica, Emergencia familiar, Trámite personal…"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            required
          />
        </label>
        <datalist id="early-leave-suggestions">
          <option value="Cita médica" />
          <option value="Emergencia familiar" />
          <option value="Malestar o enfermedad" />
          <option value="Trámite personal" />
          <option value="Permiso autorizado" />
        </datalist>

        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={!valid}>
          Continuar
        </button>
      </form>

      <ConfirmDialog
        open={confirming}
        title="Confirmá la salida anticipada"
        rows={[
          ['Fecha', dateOnly(leaveDate)],
          ['Hora oficial', scheduledEnd],
          ['Hora real', actualEnd],
          ['Horas a reponer', owed !== null ? fmtMinutes(owed) : '—'],
          ['Detalle', note.trim()],
        ]}
        busy={busy}
        onConfirm={submit}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
