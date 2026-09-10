import { useCallback, useEffect, useState } from 'react';
import { api, ApiError } from '../../api';
import { useAuth } from '../../auth';
import Req from '../../components/Req';
import type { AdminUser } from '../../types';

export default function Users() {
  const { user: me } = useAuth();
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [showNew, setShowNew] = useState(false);
  const [username, setUsername] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [pin, setPin] = useState('');
  const [creating, setCreating] = useState(false);

  const load = useCallback(() => {
    setLoading(true);
    api<{ users: AdminUser[] }>('/admin/users')
      .then((r) => setUsers(r.users))
      .finally(() => setLoading(false));
  }, []);

  useEffect(load, [load]);

  async function createUser(e: React.FormEvent) {
    e.preventDefault();
    setCreating(true);
    setError(null);
    try {
      await api('/admin/users', {
        method: 'POST',
        body: {
          username: username.trim().toLowerCase(),
          display_name: displayName.trim(),
          pin,
          role: 'collaborator',
        },
      });
      setUsername('');
      setDisplayName('');
      setPin('');
      setShowNew(false);
      load();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'No se pudo crear.');
    } finally {
      setCreating(false);
    }
  }

  async function toggleActive(u: AdminUser) {
    await api(`/admin/users/${u.id}`, { method: 'PATCH', body: { active: !u.active } });
    load();
  }

  async function unlock(u: AdminUser) {
    await api(`/admin/users/${u.id}`, { method: 'PATCH', body: { unlock: true } });
    load();
  }

  async function resetPin(u: AdminUser) {
    const value = window.prompt(`Nuevo PIN para ${u.display_name} (5 caracteres: 1 letra y 4 números, ej. a1234):`);
    if (!value) return;
    if (!/^[A-Za-z]\d{4}$/.test(value)) {
      alert('El PIN debe tener 5 caracteres: 1 letra y 4 números (ej. a1234).');
      return;
    }
    try {
      await api(`/admin/users/${u.id}/reset-pin`, { method: 'POST', body: { pin: value } });
      alert('PIN actualizado. Se cerraron las sesiones de ese usuario.');
    } catch (err) {
      alert(err instanceof ApiError ? err.message : 'No se pudo cambiar el PIN.');
    }
  }

  return (
    <div className="stack">
      <div className="entry-head">
        <h1>Usuarios</h1>
        <button className="btn primary sm" onClick={() => setShowNew((v) => !v)}>
          {showNew ? 'Cerrar' : 'Nuevo'}
        </button>
      </div>

      {showNew && (
        <form className="card" onSubmit={createUser}>
          <label>
            Usuario (para ingresar) <Req />
            <input
              autoCapitalize="none"
              value={username}
              onChange={(e) => setUsername(e.target.value)}
              required
            />
          </label>
          <label>
            Nombre para mostrar <Req />
            <input value={displayName} onChange={(e) => setDisplayName(e.target.value)} required />
          </label>
          <label>
            PIN inicial (5 caracteres: 1 letra y 4 números, ej. a1234) <Req />
            <input
              value={pin}
              onChange={(e) => setPin(e.target.value.replace(/[^A-Za-z0-9]/g, '').slice(0, 5))}
              required
            />
          </label>
          {error && <p className="error-box">{error}</p>}
          <button className="btn primary block" disabled={creating}>
            {creating ? 'Creando…' : 'Crear colaborador'}
          </button>
        </form>
      )}

      {loading && <div className="center muted">Cargando…</div>}

      {users.map((u) => (
        <div key={u.id} className={`card ${u.active ? '' : 'is-voided'}`}>
          <div className="entry-head">
            <strong>
              {u.display_name} <span className="muted">@{u.username}</span>
            </strong>
            <span className={`pill ${u.active ? 'approved' : 'voided'}`}>
              {u.role === 'admin' ? 'Admin' : u.active ? 'Activo' : 'Inactivo'}
            </span>
          </div>
          {u.locked && <p className="warn-inline">🔒 Bloqueado por intentos fallidos</p>}
          {u.id !== me?.id && (
            <div className="entry-actions">
              <button className="btn ghost sm" onClick={() => resetPin(u)}>
                Cambiar PIN
              </button>
              {u.locked && (
                <button className="btn ghost sm" onClick={() => unlock(u)}>
                  Desbloquear
                </button>
              )}
              {u.role !== 'admin' && (
                <button
                  className={`btn sm ${u.active ? 'danger' : 'ok'}`}
                  onClick={() => toggleActive(u)}
                >
                  {u.active ? 'Desactivar' : 'Activar'}
                </button>
              )}
            </div>
          )}
        </div>
      ))}
    </div>
  );
}
