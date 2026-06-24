import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import { Link } from 'react-router-dom';
import InlineNameEdit from '@/components/InlineNameEdit';
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
    resetAssignments,
    setGoal,
    sweepBudget,
    updateCategory,
    updateCategoryGroup,
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

// Felles kolonneoppsett for header- og kategorirader (avkrysning · navn · tildelt · aktivitet · tilgjengelig).
// Smalere tallkolonner på små skjermer (desktop-først, grasiøs degradering).
const ROW_GRID =
    'grid grid-cols-[1.25rem_minmax(0,1fr)_5rem_5rem_5.5rem] sm:grid-cols-[1.25rem_minmax(0,1fr)_8rem_8rem_8rem] items-center gap-2';

export default function Budget() {
    const [month, setMonth] = useState(currentMonth());
    const [budget, setBudget] = useState<BudgetMonth | null>(null);
    const [loading, setLoading] = useState(true);
    const [autoBusy, setAutoBusy] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [showBulkMove, setShowBulkMove] = useState(false);
    const [showAddGroup, setShowAddGroup] = useState(false);
    const [showReset, setShowReset] = useState(false);

    const reload = useCallback(() => {
        return getBudget(month).then(setBudget);
    }, [month]);

    useEffect(() => {
        setLoading(true);
        reload().finally(() => setLoading(false));
    }, [reload]);

    // Nullstill seleksjonen ved månedsbytte.
    useEffect(() => setSelected(new Set()), [month]);

    const allCategoryIds = useMemo(
        () => (budget ? budget.groups.flatMap((g) => g.categories.map((c) => c.id)) : []),
        [budget],
    );
    const selectedCats = useMemo(
        () => (budget ? budget.groups.flatMap((g) => g.categories).filter((c) => selected.has(c.id)) : []),
        [budget, selected],
    );

    const fundNeeded = selectedCats.reduce((s, c) => s + (c.goal ? c.needed : 0), 0);
    const coverNeeded = selectedCats.reduce((s, c) => s + Math.max(0, -c.projected_available), 0);

    function toggleCategory(id: number) {
        setSelected((prev) => {
            const next = new Set(prev);
            next.has(id) ? next.delete(id) : next.add(id);
            return next;
        });
    }

    function toggleGroup(group: BudgetGroup) {
        const ids = group.categories.map((c) => c.id);
        const allSelected = ids.length > 0 && ids.every((id) => selected.has(id));
        setSelected((prev) => {
            const next = new Set(prev);
            ids.forEach((id) => (allSelected ? next.delete(id) : next.add(id)));
            return next;
        });
    }

    function toggleAll() {
        setSelected((prev) =>
            prev.size === allCategoryIds.length && allCategoryIds.length > 0
                ? new Set()
                : new Set(allCategoryIds),
        );
    }

    function addGroup() {
        setShowAddGroup(true);
    }

    async function runAutoAssign(strategy: AutoAssignStrategy) {
        setAutoBusy(true);
        try {
            setBudget(await autoAssign(month, strategy, Array.from(selected)));
        } catch (e) {
            console.error(apiErrorMessage(e, 'Auto-allokering feilet.'));
        } finally {
            setAutoBusy(false);
        }
    }

    async function runReset() {
        setAutoBusy(true);
        try {
            setBudget(await resetAssignments(month, Array.from(selected)));
            setShowReset(false);
        } catch (e) {
            console.error(apiErrorMessage(e, 'Kunne ikke nullstille tildeling.'));
        } finally {
            setAutoBusy(false);
        }
    }

    const hasCategories = !!budget && budget.groups.some((g) => g.categories.length > 0);
    const noneSelected = selected.size === 0;
    const allSelected = allCategoryIds.length > 0 && selected.size === allCategoryIds.length;
    // Mål og bulk-/auto-handlinger gjelder kun inneværende og fremtidige måneder.
    // I fortiden viser vi bare tildelt/forbruk/tilgjengelig; endringer gjøres manuelt i listen.
    const isPast = month < currentMonth();

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Budsjett</h1>
                <div className="flex items-center gap-1">
                    {month !== currentMonth() && (
                        <button
                            onClick={() => setMonth(currentMonth())}
                            className="mr-1 rounded-lg px-2 py-1.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100"
                        >
                            I dag
                        </button>
                    )}
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
                    uncategorizedCount={budget.uncategorized_count}
                    uncategorizedTotal={budget.uncategorized_total}
                />
            )}

            {budget && budget.prior_uncategorized > 0 && (
                <div className="mt-3 flex items-center justify-between gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    <span>
                        <span aria-hidden>⚠️</span> Du har {budget.prior_uncategorized} ukategorisert
                        {budget.prior_uncategorized === 1 ? ' transaksjon' : 'e transaksjoner'} fra tidligere
                        måneder. Kategoriser dem for å holde budsjettet rent.
                    </span>
                    <Link
                        to="/kontoer"
                        className="shrink-0 font-medium underline hover:text-amber-900"
                    >
                        Gå til kontoer
                    </Link>
                </div>
            )}

            {hasCategories && !isPast && (
                <div className="mt-3 flex flex-wrap items-center gap-2">
                    <span className="text-xs font-medium text-neutral-400">
                        {noneSelected ? 'Velg kategorier' : `${selected.size} valgt`}
                    </span>
                    <ActionButton
                        onClick={() => runAutoAssign('fund-goals')}
                        disabled={autoBusy || noneSelected || fundNeeded <= 0}
                    >
                        Fyll opp mål{fundNeeded > 0 && noneSelected === false ? ` (${formatNok(fundNeeded)})` : ''}
                    </ActionButton>
                    <ActionButton
                        onClick={() => runAutoAssign('cover-overspending')}
                        disabled={autoBusy || noneSelected || coverNeeded <= 0}
                    >
                        Dekk overtrekk{coverNeeded > 0 && noneSelected === false ? ` (${formatNok(coverNeeded)})` : ''}
                    </ActionButton>
                    <ActionButton onClick={() => setShowBulkMove(true)} disabled={autoBusy || noneSelected}>
                        Flytt valgte
                    </ActionButton>
                    <ActionButton onClick={() => setShowReset(true)} disabled={autoBusy || noneSelected}>
                        Nullstill tildeling
                    </ActionButton>
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
                    <div
                        className={`${ROW_GRID} sticky top-0 z-10 mt-6 rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-2 text-xs font-medium uppercase tracking-wide text-neutral-500`}
                    >
                        {isPast ? (
                            <span />
                        ) : (
                            <TriCheckbox
                                checked={allSelected}
                                indeterminate={selected.size > 0 && !allSelected}
                                onChange={toggleAll}
                                ariaLabel="Velg alle kategorier"
                            />
                        )}
                        <span>Kategori</span>
                        <span className="text-right">Tildelt</span>
                        <span className="text-right">Aktivitet</span>
                        <span className="text-right">Tilgjengelig</span>
                    </div>
                    <div className="mt-3 space-y-3">
                        {budget.groups.map((group) => (
                            <Group
                                key={group.id}
                                group={group}
                                allGroups={budget.groups}
                                month={month}
                                isPast={isPast}
                                selected={selected}
                                onToggleCategory={toggleCategory}
                                onToggleGroup={toggleGroup}
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

            {showBulkMove && budget && (
                <BulkMoveModal
                    sources={selectedCats}
                    allGroups={budget.groups}
                    month={month}
                    onMoved={(updated) => {
                        setBudget(updated);
                        setShowBulkMove(false);
                    }}
                    onClose={() => setShowBulkMove(false)}
                />
            )}

            {showAddGroup && (
                <NewGroupModal
                    onCreated={() => {
                        setShowAddGroup(false);
                        reload();
                    }}
                    onClose={() => setShowAddGroup(false)}
                />
            )}

            {showReset && (
                <Modal
                    title="Nullstill tildeling"
                    size="sm"
                    onClose={() => setShowReset(false)}
                    footer={
                        <>
                            <button
                                type="button"
                                onClick={() => setShowReset(false)}
                                className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
                            >
                                Avbryt
                            </button>
                            <button
                                type="button"
                                onClick={runReset}
                                disabled={autoBusy}
                                className="rounded-lg bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-500 disabled:opacity-50"
                            >
                                Nullstill
                            </button>
                        </>
                    }
                >
                    <p className="text-sm text-neutral-600">
                        Nullstille tildelingen for {selected.size} kategori(er) i {monthLabel(month)}?
                    </p>
                </Modal>
            )}
        </Layout>
    );
}

function ActionButton({
    onClick,
    disabled,
    children,
}: {
    onClick: () => void;
    disabled?: boolean;
    children: ReactNode;
}) {
    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 disabled:cursor-not-allowed disabled:opacity-40"
        >
            {children}
        </button>
    );
}

function TriCheckbox({
    checked,
    indeterminate,
    onChange,
    ariaLabel,
}: {
    checked: boolean;
    indeterminate?: boolean;
    onChange: () => void;
    ariaLabel: string;
}) {
    const ref = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (ref.current) ref.current.indeterminate = indeterminate ?? false;
    }, [indeterminate]);
    return (
        <input
            ref={ref}
            type="checkbox"
            checked={checked}
            onChange={onChange}
            aria-label={ariaLabel}
            className="h-4 w-4 cursor-pointer rounded border-neutral-300 text-neutral-900 focus:ring-neutral-400"
        />
    );
}

function ReadyToAssign({
    amount,
    upcomingIncome,
    projected,
    uncategorizedCount,
    uncategorizedTotal,
}: {
    amount: number;
    upcomingIncome: number;
    projected: number;
    uncategorizedCount: number;
    uncategorizedTotal: number;
}) {
    const positive = amount >= 0;
    return (
        <div
            className={`mt-4 rounded-xl px-5 py-4 ring-1 ${
                positive ? 'bg-green-50 text-green-900 ring-green-100' : 'bg-red-50 text-red-900 ring-red-100'
            }`}
        >
            <div className="flex items-center justify-between">
                <span className="flex items-center gap-2 text-sm font-medium">
                    <span aria-hidden>{positive ? '💰' : '⚠️'}</span>
                    Klar til å fordele
                </span>
                <span className="text-2xl font-semibold tabular-nums">{formatNok(amount)}</span>
            </div>
            {upcomingIncome !== 0 && (
                <div className="mt-1 flex items-center justify-between text-xs opacity-80">
                    <span>Med kommende inntekt ({formatNok(upcomingIncome)})</span>
                    <span className="tabular-nums">{formatNok(projected)}</span>
                </div>
            )}
            {uncategorizedCount > 0 && (
                <div className="mt-2 flex items-center justify-between gap-3 border-t border-current/10 pt-2 text-xs opacity-80">
                    <Link to="/kontoer" className="underline hover:opacity-100">
                        {uncategorizedCount} transaksjon{uncategorizedCount === 1 ? '' : 'er'} mangler kategori
                        {' '}(påvirker ikke RTA)
                    </Link>
                    <span className="tabular-nums">{formatNok(uncategorizedTotal)}</span>
                </div>
            )}
        </div>
    );
}

function Group({
    group,
    allGroups,
    month,
    isPast,
    selected,
    onToggleCategory,
    onToggleGroup,
    onChange,
    reload,
}: {
    group: BudgetGroup;
    allGroups: BudgetGroup[];
    month: string;
    isPast: boolean;
    selected: Set<number>;
    onToggleCategory: (id: number) => void;
    onToggleGroup: (group: BudgetGroup) => void;
    onChange: (budget: BudgetMonth) => void;
    reload: () => void;
}) {
    const [adding, setAdding] = useState(false);
    const [name, setName] = useState('');

    const ids = group.categories.map((c) => c.id);
    const selCount = ids.filter((id) => selected.has(id)).length;
    const allSel = ids.length > 0 && selCount === ids.length;

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
        <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white">
            <div className="flex items-center gap-2 border-b border-neutral-200 bg-neutral-100 px-4 py-2.5">
                {!isPast && (
                    <TriCheckbox
                        checked={allSel}
                        indeterminate={selCount > 0 && !allSel}
                        onChange={() => onToggleGroup(group)}
                        ariaLabel={`Velg gruppen ${group.name}`}
                    />
                )}
                <InlineNameEdit
                    display={group.name}
                    initial={group.name}
                    placeholder="Gruppenavn"
                    onSave={async (name) => {
                        await updateCategoryGroup(group.id, name);
                        reload();
                    }}
                    className="min-w-0 flex-1 text-sm font-semibold text-neutral-800"
                />
                <span className="text-sm font-semibold tabular-nums text-neutral-600">
                    {formatNok(group.available)}
                </span>
            </div>

            {group.categories.map((category) => (
                <CategoryRow
                    key={category.id}
                    category={category}
                    allGroups={allGroups}
                    month={month}
                    isPast={isPast}
                    selected={selected.has(category.id)}
                    onToggle={() => onToggleCategory(category.id)}
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
            return `Mål: ha ${formatNok(goal.target_amount)} tilgjengelig hver måned`;
        case 'target_balance_by_date':
            return `Mål: spar ${formatNok(goal.target_amount)} innen ${
                goal.target_date ? monthLabel(goal.target_date.slice(0, 7)) : '—'
            }`;
    }
}

function availableBadge(category: BudgetCategory, isPast: boolean): string {
    // I fortiden farges kun faktisk overtrekk rødt; mål er ikke relevant der.
    if (isPast) {
        return category.available < 0 ? 'bg-red-50 text-red-700' : 'bg-neutral-100 text-neutral-600';
    }
    // Rød ved faktisk overtrekk, eller når kommende regninger vil overtrekke.
    if (category.available < 0 || category.projected_available < 0) return 'bg-red-50 text-red-700';
    if (category.goal && category.needed > 0) return 'bg-amber-50 text-amber-700';
    if (category.goal && category.needed === 0) return 'bg-green-50 text-green-700';
    return 'bg-neutral-100 text-neutral-600';
}

function CategoryRow({
    category,
    allGroups,
    month,
    isPast,
    selected,
    onToggle,
    onChange,
    reload,
}: {
    category: BudgetCategory;
    allGroups: BudgetGroup[];
    month: string;
    isPast: boolean;
    selected: boolean;
    onToggle: () => void;
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
        <div className="border-b border-neutral-100 last:border-0">
            <div className={`${ROW_GRID} px-4 py-1.5 ${selected ? 'bg-neutral-50' : 'hover:bg-neutral-50'}`}>
                {isPast ? (
                    <span />
                ) : (
                    <TriCheckbox checked={selected} onChange={onToggle} ariaLabel={`Velg ${category.name}`} />
                )}
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <InlineNameEdit
                            display={category.name}
                            initial={category.name}
                            placeholder="Kategorinavn"
                            onSave={async (name) => {
                                await updateCategory(category.id, name);
                                reload();
                            }}
                            className="min-w-0 text-sm"
                        />
                        {!isPast && (
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
                        )}
                    </div>
                    {!isPast && category.goal && (
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
                    {!isPast && category.upcoming !== 0 && (
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
                <div className="flex justify-end">
                    <button
                        type="button"
                        onClick={() => setShowMove(true)}
                        title="Flytt penger til en annen kategori"
                        className={`rounded-full px-2.5 py-0.5 text-sm font-medium tabular-nums hover:ring-2 hover:ring-neutral-200 ${availableBadge(category, isPast)}`}
                    >
                        {formatNok(category.available)}
                    </button>
                </div>
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

function SummaryChip({ label, value, tone = 'neutral' }: { label: string; value: number; tone?: 'neutral' | 'available' }) {
    const valueTone =
        tone === 'available'
            ? value < 0
                ? 'text-red-600'
                : 'text-green-700'
            : value < 0
              ? 'text-neutral-800'
              : 'text-green-700';
    return (
        <div className="flex-1 rounded-xl bg-neutral-50 px-3 py-2 ring-1 ring-neutral-100">
            <div className="text-[0.65rem] font-medium uppercase tracking-wide text-neutral-400">{label}</div>
            <div className={`text-sm font-semibold tabular-nums ${valueTone}`}>{formatNok(value)}</div>
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
            <div className="mb-5 flex gap-2">
                <SummaryChip label="Tildelt" value={category.assigned} />
                <SummaryChip label="Aktivitet" value={category.activity} />
                <SummaryChip label="Tilgjengelig" value={category.available} tone="available" />
            </div>

            {error && <p className="text-sm text-red-600">{error}</p>}
            {!error && !data && <p className="text-sm text-neutral-400">Laster …</p>}
            {data && (
                <div className="space-y-5">
                    <div>
                        <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            Transaksjoner
                            <span className="rounded-full bg-neutral-100 px-1.5 py-0.5 text-[0.65rem] font-medium tabular-nums text-neutral-500">
                                {data.transactions.length}
                            </span>
                        </h3>
                        {data.transactions.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-neutral-200 px-3 py-6 text-center text-sm text-neutral-400">
                                Ingen transaksjoner denne måneden.
                            </p>
                        ) : (
                            <ul className="divide-y divide-neutral-100">
                                {data.transactions.map((t) => (
                                    <li
                                        key={t.id}
                                        className="-mx-2 flex items-center justify-between gap-3 rounded-lg px-2 py-1.5 hover:bg-neutral-50"
                                    >
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
                        <div className="rounded-xl bg-blue-50/60 p-3 ring-1 ring-blue-100">
                            <h3 className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-blue-700/80">
                                Planlagte
                                <span className="rounded-full bg-blue-100 px-1.5 py-0.5 text-[0.65rem] font-medium tabular-nums text-blue-700">
                                    {data.scheduled.length}
                                </span>
                            </h3>
                            <ul className="divide-y divide-blue-100/70">
                                {data.scheduled.map((s) => (
                                    <li key={s.id} className="flex items-center justify-between gap-3 py-1.5">
                                        <div className="min-w-0">
                                            <div className="truncate text-sm">{s.payee || '—'}</div>
                                            <div className="text-xs text-neutral-500">
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

function categoryName(groups: BudgetGroup[], id: string): string | null {
    if (!id) {
        return null;
    }
    for (const group of groups) {
        const found = group.categories.find((c) => String(c.id) === id);
        if (found) {
            return found.name;
        }
    }
    return null;
}

/** Viser «fra → til»-flyten i flytte-modalene. */
function MoveFlow({
    fromLabel,
    fromAmount,
    toLabel,
}: {
    fromLabel: string;
    fromAmount: number;
    toLabel: string | null;
}) {
    return (
        <div className="flex items-stretch gap-2">
            <div className="min-w-0 flex-1 rounded-xl bg-neutral-50 px-3 py-2 ring-1 ring-neutral-100">
                <div className="text-[0.65rem] font-medium uppercase tracking-wide text-neutral-400">Fra</div>
                <div className="truncate text-sm font-medium text-neutral-800">{fromLabel}</div>
                <div className="text-xs tabular-nums text-neutral-400">{formatNok(fromAmount)} tilgjengelig</div>
            </div>
            <div className="flex items-center text-neutral-300" aria-hidden>
                →
            </div>
            <div className="min-w-0 flex-1 rounded-xl bg-neutral-50 px-3 py-2 ring-1 ring-neutral-100">
                <div className="text-[0.65rem] font-medium uppercase tracking-wide text-neutral-400">Til</div>
                <div className={`truncate text-sm font-medium ${toLabel ? 'text-neutral-800' : 'text-neutral-300'}`}>
                    {toLabel ?? 'Velg kategori …'}
                </div>
            </div>
        </div>
    );
}

function MoveFooterButtons({ busy, onClose }: { busy: boolean; onClose: () => void }) {
    return (
        <>
            <button
                type="button"
                onClick={onClose}
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
            >
                Avbryt
            </button>
            <button
                type="submit"
                form="move-form"
                disabled={busy}
                className="rounded-lg bg-neutral-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
                Flytt
            </button>
        </>
    );
}

function NewGroupModal({
    onCreated,
    onClose,
}: {
    onCreated: () => void;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const trimmed = name.trim();
        if (!trimmed) {
            setError('Skriv inn et navn.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            await createCategoryGroup(trimmed);
            onCreated();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke opprette kategorigruppe.'));
            setBusy(false);
        }
    }

    return (
        <Modal
            title="Ny kategorigruppe"
            size="sm"
            onClose={onClose}
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
                    >
                        Avbryt
                    </button>
                    <button
                        type="submit"
                        form="new-group-form"
                        disabled={busy}
                        className="rounded-lg bg-neutral-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                    >
                        Opprett
                    </button>
                </>
            }
        >
            <form id="new-group-form" onSubmit={submit} className="space-y-4">
                <label className="block text-xs font-medium text-neutral-600">
                    Navn
                    <input
                        type="text"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        autoFocus
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm focus:border-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                    />
                </label>
                {error && <p className="text-sm text-red-600">{error}</p>}
            </form>
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
        <Modal
            title={`Flytt fra ${category.name}`}
            size="md"
            onClose={onClose}
            footer={max > 0 ? <MoveFooterButtons busy={busy} onClose={onClose} /> : undefined}
        >
            {max <= 0 ? (
                <p className="text-sm text-neutral-500">
                    Det er ingen tilgjengelige penger å flytte fra denne kategorien.
                </p>
            ) : (
                <form id="move-form" onSubmit={submit} className="space-y-4">
                    <MoveFlow
                        fromLabel={category.name}
                        fromAmount={max}
                        toLabel={categoryName(allGroups, target)}
                    />
                    <label className="block text-xs font-medium text-neutral-600">
                        Beløp
                        <div className="relative mt-1 w-40">
                            <span className="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5 text-sm text-neutral-400">
                                kr
                            </span>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                max={max}
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                autoFocus
                                className="block w-full rounded-lg border border-neutral-300 py-1.5 pl-8 pr-14 text-right text-sm tabular-nums focus:border-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                            />
                            <button
                                type="button"
                                onClick={() => setAmount(String(max))}
                                className="absolute inset-y-0 right-0 my-1 mr-1 rounded px-1.5 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                            >
                                Maks
                            </button>
                        </div>
                    </label>
                    <label className="block text-xs font-medium text-neutral-600">
                        Til kategori
                        <CategorySelect
                            groups={allGroups}
                            value={target}
                            onChange={setTarget}
                            exclude={new Set([category.id])}
                        />
                    </label>

                    {error && <p className="text-sm text-red-600">{error}</p>}
                </form>
            )}
        </Modal>
    );
}

function BulkMoveModal({
    sources,
    allGroups,
    month,
    onMoved,
    onClose,
}: {
    sources: BudgetCategory[];
    allGroups: BudgetGroup[];
    month: string;
    onMoved: (budget: BudgetMonth) => void;
    onClose: () => void;
}) {
    const contributing = sources.filter((c) => c.available > 0);
    const total = contributing.reduce((s, c) => s + c.available, 0);
    const [target, setTarget] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const sourceIds = new Set(sources.map((s) => s.id));

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!target) {
            setError('Velg en målkategori.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            onMoved(await sweepBudget(month, Array.from(sourceIds), Number(target)));
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke flytte penger.'));
            setBusy(false);
        }
    }

    return (
        <Modal
            title={`Flytt fra ${sources.length} kategorier`}
            size="md"
            onClose={onClose}
            footer={total > 0 ? <MoveFooterButtons busy={busy} onClose={onClose} /> : undefined}
        >
            {total <= 0 ? (
                <p className="text-sm text-neutral-500">
                    Ingen av de valgte kategoriene har tilgjengelige penger å flytte.
                </p>
            ) : (
                <form id="move-form" onSubmit={submit} className="space-y-4">
                    <MoveFlow
                        fromLabel={`${contributing.length} kategorier`}
                        fromAmount={total}
                        toLabel={categoryName(allGroups, target)}
                    />
                    <div>
                        <div className="mb-1 text-xs font-medium text-neutral-600">Flyttes fra</div>
                        <ul className="max-h-40 divide-y divide-neutral-100 overflow-y-auto rounded-lg ring-1 ring-neutral-100">
                            {contributing.map((c) => (
                                <li
                                    key={c.id}
                                    className="flex items-center justify-between gap-3 px-3 py-1.5 text-sm"
                                >
                                    <span className="truncate text-neutral-700">{c.name}</span>
                                    <span className="shrink-0 tabular-nums text-neutral-500">
                                        {formatNok(c.available)}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {contributing.length < sources.length && (
                            <p className="mt-1 text-xs text-neutral-400">
                                {sources.length - contributing.length} valgt
                                {sources.length - contributing.length === 1 ? ' kategori' : 'e kategorier'} uten
                                tilgjengelig hoppes over.
                            </p>
                        )}
                    </div>
                    <label className="block text-xs font-medium text-neutral-600">
                        Til kategori
                        <CategorySelect
                            groups={allGroups}
                            value={target}
                            onChange={setTarget}
                            exclude={sourceIds}
                        />
                    </label>

                    {error && <p className="text-sm text-red-600">{error}</p>}
                </form>
            )}
        </Modal>
    );
}

function CategorySelect({
    groups,
    value,
    onChange,
    exclude,
}: {
    groups: BudgetGroup[];
    value: string;
    onChange: (value: string) => void;
    exclude: Set<number>;
}) {
    return (
        <select
            value={value}
            onChange={(e) => onChange(e.target.value)}
            className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
        >
            <option value="">Velg …</option>
            {groups.map((group) => {
                const options = group.categories.filter((c) => !exclude.has(c.id));
                if (options.length === 0) return null;
                return (
                    <optgroup key={group.id} label={group.name}>
                        {options.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </optgroup>
                );
            })}
        </select>
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

/**
 * Evaluerer et enkelt regneuttrykk (+ − * / og parenteser) så tildelt-feltet kan
 * brukes som kalkulator, f.eks. «500+200». Komma tolkes som desimaltegn.
 * Returnerer null ved ugyldig uttrykk (ingen `eval` – egen rekursiv parser).
 */
function evalExpression(input: string): number | null {
    const s = input.replace(/,/g, '.').replace(/\s+/g, '');
    if (s === '') {
        return null;
    }
    let pos = 0;

    const parseNumber = (): number | null => {
        const start = pos;
        while (pos < s.length && /[0-9.]/.test(s[pos])) {
            pos++;
        }
        if (pos === start) {
            return null;
        }
        const n = Number(s.slice(start, pos));
        return Number.isFinite(n) ? n : null;
    };

    const parseFactor = (): number | null => {
        if (s[pos] === '+' || s[pos] === '-') {
            const op = s[pos++];
            const v = parseFactor();
            return v === null ? null : op === '-' ? -v : v;
        }
        if (s[pos] === '(') {
            pos++;
            const v = parseExpr();
            if (v === null || s[pos] !== ')') {
                return null;
            }
            pos++;
            return v;
        }
        return parseNumber();
    };

    const parseTerm = (): number | null => {
        let value = parseFactor();
        if (value === null) {
            return null;
        }
        while (s[pos] === '*' || s[pos] === '/') {
            const op = s[pos++];
            const rhs = parseFactor();
            if (rhs === null) {
                return null;
            }
            value = op === '*' ? value * rhs : value / rhs;
        }
        return value;
    };

    function parseExpr(): number | null {
        let value = parseTerm();
        if (value === null) {
            return null;
        }
        while (s[pos] === '+' || s[pos] === '-') {
            const op = s[pos++];
            const rhs = parseTerm();
            if (rhs === null) {
                return null;
            }
            value = op === '+' ? value + rhs : value - rhs;
        }
        return value;
    }

    const result = parseExpr();
    if (result === null || pos !== s.length || !Number.isFinite(result)) {
        return null;
    }
    return Math.round(result * 100) / 100;
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
    // Desimaltegn vises som komma (som øvrige tallkolonner); evalExpression tolker komma.
    const display = (n: number) => String(n).replace('.', ',');
    const [value, setValue] = useState(display(category.assigned));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(false);

    // Synk når data lastes på nytt (f.eks. ved månedsbytte eller etter lagring).
    useEffect(() => {
        setValue(display(category.assigned));
    }, [category.assigned, month]);

    async function commit() {
        const amount = evalExpression(value);
        if (amount === null) {
            setError(true);
            setValue(display(category.assigned));
            return;
        }
        if (amount === category.assigned) {
            setValue(display(amount));
            setError(false);
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
            type="text"
            inputMode="text"
            value={value}
            disabled={saving}
            title="Du kan skrive et regnestykke, f.eks. 500+200"
            onChange={(e) => {
                setValue(e.target.value);
                if (error) {
                    setError(false);
                }
            }}
            onFocus={(e) => e.currentTarget.select()}
            onBlur={commit}
            onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
            className={`w-full rounded-lg border px-2 py-1 text-right text-sm tabular-nums focus:outline-none ${
                error ? 'border-red-500' : 'border-transparent hover:border-neutral-300 focus:border-neutral-900'
            }`}
        />
    );
}
