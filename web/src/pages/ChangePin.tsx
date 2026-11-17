import { useState } from 'react';
import { api, ApiError } from '../api';
import BackLink from '../components/BackLink';
import Req from '../components/Req';

const PIN_FORMAT = /^[A-Za-z]\d{4}$/;
const clean = (v: string) => v.replace(/[^A-Za-z0-9]/g, '');

/** Cambio de PIN por el propio usuario (colaborador o administrador). */
export default function ChangePin() {
  const [current, setCurrent] = useState('');
  const [next, setNext] = useState('');
  const [again, setAgain] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState(false);

  const formatOk = PIN_FORMAT.test(next);
  const matches = next === again;
  const differs = next !== current;
  const valid = current !== '' && formatOk && matches && differs;

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!valid) return;
    setBusy(true);
    setError(null);
    try {
      await api('/me/pin', { method: 'POST', body: { current_pin: current, new_pin: next } });
      setDone(true);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo cambiar el PIN.');
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className="stack">
        <div className="card success">
          <h2>✅ PIN actualizado</h2>
          <p>Desde ahora ingresás con tu PIN nuevo. Si tenías la sesión abierta en otros equipos, se cerró.</p>
        </div>
        <BackLink to="/" label="Volver al inicio" />
      </div>
    );
  }

  return (
    <div className="stack">
      <BackLink to="/" label="Volver" />
      <h1>Cambiar mi PIN</h1>

      <form className="card" onSubmit={onSubmit}>
        <label>
          PIN actual <Req />
          <input
            type="password"
            autoComplete="current-password"
            value={current}
            onChange={(e) => setCurrent(clean(e.target.value))}
            required
          />
        </label>

        <label>
          PIN nuevo <Req />
          <input
            type="password"
            autoComplete="new-password"
            maxLength={5}
            value={next}
            onChange={(e) => setNext(clean(e.target.value))}
            required
          />
          <small className="help">5 caracteres: 1 letra y 4 números (ej. a1234).</small>
        </label>

        <label>
          Repetí el PIN nuevo <Req />
          <input
            type="password"
            autoComplete="new-password"
            maxLength={5}
            value={again}
            onChange={(e) => setAgain(clean(e.target.value))}
            required
          />
        </label>

        {next !== '' && !formatOk && (
          <p className="error-box">El PIN nuevo debe tener 1 letra y 4 números (ej. a1234).</p>
        )}
        {again !== '' && !matches && <p className="error-box">Los PIN nuevos no coinciden.</p>}
        {next !== '' && current !== '' && !differs && (
          <p className="error-box">El PIN nuevo debe ser distinto al actual.</p>
        )}
        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={!valid || busy}>
          {busy ? 'Guardando…' : 'Cambiar PIN'}
        </button>
      </form>
    </div>
  );
}
