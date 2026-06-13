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

export interface BudgetCategory {
    id: number;
    name: string;
    assigned: number;
    activity: number;
    available: number;
}

export interface BudgetGroup {
    id: number;
    name: string;
    assigned: number;
    activity: number;
    available: number;
    categories: BudgetCategory[];
}

export interface BudgetMonth {
    month: string; // YYYY-MM
    ready_to_assign: number;
    groups: BudgetGroup[];
}

export const ACCOUNT_TYPE_LABELS: Record<AccountType, string> = {
    cash: 'Kontant',
    bank: 'Bank',
    credit: 'Kredittkort',
    loan: 'Lån',
};
