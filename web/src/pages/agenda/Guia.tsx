import type {ReactNode} from 'react';

type ExampleProps = {
    day: string;
    type: string;
    typeClass: string;
    theme: string;
    schedule: string;
    cover: string;
    price: string;
    artists: string;
    cost?: string;
    promo: string;
    status: string;
    statusClass: string;
};

type RuleProps = {
    icon: string;
    title: string;
    color: string;
    children: ReactNode;
};

function RuleCard({icon, title, color, children}: RuleProps) {
    return (
        <div className={`agenda-rule-card ${color}`}>
            <div className="agenda-rule-head">
                <span className="agenda-rule-icon">{icon}</span>
                <h3 className="agenda-rule-title">{title}</h3>
            </div>
            <p>{children}</p>
        </div>
    );
}

function ExampleCard(p: ExampleProps) {
    return (
        <article className={`agenda-card agenda-card-example ${p.typeClass}`}>
            <header className="agenda-card-head">
                <span className="agenda-day-chip">{p.day}</span>
                <span className="agenda-type-label">{p.type}</span>
            </header>
            <div className="agenda-card-body agenda-card-static">
                <p><strong>Temática:</strong> {p.theme}</p>
                <p><strong>Horario:</strong> {p.schedule}</p>
                <p><strong>Cover:</strong> {p.cover}</p>
                <p><strong>Descripción de la Promo:</strong> {p.price}</p>
                <p><strong>DJs / Artistas:</strong> {p.artists}</p>
                {p.cost && (
                    <p><strong>Costo para Ocaso (₡):</strong> {p.cost} <em className="muted">— control interno, Julian
                        no lo ve</em></p>
                )}
                <p><strong>Detalles de la promo:</strong> <span className="agenda-promo-text">{p.promo}</span></p>
            </div>
            <footer className="agenda-card-foot">
                <div className="agenda-status-field">
                    <span className="agenda-status-caption">Estado de arte</span>
                    <span className={`agenda-status agenda-status-${p.statusClass}`}>{p.status}</span>
                </div>
            </footer>
        </article>
    );
}

export default function Guia() {
    return (
        <div className="stack">
            <div className="card">
                <h2>Cómo trabajamos el mes</h2>
                <div className="agenda-rules-grid">
                    <RuleCard icon="🗓️" title="Roles" color="c-roles">
                        <b>Fernanda</b> arma el calendario del mes (promos, rifas, fechas, marcas) — idealmente el 25,
                        antes es mejor. <b>Julian</b> ejecuta lo que está cargado ahí.
                    </RuleCard>
                    <RuleCard icon="🎯" title="Regla de oro" color="c-gold">
                        Sin dato cargado no hay reclamo que valga. Casilla vacía = pendiente de quien planea.
                    </RuleCard>
                    <RuleCard icon="⏱️" title="Anticipación" color="c-time">
                        Meta: publicar con <strong>2 semanas</strong> de anticipación, no el mismo día del
                        evento. Reforzar con algunos posts durante.
                    </RuleCard>
                    <RuleCard icon="🚨" title="Bloqueos" color="c-alert">
                        Si falta un dato, avisar al momento y con hora concreta — ej. "<b><i>necesito el dato de la
                        rifa para publicar hoy a las 4pm</i></b>". Nada de quedarse esperando en silencio.
                    </RuleCard>
                    <RuleCard icon="⚡" title="Iniciativa" color="c-initiative">
                        Si el arte ya está definido y solo falta un detalle menor: resolvelo, pedí el dato y,
                        si no llega a tiempo, publicá igual.
                    </RuleCard>
                    <RuleCard icon="🎨" title="Estado de arte" color="c-status">
                        Pendiente o Completado. Lo actualiza Julian desde el tab Resumen — ahí ve el detalle
                        de cada día en una ventana y solo puede tocar ese estado, nada más.
                    </RuleCard>
                    <RuleCard icon="📝" title="Detalles de la promo" color="c-promo">
                        No es el texto final — son los datos crudos (qué, cómo, con quién, condiciones) para
                        escribir el copy. El texto de redes lo redacta Julian.
                    </RuleCard>
                    <RuleCard icon="🤖" title="Uso de IA" color="c-ai">
                        Sí es valido, pero sin perder la esencia de su trabajo como humano y precisión de la
                        marca — y sin sobrecargar los artes. <b>Menos es más.</b> Para generar texto 100% recomendado.
                    </RuleCard>
                </div>
            </div>

            <div className="card">
                <h2>Ejemplos de eventos llenos y completos</h2>
                <div className="agenda-grid">
                    <ExampleCard
                        day="Mié 9"
                        type="Happy Hour"
                        typeClass="type-happy-hour"
                        theme="2x1 en cócteles de la casa"
                        schedule="5pm - 8pm"
                        cover="Sin cover"
                        price="2x1 en cócteles de autor"
                        artists="—"
                        promo="2x1 aplica a toda la barra de cócteles (no cerveza ni vino). Corre de 5 a 8pm, todos los miércoles del mes. Ideal para gente saliendo del trabajo."
                        status="Completado"
                        statusClass="completed"
                    />
                    <ExampleCard
                        day="Jue 10"
                        type="Karaoke"
                        typeClass="type-karaoke"
                        theme="Karaoke"
                        schedule="9:00 PM"
                        cover="Sin cover"
                        price="Happy hour de cerveza hasta las 8pm"
                        artists="Paul"
                        cost="100000 (pago Paul)"
                        promo="Paul anima con su equipo propio. Catálogo en español e inglés. Entrada libre pero las mesas se llenan rápido después de las 9pm — mencionarlo."
                        status="Completado"
                        statusClass="completed"
                    />
                    <ExampleCard
                        day="Vie 11"
                        type="Viernes de Ocaso"
                        typeClass="type-viernes-de-ocaso"
                        theme="Noche de tributo — Rock en español"
                        schedule="8pm - 1am"
                        cover="₡3000"
                        price="Barra completa"
                        artists="DJ Fede + banda invitada"
                        cost="45000 (pago banda + DJ)"
                        promo="Banda invitada toca covers de rock en español (Soda Stereo, Caifanes) de 9 a 11pm, después DJ Fede toma la posta. Cover incluye una bebida de cortesía."
                        status="Pendiente"
                        statusClass="pending"
                    />
                    <ExampleCard
                        day="Sáb 12"
                        type="DJ Night"
                        typeClass="type-dj-night"
                        theme="Reggaetón & house — Set de cierre de semana"
                        schedule="9pm - 2am"
                        cover="₡4000 (₡2000 antes de las 10pm)"
                        price="2x1 en shots de la casa antes de las 10pm"
                        artists="DJ Kenneth"
                        cost="30000 (pago DJ)"
                        promo="DJ Kenneth mezcla reggaetón viejo y nuevo con house. Entrada anticipada ₡2000 se vende por WhatsApp hasta el viernes al mediodía, después sube a ₡4000 en puerta."
                        status="Pendiente"
                        statusClass="pending"
                    />
                </div>
            </div>
        </div>
    );
}
