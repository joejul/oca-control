import { useState } from 'react';
import { useAuth } from '../auth';
import { ApiError } from '../api';
import Req from '../components/Req';

export default function Login() {
  const { login } = useAuth();
  const [username, setUsername] = useState('');
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function onSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      await login(username.trim().toLowerCase(), pin);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo iniciar sesión.');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="center">
      <form className="card login" onSubmit={onSubmit}>
        <h1>Ocaso Cafe Bar</h1>
        <p className="muted">Horas extra y consumos</p>

        <label>
          Usuario <Req />
          <input
            autoCapitalize="none"
            autoCorrect="off"
            autoFocus
            value={username}
            onChange={(e) => setUsername(e.target.value)}
            required
          />
        </label>

        <label>
          PIN <Req />
          <input
            type="password"
            autoComplete="current-password"
            value={pin}
            onChange={(e) => setPin(e.target.value.replace(/[^A-Za-z0-9]/g, ''))}
            required
          />
        </label>

        {error && <p className="error-box">{error}</p>}

        <button type="submit" className="btn primary block" disabled={busy}>
          {busy ? 'Ingresando…' : 'Ingresar'}
        </button>
      </form>
    </div>
  );
}
