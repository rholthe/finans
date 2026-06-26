import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type Dispatch,
    type FormEvent,
    type SetStateAction,
} from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import RuleForm from '@/components/RuleForm';
import {
    apiErrorMessage,
    applyRulesToTransactions,
    createTransaction,
    createTransfer,
    deleteTransaction,
    getAccount,
    getTransactions,
    listAccounts,
    listCategoryGroups,
    reconcileAccount,
    updateTransaction,
    type NewTransaction,
} from '@/lib/data';
import { formatDate, formatDateTime, formatNok, todayIso } from '@/lib/format';
import {
    ACCOUNT_TYPE_LABELS,
    type Account,
    type AccountType,
    type CategoryGroup,
    type PageMeta,
    type Transaction,
} from '@/types';

const PER_PAGE_OPTIONS = [25, 50, 100, 200, 500];

// Avvik mellom app-total og bankens saldo (inkl. reservert) varsles ved nøyaktig
// mismatch – terskelen fanger kun flyttall-støy, ikke reelle øre-avvik.
const BALANCE_MISMATCH_THRESHOLD = 0.005;

const ACCOUNT_TYPE_ICON: Record<AccountType, string> = {
    cash: '💵',
    bank: '🏦',
    saving: '🐷',
    credit: '💳',
    loan: '🏠',
};

// Samme accent-system som kontolistesiden: emerald for budsjett, sky for overvåket.
function accountAccent(onBudget: boolean) {
    return onBudget
        ? { badge: 'bg-emerald-100 text-emerald-700', gradient: 'from-emerald-50 via-white to-sky-50' }
        : { badge: 'bg-sky-100 text-sky-700', gradient: 'from-sky-50 via-white to-white' };
}

// Liten etikett/verdi-celle for saldotallene nederst i heroen.
function HeroStat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <dt className="text-[11px] font-medium uppercase tracking-wide text-neutral-400">{label}</dt>
            <dd className="text-sm font-medium tabular-nums text-neutral-700">{value}</dd>
        </div>
    );
}

export default function AccountDetail() {
    const { id } = useParams();
    const accountId = Number(id);
    const [account, setAccount] = useState<Account | null>(null);
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [meta, setMeta] = useState<PageMeta | null>(null);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [allAccounts, setAllAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);

    const [searchParams] = useSearchParams();
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [perPage, setPerPage] = useState(100);
    const [page, setPage] = useState(1);
    // Forhåndsfiltrer til ukategoriserte når badgen lenker hit (?uncategorized=1).
    const [onlyUncat, setOnlyUncat] = useState(searchParams.get('uncategorized') === '1');

    const [editingId, setEditingId] = useState<number | null>(null);
    const [ruleForTx, setRuleForTx] = useState<Transaction | null>(null);
    const [deletingTx, setDeletingTx] = useState<Transaction | null>(null);
    // Bekreftelse før en avstemt transaksjon endres (rediger, klarert-toggle
    // eller inline kategorisering). `placement` bæres for kategoriseringen.
    const [reconciledPending, setReconciledPending] = useState<{
        kind: 'edit' | 'cleared' | 'categorize';
        tx: Transaction;
        placement?: string;
    } | null>(null);
    const [notice, setNotice] = useState<string | null>(null);
    const [showReconcile, setShowReconcile] = useState(false);

    const reload = useCallback(async () => {
        const [acc, paged] = await Promise.all([
            getAccount(accountId),
            getTransactions(accountId, { from, to, perPage, page, uncategorized: onlyUncat }),
        ]);
        setAccount(acc);
        setTransactions(paged.data);
        setMeta(paged.meta);
    }, [accountId, from, to, perPage, page, onlyUncat]);

    useEffect(() => {
        setLoading(true);
        reload().finally(() => setLoading(false));
    }, [reload]);

    useEffect(() => {
        listCategoryGroups().then(setGroups).catch(() => setGroups([]));
        listAccounts().then(setAllAccounts).catch(() => setAllAccounts([]));
    }, []);

    const categoryName = useMemo(
        () => new Map(groups.flatMap((g) => g.categories.map((c) => [c.id, c.name] as const))),
        [groups],
    );

    async function toggleCleared(tx: Transaction) {
        if (tx.reconciled_at) {
            setReconciledPending({ kind: 'cleared', tx });
            return;
        }
        await updateTransaction(tx.id, { cleared: !tx.cleared });
        reload();
    }

    function startEdit(tx: Transaction) {
        if (tx.reconciled_at) {
            setReconciledPending({ kind: 'edit', tx });
            return;
        }
        setEditingId(tx.id);
    }

    // Inline kategorisering fra nedtrekket: ''=ukategorisert, 'rta'=Klar til å
    // fordele, ellers kategori-id. Splitt/øvrige endringer går via rediger-dialogen.
    async function categorize(tx: Transaction, placement: string) {
        if (tx.reconciled_at) {
            setReconciledPending({ kind: 'categorize', tx, placement });
            return;
        }
        await applyCategory(tx, placement);
    }

    async function applyCategory(tx: Transaction, placement: string) {
        const rta = placement === 'rta';
        await updateTransaction(tx.id, {
            category_id: rta || placement === '' ? null : Number(placement),
            rta,
        });
        await reload();
    }

    async function confirmReconciledAction() {
        if (!reconciledPending) return;
        const { kind, tx, placement } = reconciledPending;
        setReconciledPending(null);
        if (kind === 'cleared') {
            await updateTransaction(tx.id, { cleared: !tx.cleared });
            reload();
        } else if (kind === 'categorize') {
            await applyCategory(tx, placement ?? '');
        } else {
            setEditingId(tx.id);
        }
    }

    async function confirmDelete() {
        if (!deletingTx) return;
        await deleteTransaction(deletingTx.id);
        setDeletingTx(null);
        reload();
    }

    // Transaksjoner en regelkjøring faktisk vil treffe: bank-importerte, ulåste
    // og ikke allerede matchet (samme filter som backend bruker).
    const ruleCandidates = transactions.filter(
        (t) => t.bank_description !== null && !t.locked && t.rule_id === null,
    );

    async function applyToShown() {
        setNotice(null);
        const updated = await applyRulesToTransactions(ruleCandidates.map((t) => t.id));
        setNotice(`Oppdaterte ${updated} av ${ruleCandidates.length} transaksjon(er).`);
        reload();
    }

    if (loading && !account) {
        return (
            <Layout maxWidth="max-w-6xl">
                <p className="text-neutral-400">Laster …</p>
            </Layout>
        );
    }

    if (!account) {
        return (
            <Layout maxWidth="max-w-6xl">
                <p className="text-neutral-500">Konto ikke funnet.</p>
                <Link to="/kontoer" className="text-sm text-neutral-900 underline">
                    Tilbake
                </Link>
            </Layout>
        );
    }

    return (
        <Layout maxWidth="max-w-6xl">
            <Link to="/kontoer" className="text-sm text-neutral-500 hover:text-neutral-900">
                ← Alle kontoer
            </Link>

            {(() => {
                const accent = accountAccent(account.on_budget);
                // App-total mot bankens saldo inkl. reservert (begge inneholder reserverte).
                const bankAvailable = account.bank_balance?.available ?? null;
                const mismatch =
                    bankAvailable != null && Math.abs(account.balance - bankAvailable) >= BALANCE_MISMATCH_THRESHOLD
                        ? account.balance - bankAvailable
                        : null;
                return (
                    <div
                        className={`mt-2 rounded-2xl bg-gradient-to-br ${accent.gradient} p-6 shadow-sm ring-1 ring-neutral-200`}
                    >
                        <div className="flex items-start justify-between gap-4">
                            <div className="flex min-w-0 items-center gap-3">
                                <span
                                    className={`flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-xl ${accent.badge}`}
                                    aria-hidden
                                >
                                    {ACCOUNT_TYPE_ICON[account.type]}
                                </span>
                                <div className="min-w-0">
                                    <h1
                                        className={`flex items-center gap-2 truncate text-2xl font-semibold ${
                                            account.closed ? 'text-neutral-400 line-through' : 'text-neutral-900'
                                        }`}
                                    >
                                        {account.name}
                                        {account.bank_synced && (
                                            <span title="Banksynkronisert" className="text-sm text-sky-500" aria-hidden>
                                                🔄
                                            </span>
                                        )}
                                    </h1>
                                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                                        <span className="rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-500">
                                            {ACCOUNT_TYPE_LABELS[account.type]}
                                        </span>
                                        <span className="rounded bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-500">
                                            {account.on_budget ? '💰 Budsjett' : '👁️ Overvåket'}
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-col items-end gap-2">
                                <div className="flex flex-col items-end">
                                    {account.bank_balance && (
                                        <span className="text-[11px] font-medium uppercase tracking-wide text-neutral-400">
                                            App – totalt
                                        </span>
                                    )}
                                    <span
                                        className={`text-3xl font-semibold tabular-nums ${
                                            account.balance < 0 ? 'text-red-600' : 'text-neutral-900'
                                        }`}
                                    >
                                        {formatNok(account.balance)}
                                    </span>
                                </div>
                                <button
                                    onClick={() => setShowReconcile(true)}
                                    className="rounded-lg border border-neutral-300 bg-white/70 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-white"
                                >
                                    Avstem
                                </button>
                            </div>
                        </div>
                        <div className="mt-4 border-t border-neutral-200/70 pt-3">
                            <dl className="flex flex-wrap gap-x-8 gap-y-2">
                                <HeroStat label="App – klarert" value={formatNok(account.cleared_balance)} />
                                {account.bank_balance && (
                                    <>
                                        <HeroStat
                                            label="Bank – bokført"
                                            value={
                                                account.bank_balance.booked != null
                                                    ? formatNok(account.bank_balance.booked)
                                                    : '–'
                                            }
                                        />
                                        <HeroStat
                                            label="Bank – inkl. reservert"
                                            value={
                                                account.bank_balance.available != null
                                                    ? formatNok(account.bank_balance.available)
                                                    : '–'
                                            }
                                        />
                                    </>
                                )}
                            </dl>

                            {mismatch != null && (
                                <p className="mt-3 flex w-fit items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 ring-1 ring-amber-100">
                                    <span aria-hidden>⚠️</span>
                                    Avvik mot bank: {formatNok(mismatch)}
                                </p>
                            )}

                            {(account.last_reconciled_at || account.bank_balance?.synced_at) && (
                                <p className="mt-2 text-[11px] text-neutral-400">
                                    {account.last_reconciled_at &&
                                        `Sist avstemt ${formatDate(account.last_reconciled_at)}`}
                                    {account.last_reconciled_at && account.bank_balance?.synced_at && ' · '}
                                    {account.bank_balance?.synced_at &&
                                        `banksaldo oppdatert ${formatDateTime(account.bank_balance.synced_at)}`}
                                </p>
                            )}
                        </div>
                    </div>
                );
            })()}

            {showReconcile && (
                <ReconcileModal
                    account={account}
                    onClose={() => setShowReconcile(false)}
                    onReconciled={(message) => {
                        setShowReconcile(false);
                        setNotice(message);
                        reload();
                    }}
                />
            )}

            <EntryForms account={account} groups={groups} onCreated={reload} />

            {ruleForTx && (
                <Modal title="Ny regel fra transaksjon" onClose={() => setRuleForTx(null)}>
                    <p className="mb-3 text-xs text-neutral-500">
                        Basert på «{ruleForTx.bank_description ?? ruleForTx.payee}»
                    </p>
                    <RuleForm
                        groups={groups}
                        accounts={allAccounts}
                        prefillMatch={ruleForTx.bank_description ?? ruleForTx.payee ?? ''}
                        onSaved={() => {
                            setRuleForTx(null);
                            setNotice('Regel opprettet. Bruk «Oppdater viste» for å anvende den på transaksjonene under.');
                        }}
                        onCancel={() => setRuleForTx(null)}
                    />
                </Modal>
            )}

            {deletingTx && (
                <DeleteTransactionModal
                    tx={deletingTx}
                    categoryName={categoryName}
                    onClose={() => setDeletingTx(null)}
                    onConfirm={confirmDelete}
                />
            )}

            {reconciledPending && (
                <ConfirmReconciledModal
                    kind={reconciledPending.kind}
                    onClose={() => setReconciledPending(null)}
                    onConfirm={confirmReconciledAction}
                />
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

                {(onlyUncat || account.uncategorized_count > 0) && (
                    <button
                        onClick={() => {
                            setOnlyUncat((v) => !v);
                            setPage(1);
                        }}
                        className={`rounded-lg border px-3 py-1.5 text-sm font-medium ${
                            onlyUncat
                                ? 'border-amber-300 bg-amber-50 text-amber-700'
                                : 'border-neutral-300 text-neutral-700 hover:bg-neutral-100'
                        }`}
                    >
                        {onlyUncat ? 'Viser ukategoriserte' : `Kun ukategoriserte (${account.uncategorized_count})`}
                    </button>
                )}

                <div className="ml-auto flex items-center gap-3">
                    <button
                        onClick={applyToShown}
                        disabled={ruleCandidates.length === 0}
                        title="Kjør regler på viste, ulåste og ikke allerede matchede transaksjoner"
                        className="rounded-lg border border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Oppdater viste ({ruleCandidates.length})
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
                            <th className="px-4 py-2 font-medium">Overføring</th>
                            <th className="px-4 py-2 font-medium">Kategori</th>
                            <th className="px-4 py-2 text-center font-medium">Klarert</th>
                            <th className="px-4 py-2 text-right font-medium">Beløp</th>
                            <th className="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-neutral-100">
                        {transactions.length === 0 ? (
                            <tr>
                                <td colSpan={7} className="px-4 py-6 text-center text-neutral-400">
                                    Ingen transaksjoner.
                                </td>
                            </tr>
                        ) : (
                            transactions.map((tx) =>
                                editingId === tx.id ? (
                                    <tr key={tx.id}>
                                        <td colSpan={7} className="px-4 py-3">
                                            <EditTransactionForm
                                                tx={tx}
                                                groups={groups}
                                                categorizable={account.on_budget}
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
                                                {tx.pending && (
                                                    <span
                                                        title="Reservert – ikke bokført i banken ennå"
                                                        className="rounded bg-amber-50 px-1 py-0.5 text-[10px] font-medium uppercase text-amber-600"
                                                    >
                                                        reservert
                                                    </span>
                                                )}
                                                {tx.reconciled_at && (
                                                    <span
                                                        title="Avstemt"
                                                        className="rounded bg-green-50 px-1 py-0.5 text-[10px] font-medium uppercase text-green-600"
                                                    >
                                                        avstemt
                                                    </span>
                                                )}
                                            </span>
                                        </td>
                                        <td className="px-4 py-2 text-neutral-500">
                                            {tx.transfer_id ? (
                                                <span className="whitespace-nowrap italic text-neutral-500">
                                                    ⇄ {tx.transfer_account ?? 'Overføring'}
                                                </span>
                                            ) : (
                                                ''
                                            )}
                                        </td>
                                        <td className="px-4 py-2 text-neutral-500">
                                            {!account.on_budget ? (
                                                <span className="italic text-neutral-400">ikke behov</span>
                                            ) : tx.is_split ? (
                                                // Splitt endres kun via rediger-dialogen.
                                                <span
                                                    title={(tx.splits ?? [])
                                                        .map(
                                                            (s) =>
                                                                `${categoryName.get(s.category_id) ?? '—'}: ${formatNok(s.amount)}`,
                                                        )
                                                        .join('\n')}
                                                    className="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700"
                                                >
                                                    Delt ({tx.splits?.length ?? 0})
                                                </span>
                                            ) : tx.transfer_id ? (
                                                // Overføringsben: kategori er låst av paret (kun splitt via dialog).
                                                tx.category_id ? (
                                                    (categoryName.get(tx.category_id) ?? '—')
                                                ) : tx.rta ? (
                                                    <span className="italic text-neutral-400">Tildelt RTA</span>
                                                ) : (
                                                    <span className="italic text-neutral-400">ikke behov</span>
                                                )
                                            ) : (
                                                // Vanlig transaksjon: sett kategori/RTA/ukategorisert direkte.
                                                <InlineCategorySelect
                                                    tx={tx}
                                                    groups={groups}
                                                    onChange={(placement) => categorize(tx, placement)}
                                                />
                                            )}
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
                                            {/* Overføringer kan ikke redigeres eller regelstyres – kun slettes. */}
                                            {!tx.transfer_id && !tx.rule_id && tx.bank_description && (
                                                <button
                                                    onClick={() => setRuleForTx(tx)}
                                                    title="Lag regel fra denne"
                                                    className="text-xs text-neutral-400 hover:text-neutral-900"
                                                >
                                                    + regel
                                                </button>
                                            )}
                                            {!tx.transfer_id && (
                                                <button
                                                    onClick={() => startEdit(tx)}
                                                    className="ml-3 text-xs text-neutral-400 hover:text-neutral-900"
                                                >
                                                    Rediger
                                                </button>
                                            )}
                                            {tx.transfer_id &&
                                                account.on_budget &&
                                                (tx.category_id !== null || tx.is_split) && (
                                                    <button
                                                        onClick={() => startEdit(tx)}
                                                        className="ml-3 text-xs text-neutral-400 hover:text-neutral-900"
                                                    >
                                                        {tx.is_split ? 'Rediger splitt' : 'Splitt'}
                                                    </button>
                                                )}
                                            <button
                                                onClick={() => setDeletingTx(tx)}
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

/**
 * Inline kategori-nedtrekk i transaksjonslista: ukategorisert / «Klar til å
 * fordele» (RTA) / en konkret kategori. Splitt og øvrige endringer gjøres i
 * rediger-dialogen. Verdien styres av tx (kontrollert), så et avbrutt valg
 * (f.eks. avstemt-bekreftelse avbrytes) snapper tilbake til opprinnelig verdi.
 */
function InlineCategorySelect({
    tx,
    groups,
    onChange,
}: {
    tx: Transaction;
    groups: CategoryGroup[];
    onChange: (placement: string) => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);
    const value = tx.rta ? 'rta' : String(tx.category_id ?? '');

    async function handle(next: string) {
        if (next === value) return;
        setBusy(true);
        try {
            await onChange(next);
        } finally {
            setBusy(false);
        }
    }

    return (
        <select
            value={value}
            disabled={busy}
            onChange={(e) => handle(e.target.value)}
            className={`max-w-[12rem] rounded border bg-transparent px-1.5 py-1 text-sm focus:border-neutral-900 focus:outline-none disabled:opacity-50 ${
                value === ''
                    ? 'border-neutral-200 italic text-neutral-400'
                    : 'border-transparent text-neutral-700 hover:border-neutral-300'
            }`}
        >
            <option value="">Ukategorisert</option>
            <option value="rta">Klar til å fordele (RTA)</option>
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
    );
}

/** Bekreftelse før en tidligere avstemt transaksjon endres (rediger eller klarert-toggle). */
function ConfirmReconciledModal({
    kind,
    onClose,
    onConfirm,
}: {
    kind: 'edit' | 'cleared' | 'categorize';
    onClose: () => void;
    onConfirm: () => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);

    async function submit() {
        setBusy(true);
        try {
            await onConfirm();
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal
            title="Endre avstemt transaksjon?"
            size="sm"
            onClose={onClose}
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Avbryt
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy}
                        className="rounded-lg bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                    >
                        {busy ? 'Fortsetter …' : kind === 'edit' ? 'Rediger likevel' : 'Endre likevel'}
                    </button>
                </>
            }
        >
            <p className="text-sm text-neutral-600">
                Denne transaksjonen er tidligere avstemt.{' '}
                {kind === 'cleared'
                    ? 'Å endre klarert-status påvirker den klarerte saldoen.'
                    : 'Å endre den kan gjøre at den klarerte saldoen ikke lenger stemmer med avstemmingen.'}
            </p>
        </Modal>
    );
}

/** Bekreftelsesmodal for sletting av en transaksjon (med ekstra varsel ved avstemte/overføringer). */
function DeleteTransactionModal({
    tx,
    categoryName,
    onClose,
    onConfirm,
}: {
    tx: Transaction;
    categoryName: Map<number, string>;
    onClose: () => void;
    onConfirm: () => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);

    async function submit() {
        setBusy(true);
        try {
            await onConfirm();
        } finally {
            setBusy(false);
        }
    }

    const category = tx.is_split
        ? `Delt (${tx.splits?.length ?? 0})`
        : tx.category_id
          ? (categoryName.get(tx.category_id) ?? '—')
          : null;

    return (
        <Modal
            title="Slette transaksjon?"
            size="sm"
            onClose={onClose}
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Avbryt
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy}
                        className="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        {busy ? 'Sletter …' : 'Slett'}
                    </button>
                </>
            }
        >
            <p className="text-sm text-neutral-600">Denne handlingen kan ikke angres.</p>

            <dl className="mt-3 space-y-1.5 rounded-xl bg-neutral-50 p-3 text-sm ring-1 ring-neutral-100">
                <div className="flex justify-between gap-3">
                    <dt className="text-neutral-500">Dato</dt>
                    <dd className="tabular-nums text-neutral-800">{formatDate(tx.date)}</dd>
                </div>
                <div className="flex justify-between gap-3">
                    <dt className="text-neutral-500">Mottaker</dt>
                    <dd className="truncate text-neutral-800">{tx.payee ?? '—'}</dd>
                </div>
                {category && (
                    <div className="flex justify-between gap-3">
                        <dt className="text-neutral-500">Kategori</dt>
                        <dd className="truncate text-neutral-800">{category}</dd>
                    </div>
                )}
                <div className="flex justify-between gap-3">
                    <dt className="text-neutral-500">Beløp</dt>
                    <dd
                        className={`font-medium tabular-nums ${
                            tx.amount < 0 ? 'text-red-600' : 'text-green-700'
                        }`}
                    >
                        {formatNok(tx.amount)}
                    </dd>
                </div>
            </dl>

            {tx.transfer_id && (
                <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span aria-hidden>⚠️</span> Dette er en overføring – det sammenkoblede benet
                    {tx.transfer_account ? ` mot ${tx.transfer_account}` : ''} slettes også.
                </p>
            )}

            {tx.reconciled_at && (
                <p className="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    <span aria-hidden>⚠️</span> Transaksjonen er tidligere avstemt. Sletting endrer den
                    klarerte saldoen.
                </p>
            )}
        </Modal>
    );
}

function ReconcileModal({
    account,
    onClose,
    onReconciled,
}: {
    account: Account;
    onClose: () => void;
    onReconciled: (message: string) => void;
}) {
    const [balance, setBalance] = useState(String(account.cleared_balance));
    const [date, setDate] = useState(todayIso());
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        const value = Number(balance);
        if (Number.isNaN(value)) {
            setError('Oppgi en gyldig saldo.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            const result = await reconcileAccount(account.id, value, date);
            const message =
                result.adjustment_amount === 0
                    ? 'Avstemt – ingen justering nødvendig.'
                    : `Avstemt – justering på ${formatNok(result.adjustment_amount)} opprettet.`;
            onReconciled(message);
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke avstemme kontoen.'));
            setBusy(false);
        }
    }

    const diff = Number(balance) - account.cleared_balance;

    return (
        <Modal title={`Avstem ${account.name}`} onClose={onClose}>
            <form onSubmit={submit} className="space-y-3">
                {account.uncategorized_count > 0 && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        <span aria-hidden>⚠️</span> Kontoen har {account.uncategorized_count} ukategorisert
                        {account.uncategorized_count === 1 ? ' transaksjon' : 'e transaksjoner'}. Avstemming
                        fungerer fortsatt, men kategoriser dem gjerne først for et korrekt budsjett.
                    </div>
                )}
                <p className="text-sm text-neutral-500">
                    Klarert saldo:{' '}
                    <span className="font-medium tabular-nums">{formatNok(account.cleared_balance)}</span>
                </p>
                <label className="block text-xs font-medium text-neutral-600">
                    Faktisk saldo i banken
                    <input
                        type="number"
                        step="0.01"
                        value={balance}
                        onChange={(e) => setBalance(e.target.value)}
                        autoFocus
                        className="mt-1 block w-44 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>
                <label className="block text-xs font-medium text-neutral-600">
                    Dato for justering
                    <input
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                        className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </label>

                {!Number.isNaN(diff) && diff !== 0 && (
                    <p className="text-sm text-neutral-600">
                        Avvik på <span className="font-medium tabular-nums">{formatNok(diff)}</span> bokføres
                        som en justering.
                    </p>
                )}

                {error && <p className="text-sm text-red-600">{error}</p>}

                <div className="flex gap-2 pt-1">
                    <button
                        type="submit"
                        disabled={busy}
                        className="rounded-lg bg-neutral-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                    >
                        {busy ? 'Avstemmer …' : 'Avstem'}
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
        </Modal>
    );
}

function EntryForms({
    account,
    groups,
    onCreated,
}: {
    account: Account;
    groups: CategoryGroup[];
    onCreated: () => void;
}) {
    const [mode, setMode] = useState<'transaction' | 'transfer'>('transaction');

    return (
        <div className="mt-6">
            <div className="mb-3 flex gap-2">
                {(['transaction', 'transfer'] as const).map((m) => (
                    <button
                        key={m}
                        type="button"
                        onClick={() => setMode(m)}
                        className={`rounded-lg px-3 py-1.5 text-sm font-medium ${
                            mode === m ? 'bg-neutral-900 text-white' : 'text-neutral-600 hover:bg-neutral-100'
                        }`}
                    >
                        {m === 'transaction' ? 'Transaksjon' : 'Overføring'}
                    </button>
                ))}
            </div>
            {mode === 'transaction' ? (
                <NewTransactionForm
                    accountId={account.id}
                    groups={groups}
                    categorizable={account.on_budget}
                    onCreated={onCreated}
                />
            ) : (
                <NewTransferForm account={account} groups={groups} onCreated={onCreated} />
            )}
        </div>
    );
}

function NewTransferForm({
    account,
    groups,
    onCreated,
}: {
    account: Account;
    groups: CategoryGroup[];
    onCreated: () => void;
}) {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [otherId, setOtherId] = useState('');
    const [direction, setDirection] = useState<'out' | 'in'>('out');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(todayIso());
    const [memo, setMemo] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        listAccounts()
            .then((all) => setAccounts(all.filter((a) => a.id !== account.id && !a.closed)))
            .catch(() => setAccounts([]));
    }, [account.id]);

    // Avsender/mottaker gitt retning, og om dette er en overføring UT av budsjettet
    // til en overvåket konto (da kreves kategori på budsjett-benet).
    const otherAccount = accounts.find((a) => a.id === Number(otherId));
    const fromAcc = direction === 'out' ? account : otherAccount;
    const toAcc = direction === 'out' ? otherAccount : account;
    const needsCategory = !!fromAcc?.on_budget && !!toAcc && !toAcc.on_budget;

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        const magnitude = Math.abs(Number(amount));
        const other = Number(otherId);
        if (!other) {
            setError('Velg en konto.');
            return;
        }
        if (!magnitude) {
            setError('Oppgi et beløp.');
            return;
        }
        if (needsCategory && !categoryId) {
            setError('Overføring ut av budsjettet krever en kategori.');
            return;
        }
        setBusy(true);
        setError(null);
        try {
            await createTransfer({
                from_account_id: direction === 'out' ? account.id : other,
                to_account_id: direction === 'out' ? other : account.id,
                amount: magnitude,
                date,
                memo: memo || undefined,
                category_id: needsCategory ? Number(categoryId) : undefined,
            });
            setAmount('');
            setMemo('');
            setCategoryId('');
            onCreated();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke opprette overføringen.'));
        } finally {
            setBusy(false);
        }
    }

    if (accounts.length === 0) {
        return (
            <p className="rounded-xl border border-dashed border-neutral-300 p-4 text-sm text-neutral-500">
                Du trenger minst én konto til for å overføre.
            </p>
        );
    }

    return (
        <form
            onSubmit={onSubmit}
            className="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4"
        >
            <label className="text-sm font-medium text-neutral-700">
                Dato
                <input
                    type="date"
                    value={date}
                    onChange={(e) => setDate(e.target.value)}
                    className="mt-1 block rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <div className="text-sm font-medium text-neutral-700">
                Retning
                <div className="mt-1 flex overflow-hidden rounded-lg border border-neutral-300">
                    <button
                        type="button"
                        onClick={() => setDirection('out')}
                        className={`px-3 py-2 ${direction === 'out' ? 'bg-red-600 text-white' : 'bg-white'}`}
                    >
                        Til
                    </button>
                    <button
                        type="button"
                        onClick={() => setDirection('in')}
                        className={`px-3 py-2 ${direction === 'in' ? 'bg-green-600 text-white' : 'bg-white'}`}
                    >
                        Fra
                    </button>
                </div>
            </div>

            <label className="flex-1 text-sm font-medium text-neutral-700">
                {direction === 'out' ? 'Til konto' : 'Fra konto'}
                <select
                    value={otherId}
                    onChange={(e) => setOtherId(e.target.value)}
                    className="mt-1 block w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    <option value="">Velg konto …</option>
                    {accounts.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.name}
                        </option>
                    ))}
                </select>
            </label>

            {needsCategory && (
                <label className="text-sm font-medium text-neutral-700">
                    Kategori
                    <select
                        value={categoryId}
                        onChange={(e) => setCategoryId(e.target.value)}
                        className="mt-1 block rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                        title="Overføring ut av budsjettet er kategorisert forbruk"
                    >
                        <option value="">Velg kategori …</option>
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

            <label className="text-sm font-medium text-neutral-700">
                Beløp
                <input
                    type="number"
                    step="0.01"
                    min="0"
                    value={amount}
                    onChange={(e) => setAmount(e.target.value)}
                    className="mt-1 block w-32 rounded-lg border border-neutral-300 px-3 py-2 text-right focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <button
                type="submit"
                disabled={busy}
                className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
            >
                {busy ? 'Lagrer …' : 'Overfør'}
            </button>

            {error && <p className="w-full text-sm text-red-600">{error}</p>}
        </form>
    );
}

function NewTransactionForm({
    accountId,
    groups,
    categorizable,
    onCreated,
}: {
    accountId: number;
    groups: CategoryGroup[];
    categorizable: boolean;
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
        const rta = categorizable && form.category_id === 'rta';
        const payload: NewTransaction = {
            date: form.date,
            amount: direction === 'out' ? -magnitude : magnitude,
            // Overvåkede kontoer: kategori er aldri relevant.
            category_id: !categorizable || rta || !form.category_id ? null : Number(form.category_id),
            rta,
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
            className="flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-4"
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

            {categorizable && groups.length > 0 && (
                <label className="text-sm font-medium text-neutral-700">
                    Kategori
                    <select
                        value={form.category_id}
                        onChange={(e) => setForm({ ...form, category_id: e.target.value })}
                        className="mt-1 block rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Ukategorisert</option>
                        <option value="rta">Klar til å fordele (RTA)</option>
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

type SplitLine = { category_id: string; amount: string };

const parseAmount = (s: string): number => Number(s.replace(',', '.'));

function CategoryOptions({ groups }: { groups: CategoryGroup[] }) {
    return (
        <>
            {groups.map((group) => (
                <optgroup key={group.id} label={group.name}>
                    {group.categories.map((category) => (
                        <option key={category.id} value={category.id}>
                            {category.name}
                        </option>
                    ))}
                </optgroup>
            ))}
        </>
    );
}

/** Linje-editor for å fordele et beløp på flere kategorier. */
function SplitEditor({
    lines,
    setLines,
    groups,
    target,
    rest,
}: {
    lines: SplitLine[];
    setLines: Dispatch<SetStateAction<SplitLine[]>>;
    groups: CategoryGroup[];
    target: number;
    rest: number;
}) {
    const balanced = Math.abs(rest) < 0.005;
    return (
        <div className="w-full space-y-2 rounded-lg bg-neutral-50 p-3 ring-1 ring-neutral-100">
            {lines.map((line, i) => (
                <div key={i} className="flex items-center gap-2">
                    <select
                        value={line.category_id}
                        onChange={(e) =>
                            setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, category_id: e.target.value } : l)))
                        }
                        className="min-w-0 flex-1 rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Velg kategori …</option>
                        <CategoryOptions groups={groups} />
                    </select>
                    <input
                        value={line.amount}
                        inputMode="decimal"
                        placeholder="0"
                        onChange={(e) =>
                            setLines((ls) => ls.map((l, idx) => (idx === i ? { ...l, amount: e.target.value } : l)))
                        }
                        className="w-28 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-neutral-900 focus:outline-none"
                    />
                    <button
                        type="button"
                        onClick={() => setLines((ls) => ls.filter((_, idx) => idx !== i))}
                        disabled={lines.length <= 2}
                        title="Fjern linje"
                        className="rounded px-1.5 py-1 text-neutral-400 hover:bg-neutral-200 hover:text-neutral-700 disabled:opacity-30"
                    >
                        ✕
                    </button>
                </div>
            ))}
            <div className="flex items-center justify-between pt-1">
                <button
                    type="button"
                    onClick={() => setLines((ls) => [...ls, { category_id: '', amount: '' }])}
                    className="text-xs font-medium text-neutral-500 hover:text-neutral-900"
                >
                    + Legg til linje
                </button>
                <span className={`text-xs tabular-nums ${balanced ? 'text-green-600' : 'text-amber-600'}`}>
                    Fordelt {formatNok(target - rest)} / {formatNok(target)} · Rest {formatNok(rest)}
                </span>
            </div>
        </div>
    );
}

function EditTransactionForm({
    tx,
    groups,
    categorizable,
    onSaved,
    onCancel,
}: {
    tx: Transaction;
    groups: CategoryGroup[];
    categorizable: boolean;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const isTransfer = tx.transfer_id !== null;
    const [date, setDate] = useState(tx.date);
    const [payee, setPayee] = useState(tx.payee ?? '');
    const [memo, setMemo] = useState(tx.memo ?? '');
    const [direction, setDirection] = useState<'out' | 'in'>(tx.amount < 0 ? 'out' : 'in');
    const [amount, setAmount] = useState(String(Math.abs(tx.amount)));
    // 'rta' = Klar til å fordele, '' = ukategorisert, ellers kategori-id.
    const [placement, setPlacement] = useState(tx.rta ? 'rta' : String(tx.category_id ?? ''));
    const [mode, setMode] = useState<'single' | 'split'>(tx.is_split ? 'split' : 'single');
    const [lines, setLines] = useState<SplitLine[]>(
        tx.is_split && tx.splits?.length
            ? tx.splits.map((s) => ({ category_id: String(s.category_id), amount: String(Math.abs(s.amount)) }))
            : [
                  { category_id: '', amount: '' },
                  { category_id: '', amount: '' },
              ],
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Overføringsben kan kun splittes (beløp/dato/mottaker styres av paret).
    const sign = isTransfer ? (tx.amount < 0 ? -1 : 1) : direction === 'out' ? -1 : 1;
    const target = isTransfer ? Math.abs(tx.amount) : Math.abs(parseAmount(amount) || 0);
    const splitSum = lines.reduce((s, l) => s + (parseAmount(l.amount) || 0), 0);
    const rest = Math.round((target - splitSum) * 100) / 100;

    async function submit(e: FormEvent) {
        e.preventDefault();
        setError(null);

        if (mode === 'split') {
            const valid = lines.filter((l) => l.category_id && parseAmount(l.amount) > 0);
            if (valid.length < 2) {
                setError('En splitt må ha minst to linjer med kategori og beløp.');
                return;
            }
            if (Math.abs(rest) > 0.005) {
                setError('Summen av splittene må være lik beløpet.');
                return;
            }
            setBusy(true);
            try {
                await updateTransaction(tx.id, {
                    ...(isTransfer
                        ? {}
                        : {
                              date,
                              amount: sign * target,
                              payee: payee || undefined,
                              memo: memo || undefined,
                          }),
                    splits: valid.map((l) => ({
                        category_id: Number(l.category_id),
                        amount: sign * parseAmount(l.amount),
                    })),
                });
                onSaved();
            } catch (err) {
                setError(apiErrorMessage(err, 'Kunne ikke lagre.'));
                setBusy(false);
            }
            return;
        }

        // Enkel kategori. En sendt kategori/RTA fjerner en evt. tidligere splitt (backend).
        const magnitude = Math.abs(parseAmount(amount));
        const rta = placement === 'rta';
        setBusy(true);
        try {
            await updateTransaction(tx.id, {
                date,
                amount: sign * magnitude,
                payee: payee || undefined,
                memo: memo || undefined,
                ...(categorizable
                    ? { category_id: rta || !placement ? null : Number(placement), rta }
                    : {}),
            });
            onSaved();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre.'));
            setBusy(false);
        }
    }

    // Overføringsben: kun splitt-editoren (resten styres av overføringsparet).
    if (isTransfer) {
        return (
            <form onSubmit={submit} className="space-y-3">
                <div className="text-xs font-medium text-neutral-600">
                    Splitt overføring på flere kategorier ({formatNok(tx.amount)})
                </div>
                <SplitEditor lines={lines} setLines={setLines} groups={groups} target={target} rest={rest} />
                {error && <p className="text-sm text-red-600">{error}</p>}
                <div className="flex gap-2">
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
                </div>
            </form>
        );
    }

    return (
        <form onSubmit={submit} className="space-y-3">
            <div className="flex flex-wrap items-end gap-3">
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
                        inputMode="decimal"
                        value={amount}
                        onChange={(e) => setAmount(e.target.value)}
                        className="mt-1 block w-28 rounded-lg border border-neutral-300 px-2 py-1.5 text-right text-sm tabular-nums focus:border-neutral-900 focus:outline-none"
                    />
                </label>
            </div>

            {categorizable && (
                <div className="space-y-2">
                    <div className="flex w-fit rounded-lg bg-neutral-100 p-1 text-sm">
                        {(['single', 'split'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                onClick={() => setMode(m)}
                                className={`rounded-md px-3 py-1 font-medium transition ${
                                    mode === m
                                        ? 'bg-white text-neutral-900 shadow-sm'
                                        : 'text-neutral-500 hover:text-neutral-800'
                                }`}
                            >
                                {m === 'single' ? 'Én kategori' : 'Splitt'}
                            </button>
                        ))}
                    </div>

                    {mode === 'single' ? (
                        <label className="block text-xs font-medium text-neutral-600">
                            Kategori
                            <select
                                value={placement}
                                onChange={(e) => setPlacement(e.target.value)}
                                className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                            >
                                <option value="">Ukategorisert</option>
                                <option value="rta">Klar til å fordele (RTA)</option>
                                <CategoryOptions groups={groups} />
                            </select>
                        </label>
                    ) : (
                        <SplitEditor
                            lines={lines}
                            setLines={setLines}
                            groups={groups}
                            target={target}
                            rest={rest}
                        />
                    )}
                </div>
            )}

            {error && <p className="text-sm text-red-600">{error}</p>}
            <div className="flex gap-2">
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
            </div>
        </form>
    );
}
