import axios from 'axios';
import api from './api';
import type {
    Account,
    AccountType,
    BudgetMonth,
    Category,
    CategoryGroup,
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

export async function listTransactions(accountId: number): Promise<Transaction[]> {
    const res = await api.get<Wrapped<Transaction[]>>(`/accounts/${accountId}/transactions`);
    return res.data.data;
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
