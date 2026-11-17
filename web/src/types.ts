export type Role = 'admin' | 'collaborator';

export type User = {
    id: number;
    username: string;
    display_name: string;
    role: Role;
};

export type Collaborator = {
    id: number;
    display_name: string;
};

export type OvertimeStatus = 'pending' | 'approved' | 'rejected';

export type OvertimeEntry = {
    id: number;
    user_id?: number;
    display_name?: string;
    work_date: string;
    hours: number;
    note: string | null;
    status: OvertimeStatus;
    review_note: string | null;
    created_at: string;
    voided: boolean;
    void_reason?: string | null;
};

export type VacationStatus = 'pending' | 'approved' | 'rejected';

export type VacationRequest = {
    id: number;
    user_id?: number;
    display_name?: string;
    request_date: string;
    description: string | null;
    status: VacationStatus;
    review_note: string | null;
    created_at: string;
    voided: boolean;
    void_reason?: string | null;
};

export type TimeDebtBalance = {
    user_id: number;
    display_name: string;
    active: boolean;
    owed_minutes: number;
    approved_minutes: number;
    pending_minutes: number;
    /** Lo que todavía debe reponer (nunca negativo). */
    due_minutes: number;
    /** Lo que aún puede solicitar reponer (saldo menos pendientes de aprobación). */
    available_minutes: number;
};

export type EarlyLeave = {
    id: number;
    user_id?: number;
    display_name?: string;
    leave_date: string;
    scheduled_end: string;
    actual_end: string;
    minutes_owed: number;
    note: string;
    created_at: string;
    voided: boolean;
    void_reason?: string | null;
};

export type MakeupHours = {
    id: number;
    user_id?: number;
    display_name?: string;
    makeup_date: string;
    minutes: number;
    note: string | null;
    status: 'pending' | 'approved' | 'rejected';
    review_note: string | null;
    created_at: string;
    voided: boolean;
    void_reason?: string | null;
};

export type TimeDebtMine = {
    balance: TimeDebtBalance;
    early_leaves: EarlyLeave[];
    makeups: MakeupHours[];
};

export type ConsumptionEntry = {
    id: number;
    amount: number;
    detail: string | null;
    created_at: string;
    consumer_id: number;
    consumer_name: string;
    registered_by: number;
    registered_by_name: string;
    voided: boolean;
    void_reason?: string | null;
};

export type ClosureRow = {
    user_id: number;
    display_name: string;
    overtime_hours: number;
    consumption_total: number;
    /** Saldo por reponer al generar el cierre. Ausente en cierres anteriores al módulo. */
    makeup_due_minutes?: number;
};

export type ClosureTotals = {
    overtime_hours: number;
    consumption_total: number;
    makeup_due_minutes?: number;
};

export type ClosurePreview = {
    start: string;
    end: string;
    /** true si ya hubo un cierre: el inicio es fijo (día siguiente al fin del anterior). */
    start_locked: boolean;
    last_end: string | null;
    /** Nombre automático, p. ej. "Cierre 1-15 Noviembre". */
    suggested_label: string;
    rows: ClosureRow[];
    totals: ClosureTotals;
    pending_count: number;
    /** Registros de fechas anteriores al inicio que nunca se habían cerrado y entran en este cierre. */
    carried_count: number;
};

export type ClosureSummary = {
    id: number;
    label: string;
    start_date: string;
    end_date: string;
    generated_at: string;
    generated_by_name: string;
    totals: ClosureTotals | null;
};

export type ClosureDetail = {
    closure: { id: number; label: string; start: string; end: string; generated_at: string };
    rows: ClosureRow[];
    totals: ClosureTotals | null;
    overtime: OvertimeEntry[];
    consumption: ConsumptionEntry[];
};

export type EventType =
    | 'Happy Hour'
    | 'Karaoke'
    | 'Viernes de Ocaso'
    | 'DJ Night'
    | 'Especial'
    | 'Cerrado'
    | 'Otro';

export type ArtStatus = 'pending' | 'completed';

export type AgendaEvent = {
    id: number;
    month: string;
    date: string | null;
    type: EventType;
    theme: string | null;
    schedule: string | null;
    cover: string | null;
    promo: string | null;
    artists: string | null;
    price: string | null;
    internal_cost: number | null;
    art_status: ArtStatus;
    created_at: string;
    updated_at: string;
};

export type AdminUser = {
    id: number;
    username: string;
    display_name: string;
    role: Role;
    active: boolean;
    locked: boolean;
    created_at: string;
};

export type NewsKind = 'overtime' | 'vacation' | 'makeup' | 'early_leave';
export type NewsEvent = 'approved' | 'rejected' | 'voided';

/** Algo que el administrador resolvió sobre un registro del colaborador. */
export type NewsItem = {
    kind: NewsKind;
    id: number;
    event: NewsEvent;
    ref_date: string;
    /** Horas (overtime) o minutos (makeup / early_leave); null en vacaciones. */
    amount: number | null;
    note: string | null;
    event_at: string;
    unread: boolean;
};

export type AuditRow = {
    id: number;
    created_at: string;
    actor_id: number | null;
    actor_name: string | null;
    action: string;
    entity_type: string | null;
    entity_id: number | null;
    /** Colaborador dueño del registro afectado (si aplica). */
    subject_name: string | null;
    details: Record<string, unknown> | null;
};

export type AuditPage = {
    rows: AuditRow[];
    total: number;
    page: number;
    per_page: number;
};
