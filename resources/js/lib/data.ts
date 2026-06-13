import axios from 'axios';
import api from './api';
import type {
    Account,
    AccountType,
    BankConnection,
    BudgetMonth,
    Category,
    CategoryGroup,
    AppSettings,
    Goal,
    GoalType,
    Institution,
    Paginated,
    Rule,
    RuleApplies,
    ScheduledTransaction,
    ScheduleFrequency,
    SyncResult,
    Transaction,
} from '@/types';

interface Wrapped<T> {
    data: T;
}

/**
 * Gjør en API-feil om til en lesbar norsk melding. Skiller på sesjon/CSRF (419),
 * ikke innlogget (401) og valideringsfeil (422 – viser feltfeilene fra Laravel),
 * slik at brukeren får vite hva som faktisk gikk galt i stedet for en gjettet
 * «sjekk feltene»-melding.
 */
export function apiErrorMessage(error: unknown, fallback: string): string {
    if (axios.isAxiosError(error)) {
        const status = error.response?.status;

        if (status === 419) {
            return 'Økten er utløpt. Last siden på nytt og prøv igjen.';
        }
        if (status === 401) {
            return 'Du er ikke innlogget. Logg inn på nytt.';
        }
        if (status === 422) {
            const errors = (error.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
            const firstField = errors && Object.values(errors)[0];
            if (firstField?.[0]) {
                return firstField[0];
            }
        }

        const message = (error.response?.data as { message?: string } | undefined)?.message;
        if (message) {
            return message;
        }
    }

    return fallback;
}

export async function listAccounts(): Promise<Account[]> {
    const res = await api.get<Wrapped<Account[]>>('/accounts');
    return res.data.data;
}

export async function getAccount(id: number): Promise<Account> {
    const res = await api.get<Wrapped<Account>>(`/accounts/${id}`);
    return res.data.data;
}

export interface NewAccount {
    name: string;
    type: AccountType;
    on_budget: boolean;
    starting_balance?: number;
    note?: string;
}

export async function createAccount(payload: NewAccount): Promise<Account> {
    const res = await api.post<Wrapped<Account>>('/accounts', payload);
    return res.data.data;
}

export async function deleteAccount(id: number): Promise<void> {
    await api.delete(`/accounts/${id}`);
}

export interface TransactionQuery {
    from?: string;
    to?: string;
    perPage?: number;
    page?: number;
}

export async function getTransactions(
    accountId: number,
    query: TransactionQuery = {},
): Promise<Paginated<Transaction>> {
    const res = await api.get<Paginated<Transaction>>(`/accounts/${accountId}/transactions`, {
        params: {
            from: query.from || undefined,
            to: query.to || undefined,
            per_page: query.perPage,
            page: query.page,
        },
    });
    return res.data;
}

/** Kjør reglene på et avgrenset sett (de viste transaksjonene). Returnerer antall oppdatert. */
export async function applyRulesToTransactions(
    ids: number[],
    includeMatched = false,
): Promise<number> {
    const res = await api.post<{ updated: number }>('/transactions/apply-rules', {
        transaction_ids: ids,
        include_matched: includeMatched,
    });
    return res.data.updated;
}

export interface NewTransaction {
    date: string;
    amount: number;
    category_id?: number | null;
    payee?: string;
    memo?: string;
    cleared?: boolean;
}

export async function createTransaction(
    accountId: number,
    payload: NewTransaction,
): Promise<Transaction> {
    const res = await api.post<Wrapped<Transaction>>(
        `/accounts/${accountId}/transactions`,
        payload,
    );
    return res.data.data;
}

export async function updateTransaction(
    id: number,
    payload: Partial<NewTransaction>,
): Promise<Transaction> {
    const res = await api.patch<Wrapped<Transaction>>(`/transactions/${id}`, payload);
    return res.data.data;
}

export async function deleteTransaction(id: number): Promise<void> {
    await api.delete(`/transactions/${id}`);
}

// --- Kategorier & kategorigrupper ---

export async function listCategoryGroups(): Promise<CategoryGroup[]> {
    const res = await api.get<Wrapped<CategoryGroup[]>>('/category-groups');
    return res.data.data;
}

export async function createCategoryGroup(name: string): Promise<CategoryGroup> {
    const res = await api.post<Wrapped<CategoryGroup>>('/category-groups', { name });
    return res.data.data;
}

export async function deleteCategoryGroup(id: number): Promise<void> {
    await api.delete(`/category-groups/${id}`);
}

export async function createCategory(categoryGroupId: number, name: string): Promise<Category> {
    const res = await api.post<Wrapped<Category>>('/categories', {
        category_group_id: categoryGroupId,
        name,
    });
    return res.data.data;
}

export async function deleteCategory(id: number): Promise<void> {
    await api.delete(`/categories/${id}`);
}

// --- Budsjett ---
// Budsjett-endepunktene returnerer hele månedsvisningen direkte (ikke pakket i { data }).

export async function getBudget(month: string): Promise<BudgetMonth> {
    const res = await api.get<BudgetMonth>('/budget', { params: { month } });
    return res.data;
}

export async function assignBudget(
    month: string,
    categoryId: number,
    assigned: number,
): Promise<BudgetMonth> {
    const res = await api.put<BudgetMonth>(`/budget/${month}/categories/${categoryId}`, {
        assigned,
    });
    return res.data;
}

export type AutoAssignStrategy = 'fund-goals' | 'cover-overspending';

export async function autoAssign(
    month: string,
    strategy: AutoAssignStrategy,
): Promise<BudgetMonth> {
    const res = await api.post<BudgetMonth>(`/budget/${month}/auto-assign`, { strategy });
    return res.data;
}

export async function fundCategory(month: string, categoryId: number): Promise<BudgetMonth> {
    const res = await api.post<BudgetMonth>(`/budget/${month}/categories/${categoryId}/fund`);
    return res.data;
}

// --- Mål ---

export interface GoalInput {
    type: GoalType;
    target_amount: number;
    target_date?: string | null; // YYYY-MM (kun for target_balance_by_date)
}

export async function setGoal(categoryId: number, payload: GoalInput): Promise<Goal> {
    const res = await api.put<Goal>(`/categories/${categoryId}/goal`, payload);
    return res.data;
}

export async function deleteGoal(categoryId: number): Promise<void> {
    await api.delete(`/categories/${categoryId}/goal`);
}

// --- Planlagte (repeterende) transaksjoner ---

export interface NewScheduledTransaction {
    account_id: number;
    category_id?: number | null;
    amount: number;
    payee?: string | null;
    memo?: string | null;
    frequency: ScheduleFrequency;
    start_date: string; // YYYY-MM-DD
    end_date?: string | null;
}

export async function listScheduledTransactions(): Promise<ScheduledTransaction[]> {
    const res = await api.get<Wrapped<ScheduledTransaction[]>>('/scheduled-transactions');
    return res.data.data;
}

export async function createScheduledTransaction(
    payload: NewScheduledTransaction,
): Promise<ScheduledTransaction> {
    const res = await api.post<Wrapped<ScheduledTransaction>>('/scheduled-transactions', payload);
    return res.data.data;
}

export async function updateScheduledTransaction(
    id: number,
    payload: Partial<NewScheduledTransaction>,
): Promise<ScheduledTransaction> {
    const res = await api.patch<Wrapped<ScheduledTransaction>>(
        `/scheduled-transactions/${id}`,
        payload,
    );
    return res.data.data;
}

export async function deleteScheduledTransaction(id: number): Promise<void> {
    await api.delete(`/scheduled-transactions/${id}`);
}

// --- Bankintegrasjon ---

export async function listInstitutions(): Promise<Institution[]> {
    const res = await api.get<Institution[]>('/bank/institutions');
    return res.data;
}

export async function listBankConnections(): Promise<BankConnection[]> {
    const res = await api.get<Wrapped<BankConnection[]>>('/bank/connections');
    return res.data.data;
}

/** Returnerer samtykke-lenken brukeren skal sendes til (window.location). */
export async function connectBank(institutionId: string): Promise<string> {
    const res = await api.post<{ link: string }>('/bank/connect', { institution_id: institutionId });
    return res.data.link;
}

export async function linkBankAccount(
    id: number,
    payload: { account_id?: number | null; ignored?: boolean },
): Promise<void> {
    await api.put(`/bank/accounts/${id}`, payload);
}

export async function deleteBankConnection(id: number): Promise<void> {
    await api.delete(`/bank/connections/${id}`);
}

/** Starter en manuell synk (køes). Returnerer processing-event som kan polles. */
export async function syncBank(): Promise<SyncResult> {
    const res = await api.post<SyncResult>('/bank/sync');
    return res.data;
}

export async function getSyncStatus(id: number): Promise<SyncResult> {
    const res = await api.get<SyncResult>(`/bank/sync-status/${id}`);
    return res.data;
}

export async function getSettings(): Promise<AppSettings> {
    const res = await api.get<Wrapped<AppSettings>>('/settings');
    return res.data.data;
}

export async function updateSettings(payload: Partial<AppSettings>): Promise<AppSettings> {
    const res = await api.put<Wrapped<AppSettings>>('/settings', payload);
    return res.data.data;
}

// --- Regelmotor ---

export interface RuleInput {
    name?: string | null;
    match_contains: string;
    match_not_contains?: string | null;
    applies_to?: RuleApplies;
    set_payee?: string | null;
    set_memo?: string | null;
    category_id?: number | null;
    active?: boolean;
}

export async function listRules(): Promise<Rule[]> {
    const res = await api.get<Wrapped<Rule[]>>('/rules');
    return res.data.data;
}

export async function createRule(payload: RuleInput): Promise<Rule> {
    const res = await api.post<Wrapped<Rule>>('/rules', payload);
    return res.data.data;
}

export async function updateRule(id: number, payload: Partial<RuleInput>): Promise<Rule> {
    const res = await api.patch<Wrapped<Rule>>(`/rules/${id}`, payload);
    return res.data.data;
}

export async function deleteRule(id: number): Promise<void> {
    await api.delete(`/rules/${id}`);
}

export async function reorderRules(rules: { id: number; priority: number }[]): Promise<void> {
    await api.put('/rules/reorder', { rules });
}
