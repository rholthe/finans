export type AccountType = 'cash' | 'bank' | 'saving' | 'credit' | 'loan';

export interface Account {
    id: number;
    name: string;
    type: AccountType;
    on_budget: boolean;
    currency: string;
    closed: boolean;
    note: string | null;
    interest_rate: number | null; // effektiv årsrente (kun lånekontoer)
    balance: number;
    cleared_balance: number;
    last_reconciled_at: string | null;
    uncategorized_count: number; // transaksjoner som mangler aktiv kategorisering
    bank_synced: boolean; // koblet til banksynk (overføringsregler kan ikke peke hit)
    bank_balance?: BankBalance | null; // bankens egen saldo fra siste synk (kun på kontodetalj)
}

/** Nedbetalingsprojeksjon for en lånekonto. */
export interface LoanProjection {
    balance: number; // nåværende saldo (negativ = gjeld)
    interest_rate: number | null; // effektiv årsrente
    monthly_rate: number; // utledet månedsrente
    avg_payment: number; // snittlig månedlig innbetaling (basis)
    basis_months: number; // 3 | 6 | 12
    payoff_month: string | null; // YYYY-MM, null = ikke nedbetalbar
    months_to_payoff: number | null;
    total_interest: number; // gjenstående renter fram til nedbetalt
    series: { month: string; balance: number }[];
}

/** Bankens egen saldo fra siste synk – mot appens klarerte/totale beløp. */
export interface BankBalance {
    booked: number | null; // kun bokførte poster
    available: number | null; // inkl. reserverte
    synced_at: string | null; // tidspunkt for siste saldosynk (ISO)
}

export interface ReconcileResult {
    account: Account;
    cleared_balance: number; // klarert saldo før justering
    adjustment_amount: number; // justeringens beløp (0 = ingen justering)
}

export interface Transaction {
    id: number;
    account_id: number;
    category_id: number | null;
    rule_id: number | null;
    locked: boolean;
    bank_description: string | null;
    date: string; // YYYY-MM-DD
    amount: number; // signert: positiv = inn, negativ = ut
    payee: string | null;
    memo: string | null;
    cleared: boolean;
    rta: boolean; // true = bevisst plassert i «Klar til å fordele» (vs. ikke vurdert ennå)
    is_split: boolean; // beløpet er fordelt på flere kategorier (se splits)
    splits?: TransactionSplit[]; // splittlinjer (kun med når relasjonen er lastet)
    pending: boolean; // reservert bankpost (ikke bokført ennå); byttes ut ved bokføring
    reconciled_at: string | null; // satt når raden er avstemt (null = ikke avstemt)
    account?: string; // kontonavn (kun i kontouavhengig søk)
    category?: string | null; // kategorinavn (kun i kontouavhengig søk)
    is_starting_balance: boolean;
    transfer_id: number | null; // det andre benet i en overføring (null = vanlig transaksjon)
    transfer_account?: string | null; // navnet på motkontoen i overføringen
}

export interface TransactionSplit {
    id: number;
    category_id: number;
    amount: number; // signert, samme fortegn som transaksjonen
    memo: string | null;
}

export interface PageMeta {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface Paginated<T> {
    data: T[];
    meta: PageMeta;
}

export type RuleApplies = 'both' | 'inflow' | 'outflow';

export const APPLIES_TO_LABELS: Record<RuleApplies, string> = {
    both: 'Inn og ut',
    inflow: 'Kun inn',
    outflow: 'Kun ut',
};

export interface Rule {
    id: number;
    active: boolean;
    match_contains: string | null;
    match_not_contains: string | null;
    applies_to: RuleApplies;
    set_payee: string | null;
    set_memo: string | null;
    category_id: number | null;
    target_type: RuleTarget;
    transfer_account_id: number | null;
    last_applied_at: string | null;
}

export type RuleTarget = 'category' | 'rta' | 'transfer';

export const RULE_TARGET_LABELS: Record<RuleTarget, string> = {
    category: 'Kategori',
    rta: 'Klar til å fordele (RTA)',
    transfer: 'Overføring',
};

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
    target_balance: 'Ha tilgjengelig hver måned',
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
    prior_uncategorized: number; // ukategoriserte transaksjoner datert før denne måneden
    uncategorized_count: number; // hele restpotten som «mangler kategori» (alle datoer, eks. reserverte)
    uncategorized_total: number; // signert sum av samme restpott
    groups: BudgetGroup[];
}

export interface CategoryActivityTransaction {
    id: number;
    date: string; // YYYY-MM-DD
    amount: number; // signert
    payee: string | null;
    memo: string | null;
    account: string | null;
}

export interface CategoryActivityScheduled {
    id: number;
    amount: number; // signert
    payee: string | null;
    memo: string | null;
    account: string | null;
    frequency: ScheduleFrequency;
    dates: string[]; // forfall i måneden (YYYY-MM-DD)
    total: number; // amount * antall forfall
}

export interface CategoryActivity {
    category: { id: number; name: string };
    month: string; // YYYY-MM
    transactions: CategoryActivityTransaction[];
    scheduled: CategoryActivityScheduled[];
}

// --- Rapporter ---

export interface SpendingReport {
    from: string;
    to: string;
    total: number;
    groups: {
        id: number;
        name: string;
        total: number;
        categories: { id: number; name: string; total: number }[];
    }[];
}

export interface IncomeExpenseReport {
    from: string;
    to: string;
    months: { month: string; income: number; expense: number; net: number }[];
}

export interface CategoryTrendReport {
    category: { id: number; name: string };
    from: string;
    to: string;
    months: { month: string; total: number }[];
}

export interface NetWorthReport {
    from: string;
    to: string;
    months: { month: string; assets: number; debt: number; net: number }[];
}

export interface AgeOfMoneyReport {
    from: string;
    to: string;
    current: number | null; // alder (dager) ved slutten av perioden
    months: { month: string; age: number | null }[];
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
    transfer_account_id: number | null; // satt = planlagt overføring (account_id er «fra»)
    category_id: number | null;
    rta: boolean; // bevisst til «Klar til å fordele» (posteringen får rta=true)
    amount: number; // signert: positiv = inntekt, negativ = utgift (for overføringer: negativt fra account_id)
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
    saving: 'Sparing',
    credit: 'Kredittkort',
    loan: 'Lån',
};

export type BankProvider = 'gocardless' | 'enablebanking';

export const BANK_PROVIDER_LABELS: Record<BankProvider, string> = {
    gocardless: 'GoCardless',
    enablebanking: 'Enable Banking',
};

export interface Institution {
    id: string;
    name: string;
    logo?: string;
}

export interface BankAccountLink {
    id: number;
    external_id: string;
    name: string | null; // brukervalgt visningsnavn; null = fall tilbake på iban/external_id
    iban: string | null;
    account_id: number | null;
    ignored: boolean;
    rate_limit: number | null;
    rate_limit_remaining: number | null;
    rate_limit_reset_at: string | null;
}

export interface BankConnection {
    id: number;
    provider: BankProvider;
    name: string;
    institution_id: string;
    status: string; // rå leverandørstatus (GoCardless: LN = linket)
    valid_until: string | null; // ISO-tidspunkt for samtykkeutløp, eller null hvis ukjent
    accounts: BankAccountLink[];
}

export interface SyncResult {
    id: number;
    status: string;
    trigger: string;
    imported_count: number;
    report: { status: string; message: string }[];
    finished: boolean;
}

export interface AppSettings {
    manual_sync_days: number;
    auto_sync_days: number;
    report_email: string | null;
}
