import { Link } from 'react-router-dom';
import { useAuth } from '../../auth';

export default function Home() {
  const { user } = useAuth();

  return (
    <div className="stack">
      <p className="hello">Hola, {user?.display_name} 👋</p>

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
