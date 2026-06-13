import { useEffect, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/Layout';
import { apiErrorMessage, createAccount, listAccounts, type NewAccount } from '@/lib/data';
import { formatNok } from '@/lib/format';
import { ACCOUNT_TYPE_LABELS, type Account, type AccountType } from '@/types';

export default function Accounts() {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [showForm, setShowForm] = useState(false);

    function reload() {
        return listAccounts().then(setAccounts);
    }

    useEffect(() => {
        reload().finally(() => setLoading(false));
    }, []);

    const budget = accounts.filter((a) => a.on_budget);
    const tracking = accounts.filter((a) => !a.on_budget);
    const netWorth = accounts.reduce((sum, a) => sum + a.balance, 0);

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Kontoer</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Nettoformue:{' '}
                        <span className={netWorth < 0 ? 'text-red-600' : 'text-neutral-900'}>
                            {formatNok(netWorth)}
                        </span>
                    </p>
                </div>
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    {showForm ? 'Avbryt' : 'Ny konto'}
                </button>
            </div>

            {showForm && (
                <NewAccountForm
                    onCreated={() => {
                        setShowForm(false);
                        reload();
                    }}
                />
            )}

            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : accounts.length === 0 ? (
                <p className="mt-8 text-neutral-500">Ingen kontoer ennå. Opprett din første konto.</p>
            ) : (
                <div className="mt-8 space-y-8">
                    <AccountGroup title="Budsjettkontoer" accounts={budget} />
                    {tracking.length > 0 && (
                        <AccountGroup title="Overvåkede kontoer" accounts={tracking} />
                    )}
                </div>
            )}
        </Layout>
    );
}

function AccountGroup({ title, accounts }: { title: string; accounts: Account[] }) {
    if (accounts.length === 0) return null;
    const total = accounts.reduce((sum, a) => sum + a.balance, 0);

    return (
        <section>
            <div className="flex items-center justify-between border-b border-neutral-200 pb-2">
                <h2 className="text-sm font-semibold uppercase tracking-wide text-neutral-500">
                    {title}
                </h2>
                <span className="text-sm font-medium text-neutral-500">{formatNok(total)}</span>
            </div>
            <ul className="divide-y divide-neutral-100">
                {accounts.map((account) => (
                    <li key={account.id}>
                        <Link
                            to={`/accounts/${account.id}`}
                            className="flex items-center justify-between px-1 py-3 hover:bg-neutral-100"
                        >
                            <span className="flex items-center gap-2">
                                <span className={account.closed ? 'text-neutral-400 line-through' : ''}>
                                    {account.name}
                                </span>
                                <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
                                    {ACCOUNT_TYPE_LABELS[account.type]}
                                </span>
                            </span>
                            <span
                                className={`font-medium tabular-nums ${
                                    account.balance < 0 ? 'text-red-600' : 'text-neutral-900'
                                }`}
                            >
                                {formatNok(account.balance)}
                            </span>
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}

function NewAccountForm({ onCreated }: { onCreated: () => void }) {
    const [form, setForm] = useState<NewAccount>({
        name: '',
        type: 'bank',
        on_budget: true,
        starting_balance: undefined,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setError(null);
        try {
            await createAccount({
                ...form,
                starting_balance: form.starting_balance || undefined,
            });
            onCreated();
        } catch (e) {
            setError(apiErrorMessage(e, 'Kunne ikke opprette konto. Sjekk feltene.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            onSubmit={onSubmit}
            className="mt-6 grid gap-4 rounded-xl border border-neutral-200 bg-white p-5 sm:grid-cols-2"
        >
            <label className="text-sm font-medium text-neutral-700">
                Navn
                <input
                    value={form.name}
                    onChange={(e) => setForm({ ...form, name: e.target.value })}
                    required
                    autoFocus
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Type
                <select
                    value={form.type}
                    onChange={(e) => setForm({ ...form, type: e.target.value as AccountType })}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    {Object.entries(ACCOUNT_TYPE_LABELS).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Startsaldo (valgfritt)
                <input
                    type="number"
                    step="0.01"
                    value={form.starting_balance ?? ''}
                    onChange={(e) =>
                        setForm({
                            ...form,
                            starting_balance: e.target.value === '' ? undefined : Number(e.target.value),
                        })
                    }
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="flex items-center gap-2 self-end text-sm font-medium text-neutral-700">
                <input
                    type="checkbox"
                    checked={form.on_budget}
                    onChange={(e) => setForm({ ...form, on_budget: e.target.checked })}
                    className="h-4 w-4"
                />
                Budsjettkonto (aktiv)
            </label>

            <div className="sm:col-span-2">
                {error && <p className="mb-2 text-sm text-red-600">{error}</p>}
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                >
                    {busy ? 'Lagrer …' : 'Opprett konto'}
                </button>
            </div>
        </form>
    );
}
