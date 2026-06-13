import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import Layout from '@/components/Layout';
import {
    createTransaction,
    deleteTransaction,
    getAccount,
    listCategoryGroups,
    listTransactions,
    updateTransaction,
    type NewTransaction,
} from '@/lib/data';
import { formatDate, formatNok, todayIso } from '@/lib/format';
import { ACCOUNT_TYPE_LABELS, type Account, type CategoryGroup, type Transaction } from '@/types';

export default function AccountDetail() {
    const { id } = useParams();
    const accountId = Number(id);
    const [account, setAccount] = useState<Account | null>(null);
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [loading, setLoading] = useState(true);

    const reload = useCallback(async () => {
        const [acc, txs] = await Promise.all([
            getAccount(accountId),
            listTransactions(accountId),
        ]);
        setAccount(acc);
        setTransactions(txs);
    }, [accountId]);

    useEffect(() => {
        reload().finally(() => setLoading(false));
    }, [reload]);

    async function toggleCleared(tx: Transaction) {
        await updateTransaction(tx.id, { cleared: !tx.cleared });
        reload();
    }

    async function remove(tx: Transaction) {
        if (!confirm('Slette denne transaksjonen?')) return;
        await deleteTransaction(tx.id);
        reload();
    }

    if (loading) {
        return (
            <Layout>
                <p className="text-neutral-400">Laster …</p>
            </Layout>
        );
    }

    if (!account) {
        return (
            <Layout>
                <p className="text-neutral-500">Konto ikke funnet.</p>
                <Link to="/kontoer" className="text-sm text-neutral-900 underline">
                    Tilbake
                </Link>
            </Layout>
        );
    }

    return (
        <Layout>
            <Link to="/kontoer" className="text-sm text-neutral-500 hover:text-neutral-900">
                ← Alle kontoer
            </Link>

            <div className="mt-2 flex items-baseline justify-between">
                <h1 className="flex items-center gap-2 text-2xl font-semibold">
                    {account.name}
                    <span className="rounded bg-neutral-100 px-2 py-0.5 text-xs font-normal text-neutral-500">
                        {ACCOUNT_TYPE_LABELS[account.type]}
                    </span>
                </h1>
                <span
                    className={`text-xl font-semibold tabular-nums ${
                        account.balance < 0 ? 'text-red-600' : 'text-neutral-900'
                    }`}
                >
                    {formatNok(account.balance)}
                </span>
            </div>

            <NewTransactionForm accountId={accountId} onCreated={reload} />

            <div className="mt-8 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th className="px-4 py-2 font-medium">Dato</th>
                            <th className="px-4 py-2 font-medium">Mottaker</th>
                            <th className="px-4 py-2 font-medium">Notat</th>
                            <th className="px-4 py-2 text-center font-medium">Klarert</th>
                            <th className="px-4 py-2 text-right font-medium">Beløp</th>
                            <th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-100">
                        {transactions.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-6 text-center text-neutral-400">
                                    Ingen transaksjoner ennå.
                                </td>
                            </tr>
                        ) : (
                            transactions.map((tx) => (
                                <tr key={tx.id} className="hover:bg-neutral-50">
                                    <td className="whitespace-nowrap px-4 py-2 text-neutral-600">
                                        {formatDate(tx.date)}
                                    </td>
                                    <td className="px-4 py-2">
                                        <span className="flex items-center gap-1.5">
                                            {tx.payee ?? '—'}
                                            {tx.rule_id && (
                                                <span
                                                    title="Satt automatisk av en regel"
                                                    className="rounded bg-neutral-100 px-1 py-0.5 text-[10px] font-medium uppercase text-neutral-400"
                                                >
                                                    auto
                                                </span>
                                            )}
                                        </span>
                                    </td>
                                    <td className="px-4 py-2 text-neutral-500">{tx.memo ?? ''}</td>
                                    <td className="px-4 py-2 text-center">
                                        <button
                                            onClick={() => toggleCleared(tx)}
                                            title={tx.cleared ? 'Klarert' : 'Ikke klarert'}
                                            className={`h-5 w-5 rounded-full border text-xs ${
                                                tx.cleared
                                                    ? 'border-green-600 bg-green-600 text-white'
                                                    : 'border-neutral-300 text-transparent'
                                            }`}
                                        >
                                            ✓
                                        </button>
                                    </td>
                                    <td
                                        className={`whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums ${
                                            tx.amount < 0 ? 'text-red-600' : 'text-green-700'
                                        }`}
                                    >
                                        {formatNok(tx.amount)}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        <button
                                            onClick={() => remove(tx)}
                                            className="text-xs text-neutral-400 hover:text-red-600"
                                        >
                                            Slett
                                        </button>
                                    </td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>
        </Layout>
    );
}

function NewTransactionForm({
    accountId,
    onCreated,
}: {
    accountId: number;
    onCreated: () => void;
}) {
    const empty = { date: todayIso(), payee: '', memo: '', amount: '', category_id: '' };
    const [form, setForm] = useState(empty);
    const [direction, setDirection] = useState<'out' | 'in'>('out');
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        listCategoryGroups().then(setGroups).catch(() => setGroups([]));
    }, []);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        const magnitude = Math.abs(Number(form.amount));
        if (!magnitude) {
            setError('Oppgi et beløp.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: NewTransaction = {
            date: form.date,
            amount: direction === 'out' ? -magnitude : magnitude,
            category_id: form.category_id ? Number(form.category_id) : null,
            payee: form.payee || undefined,
            memo: form.memo || undefined,
        };
        try {
            await createTransaction(accountId, payload);
            setForm({ ...empty, date: form.date });
            onCreated();
        } catch {
            setError('Kunne ikke lagre transaksjonen.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            onSubmit={onSubmit}
            className="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4"
        >
            <label className="text-sm font-medium text-neutral-700">
                Dato
                <input
                    type="date"
                    value={form.date}
                    onChange={(e) => setForm({ ...form, date: e.target.value })}
                    className="mt-1 block rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="flex-1 text-sm font-medium text-neutral-700">
                Mottaker
                <input
                    value={form.payee}
                    onChange={(e) => setForm({ ...form, payee: e.target.value })}
                    placeholder="f.eks. Rema 1000"
                    className="mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            {groups.length > 0 && (
                <label className="text-sm font-medium text-neutral-700">
                    Kategori
                    <select
                        value={form.category_id}
                        onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                        className="mt-1 block rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
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
            )}

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

            <label className="text-sm font-medium text-neutral-700">
                Beløp
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={form.amount}
                    onChange={(e) => setForm({ ...form, amount: e.target.value })}
                    className="mt-1 block w-32 rounded-lg border border-neutral-300 px-3 py-2 text-right focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <button
                type="submit"
                disabled={busy}
                className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
                {busy ? 'Lagrer …' : 'Legg til'}
            </button>

            {error && <p className="w-full text-sm text-red-600">{error}</p>}
        </form>
    );
}
