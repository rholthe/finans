import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { useSearchParams } from 'react-router-dom';
import Layout from '@/components/Layout';
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

export default function Planlagte() {
    const [items, setItems] = useState<ScheduledTransaction[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<ScheduledTransaction | 'new' | null>(null);
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

    async function remove(item: ScheduledTransaction) {
        if (!confirm('Slette denne planlagte transaksjonen?')) return;
        await deleteScheduledTransaction(item.id);
        reload();
    }

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Planlagte transaksjoner</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Regninger og inntekter som gjentar seg. Posteres automatisk når datoen passeres.
                    </p>
                </div>
                <button
                    onClick={() => setEditing((e) => (e === 'new' ? null : 'new'))}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    {editing === 'new' ? 'Avbryt' : 'Ny'}
                </button>
            </div>

            {editing === 'new' && accounts.length > 0 && (
                <ScheduledForm
                    accounts={accounts}
                    groups={groups}
                    onSaved={() => {
                        setEditing(null);
                        reload();
                    }}
                />
            )}
            {editing === 'new' && accounts.length === 0 && (
                <p className="mt-4 text-sm text-amber-600">Opprett en konto først.</p>
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
                <p className="mt-8 text-neutral-500">Ingen planlagte transaksjoner ennå.</p>
            ) : filtered.length === 0 ? (
                <p className="mt-8 text-neutral-500">Ingen planlagte transaksjoner som matcher filteret.</p>
            ) : (
                <div className="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                    <table className="w-full text-sm">
                        <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                            <tr>
                                <th className="px-4 py-2 font-medium">Mottaker</th>
                                <th className="px-4 py-2 font-medium">Frekvens</th>
                                <th className="px-4 py-2 font-medium">Neste</th>
                                <th className="px-4 py-2 font-medium">Konto</th>
                                <th className="px-4 py-2 font-medium">Kategori</th>
                                <th className="px-4 py-2 text-right font-medium">Beløp</th>
                                <th className="px-4 py-2"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100">
                            {filtered.map((item) => (
                                <tr key={item.id} className="hover:bg-neutral-50">
                                    <td className="px-4 py-2">{item.payee ?? '—'}</td>
                                    <td className="px-4 py-2 text-neutral-600">
                                        {FREQUENCY_LABELS[item.frequency]}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2 text-neutral-600">
                                        {formatDate(item.next_date)}
                                    </td>
                                    <td className="px-4 py-2 text-neutral-600">
                                        {accountName.get(item.account_id) ?? '—'}
                                    </td>
                                    <td className="px-4 py-2 text-neutral-500">
                                        {item.category_id ? categoryName.get(item.category_id) ?? '—' : '—'}
                                    </td>
                                    <td
                                        className={`whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums ${
                                            item.amount < 0 ? 'text-red-600' : 'text-green-700'
                                        }`}
                                    >
                                        {formatNok(item.amount)}
                                    </td>
                                    <td className="whitespace-nowrap px-4 py-2 text-right">
                                        <button
                                            onClick={() => setEditing(item)}
                                            className="text-xs text-neutral-400 hover:text-neutral-900"
                                        >
                                            Rediger
                                        </button>
                                        <button
                                            onClick={() => remove(item)}
                                            className="ml-3 text-xs text-neutral-400 hover:text-red-600"
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

            {editing && editing !== 'new' && (
                <ScheduledForm
                    accounts={accounts}
                    groups={groups}
                    existing={editing}
                    onSaved={() => {
                        setEditing(null);
                        reload();
                    }}
                    onCancel={() => setEditing(null)}
                />
            )}
        </Layout>
    );
}

function ScheduledForm({
    accounts,
    groups,
    existing,
    onSaved,
    onCancel,
}: {
    accounts: Account[];
    groups: CategoryGroup[];
    existing?: ScheduledTransaction;
    onSaved: () => void;
    onCancel?: () => void;
}) {
    const [accountId, setAccountId] = useState(String(existing?.account_id ?? accounts[0]?.id ?? ''));
    const [categoryId, setCategoryId] = useState(String(existing?.category_id ?? ''));
    const [direction, setDirection] = useState<'out' | 'in'>(
        existing && existing.amount > 0 ? 'in' : 'out',
    );
    const [amount, setAmount] = useState(existing ? String(Math.abs(existing.amount)) : '');
    const [payee, setPayee] = useState(existing?.payee ?? '');
    const [frequency, setFrequency] = useState<ScheduleFrequency>(existing?.frequency ?? 'monthly');
    const [startDate, setStartDate] = useState(existing?.start_date ?? todayIso());
    const [endDate, setEndDate] = useState(existing?.end_date ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const magnitude = Math.abs(Number(amount));
        if (!magnitude) {
            setError('Oppgi et beløp.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: NewScheduledTransaction = {
            account_id: Number(accountId),
            category_id: categoryId ? Number(categoryId) : null,
            amount: direction === 'out' ? -magnitude : magnitude,
            payee: payee || null,
            frequency,
            start_date: startDate,
            end_date: endDate || null,
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
        <form
            onSubmit={submit}
            className="mt-6 grid gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:grid-cols-2"
        >
            <label className="text-sm font-medium text-neutral-700">
                Mottaker / beskrivelse
                <input
                    value={payee}
                    onChange={(e) => setPayee(e.target.value)}
                    autoFocus
                    placeholder="f.eks. Husleie"
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Konto
                <select
                    value={accountId}
                    onChange={(e) => setAccountId(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    {accounts.map((account) => (
                        <option key={account.id} value={account.id}>
                            {account.name}
                        </option>
                    ))}
                </select>
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Kategori
                <select
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    <option value="">Ingen (inntekt / ufordelt)</option>
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

            <div className="flex gap-3">
                <div className="text-sm font-medium text-neutral-700">
                    Retning
                    <div className="mt-1 flex overflow-hidden rounded-lg border border-neutral-300">
                        <button
                            type="button"
                            onClick={() => setDirection('out')}
                            className={`px-3 py-2 ${direction === 'out' ? 'bg-red-600 text-white' : 'bg-white'}`}
                        >
                            Ut
                        </button>
                        <button
                            type="button"
                            onClick={() => setDirection('in')}
                            className={`px-3 py-2 ${direction === 'in' ? 'bg-green-600 text-white' : 'bg-white'}`}
                        >
                            Inn
                        </button>
                    </div>
                </div>
                <label className="flex-1 text-sm font-medium text-neutral-700">
                    Beløp
                    <input
                        type="number"
                        step="0.01"
                        min="0"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-2 text-right focus:border-neutral-900 focus:outline-none"
                    />
                </label>
            </div>

            <label className="text-sm font-medium text-neutral-700">
                Frekvens
                <select
                    value={frequency}
                    onChange={(e) => setFrequency(e.target.value as ScheduleFrequency)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    {(Object.keys(FREQUENCY_LABELS) as ScheduleFrequency[]).map((value) => (
                        <option key={value} value={value}>
                            {FREQUENCY_LABELS[value]}
                        </option>
                    ))}
                </select>
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Første dato
                <input
                    type="date"
                    value={startDate}
                    onChange={(e) => setStartDate(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Sluttdato (valgfritt)
                <input
                    type="date"
                    value={endDate}
                    onChange={(e) => setEndDate(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <div className="flex items-center gap-3 sm:col-span-2">
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                >
                    {busy ? 'Lagrer …' : 'Lagre'}
                </button>
                {onCancel && (
                    <button
                        type="button"
                        onClick={onCancel}
                        className="rounded-lg px-4 py-2 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
                    >
                        Avbryt
                    </button>
                )}
                {error && <p className="text-sm text-red-600">{error}</p>}
            </div>
        </form>
    );
}
