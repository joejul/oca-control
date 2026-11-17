import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, ApiError } from '../../api';
import BackLink from '../../components/BackLink';
import ConfirmDialog from '../../components/ConfirmDialog';
import Req from '../../components/Req';
import { addDays, dateOnly, minutes as fmtMinutes, today } from '../../format';
import type { TimeDebtBalance, TimeDebtMine } from '../../types';

export default function MakeupForm() {
  const maxDate = today();
  const minDate = addDays(maxDate, -60);

  const [balance, setBalance] = useState<TimeDebtBalance | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [makeupDate, setMakeupDate] = useState(maxDate);
  const [hh, setHh] = useState('');
  const [mm, setMm] = useState('');
  const [note, setNote] = useState('');
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<{ minutes: number; balance: TimeDebtBalance } | null>(null);

  useEffect(() => {
    api<TimeDebtMine>('/time-debt/mine')
      .then((r) => setBalance(r.balance))
      .catch(() => setLoadError(true));
  }, []);

  const total = useMemo(() => {
    const h = Number(hh || 0);
    const m = Number(mm || 0);
    if (!Number.isInteger(h) || !Number.isInteger(m) || h < 0 || m < 0 || m > 59) return 0;
    return h * 60 + m;
  }, [hh, mm]);

  const available = balance?.available_minutes ?? 0;
  const overLimit = total > available;
  const valid =
    balance !== null &&
    total >= 1 &&
    !overLimit &&
    makeupDate !== '' &&
    makeupDate <= maxDate &&
    makeupDate >= minDate;

  function openConfirm(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (valid) setConfirming(true);
  }

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const res = await api<{ balance: TimeDebtBalance }>('/makeup', {
        method: 'POST',
        body: { makeup_date: makeupDate, minutes: total, note: note.trim() || undefined },
      });
      setDone({ minutes: total, balance: res.balance });
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
          <h2>✅ Reposición registrada</h2>
          <p>
            {fmtMinutes(done.minutes)} del {dateOnly(makeupDate)}. Queda{' '}
            <strong>pendiente de aprobación</strong>.
          </p>
        </div>
        <Link to="/horas-por-reponer" className="btn primary block">
          Ver mi saldo
        </Link>
        <BackLink label="Ir al inicio" />
      </div>
    );
  }

  return (
    <div className="stack">
      <BackLink to="/horas-por-reponer" label="Horas por reponer" />
      <h1>Registrar reposición</h1>

      {loadError && <p className="error-box">No se pudo cargar tu saldo. Intentá de nuevo.</p>}

      {balance && balance.due_minutes === 0 && (
        <div className="card">
          <p>No tenés horas pendientes por reponer. 🎉</p>
        </div>
      )}

      {balance && balance.due_minutes > 0 && (
        <>
          <div className="totals-row">
            <div className="total-card">
              <span className="total-label">Por reponer</span>
              <span className="total-value">{fmtMinutes(balance.due_minutes)}</span>
            </div>
            <div className="total-card">
              <span className="total-label">Podés solicitar</span>
              <span className="total-value">{fmtMinutes(balance.available_minutes)}</span>
            </div>
          </div>
          {balance.pending_minutes > 0 && (
            <p className="help">
              Tenés {fmtMinutes(balance.pending_minutes)} en reposiciones pendientes de aprobación.
            </p>
          )}
        </>
      )}

      {balance && balance.available_minutes > 0 && (
        <form className="card" onSubmit={openConfirm}>
          <label>
            Fecha en que repusiste <Req />
            <input
              type="date"
              value={makeupDate}
              min={minDate}
              max={maxDate}
              onChange={(e) => setMakeupDate(e.target.value)}
              required
            />
          </label>

          <span className="field-title">
            Tiempo repuesto <Req />
          </span>
          <div className="field-row">
            <label>
              Horas
              <input
                type="number"
                inputMode="numeric"
                min="0"
                max="24"
                step="1"
                placeholder="0"
                value={hh}
                onChange={(e) => setHh(e.target.value)}
              />
            </label>
            <label>
              Minutos
              <input
                type="number"
                inputMode="numeric"
                min="0"
                max="59"
                step="1"
                placeholder="0"
                value={mm}
                onChange={(e) => setMm(e.target.value)}
              />
            </label>
          </div>
          <p className="help">Máximo {fmtMinutes(balance.available_minutes)}.</p>

          {overLimit && (
            <p className="error-box">
              No podés reponer más de {fmtMinutes(balance.available_minutes)} (tu saldo menos lo que
              ya está pendiente de aprobación).
            </p>
          )}

          <label>
            Detalle
            <input
              type="text"
              maxLength={200}
              placeholder="ej. Cubrí cierre del sábado"
              value={note}
              onChange={(e) => setNote(e.target.value)}
            />
          </label>

          {error && <p className="error-box">{error}</p>}

          <button type="submit" className="btn primary block" disabled={!valid}>
            Continuar
          </button>
        </form>
      )}

      {balance && balance.due_minutes > 0 && balance.available_minutes === 0 && (
        <div className="card">
          <p>
            Ya tenés reposiciones pendientes de aprobación que cubren todo tu saldo. Cuando el
            administrador las revise, vas a ver el saldo actualizado.
          </p>
        </div>
      )}

      <ConfirmDialog
        open={confirming}
        title="Confirmá la reposición"
        rows={[
          ['Fecha', dateOnly(makeupDate)],
          ['Tiempo repuesto', fmtMinutes(total)],
          ['Detalle', note.trim() || '—'],
        ]}
        warning="Este registro NO se puede modificar después de guardar y queda pendiente de aprobación del administrador."
        busy={busy}
        onConfirm={submit}
        onCancel={() => setConfirming(false)}
      />
    </div>
  );
}
