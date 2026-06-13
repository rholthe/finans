import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link, useParams } from 'react-router-dom';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import RuleForm from '@/components/RuleForm';
import {
    applyRulesToTransactions,
    createTransaction,
    deleteTransaction,
    getAccount,
    getTransactions,
    listCategoryGroups,
    updateTransaction,
    type NewTransaction,
} from '@/lib/data';
import { formatDate, formatNok, todayIso } from '@/lib/format';
import {
    ACCOUNT_TYPE_LABELS,
    type Account,
    type CategoryGroup,
    type PageMeta,
    type Transaction,
} from '@/types';

const PER_PAGE_OPTIONS = [25, 50, 100, 200, 500];

export default function AccountDetail() {
    const { id } = useParams();
    const accountId = Number(id);
    const [account, setAccount] = useState<Account | null>(null);
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [loading, setLoading] = useState(true);

    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [perPage, setPerPage] = useState(100);
    const [page, setPage] = useState(1);

    const [editingId, setEditingId] = useState<number | null>(null);
    const [ruleForTx, setRuleForTx] = useState<Transaction | null>(null);
    const [includeMatched, setIncludeMatched] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);

    const reload = useCallback(async () => {
        const [acc, paged] = await Promise.all([
            getAccount(accountId),
            getTransactions(accountId, { from, to, perPage, page }),
        ]);
        setAccount(acc);
        setTransactions(paged.data);
        setMeta(paged.meta);
    }, [accountId, from, to, perPage, page]);

    useEffect(() => {
        setLoading(true);
        reload().finally(() => setLoading(false));
    }, [reload]);

    useEffect(() => {
        listCategoryGroups().then(setGroups).catch(() => setGroups([]));
    }, []);

    const categoryName = useMemo(
        () => new Map(groups.flatMap((g) => g.categories.map((c) => [c.id, c.name] as const))),
        [groups],
    );

    async function toggleCleared(tx: Transaction) {
        await updateTransaction(tx.id, { cleared: !tx.cleared });
        reload();
    }

    async function remove(tx: Transaction) {
        if (!confirm('Slette denne transaksjonen?')) return;
        await deleteTransaction(tx.id);
        reload();
    }

    async function applyToShown() {
        setNotice(null);
        const updated = await applyRulesToTransactions(
            transactions.map((t) => t.id),
            includeMatched,
        );
        setNotice(`Oppdaterte ${updated} av ${transactions.length} viste transaksjon(er).`);
        reload();
    }

    if (loading && !account) {
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

            <NewTransactionForm accountId={accountId} groups={groups} onCreated={reload} />

            {ruleForTx && (
                <Modal title="Ny regel fra transaksjon" onClose={() => setRuleForTx(null)}>
                    <p className="mb-3 text-xs text-neutral-500">
                        Basert på «{ruleForTx.bank_description ?? ruleForTx.payee}»
                    </p>
                    <RuleForm
                        groups={groups}
                        prefillMatch={ruleForTx.bank_description ?? ruleForTx.payee ?? ''}
                        onSaved={() => {
                            setRuleForTx(null);
                            setNotice('Regel opprettet. Bruk «Oppdater viste» for å anvende den på transaksjonene under.');
                        }}
                        onCancel={() => setRuleForTx(null)}
                    />
                </Modal>
            )}

            {/* Filter + paginering */}
            <div className="mt-8 flex flex-wrap items-end gap-3">
                <label className="text-xs font-medium text-neutral-600">
                    Fra
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => {
                            setFrom(e.target.value);
                            setPage(1);
                        }}
                        className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Til
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => {
                            setTo(e.target.value);
                            setPage(1);
                        }}
                        className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Per side
                    <select
                        value={perPage}
                        onChange={(e) => {
                            setPerPage(Number(e.target.value));
                            setPage(1);
                        }}
                        className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        {PER_PAGE_OPTIONS.map((n) => (
                            <option key={n} value={n}>
                                {n}
                            </option>
                        ))}
                    </select>
                </label>
                {(from || to) && (
                    <button
                        onClick={() => {
                            setFrom('');
                            setTo('');
                            setPage(1);
                        }}
                        className="text-sm font-medium text-neutral-500 hover:text-neutral-900"
                    >
                        Nullstill
                    </button>
                )}

                <div className="ml-auto flex items-center gap-3">
                    <label className="flex items-center gap-1 text-xs text-neutral-500">
                        <input
                            type="checkbox"
                            checked={includeMatched}
                            onChange={(e) => setIncludeMatched(e.target.checked)}
                            className="h-4 w-4"
                        />
                        inkl. matchede
                    </label>
                    <button
                        onClick={applyToShown}
                        disabled={transactions.length === 0}
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Oppdater viste ({transactions.length})
                    </button>
                </div>
            </div>

            {notice && <p className="mt-3 rounded-lg bg-neutral-100 px-4 py-2 text-sm text-neutral-700">{notice}</p>}

            <div className="mt-3 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                <table className="w-full text-sm">
                    <thead className="bg-neutral-50 text-left text-xs uppercase tracking-wide text-neutral-500">
                        <tr>
                            <th className="px-4 py-2 font-medium">Dato</th>
                            <th className="px-4 py-2 font-medium">Mottaker</th>
                            <th className="px-4 py-2 font-medium">Kategori</th>
                            <th className="px-4 py-2 text-center font-medium">Klarert</th>
                            <th className="px-4 py-2 text-right font-medium">Beløp</th>
                            <th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-100">
                        {transactions.length === 0 ? (
                            <tr>
                                <td colSpan={6} className="px-4 py-6 text-center text-neutral-400">
                                    Ingen transaksjoner.
                                </td>
                            </tr>
                        ) : (
                            transactions.map((tx) =>
                                editingId === tx.id ? (
                                    <tr key={tx.id}>
                                        <td colSpan={6} className="px-4 py-3">
                                            <EditTransactionForm
                                                tx={tx}
                                                groups={groups}
                                                onSaved={() => {
                                                    setEditingId(null);
                                                    reload();
                                                }}
                                                onCancel={() => setEditingId(null)}
                                            />
                                        </td>
                                    </tr>
                                ) : (
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
                                                {tx.locked && (
                                                    <span title="Låst – beskyttet mot regler">🔒</span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-neutral-500">
                                            {tx.category_id ? categoryName.get(tx.category_id) ?? '—' : '—'}
                                        </td>
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
                                        <td className="whitespace-nowrap px-4 py-2 text-right">
                                            {!tx.rule_id && tx.bank_description && (
                                                <button
                                                    onClick={() => setRuleForTx(tx)}
                                                    title="Lag regel fra denne"
                                                    className="text-xs text-neutral-400 hover:text-neutral-900"
                                                >
                                                    + regel
                                                </button>
                                            )}
                                            <button
                                                onClick={() => setEditingId(tx.id)}
                                                className="ml-3 text-xs text-neutral-400 hover:text-neutral-900"
                                            >
                                                Rediger
                                            </button>
                                            <button
                                                onClick={() => remove(tx)}
                                                className="ml-3 text-xs text-neutral-400 hover:text-red-600"
                                            >
                                                Slett
                                            </button>
                                        </td>
                                    </tr>
                                ),
                            )
                        )}
                    </tbody>
                </table>
            </div>

            {meta && meta.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between text-sm text-neutral-500">
                    <span>
                        {meta.total} transaksjoner · side {meta.current_page} av {meta.last_page}
                    </span>
                    <div className="flex gap-1">
                        <button
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={meta.current_page <= 1}
                            className="rounded-lg px-3 py-1.5 hover:bg-neutral-100 disabled:opacity-30"
                        >
                            ← Forrige
                        </button>
                        <button
                            onClick={() => setPage((p) => p + 1)}
                            disabled={meta.current_page >= meta.last_page}
                            className="rounded-lg px-3 py-1.5 hover:bg-neutral-100 disabled:opacity-30"
                        >
                            Neste →
                        </button>
                    </div>
                </div>
            )}
        </Layout>
    );
}

function NewTransactionForm({
    accountId,
    groups,
    onCreated,
}: {
    accountId: number;
    groups: CategoryGroup[];
    onCreated: () => void;
}) {
    const empty = { date: todayIso(), payee: '', memo: '', amount: '', category_id: '' };
    const [form, setForm] = useState(empty);
    const [direction, setDirection] = useState<'out' | 'in'>('out');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

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

function EditTransactionForm({
    tx,
    groups,
    onSaved,
    onCancel,
}: {
    tx: Transaction;
    groups: CategoryGroup[];
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [date, setDate] = useState(tx.date);
    const [payee, setPayee] = useState(tx.payee ?? '');
    const [memo, setMemo] = useState(tx.memo ?? '');
    const [direction, setDirection] = useState<'out' | 'in'>(tx.amount < 0 ? 'out' : 'in');
    const [amount, setAmount] = useState(String(Math.abs(tx.amount)));
    const [categoryId, setCategoryId] = useState(String(tx.category_id ?? ''));
    const [busy, setBusy] = useState(false);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const magnitude = Math.abs(Number(amount));
        setBusy(true);
        // Manuell redigering låser raden automatisk (backend).
        await updateTransaction(tx.id, {
            date,
            amount: direction === 'out' ? -magnitude : magnitude,
            payee: payee || undefined,
            memo: memo || undefined,
            category_id: categoryId ? Number(categoryId) : null,
        });
        setBusy(false);
        onSaved();
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-end gap-3">
            <label className="text-xs font-medium text-neutral-600">
                Dato
                <input
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                />
            </label>
            <label className="flex-1 text-xs font-medium text-neutral-600">
                Mottaker
                <input
                    value={payee}
                    onChange={(e) => setPayee(e.target.value)}
                    className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                />
            </label>
            <label className="flex-1 text-xs font-medium text-neutral-600">
                Notat
                <input
                    value={memo}
                    onChange={(e) => setMemo(e.target.value)}
                    className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                />
            </label>
            <label className="text-xs font-medium text-neutral-600">
                Kategori
                <select
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                >
                    <option value="">Ingen</option>
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
            <div className="text-xs font-medium text-neutral-600">
                Retning
                <div className="mt-1 flex overflow-hidden rounded-lg border border-neutral-300 text-sm">
                    <button
                        type="button"
                        onClick={() => setDirection('out')}
                        className={`px-3 py-1.5 ${direction === 'out' ? 'bg-red-600 text-white' : 'bg-white'}`}
                    >
                        Ut
                    </button>
                    <button
                        type="button"
                        onClick={() => setDirection('in')}
                        className={`px-3 py-1.5 ${direction === 'in' ? 'bg-green-600 text-white' : 'bg-white'}`}
                    >
                        Inn
                    </button>
                </div>
            </div>
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
            <button
                type="submit"
                disabled={busy}
                className="rounded-lg bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
                Lagre
            </button>
            <button
                type="button"
                onClick={onCancel}
                className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100"
            >
                Avbryt
            </button>
        </form>
    );
}
