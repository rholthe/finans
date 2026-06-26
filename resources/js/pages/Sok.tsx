import { useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Layout from '@/components/Layout';
import { listAccounts, searchTransactions } from '@/lib/data';
import { formatDate, formatNok } from '@/lib/format';
import type { Account, Paginated, Transaction } from '@/types';

const PER_PAGE = 50;

export default function Sok() {
    const navigate = useNavigate();
    const [accounts, setAccounts] = useState<Account[]>([]);

    const [q, setQ] = useState('');
    const [accountId, setAccountId] = useState<number | ''>('');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [minAmount, setMinAmount] = useState('');
    const [maxAmount, setMaxAmount] = useState('');
    const [uncategorized, setUncategorized] = useState(false);
    const [page, setPage] = useState(1);

    const [data, setData] = useState<Paginated<Transaction> | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        listAccounts().then(setAccounts).catch(() => setAccounts([]));
    }, []);

    // Debounced søk når filtre eller side endres.
    useEffect(() => {
        setLoading(true);
        const handle = setTimeout(() => {
            searchTransactions({
                q: q.trim() || undefined,
                accountId: accountId === '' ? undefined : accountId,
                from: from || undefined,
                to: to || undefined,
                minAmount: minAmount === '' ? undefined : Number(minAmount.replace(',', '.')),
                maxAmount: maxAmount === '' ? undefined : Number(maxAmount.replace(',', '.')),
                uncategorized: uncategorized || undefined,
                perPage: PER_PAGE,
                page,
            })
                .then(setData)
                .catch(() => setData(null))
                .finally(() => setLoading(false));
        }, 300);
        return () => clearTimeout(handle);
    }, [q, accountId, from, to, minAmount, maxAmount, uncategorized, page]);

    // Endrede filtre nullstiller siden.
    function onFilter<T>(setter: (value: T) => void) {
        return (value: T) => {
            setter(value);
            setPage(1);
        };
    }

    const accountName = useMemo(() => {
        const map = new Map(accounts.map((a) => [a.id, a.name]));
        return (id: number) => map.get(id) ?? '—';
    }, [accounts]);

    const meta = data?.meta;
    const rows = data?.data ?? [];

    return (
        <Layout>
            <h1 className="text-2xl font-semibold">Søk</h1>

            <div className="mt-4 grid gap-3 rounded-xl border border-neutral-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-4">
                <label className="text-xs font-medium text-neutral-600 lg:col-span-2">
                    Tekst (mottaker / notat / bankinfo)
                    <input
                        type="text"
                        value={q}
                        onChange={(e) => onFilter(setQ)(e.target.value)}
                        placeholder="Søk …"
                        autoFocus
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-1.5 text-sm focus:border-neutral-900 focus:outline-none focus:ring-2 focus:ring-neutral-200"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Konto
                    <select
                        value={accountId}
                        onChange={(e) => onFilter(setAccountId)(e.target.value === '' ? '' : Number(e.target.value))}
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Alle kontoer</option>
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex items-end gap-2 text-xs font-medium text-neutral-600">
                    <input
                        type="checkbox"
                        checked={uncategorized}
                        onChange={(e) => onFilter(setUncategorized)(e.target.checked)}
                        className="h-4 w-4 rounded border-neutral-300 text-neutral-900 focus:ring-neutral-400"
                    />
                    <span className="pb-1">Kun ukategorisert</span>
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Fra dato
                    <input
                        type="date"
                        value={from}
                        onChange={(e) => onFilter(setFrom)(e.target.value)}
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Til dato
                    <input
                        type="date"
                        value={to}
                        onChange={(e) => onFilter(setTo)(e.target.value)}
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Beløp fra
                    <input
                        type="number"
                        step="0.01"
                        value={minAmount}
                        onChange={(e) => onFilter(setMinAmount)(e.target.value)}
                        placeholder="f.eks. -1000"
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="text-xs font-medium text-neutral-600">
                    Beløp til
                    <input
                        type="number"
                        step="0.01"
                        value={maxAmount}
                        onChange={(e) => onFilter(setMaxAmount)(e.target.value)}
                        placeholder="f.eks. 0"
                        className="mt-1 block w-full rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-neutral-900 focus:outline-none"
                    />
                </label>
            </div>

            <div className="mt-3 flex items-center justify-between text-sm text-neutral-500">
                <span>{meta ? `${meta.total} treff` : ' '}</span>
                {meta && meta.last_page > 1 && (
                    <div className="flex items-center gap-2">
                        <button
                            onClick={() => setPage((p) => Math.max(1, p - 1))}
                            disabled={meta.current_page <= 1}
                            className="rounded-lg px-2 py-1 hover:bg-neutral-100 disabled:opacity-40"
                        >
                            ← Forrige
                        </button>
                        <span className="tabular-nums">
                            {meta.current_page} / {meta.last_page}
                        </span>
                        <button
                            onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                            disabled={meta.current_page >= meta.last_page}
                            className="rounded-lg px-2 py-1 hover:bg-neutral-100 disabled:opacity-40"
                        >
                            Neste →
                        </button>
                    </div>
                )}
            </div>

            <div className="mt-2 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                {loading && !data ? (
                    <p className="py-12 text-center text-sm text-neutral-400">Laster …</p>
                ) : rows.length === 0 ? (
                    <p className="py-12 text-center text-sm text-neutral-400">Ingen treff.</p>
                ) : (
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b border-neutral-200 bg-neutral-50 text-xs font-medium uppercase tracking-wide text-neutral-500">
                                <th className="px-4 py-2 text-left">Dato</th>
                                <th className="px-4 py-2 text-left">Konto</th>
                                <th className="px-4 py-2 text-left">Mottaker</th>
                                <th className="px-4 py-2 text-left">Kategori</th>
                                <th className="px-4 py-2 text-right">Beløp</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((tx) => (
                                <tr
                                    key={tx.id}
                                    onClick={() => navigate(`/accounts/${tx.account_id}`)}
                                    className="cursor-pointer border-b border-neutral-100 last:border-0 hover:bg-neutral-50"
                                >
                                    <td className="whitespace-nowrap px-4 py-2 tabular-nums text-neutral-500">
                                        {formatDate(tx.date)}
                                    </td>
                                    <td className="px-4 py-2 text-neutral-600">{tx.account ?? accountName(tx.account_id)}</td>
                                    <td className="px-4 py-2">
                                        <div className="text-neutral-800">{tx.payee || '—'}</div>
                                        {tx.memo && <div className="text-xs text-neutral-400">{tx.memo}</div>}
                                    </td>
                                    <td className="px-4 py-2 text-neutral-500">
                                        {tx.is_split ? (
                                            <span className="text-neutral-400">Splittet</span>
                                        ) : tx.transfer_id ? (
                                            <span className="text-neutral-400">Overføring</span>
                                        ) : tx.category ? (
                                            tx.category
                                        ) : tx.rta ? (
                                            <span className="text-neutral-400">Klar til å fordele</span>
                                        ) : (
                                            <span className="text-amber-600">Mangler kategori</span>
                                        )}
                                    </td>
                                    <td
                                        className={`whitespace-nowrap px-4 py-2 text-right font-medium tabular-nums ${
                                            tx.amount < 0 ? 'text-neutral-700' : 'text-green-700'
                                        }`}
                                    >
                                        {formatNok(tx.amount)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </Layout>
    );
}
