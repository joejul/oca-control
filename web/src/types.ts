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
};

export type ClosureTotals = {
    overtime_hours: number;
    consumption_total: number;
};

export type ClosurePreview = {
    start: string;
    end: string;
    rows: ClosureRow[];
    totals: ClosureTotals;
    pending_count: number;
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

export type AdminUser = {
    id: number;
    username: string;
    display_name: string;
    role: Role;
    active: boolean;
    locked: boolean;
    created_at: string;
};
