import { useCallback, useEffect, useState, type FormEvent } from 'react';
import Layout from '@/components/Layout';
import {
    apiErrorMessage,
    assignBudget,
    createCategory,
    createCategoryGroup,
    getBudget,
} from '@/lib/data';
import { currentMonth, formatNok, monthLabel, shiftMonth } from '@/lib/format';
import type { BudgetCategory, BudgetGroup, BudgetMonth } from '@/types';

export default function Budget() {
    const [month, setMonth] = useState(currentMonth());
    const [budget, setBudget] = useState<BudgetMonth | null>(null);
    const [loading, setLoading] = useState(true);

    const reload = useCallback(() => {
        return getBudget(month).then(setBudget);
    }, [month]);

    useEffect(() => {
        setLoading(true);
        reload().finally(() => setLoading(false));
    }, [reload]);

    async function addGroup() {
        const name = prompt('Navn på kategorigruppe?')?.trim();
        if (!name) return;
        await createCategoryGroup(name);
        reload();
    }

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Budsjett</h1>
                <div className="flex items-center gap-1">
                    <button
                        onClick={() => setMonth((m) => shiftMonth(m, -1))}
                        className="rounded-lg px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100"
                        aria-label="Forrige måned"
                    >
                        ←
                    </button>
                    <span className="w-36 text-center text-sm font-medium capitalize">
                        {monthLabel(month)}
                    </span>
                    <button
                        onClick={() => setMonth((m) => shiftMonth(m, 1))}
                        className="rounded-lg px-3 py-1.5 text-sm text-neutral-600 hover:bg-neutral-100"
                        aria-label="Neste måned"
                    >
                        →
                    </button>
                </div>
            </div>

            {budget && <ReadyToAssign amount={budget.ready_to_assign} />}

            {loading && !budget ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : !budget || budget.groups.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center">
                    <p className="text-neutral-500">Ingen kategorier ennå.</p>
                    <button
                        onClick={addGroup}
                        className="mt-3 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                    >
                        Opprett kategorigruppe
                    </button>
                </div>
            ) : (
                <>
                    <div className="mt-6 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                        <div className="grid grid-cols-[1fr_8rem_8rem_8rem] gap-2 border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-xs font-medium uppercase tracking-wide text-neutral-500">
                            <span>Kategori</span>
                            <span className="text-right">Tildelt</span>
                            <span className="text-right">Aktivitet</span>
                            <span className="text-right">Tilgjengelig</span>
                        </div>
                        {budget.groups.map((group) => (
                            <Group key={group.id} group={group} month={month} onChange={setBudget} onAdded={reload} />
                        ))}
                    </div>
                    <button
                        onClick={addGroup}
                        className="mt-4 text-sm font-medium text-neutral-500 hover:text-neutral-900"
                    >
                        + Ny kategorigruppe
                    </button>
                </>
            )}
        </Layout>
    );
}

function ReadyToAssign({ amount }: { amount: number }) {
    const positive = amount >= 0;
    return (
        <div
            className={`mt-4 flex items-center justify-between rounded-xl px-5 py-4 ${
                positive ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900'
            }`}
        >
            <span className="text-sm font-medium">Klar til å fordele</span>
            <span className="text-xl font-semibold tabular-nums">{formatNok(amount)}</span>
        </div>
    );
}

function Group({
    group,
    month,
    onChange,
    onAdded,
}: {
    group: BudgetGroup;
    month: string;
    onChange: (budget: BudgetMonth) => void;
    onAdded: () => void;
}) {
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');

    async function submit(e: FormEvent) {
        e.preventDefault();
        const trimmed = name.trim();
        if (!trimmed) return;
        await createCategory(group.id, trimmed);
        setName('');
        setAdding(false);
        onAdded();
    }

    return (
        <div className="border-b border-neutral-100 last:border-0">
            <div className="flex items-center justify-between bg-neutral-50/60 px-4 py-2">
                <span className="text-sm font-semibold text-neutral-700">{group.name}</span>
                <span className="text-sm font-medium tabular-nums text-neutral-500">
                    {formatNok(group.available)}
                </span>
            </div>

            {group.categories.map((category) => (
                <CategoryRow key={category.id} category={category} month={month} onChange={onChange} />
            ))}

            {adding ? (
                <form onSubmit={submit} className="px-4 py-2">
                    <input
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        onBlur={() => !name.trim() && setAdding(false)}
                        autoFocus
                        placeholder="Kategorinavn – Enter for å lagre"
                        className="w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </form>
            ) : (
                <button
                    onClick={() => setAdding(true)}
                    className="px-4 py-2 text-xs font-medium text-neutral-400 hover:text-neutral-900"
                >
                    + Ny kategori
                </button>
            )}
        </div>
    );
}

function CategoryRow({
    category,
    month,
    onChange,
}: {
    category: BudgetCategory;
    month: string;
    onChange: (budget: BudgetMonth) => void;
}) {
    return (
        <div className="grid grid-cols-[1fr_8rem_8rem_8rem] items-center gap-2 px-4 py-1.5 hover:bg-neutral-50">
            <span className="text-sm">{category.name}</span>
            <AssignedInput category={category} month={month} onChange={onChange} />
            <span className="text-right text-sm tabular-nums text-neutral-500">
                {formatNok(category.activity)}
            </span>
            <span
                className={`text-right text-sm font-medium tabular-nums ${
                    category.available < 0 ? 'text-red-600' : 'text-neutral-900'
                }`}
            >
                {formatNok(category.available)}
            </span>
        </div>
    );
}

function AssignedInput({
    category,
    month,
    onChange,
}: {
    category: BudgetCategory;
    month: string;
    onChange: (budget: BudgetMonth) => void;
}) {
    const [value, setValue] = useState(String(category.assigned));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(false);

    // Synk når data lastes på nytt (f.eks. ved månedsbytte eller etter lagring).
    useEffect(() => {
        setValue(String(category.assigned));
    }, [category.assigned, month]);

    async function commit() {
        const amount = Number(value);
        if (Number.isNaN(amount) || amount === category.assigned) {
            setValue(String(category.assigned));
            return;
        }
        setSaving(true);
        setError(false);
        try {
            const updated = await assignBudget(month, category.id, amount);
            onChange(updated);
        } catch (e) {
            setError(true);
            console.error(apiErrorMessage(e, 'Kunne ikke lagre tildeling.'));
        } finally {
            setSaving(false);
        }
    }

    return (
        <input
            type="number"
            step="0.01"
            inputMode="decimal"
            value={value}
            disabled={saving}
            onChange={(e) => setValue(e.target.value)}
            onBlur={commit}
            onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            className={`w-full rounded-lg border px-2 py-1 text-right text-sm tabular-nums focus:outline-none ${
                error ? 'border-red-500' : 'border-transparent hover:border-neutral-300 focus:border-neutral-900'
            }`}
        />
    );
}
