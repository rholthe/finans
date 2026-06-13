export type AccountType = 'cash' | 'bank' | 'credit' | 'loan';

export interface Account {
    id: number;
    name: string;
    type: AccountType;
    on_budget: boolean;
    currency: string;
    closed: boolean;
    note: string | null;
    balance: number;
}

export interface Transaction {
    id: number;
    account_id: number;
    category_id: number | null;
    date: string; // YYYY-MM-DD
    amount: number; // signert: positiv = inn, negativ = ut
    payee: string | null;
    memo: string | null;
    cleared: boolean;
    is_starting_balance: boolean;
}

export interface Category {
    id: number;
    category_group_id: number;
    name: string;
    sort_order: number;
    note: string | null;
}

export interface CategoryGroup {
    id: number;
    name: string;
    sort_order: number;
    categories: Category[];
}

export type GoalType = 'monthly' | 'target_balance' | 'target_balance_by_date';

export interface Goal {
    type: GoalType;
    target_amount: number;
    target_date: string | null; // YYYY-MM-DD (kun for target_balance_by_date)
}

export const GOAL_TYPE_LABELS: Record<GoalType, string> = {
    monthly: 'Fyll opp hver måned',
    target_balance: 'Spar opp til beløp',
    target_balance_by_date: 'Spar opp til beløp innen dato',
};

export interface BudgetCategory {
    id: number;
    name: string;
    assigned: number;
    activity: number;
    available: number;
    upcoming: number; // sum av kommende, ikke-posterte poster i måneden (signert)
    projected_available: number; // available + upcoming
    goal: Goal | null;
    needed: number; // hvor mye mer som må tildeles denne måneden (0 hvis i rute / uten mål)
}

export interface BudgetGroup {
    id: number;
    name: string;
    assigned: number;
    activity: number;
    available: number;
    upcoming: number;
    projected_available: number;
    categories: BudgetCategory[];
}

export interface BudgetMonth {
    month: string; // YYYY-MM
    ready_to_assign: number;
    upcoming_income: number; // netto kommende ukategoriserte poster på budsjettkontoer
    projected_ready_to_assign: number;
    groups: BudgetGroup[];
}

export type ScheduleFrequency =
    | 'weekly'
    | 'biweekly'
    | 'monthly'
    | 'quarterly'
    | 'semiannually'
    | 'yearly';

export const FREQUENCY_LABELS: Record<ScheduleFrequency, string> = {
    weekly: 'Ukentlig',
    biweekly: 'Hver 2. uke',
    monthly: 'Månedlig',
    quarterly: 'Hver 3. måned',
    semiannually: 'Hver 6. måned',
    yearly: 'Årlig',
};

export interface ScheduledTransaction {
    id: number;
    account_id: number;
    category_id: number | null;
    amount: number; // signert: positiv = inntekt, negativ = utgift
    payee: string | null;
    memo: string | null;
    frequency: ScheduleFrequency;
    start_date: string; // YYYY-MM-DD
    next_date: string; // YYYY-MM-DD
    end_date: string | null;
    last_posted_date: string | null;
}

export const ACCOUNT_TYPE_LABELS: Record<AccountType, string> = {
    cash: 'Kontant',
    bank: 'Bank',
    credit: 'Kredittkort',
    loan: 'Lån',
};

export interface Institution {
    id: string;
    name: string;
    logo?: string;
}

export interface BankAccountLink {
    id: number;
    external_id: string;
    iban: string | null;
    account_id: number | null;
    ignored: boolean;
}

export interface BankConnection {
    id: number;
    name: string;
    institution_id: string;
    status: string; // GoCardless requisition-status (LN = linket)
    accounts: BankAccountLink[];
}

export interface SyncResult {
    status: string;
    imported_count: number;
    report: { status: string; message: string }[];
}
