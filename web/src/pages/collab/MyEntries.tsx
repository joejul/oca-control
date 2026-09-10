import { useEffect, useState } from 'react';
import { api } from '../../api';
import { useAuth } from '../../auth';
import BackLink from '../../components/BackLink';
import { dateOnly, dateTime, hours as fmtHours, money } from '../../format';
import type { ConsumptionEntry, OvertimeEntry, VacationRequest } from '../../types';

const STATUS_LABEL: Record<string, string> = {
  pending: 'Pendiente',
  approved: 'Aprobada',
  rejected: 'Rechazada',
};

export default function MyEntries() {
  const { user } = useAuth();
  const [overtime, setOvertime] = useState<OvertimeEntry[]>([]);
  const [consumption, setConsumption] = useState<ConsumptionEntry[]>([]);
  const [vacation, setVacation] = useState<VacationRequest[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      api<{ entries: OvertimeEntry[] }>('/overtime/mine'),
      api<{ entries: ConsumptionEntry[] }>('/consumption/mine'),
      api<{ entries: VacationRequest[] }>('/vacation/mine'),
    ])
      .then(([o, c, v]) => {
        setOvertime(o.entries);
        setConsumption(c.entries);
        setVacation(v.entries);
      })
      .finally(() => setLoading(false));
  }, []);

  const myConsumption = consumption.filter((c) => c.consumer_id === user?.id && !c.voided);
  const registeredByMe = consumption.filter((c) => c.registered_by === user?.id);
  const approvedHours = overtime
    .filter((o) => o.status === 'approved' && !o.voided)
    .reduce((s, o) => s + o.hours, 0);
  const consumoTotal = myConsumption.reduce((s, c) => s + c.amount, 0);

  if (loading) return <div className="center muted">Cargando…</div>;

  return (
    <div className="stack">
      <BackLink />
      <h1>Mis registros</h1>

      <div className="totals-row">
        <div className="total-card">
          <span className="total-label">Horas aprobadas</span>
          <span className="total-value">{fmtHours(approvedHours)}</span>
        </div>
        <div className="total-card">
          <span className="total-label">Mis consumos</span>
          <span className="total-value">{money(consumoTotal)}</span>
        </div>
      </div>

      <section className="card">
        <h2>Horas extra</h2>
        {overtime.length === 0 && <p className="muted">Sin registros en el período actual.</p>}
        {overtime.map((o) => (
          <div key={o.id} className={`row ${o.voided ? 'is-voided' : ''}`}>
            <div>
              <strong>{fmtHours(o.hours)}</strong> · {dateOnly(o.work_date)}
              {o.note && <small className="block">{o.note}</small>}
              {o.review_note && <small className="block muted">Nota admin: {o.review_note}</small>}
            </div>
            <span className={`pill ${o.voided ? 'voided' : o.status}`}>
              {o.voided ? 'Anulada' : STATUS_LABEL[o.status]}
            </span>
          </div>
        ))}
      </section>

      <section className="card">
        <h2>Vacaciones</h2>
        {vacation.length === 0 && <p className="muted">Sin solicitudes.</p>}
        {vacation.map((v) => (
          <div key={v.id} className={`row ${v.voided ? 'is-voided' : ''}`}>
            <div>
              <strong>{dateOnly(v.request_date)}</strong>
              {v.description && <small className="block">{v.description}</small>}
              {v.review_note && <small className="block muted">Nota admin: {v.review_note}</small>}
            </div>
            <span className={`pill ${v.voided ? 'voided' : v.status}`}>
              {v.voided ? 'Anulada' : STATUS_LABEL[v.status]}
            </span>
          </div>
        ))}
      </section>

      <section className="card">
        <h2>Consumos a mi nombre</h2>
        {myConsumption.length === 0 && <p className="muted">Sin consumos en el período actual.</p>}
        {myConsumption.map((c) => (
          <div key={c.id} className="row">
            <div>
              <strong>{money(c.amount)}</strong>
              {c.detail && <small className="block">{c.detail}</small>}
              <small className="block muted">
                Registró: {c.registered_by_name} · {dateTime(c.created_at)}
              </small>
            </div>
          </div>
        ))}
      </section>

      {registeredByMe.length > 0 && (
        <section className="card">
          <h2>Consumos que registré</h2>
          {registeredByMe.map((c) => (
            <div key={c.id} className={`row ${c.voided ? 'is-voided' : ''}`}>
              <div>
                <strong>{money(c.amount)}</strong> · para {c.consumer_name}
                {c.detail && <small className="block">{c.detail}</small>}
              </div>
              {c.voided && <span className="pill voided">Anulado</span>}
            </div>
          ))}
        </section>
      )}
    </div>
  );
}
