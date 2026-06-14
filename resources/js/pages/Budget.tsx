import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import {
    apiErrorMessage,
    assignBudget,
    autoAssign,
    createCategory,
    createCategoryGroup,
    deleteGoal,
    fundCategory,
    getBudget,
    getCategoryActivity,
    moveBudget,
    setGoal,
    type AutoAssignStrategy,
    type GoalInput,
} from '@/lib/data';
import { currentMonth, formatDate, formatNok, monthLabel, shiftMonth } from '@/lib/format';
import {
    FREQUENCY_LABELS,
    GOAL_TYPE_LABELS,
    type BudgetCategory,
    type BudgetGroup,
    type BudgetMonth,
    type CategoryActivity,
    type Goal,
    type GoalType,
} from '@/types';

export default function Budget() {
    const [month, setMonth] = useState(currentMonth());
    const [budget, setBudget] = useState<BudgetMonth | null>(null);
    const [loading, setLoading] = useState(true);
    const [autoBusy, setAutoBusy] = useState(false);

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

    async function runAutoAssign(strategy: AutoAssignStrategy) {
        setAutoBusy(true);
        try {
            setBudget(await autoAssign(month, strategy));
        } catch (e) {
            console.error(apiErrorMessage(e, 'Auto-allokering feilet.'));
        } finally {
            setAutoBusy(false);
        }
    }

    const hasCategories = !!budget && budget.groups.some((g) => g.categories.length > 0);

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

            {budget && (
                <ReadyToAssign
                    amount={budget.ready_to_assign}
                    upcomingIncome={budget.upcoming_income}
                    projected={budget.projected_ready_to_assign}
                />
            )}

            {hasCategories && (
                <div className="mt-3 flex gap-2">
                    <button
                        onClick={() => runAutoAssign('fund-goals')}
                        disabled={autoBusy}
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Fyll opp mål
                    </button>
                    <button
                        onClick={() => runAutoAssign('cover-overspending')}
                        disabled={autoBusy}
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Dekk overtrekk
                    </button>
                </div>
            )}

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
                            <Group
                                key={group.id}
                                group={group}
                                allGroups={budget.groups}
                                month={month}
                                onChange={setBudget}
                                reload={reload}
                            />
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

function ReadyToAssign({
    amount,
    upcomingIncome,
    projected,
}: {
    amount: number;
    upcomingIncome: number;
    projected: number;
}) {
    const positive = amount >= 0;
    return (
        <div
            className={`mt-4 rounded-xl px-5 py-4 ${
                positive ? 'bg-green-50 text-green-900' : 'bg-red-50 text-red-900'
            }`}
        >
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium">Klar til å fordele</span>
                <span className="text-xl font-semibold tabular-nums">{formatNok(amount)}</span>
            </div>
            {upcomingIncome !== 0 && (
                <div className="mt-1 flex items-center justify-between text-xs opacity-80">
                    <span>Med kommende inntekt ({formatNok(upcomingIncome)})</span>
                    <span className="tabular-nums">{formatNok(projected)}</span>
                </div>
            )}
        </div>
    );
}

function Group({
    group,
    allGroups,
    month,
    onChange,
    reload,
}: {
    group: BudgetGroup;
    allGroups: BudgetGroup[];
    month: string;
    onChange: (budget: BudgetMonth) => void;
    reload: () => void;
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
        reload();
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
                <CategoryRow
                    key={category.id}
                    category={category}
                    allGroups={allGroups}
                    month={month}
                    onChange={onChange}
                    reload={reload}
                />
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

function goalSummary(goal: Goal): string {
    switch (goal.type) {
        case 'monthly':
            return `Mål: ${formatNok(goal.target_amount)} hver måned`;
        case 'target_balance':
            return `Mål: spar ${formatNok(goal.target_amount)}`;
        case 'target_balance_by_date':
            return `Mål: spar ${formatNok(goal.target_amount)} innen ${
                goal.target_date ? monthLabel(goal.target_date.slice(0, 7)) : '—'
            }`;
    }
}

function availableClass(category: BudgetCategory): string {
    // Rød ved faktisk overtrekk, eller når kommende regninger vil overtrekke.
    if (category.available < 0 || category.projected_available < 0) return 'text-red-600';
    if (category.goal && category.needed > 0) return 'text-amber-600';
    if (category.goal && category.needed === 0) return 'text-green-700';
    return 'text-neutral-900';
}

function CategoryRow({
    category,
    allGroups,
    month,
    onChange,
    reload,
}: {
    category: BudgetCategory;
    allGroups: BudgetGroup[];
    month: string;
    onChange: (budget: BudgetMonth) => void;
    reload: () => void;
}) {
    const [editingGoal, setEditingGoal] = useState(false);
    const [showActivity, setShowActivity] = useState(false);
    const [showMove, setShowMove] = useState(false);

    async function fund() {
        onChange(await fundCategory(month, category.id));
    }

    return (
        <div className="border-b border-neutral-50 last:border-0">
            <div className="grid grid-cols-[1fr_8rem_8rem_8rem] items-center gap-2 px-4 py-1.5 hover:bg-neutral-50">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span className="truncate text-sm">{category.name}</span>
                        <button
                            onClick={() => setEditingGoal((v) => !v)}
                            title={category.goal ? 'Rediger mål' : 'Sett mål'}
                            className={`text-xs ${
                                category.goal
                                    ? 'text-neutral-500 hover:text-neutral-900'
                                    : 'text-neutral-300 hover:text-neutral-600'
                            }`}
                        >
                            ◎
                        </button>
                    </div>
                    {category.goal && (
                        <p className="text-xs text-neutral-400">
                            {goalSummary(category.goal)}
                            {category.needed > 0 && (
                                <>
                                    {' · '}
                                    <span className="text-amber-600">
                                        trenger {formatNok(category.needed)}
                                    </span>
                                    <button
                                        onClick={fund}
                                        className="ml-1 font-medium text-neutral-600 underline hover:text-neutral-900"
                                    >
                                        fyll
                                    </button>
                                </>
                            )}
                        </p>
                    )}
                    {category.upcoming !== 0 && (
                        <p className="text-xs text-neutral-400">
                            <Link
                                to={`/planlagte?category=${category.id}`}
                                title="Vis planlagte i denne kategorien"
                                className={`underline hover:text-neutral-900 ${
                                    category.upcoming < 0 ? 'text-red-500' : 'text-green-600'
                                }`}
                            >
                                Kommende {formatNok(category.upcoming)}
                            </Link>
                            {' · '}projisert{' '}
                            <span
                                className={
                                    category.projected_available < 0 ? 'text-red-600' : 'text-neutral-600'
                                }
                            >
                                {formatNok(category.projected_available)}
                            </span>
                        </p>
                    )}
                </div>
                <AssignedInput category={category} month={month} onChange={onChange} />
                <button
                    type="button"
                    onClick={() => setShowActivity(true)}
                    title="Vis transaksjoner og planlagte"
                    className="w-full rounded px-1 text-right text-sm tabular-nums text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                >
                    {formatNok(category.activity)}
                </button>
                <button
                    type="button"
                    onClick={() => setShowMove(true)}
                    title="Flytt penger til en annen kategori"
                    className={`w-full rounded px-1 text-right text-sm font-medium tabular-nums hover:bg-neutral-100 ${availableClass(category)}`}
                >
                    {formatNok(category.available)}
                </button>
            </div>

            {editingGoal && (
                <GoalForm
                    category={category}
                    onSaved={() => {
                        setEditingGoal(false);
                        reload();
                    }}
                    onCancel={() => setEditingGoal(false)}
                />
            )}

            {showActivity && (
                <ActivityModal
                    category={category}
                    month={month}
                    onClose={() => setShowActivity(false)}
                />
            )}

            {showMove && (
                <MoveModal
                    category={category}
                    allGroups={allGroups}
                    month={month}
                    onMoved={(updated) => {
                        onChange(updated);
                        setShowMove(false);
                    }}
                    onClose={() => setShowMove(false)}
                />
            )}
        </div>
    );
}

function ActivityModal({
    category,
    month,
    onClose,
}: {
    category: BudgetCategory;
    month: string;
    onClose: () => void;
}) {
    const [data, setData] = useState<CategoryActivity | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        getCategoryActivity(month, category.id)
            .then(setData)
            .catch((e) => setError(apiErrorMessage(e, 'Kunne ikke hente transaksjoner.')));
    }, [month, category.id]);

    return (
        <Modal title={`${category.name} – ${monthLabel(month)}`} onClose={onClose}>
            {error && <p className="text-sm text-red-600">{error}</p>}
            {!error && !data && <p className="text-sm text-neutral-400">Laster …</p>}
            {data && (
                <div className="space-y-5">
                    <div>
                        <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            Transaksjoner
                        </h3>
                        {data.transactions.length === 0 ? (
                            <p className="text-sm text-neutral-400">Ingen transaksjoner denne måneden.</p>
                        ) : (
                            <ul className="divide-y divide-neutral-100">
                                {data.transactions.map((t) => (
                                    <li key={t.id} className="flex items-center justify-between gap-3 py-1.5">
                                        <div className="min-w-0">
                                            <div className="truncate text-sm">{t.payee || '—'}</div>
                                            <div className="text-xs text-neutral-400">
                                                {formatDate(t.date)} · {t.account ?? '—'}
                                                {t.memo ? ` · ${t.memo}` : ''}
                                            </div>
                                        </div>
                                        <span
                                            className={`shrink-0 text-sm tabular-nums ${
                                                t.amount < 0 ? 'text-neutral-700' : 'text-green-700'
                                            }`}
                                        >
                                            {formatNok(t.amount)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    {data.scheduled.length > 0 && (
                        <div>
                            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                Planlagte
                            </h3>
                            <ul className="divide-y divide-neutral-100">
                                {data.scheduled.map((s) => (
                                    <li key={s.id} className="flex items-center justify-between gap-3 py-1.5">
                                        <div className="min-w-0">
                                            <div className="truncate text-sm">{s.payee || '—'}</div>
                                            <div className="text-xs text-neutral-400">
                                                {FREQUENCY_LABELS[s.frequency]} · {s.account ?? '—'} ·{' '}
                                                {s.dates.map((d) => formatDate(d)).join(', ')}
                                            </div>
                                        </div>
                                        <span
                                            className={`shrink-0 text-sm tabular-nums ${
                                                s.total < 0 ? 'text-neutral-700' : 'text-green-700'
                                            }`}
                                        >
                                            {formatNok(s.total)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            )}
        </Modal>
    );
}

function MoveModal({
    category,
    allGroups,
    month,
    onMoved,
    onClose,
}: {
    category: BudgetCategory;
    allGroups: BudgetGroup[];
    month: string;
    onMoved: (budget: BudgetMonth) => void;
    onClose: () => void;
}) {
    const max = Math.max(0, category.available);
    const [amount, setAmount] = useState(String(max));
    const [target, setTarget] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const value = Number(amount);
        if (!value || value <= 0) {
            setError('Oppgi et beløp større enn 0.');
            return;
        }
        if (value > max + 0.001) {
            setError(`Du kan flytte maks ${formatNok(max)}.`);
            return;
        }
        if (!target) {
            setError('Velg en kategori å flytte til.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            onMoved(await moveBudget(month, category.id, Number(target), value));
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke flytte penger.'));
            setBusy(false);
        }
    }

    return (
        <Modal title={`Flytt fra ${category.name}`} onClose={onClose}>
            {max <= 0 ? (
                <p className="text-sm text-neutral-500">
                    Det er ingen tilgjengelige penger å flytte fra denne kategorien.
                </p>
            ) : (
                <form onSubmit={submit} className="space-y-3">
                    <p className="text-sm text-neutral-500">
                        Tilgjengelig: <span className="font-medium tabular-nums">{formatNok(max)}</span>
                    </p>
                    <label className="block text-xs font-medium text-neutral-600">
                        Beløp
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max={max}
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            autoFocus
                            className="mt-1 block w-40 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm focus:border-neutral-900 focus:outline-none"
                        />
                    </label>
                    <label className="block text-xs font-medium text-neutral-600">
                        Til kategori
                        <select
                            value={target}
                            onChange={(e) => setTarget(e.target.value)}
                            className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                        >
                            <option value="">Velg …</option>
                            {allGroups.map((group) => (
                                <optgroup key={group.id} label={group.name}>
                                    {group.categories
                                        .filter((c) => c.id !== category.id)
                                        .map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                </optgroup>
                            ))}
                        </select>
                    </label>

                    {error && <p className="text-sm text-red-600">{error}</p>}

                    <div className="flex gap-2 pt-1">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-lg bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                        >
                            Flytt
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
                        >
                            Avbryt
                        </button>
                    </div>
                </form>
            )}
        </Modal>
    );
}

function GoalForm({
    category,
    onSaved,
    onCancel,
}: {
    category: BudgetCategory;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const existing = category.goal;
    const [type, setType] = useState<GoalType>(existing?.type ?? 'monthly');
    const [amount, setAmount] = useState(existing ? String(existing.target_amount) : '');
    const [date, setDate] = useState(existing?.target_date?.slice(0, 7) ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function save(e: FormEvent) {
        e.preventDefault();
        const target = Number(amount);
        if (!target || target <= 0) {
            setError('Oppgi et beløp større enn 0.');
            return;
        }
        if (type === 'target_balance_by_date' && !date) {
            setError('Velg en måned for fristen.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: GoalInput = {
            type,
            target_amount: target,
            target_date: type === 'target_balance_by_date' ? date : null,
        };
        try {
            await setGoal(category.id, payload);
            onSaved();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre målet.'));
        } finally {
            setBusy(false);
        }
    }

    async function remove() {
        setBusy(true);
        try {
            await deleteGoal(category.id);
            onSaved();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke fjerne målet.'));
            setBusy(false);
        }
    }

    return (
        <form onSubmit={save} className="flex flex-wrap items-end gap-3 bg-neutral-50 px-4 py-3">
            <label className="text-xs font-medium text-neutral-600">
                Måltype
                <select
                    value={type}
                    onChange={(e) => setType(e.target.value as GoalType)}
                    className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                >
                    {(Object.keys(GOAL_TYPE_LABELS) as GoalType[]).map((value) => (
                        <option key={value} value={value}>
                            {GOAL_TYPE_LABELS[value]}
                        </option>
                    ))}
                </select>
            </label>

            <label className="text-xs font-medium text-neutral-600">
                Beløp
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className="mt-1 block w-28 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm focus:border-neutral-900 focus:outline-none"
                />
            </label>

            {type === 'target_balance_by_date' && (
                <label className="text-xs font-medium text-neutral-600">
                    Frist
                    <input
                        type="month"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
            )}

            <button
                type="submit"
                disabled={busy}
                className="rounded-lg bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
                Lagre
            </button>
            {existing && (
                <button
                    type="button"
                    onClick={remove}
                    disabled={busy}
                    className="rounded-lg px-3 py-1.5 text-sm font-medium text-red-600 hover:bg-red-50 disabled:opacity-50"
                >
                    Fjern
                </button>
            )}
            <button
                type="button"
                onClick={onCancel}
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
            >
                Avbryt
            </button>

            {error && <p className="w-full text-sm text-red-600">{error}</p>}
        </form>
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
