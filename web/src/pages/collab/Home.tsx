import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../../api';
import News from '../../components/News';
import { useAuth } from '../../auth';
import { minutes as fmtMinutes } from '../../format';
import type { TimeDebtMine } from '../../types';

export default function Home() {
  const { user } = useAuth();
  const [due, setDue] = useState<number | null>(null);

  useEffect(() => {
    // Si falla (p. ej. servidor sin el módulo) el botón simplemente no muestra saldo.
    api<TimeDebtMine>('/time-debt/mine')
      .then((r) => setDue(r.balance.due_minutes))
      .catch(() => setDue(null));
  }, []);

  return (
    <div className="stack">
      <p className="hello">Hola, {user?.display_name} 👋</p>

      <News />

      <Link to="/horas-extra" className="big-btn">
        <span className="big-btn-icon">⏱️</span>
        <span>
          <strong>Registrar horas extra</strong>
          <small>Se envían para aprobación del administrador</small>
        </span>
      </Link>

      <Link to="/consumo" className="big-btn">
        <span className="big-btn-icon">🍺</span>
        <span>
          <strong>Registrar consumo de un compañero</strong>
          <small>No podés registrar tu propio consumo</small>
        </span>
      </Link>

      <Link to="/vacaciones" className="big-btn">
        <span className="big-btn-icon">🏖️</span>
        <span>
          <strong>Solicitar vacaciones</strong>
          <small>Se envía para aprobación del administrador</small>
        </span>
      </Link>

      <Link to="/horas-por-reponer" className="big-btn">
        <span className="big-btn-icon">🚪</span>
        <span>
          <strong>Horas por reponer</strong>
          <small>
            {due === null
              ? 'Salidas anticipadas y reposiciones'
              : due > 0
                ? `Debés reponer ${fmtMinutes(due)}`
                : 'Sin horas pendientes'}
          </small>
        </span>
        {due ? <span className="badge">{fmtMinutes(due)}</span> : null}
      </Link>

      <Link to="/mis-registros" className="big-btn secondary">
        <span className="big-btn-icon">📋</span>
        <span>
          <strong>Mis registros</strong>
          <small>Lo que llevás en el período actual</small>
        </span>
      </Link>
    </div>
  );
}
