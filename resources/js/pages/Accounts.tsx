import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import Layout from '@/components/Layout';
import { apiErrorMessage, createAccount, listAccounts, type NewAccount } from '@/lib/data';
import { formatNok } from '@/lib/format';
import { ACCOUNT_TYPE_LABELS, type Account, type AccountType } from '@/types';

const ACCOUNT_TYPE_ICON: Record<AccountType, string> = {
    cash: '💵',
    bank: '🏦',
    credit: '💳',
    loan: '🏠',
};

type Accent = {
    icon: string;
    badge: string; // ikon-sirkel (bg + tekst)
    chip: string; // liten teller-chip
    hover: string; // kant ved hover på kort
};

const ACCENTS: Record<'budget' | 'tracking', Accent> = {
    budget: {
        icon: '💰',
        badge: 'bg-emerald-100 text-emerald-700',
        chip: 'bg-emerald-50 text-emerald-700',
        hover: 'hover:border-emerald-300',
    },
    tracking: {
        icon: '👁️',
        badge: 'bg-sky-100 text-sky-700',
        chip: 'bg-sky-50 text-sky-700',
        hover: 'hover:border-sky-300',
    },
};

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
    const budgetTotal = budget.reduce((sum, a) => sum + a.balance, 0);
    const trackingTotal = tracking.reduce((sum, a) => sum + a.balance, 0);
    const netWorth = budgetTotal + trackingTotal;

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <h1 className="text-2xl font-semibold">Kontoer</h1>
                <button
                    onClick={() => setShowForm((v) => !v)}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    {showForm ? 'Avbryt' : '+ Ny konto'}
                </button>
            </div>

            {!loading && accounts.length > 0 && (
                <div className="mt-4 rounded-2xl bg-gradient-to-br from-neutral-900 to-neutral-700 p-6 text-white shadow-sm">
                    <div className="text-sm font-medium text-white/70">Nettoformue</div>
                    <div
                        className={`mt-0.5 text-3xl font-semibold tabular-nums ${
                            netWorth < 0 ? 'text-red-300' : 'text-white'
                        }`}
                    >
                        {formatNok(netWorth)}
                    </div>
                    <div className="mt-4 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                        <div className="flex items-center gap-2">
                            <span aria-hidden>{ACCENTS.budget.icon}</span>
                            <span className="text-white/70">Budsjettkontoer</span>
                            <span className="font-medium tabular-nums">{formatNok(budgetTotal)}</span>
                        </div>
                        {tracking.length > 0 && (
                            <div className="flex items-center gap-2">
                                <span aria-hidden>{ACCENTS.tracking.icon}</span>
                                <span className="text-white/70">Overvåket</span>
                                <span className="font-medium tabular-nums">{formatNok(trackingTotal)}</span>
                            </div>
                        )}
                    </div>
                </div>
            )}

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
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen kontoer ennå. Opprett din første konto.
                </div>
            ) : (
                <div className="mt-8 space-y-8">
                    <AccountGroup title="Budsjettkontoer" accounts={budget} accent={ACCENTS.budget} />
                    {tracking.length > 0 && (
                        <AccountGroup
                            title="Overvåkede kontoer"
                            accounts={tracking}
                            accent={ACCENTS.tracking}
                        />
                    )}
                </div>
            )}
        </Layout>
    );
}

function AccountGroup({
    title,
    accounts,
    accent,
}: {
    title: string;
    accounts: Account[];
    accent: Accent;
}) {
    if (accounts.length === 0) return null;
    const total = accounts.reduce((sum, a) => sum + a.balance, 0);

    return (
        <section>
            <div className="mb-3 flex items-center justify-between">
                <div className="flex items-center gap-2">
                    <span
                        className={`flex h-7 w-7 items-center justify-center rounded-lg text-sm ${accent.badge}`}
                        aria-hidden
                    >
                        {accent.icon}
                    </span>
                    <h2 className="text-sm font-semibold text-neutral-700">{title}</h2>
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${accent.chip}`}>
                        {accounts.length}
                    </span>
                </div>
                <span
                    className={`text-sm font-semibold tabular-nums ${
                        total < 0 ? 'text-red-600' : 'text-neutral-700'
                    }`}
                >
                    {formatNok(total)}
                </span>
            </div>
            <ul className="grid gap-3 sm:grid-cols-2">
                {accounts.map((account) => (
                    <AccountCard key={account.id} account={account} accent={accent} />
                ))}
            </ul>
        </section>
    );
}

function AccountCard({ account, accent }: { account: Account; accent: Accent }) {
    const navigate = useNavigate();

    return (
        <li>
            <Link
                to={`/accounts/${account.id}`}
                className={`group flex items-center gap-3 rounded-xl border border-neutral-200 bg-white p-3.5 shadow-sm transition hover:shadow-md ${accent.hover}`}
            >
                <span
                    className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-lg ${accent.badge}`}
                    aria-hidden
                >
                    {ACCOUNT_TYPE_ICON[account.type]}
                </span>
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-1.5">
                        <span
                            className={`truncate font-medium ${
                                account.closed ? 'text-neutral-400 line-through' : 'text-neutral-900'
                            }`}
                        >
                            {account.name}
                        </span>
                        {account.bank_synced && (
                            <span
                                title="Banksynkronisert"
                                className="shrink-0 text-xs text-sky-500"
                                aria-hidden
                            >
                                🔄
                            </span>
                        )}
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                        <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
                            {ACCOUNT_TYPE_LABELS[account.type]}
                        </span>
                        {account.uncategorized_count > 0 && (
                            <span
                                role="button"
                                tabIndex={0}
                                title="Ukategoriserte transaksjoner – klikk for å se dem"
                                onClick={(e) => {
                                    e.preventDefault();
                                    e.stopPropagation();
                                    navigate(`/accounts/${account.id}?uncategorized=1`);
                                }}
                                className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-700 hover:bg-amber-200"
                            >
                                {account.uncategorized_count} ukategorisert
                            </span>
                        )}
                    </div>
                </div>
                <span
                    className={`shrink-0 font-semibold tabular-nums ${
                        account.balance < 0 ? 'text-red-600' : 'text-neutral-900'
                    }`}
                >
                    {formatNok(account.balance)}
                </span>
            </Link>
        </li>
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
