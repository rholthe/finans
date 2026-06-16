import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import {
    apiErrorMessage,
    createScheduledTransaction,
    deleteScheduledTransaction,
    listAccounts,
    listCategoryGroups,
    listScheduledTransactions,
    updateScheduledTransaction,
    type NewScheduledTransaction,
} from '@/lib/data';
import { formatDate, formatNok, todayIso } from '@/lib/format';
import {
    FREQUENCY_LABELS,
    type Account,
    type CategoryGroup,
    type ScheduledTransaction,
    type ScheduleFrequency,
} from '@/types';

// Felles feltstil, i tråd med øvrige skjemaer (fokusring som kontolisten).
const FIELD =
    'mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-200';

function payeeLabel(item: ScheduledTransaction): string {
    return item.transfer_account_id ? '⇄ Overføring' : (item.payee ?? '—');
}

export default function Planlagte() {
    const [items, setItems] = useState<ScheduledTransaction[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<ScheduledTransaction | 'new' | null>(null);
    const [deleting, setDeleting] = useState<ScheduledTransaction | null>(null);
    const [searchParams, setSearchParams] = useSearchParams();

    const accountFilter = searchParams.get('account') ?? '';
    const categoryFilter = searchParams.get('category') ?? '';

    function setFilter(key: 'account' | 'category', value: string) {
        setSearchParams(
            (prev) => {
                const next = new URLSearchParams(prev);
                if (value) {
                    next.set(key, value);
                } else {
                    next.delete(key);
                }
                return next;
            },
            { replace: true },
        );
    }

    function reload() {
        return listScheduledTransactions().then(setItems);
    }

    useEffect(() => {
        Promise.all([reload(), listAccounts().then(setAccounts), listCategoryGroups().then(setGroups)])
            .finally(() => setLoading(false));
    }, []);

    const accountName = useMemo(
        () => new Map(accounts.map((a) => [a.id, a.name])),
        [accounts],
    );
    const categoryName = useMemo(
        () => new Map(groups.flatMap((g) => g.categories.map((c) => [c.id, c.name] as const))),
        [groups],
    );

    const filtered = useMemo(
        () =>
            items.filter(
                (item) =>
                    (!accountFilter || item.account_id === Number(accountFilter)) &&
                    (!categoryFilter ||
                        (categoryFilter === 'none'
                            ? item.category_id === null
                            : item.category_id === Number(categoryFilter))),
            ),
        [items, accountFilter, categoryFilter],
    );

    const nextUp = useMemo(
        () => [...items].sort((a, b) => a.next_date.localeCompare(b.next_date))[0] ?? null,
        [items],
    );

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Planlagte transaksjoner</h1>
                <button
                    onClick={() => setEditing((e) => (e === 'new' ? null : 'new'))}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    + Ny
                </button>
            </div>

            {!loading && items.length > 0 && (
                <div className="mt-4 grid gap-4 rounded-2xl bg-gradient-to-br from-violet-50 via-white to-sky-50 p-6 shadow-sm ring-1 ring-neutral-200 sm:grid-cols-2">
                    <div>
                        <div className="flex items-center gap-2 text-sm font-medium text-neutral-500">
                            <span aria-hidden>🗓️</span> Aktive planlagte
                        </div>
                        <div className="mt-0.5 text-3xl font-semibold tabular-nums text-neutral-900">
                            {items.length}
                        </div>
                        <p className="mt-1 text-xs text-neutral-400">
                            Regninger og inntekter som gjentar seg. Posteres automatisk når datoen passeres.
                        </p>
                    </div>
                    {nextUp && (
                        <div className="sm:text-right">
                            <div className="text-sm font-medium text-neutral-500">Neste forfall</div>
                            <div className="mt-0.5 text-sm font-medium text-neutral-800">
                                {formatDate(nextUp.next_date)} · {payeeLabel(nextUp)}
                            </div>
                            <div
                                className={`text-sm font-semibold tabular-nums ${
                                    nextUp.amount < 0 ? 'text-red-600' : 'text-green-700'
                                }`}
                            >
                                {formatNok(nextUp.amount)}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {items.length > 0 && (
                <div className="mt-6 flex flex-wrap items-center gap-3">
                    <select
                        value={accountFilter}
                        onChange={(e) => setFilter('account', e.target.value)}
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Alle kontoer</option>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </select>
                    <select
                        value={categoryFilter}
                        onChange={(e) => setFilter('category', e.target.value)}
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Alle kategorier</option>
                        <option value="none">Uten kategori</option>
                        {groups.map((group) => (
                            <optgroup key={group.id} label={group.name}>
                                {group.categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </optgroup>
                        ))}
                    </select>
                    {(accountFilter || categoryFilter) && (
                        <button
                            onClick={() => setSearchParams({}, { replace: true })}
                            className="text-sm font-medium text-neutral-500 hover:text-neutral-900"
                        >
                            Nullstill filter
                        </button>
                    )}
                </div>
            )}

            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : items.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen planlagte transaksjoner ennå.
                </div>
            ) : filtered.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen planlagte transaksjoner som matcher filteret.
                </div>
            ) : (
                <div className="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                    <table className="w-full text-sm">
                        <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th className="px-4 py-2.5 font-medium">Mottaker</th>
                                <th className="px-4 py-2.5 font-medium">Frekvens</th>
                                <th className="px-4 py-2.5 font-medium">Neste</th>
                                <th className="px-4 py-2.5 font-medium">Konto</th>
                                <th className="px-4 py-2.5 font-medium">Kategori</th>
                                <th className="px-4 py-2.5 text-right font-medium">Beløp</th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100">
                            {filtered.map((item) => (
                                <tr key={item.id} className="group hover:bg-neutral-50">
                                    <td className="px-4 py-2.5">
                                        <span className="flex items-center gap-2">
                                            <DirectionIcon item={item} />
                                            <span className="font-medium text-neutral-800">
                                                {payeeLabel(item)}
                                            </span>
                                        </span>
                                    </td>
                                    <td className="px-4 py-2.5">
                                        <span className="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700">
                                            {FREQUENCY_LABELS[item.frequency]}
                                        </span>
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-neutral-600">
                                        {formatDate(item.next_date)}
                                    </td>
                                    <td className="px-4 py-2.5 text-neutral-600">
                                        {accountName.get(item.account_id) ?? '—'}
                                        {item.transfer_account_id &&
                                            ` → ${accountName.get(item.transfer_account_id) ?? '—'}`}
                                    </td>
                                    <td className="px-4 py-2.5 text-neutral-500">
                                        {item.category_id ? categoryName.get(item.category_id) ?? '—' : '—'}
                                    </td>
                                    <td
                                        className={`whitespace-nowrap px-4 py-2.5 text-right font-semibold tabular-nums ${
                                            item.amount < 0 ? 'text-red-600' : 'text-green-700'
                                        }`}
                                    >
                                        {formatNok(item.amount)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2.5 text-right">
                                        <button
                                            onClick={() => setEditing(item)}
                                            className="rounded px-2 py-1 text-xs font-medium text-neutral-500 hover:bg-neutral-100 hover:text-neutral-900"
                                        >
                                            Rediger
                                        </button>
                                        <button
                                            onClick={() => setDeleting(item)}
                                            className="ml-1 rounded px-2 py-1 text-xs font-medium text-neutral-400 hover:bg-red-50 hover:text-red-600"
                                        >
                                            Slett
                                        </button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {editing && accounts.length === 0 ? (
                <Modal title="Ny planlagt" size="sm" onClose={() => setEditing(null)}>
                    <p className="text-sm text-amber-600">Opprett en konto først.</p>
                </Modal>
            ) : (
                editing && (
                    <ScheduledForm
                        accounts={accounts}
                        groups={groups}
                        existing={editing === 'new' ? undefined : editing}
                        onSaved={() => {
                            setEditing(null);
                            reload();
                        }}
                        onClose={() => setEditing(null)}
                    />
                )
            )}

            {deleting && (
                <DeleteModal
                    item={deleting}
                    onClose={() => setDeleting(null)}
                    onDeleted={() => {
                        setDeleting(null);
                        reload();
                    }}
                />
            )}
        </Layout>
    );
}

function DirectionIcon({ item }: { item: ScheduledTransaction }) {
    const [bg, text, symbol] = item.transfer_account_id
        ? ['bg-violet-100', 'text-violet-700', '⇄']
        : item.amount < 0
          ? ['bg-red-100', 'text-red-600', '↓']
          : ['bg-green-100', 'text-green-700', '↑'];
    return (
        <span
            className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs ${bg} ${text}`}
            aria-hidden
        >
            {symbol}
        </span>
    );
}

function DeleteModal({
    item,
    onClose,
    onDeleted,
}: {
    item: ScheduledTransaction;
    onClose: () => void;
    onDeleted: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function confirm() {
        setBusy(true);
        setError(null);
        try {
            await deleteScheduledTransaction(item.id);
            onDeleted();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke slette.'));
            setBusy(false);
        }
    }

    return (
        <Modal
            title="Slett planlagt"
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
                        type="button"
                        onClick={confirm}
                        disabled={busy}
                        className="rounded-lg bg-red-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        Slett
                    </button>
                </>
            }
        >
            <div className="flex gap-3">
                <span
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600"
                    aria-hidden
                >
                    🗑️
                </span>
                <div className="text-sm text-neutral-600">
                    <p>
                        Slette <span className="font-medium text-neutral-900">«{payeeLabel(item)}»</span>?
                    </p>
                    <p className="mt-1 text-neutral-500">
                        Den planlagte transaksjonen fjernes. Tidligere posteringer beholdes.
                    </p>
                    {error && <p className="mt-2 text-red-600">{error}</p>}
                </div>
            </div>
        </Modal>
    );
}

function ScheduledForm({
    accounts,
    groups,
    existing,
    onSaved,
    onClose,
}: {
    accounts: Account[];
    groups: CategoryGroup[];
    existing?: ScheduledTransaction;
    onSaved: () => void;
    onClose: () => void;
}) {
    const [type, setType] = useState<'transaction' | 'transfer'>(
        existing?.transfer_account_id ? 'transfer' : 'transaction',
    );
    const [accountId, setAccountId] = useState(String(existing?.account_id ?? accounts[0]?.id ?? ''));
    const [transferAccountId, setTransferAccountId] = useState(String(existing?.transfer_account_id ?? ''));
    // 'rta' = Klar til å fordele, '' = ukategorisert, ellers kategori-id.
    const [categoryId, setCategoryId] = useState(existing?.rta ? 'rta' : String(existing?.category_id ?? ''));
    const [direction, setDirection] = useState<'out' | 'in'>(
        existing && existing.amount > 0 ? 'in' : 'out',
    );
    const [amount, setAmount] = useState(existing ? String(Math.abs(existing.amount)) : '');
    const [payee, setPayee] = useState(existing?.payee ?? '');
    const [frequency, setFrequency] = useState<ScheduleFrequency>(existing?.frequency ?? 'monthly');
    const [startDate, setStartDate] = useState(existing?.start_date ?? todayIso());
    const [nextDate, setNextDate] = useState(existing?.next_date ?? todayIso());
    const [endDate, setEndDate] = useState(existing?.end_date ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const isTransfer = type === 'transfer';
    // Overføring ut av budsjettet til en overvåket konto krever kategori.
    const fromAcc = accounts.find((a) => a.id === Number(accountId));
    const toAcc = accounts.find((a) => a.id === Number(transferAccountId));
    const needsCategory = isTransfer && !!fromAcc?.on_budget && !!toAcc && !toAcc.on_budget;

    async function submit(e: FormEvent) {
        e.preventDefault();
        const magnitude = Math.abs(Number(amount));
        if (!magnitude) {
            setError('Oppgi et beløp.');
            return;
        }
        if (isTransfer && !transferAccountId) {
            setError('Velg en mottakerkonto.');
            return;
        }
        if (needsCategory && !categoryId) {
            setError('Overføring ut av budsjettet krever en kategori.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: NewScheduledTransaction = isTransfer
            ? {
                  account_id: Number(accountId),
                  transfer_account_id: Number(transferAccountId),
                  category_id: needsCategory ? Number(categoryId) : null,
                  amount: magnitude, // backend signerer fra fra-kontoens ståsted
                  payee: null,
                  frequency,
                  start_date: startDate,
                  end_date: endDate || null,
                  ...(existing ? { next_date: nextDate } : {}),
              }
            : {
                  account_id: Number(accountId),
                  transfer_account_id: null,
                  category_id: categoryId === 'rta' || !categoryId ? null : Number(categoryId),
                  rta: categoryId === 'rta',
                  amount: direction === 'out' ? -magnitude : magnitude,
                  payee: payee || null,
                  frequency,
                  start_date: startDate,
                  end_date: endDate || null,
                  // Ved redigering flytter vi neste forfall; startdatoen (ankeret) er uendret.
                  ...(existing ? { next_date: nextDate } : {}),
              };
        try {
            if (existing) {
                await updateScheduledTransaction(existing.id, payload);
            } else {
                await createScheduledTransaction(payload);
            }
            onSaved();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal
            title={existing ? 'Rediger planlagt' : 'Ny planlagt'}
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
                        form="scheduled-form"
                        disabled={busy}
                        className="rounded-lg bg-neutral-900 px-4 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                    >
                        {busy ? 'Lagrer …' : 'Lagre'}
                    </button>
                </>
            }
        >
            <form id="scheduled-form" onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
                <div className="flex rounded-lg bg-neutral-100 p-1 sm:col-span-2">
                    {(['transaction', 'transfer'] as const).map((t) => (
                        <button
                            key={t}
                            type="button"
                            onClick={() => setType(t)}
                            className={`flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition ${
                                type === t
                                    ? 'bg-white text-neutral-900 shadow-sm'
                                    : 'text-neutral-500 hover:text-neutral-800'
                            }`}
                        >
                            {t === 'transaction' ? 'Transaksjon' : 'Overføring'}
                        </button>
                    ))}
                </div>

                {!isTransfer && (
                    <label className="text-sm font-medium text-neutral-700 sm:col-span-2">
                        Mottaker / beskrivelse
                        <input
                            value={payee}
                            onChange={(e) => setPayee(e.target.value)}
                            autoFocus
                            placeholder="f.eks. Husleie"
                            className={FIELD}
                        />
                    </label>
                )}

                <label className="text-sm font-medium text-neutral-700">
                    {isTransfer ? 'Fra konto' : 'Konto'}
                    <select value={accountId} onChange={(e) => setAccountId(e.target.value)} className={FIELD}>
                        {accounts.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </select>
                </label>

                {isTransfer && (
                    <label className="text-sm font-medium text-neutral-700">
                        Til konto
                        <select
                            value={transferAccountId}
                            onChange={(e) => setTransferAccountId(e.target.value)}
                            className={FIELD}
                        >
                            <option value="">Velg konto …</option>
                            {accounts
                                .filter((a) => a.id !== Number(accountId))
                                .map((account) => (
                                    <option key={account.id} value={account.id}>
                                        {account.name}
                                    </option>
                                ))}
                        </select>
                    </label>
                )}

                {(!isTransfer || needsCategory) && (
                    <label className="text-sm font-medium text-neutral-700">
                        Kategori
                        <select
                            value={categoryId}
                            onChange={(e) => setCategoryId(e.target.value)}
                            className={FIELD}
                        >
                            {!isTransfer && <option value="">Ukategorisert</option>}
                            {!isTransfer && <option value="rta">Klar til å fordele (RTA)</option>}
                            {isTransfer && <option value="">Velg kategori …</option>}
                            {groups.map((group) => (
                                <optgroup key={group.id} label={group.name}>
                                    {group.categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </select>
                    </label>
                )}

                <div className="flex items-end gap-3">
                    {!isTransfer && (
                        <div className="text-sm font-medium text-neutral-700">
                            Retning
                            <div className="mt-1 flex overflow-hidden rounded-lg border border-neutral-300">
                                <button
                                    type="button"
                                    onClick={() => setDirection('out')}
                                    className={`px-3 py-2 text-sm ${direction === 'out' ? 'bg-red-600 text-white' : 'bg-white text-neutral-600'}`}
                                >
                                    Ut
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setDirection('in')}
                                    className={`px-3 py-2 text-sm ${direction === 'in' ? 'bg-green-600 text-white' : 'bg-white text-neutral-600'}`}
                                >
                                    Inn
                                </button>
                            </div>
                        </div>
                    )}
                    <label className="flex-1 text-sm font-medium text-neutral-700">
                        Beløp
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            value={amount}
                            onChange={(e) => setAmount(e.target.value)}
                            className={`${FIELD} text-right tabular-nums`}
                        />
                    </label>
                </div>

                <label className="text-sm font-medium text-neutral-700">
                    Frekvens
                    <select
                        value={frequency}
                        onChange={(e) => setFrequency(e.target.value as ScheduleFrequency)}
                        className={FIELD}
                    >
                        {(Object.keys(FREQUENCY_LABELS) as ScheduleFrequency[]).map((value) => (
                            <option key={value} value={value}>
                                {FREQUENCY_LABELS[value]}
                            </option>
                        ))}
                    </select>
                </label>

                {existing ? (
                    <label className="text-sm font-medium text-neutral-700">
                        Neste forfall
                        <input
                            type="date"
                            value={nextDate}
                            min={todayIso()}
                            onChange={(e) => setNextDate(e.target.value)}
                            className={FIELD}
                        />
                        <span className="mt-1 block text-xs font-normal text-neutral-400">
                            Flytter neste forekomst. Tidligere posteringer beholdes.
                        </span>
                    </label>
                ) : (
                    <label className="text-sm font-medium text-neutral-700">
                        Første dato
                        <input
                            type="date"
                            value={startDate}
                            onChange={(e) => setStartDate(e.target.value)}
                            className={FIELD}
                        />
                    </label>
                )}

                <label className="text-sm font-medium text-neutral-700">
                    Sluttdato (valgfritt)
                    <input
                        type="date"
                        value={endDate}
                        onChange={(e) => setEndDate(e.target.value)}
                        className={FIELD}
                    />
                </label>

                {error && <p className="text-sm text-red-600 sm:col-span-2">{error}</p>}
            </form>
        </Modal>
    );
}
