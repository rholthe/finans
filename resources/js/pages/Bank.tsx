import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import InlineNameEdit from '@/components/InlineNameEdit';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import {
    apiErrorMessage,
    connectBank,
    deleteBankConnection,
    getSyncStatus,
    linkBankAccount,
    listAccounts,
    listBankConnections,
    listInstitutions,
    renameBankConnection,
    renewBankConnection,
    syncBank,
} from '@/lib/data';
import { formatDate } from '@/lib/format';
import {
    BANK_PROVIDER_LABELS,
    type Account,
    type BankAccountLink,
    type BankConnection,
    type BankProvider,
    type Institution,
    type SyncResult,
} from '@/types';

/** «3 av 4 igjen» + når kvoten nullstilles, fra sist kjente rate-limit. */
function rateLimitLabel(a: BankAccountLink): string | null {
    if (a.rate_limit_remaining === null) return null;
    // Utløpt rate-limit-vindu: tallene er utdaterte (kvoten er nullstilt), så
    // ikke vis dem. Enable Banking sender ingen nye tall ved vellykket synk, så
    // uten denne sjekken ville «0 synk igjen i dag» henge igjen til neste synk.
    if (a.rate_limit_reset_at && new Date(a.rate_limit_reset_at).getTime() <= Date.now()) return null;
    const total = a.rate_limit ? ` av ${a.rate_limit}` : '';
    let reset = '';
    if (a.rate_limit_reset_at) {
        const hours = Math.max(0, Math.round((new Date(a.rate_limit_reset_at).getTime() - Date.now()) / 3_600_000));
        reset = hours > 0 ? `, nullstilles om ~${hours}t` : '';
    }
    return `${a.rate_limit_remaining}${total} synk igjen i dag${reset}`;
}

const SANDBOX_ID = 'SANDBOXFINANCE_SFIN0000';

/** Normaliserte «linket»-statuser på tvers av leverandører (GoCardless «LN», Enable Banking «AUTHORIZED»/«VALID»). */
function isLinked(status: string): boolean {
    return ['LN', 'AUTHORIZED', 'VALID'].includes(status);
}

/** Dager til utløp (negativt = utløpt), eller null hvis ukjent. */
function daysUntil(iso: string | null): number | null {
    if (!iso) return null;
    const ms = new Date(iso).getTime() - Date.now();
    return Math.ceil(ms / 86_400_000);
}

const EXPIRY_WARNING_DAYS = 14;

const CALLBACK_MESSAGES: Record<string, { tone: 'ok' | 'error'; text: string }> = {
    connected: { tone: 'ok', text: 'Banken ble koblet til. Koble kontoene til budsjettkontoer nedenfor.' },
    renewed: { tone: 'ok', text: 'Godkjenningen ble fornyet. Kontokoblingene er beholdt.' },
    'error:token': { tone: 'error', text: 'Sikkerhetstoken stemte ikke. Prøv tilkoblingen på nytt.' },
    'error:session': { tone: 'error', text: 'Økten utløp. Prøv tilkoblingen på nytt.' },
    'error:duplicate': { tone: 'error', text: 'Denne banken/kontoen er allerede tilkoblet.' },
    'error:api': { tone: 'error', text: 'Noe gikk galt mot banken. Prøv igjen senere.' },
};

export default function Bank() {
    const [searchParams, setSearchParams] = useSearchParams();
    const [institutions, setInstitutions] = useState<Institution[]>([]);
    const [connections, setConnections] = useState<BankConnection[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [provider, setProvider] = useState<BankProvider>('gocardless');
    const [chosen, setChosen] = useState(SANDBOX_ID);
    const [connecting, setConnecting] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [syncResult, setSyncResult] = useState<SyncResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [disconnecting, setDisconnecting] = useState<BankConnection | null>(null);

    const status = searchParams.get('status');
    const reason = searchParams.get('reason');
    const banner = status
        ? CALLBACK_MESSAGES[status === 'error' && reason ? `error:${reason}` : status]
        : undefined;

    function reloadConnections() {
        return listBankConnections().then(setConnections);
    }

    useEffect(() => {
        Promise.all([reloadConnections(), listAccounts().then(setAccounts)]).finally(() =>
            setLoading(false),
        );
    }, []);

    // Institusjonslisten avhenger av valgt leverandør.
    useEffect(() => {
        let active = true;
        listInstitutions(provider)
            .then((list) => active && setInstitutions(list))
            .catch(() => active && setInstitutions([]));
        // GoCardless har en sandbox-bank; andre leverandører velger første ekte bank.
        setChosen(provider === 'gocardless' ? SANDBOX_ID : '');
        return () => {
            active = false;
        };
    }, [provider]);

    async function connect() {
        if (!chosen) {
            setError('Velg en bank.');
            return;
        }
        setConnecting(true);
        setError(null);
        try {
            const link = await connectBank(provider, chosen);
            window.location.href = link; // topp-nivå navigasjon til bankens samtykkeside
        } catch (e) {
            setError(apiErrorMessage(e, 'Kunne ikke starte banktilkobling.'));
            setConnecting(false);
        }
    }

    async function renew(connection: BankConnection) {
        setError(null);
        try {
            const link = await renewBankConnection(connection.id);
            window.location.href = link; // topp-nivå navigasjon til bankens samtykkeside
        } catch (e) {
            setError(apiErrorMessage(e, 'Kunne ikke starte fornying.'));
        }
    }

    async function runSync() {
        setSyncing(true);
        setError(null);
        setSyncResult(null);
        try {
            const started = await syncBank(); // køet → status «processing»
            // Poll til jobben er ferdig.
            let result = started;
            while (!result.finished) {
                await new Promise((r) => setTimeout(r, 1500));
                result = await getSyncStatus(started.id);
            }
            setSyncResult(result);
            await reloadConnections();
        } catch (e) {
            setError(apiErrorMessage(e, 'Synk feilet.'));
        } finally {
            setSyncing(false);
        }
    }

    async function setLink(bankAccountId: number, accountId: number | null) {
        await linkBankAccount(bankAccountId, { account_id: accountId });
        reloadConnections();
    }

    async function renameConnection(id: number, name: string) {
        if (!name) return;
        await renameBankConnection(id, name);
        reloadConnections();
    }

    async function renameAccount(bankAccountId: number, name: string) {
        await linkBankAccount(bankAccountId, { name: name || null });
        reloadConnections();
    }

    async function confirmDisconnect() {
        if (!disconnecting) return;
        await deleteBankConnection(disconnecting.id);
        setDisconnecting(null);
        reloadConnections();
    }

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Bank</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Koble til banken din via GoCardless og importer transaksjoner automatisk.
                    </p>
                </div>
                {connections.length > 0 && (
                    <button
                        onClick={runSync}
                        disabled={syncing}
                        className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                    >
                        {syncing ? 'Synkroniserer …' : 'Synk nå'}
                    </button>
                )}
            </div>

            {banner && (
                <div
                    className={`mt-4 rounded-xl px-4 py-3 text-sm ring-1 ${
                        banner.tone === 'ok'
                            ? 'bg-green-50 text-green-800 ring-green-100'
                            : 'bg-red-50 text-red-700 ring-red-100'
                    }`}
                >
                    <div className="flex items-center justify-between">
                        <span>{banner.text}</span>
                        <button
                            onClick={() => setSearchParams({}, { replace: true })}
                            className="text-xs underline opacity-70 hover:opacity-100"
                        >
                            Lukk
                        </button>
                    </div>
                </div>
            )}

            {syncResult && <SyncResultCard result={syncResult} onClose={() => setSyncResult(null)} />}

            {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

            {/* Koble til ny bank */}
            <div className="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-5">
                <label className="text-sm font-medium text-neutral-700">
                    Leverandør
                    <select
                        value={provider}
                        onChange={(e) => setProvider(e.target.value as BankProvider)}
                        className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                    >
                        {(Object.keys(BANK_PROVIDER_LABELS) as BankProvider[]).map((key) => (
                            <option key={key} value={key}>
                                {BANK_PROVIDER_LABELS[key]}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="flex-1 text-sm font-medium text-neutral-700">
                    Velg bank
                    <select
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                    >
                        {provider === 'gocardless' && <option value={SANDBOX_ID}>Sandbox (test)</option>}
                        {provider !== 'gocardless' && <option value="">Velg bank …</option>}
                        {institutions.map((inst) => (
                            <option key={inst.id} value={inst.id}>
                                {inst.name}
                            </option>
                        ))}
                    </select>
                </label>
                <button
                    onClick={connect}
                    disabled={connecting}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                >
                    {connecting ? 'Kobler til …' : 'Koble til'}
                </button>
            </div>

            {/* Tilkoblede banker */}
            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : connections.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen tilkoblede banker ennå. Koble til banken din over for å importere transaksjoner.
                </div>
            ) : (
                <div className="mt-8 space-y-6">
                    {connections.map((connection) => {
                        const days = daysUntil(connection.valid_until);
                        const expired = days !== null && days <= 0;
                        const expiringSoon = days !== null && days > 0 && days <= EXPIRY_WARNING_DAYS;
                        return (
                        <section
                            key={connection.id}
                            className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm"
                        >
                            <div className="flex items-center justify-between gap-3 border-b border-neutral-100 px-5 py-3">
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <span
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-lg text-sky-700"
                                        aria-hidden
                                    >
                                        🏦
                                    </span>
                                    <InlineNameEdit
                                        display={connection.name}
                                        initial={connection.name}
                                        placeholder="Bankens navn"
                                        onSave={(name) => renameConnection(connection.id, name)}
                                        className="min-w-0 font-semibold"
                                    />
                                    <span className="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-500">
                                        {BANK_PROVIDER_LABELS[connection.provider] ?? connection.provider}
                                    </span>
                                    <span
                                        className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                                            isLinked(connection.status)
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-amber-100 text-amber-700'
                                        }`}
                                    >
                                        {isLinked(connection.status) ? 'Tilkoblet' : connection.status}
                                    </span>
                                    <span className="shrink-0 rounded-full bg-neutral-50 px-2 py-0.5 text-xs font-medium text-neutral-400">
                                        {connection.accounts.length}{' '}
                                        {connection.accounts.length === 1 ? 'konto' : 'kontoer'}
                                    </span>
                                    {(expired || expiringSoon) && (
                                        <span
                                            title={
                                                connection.valid_until
                                                    ? `Godkjenningen utløper ${formatDate(connection.valid_until)}`
                                                    : undefined
                                            }
                                            className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${
                                                expired
                                                    ? 'bg-red-100 text-red-700'
                                                    : 'bg-amber-100 text-amber-700'
                                            }`}
                                        >
                                            {expired
                                                ? 'Godkjenning utløpt'
                                                : `Utløper om ${days} ${days === 1 ? 'dag' : 'dager'}`}
                                        </span>
                                    )}
                                </div>
                                <div className="flex shrink-0 items-center gap-3">
                                    <button
                                        onClick={() => renew(connection)}
                                        className={`text-xs font-medium ${
                                            expired || expiringSoon
                                                ? 'text-sky-600 hover:text-sky-800'
                                                : 'text-neutral-400 hover:text-neutral-900'
                                        }`}
                                    >
                                        Forny
                                    </button>
                                    <button
                                        onClick={() => setDisconnecting(connection)}
                                        className="text-xs text-neutral-400 hover:text-red-600"
                                    >
                                        Koble fra
                                    </button>
                                </div>
                            </div>
                            <ul className="divide-y divide-neutral-100">
                                {connection.accounts.map((bankAccount) => (
                                    <li
                                        key={bankAccount.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <span className="text-sm text-neutral-600">
                                            <InlineNameEdit
                                                display={
                                                    bankAccount.name ||
                                                    bankAccount.iban ||
                                                    bankAccount.external_id
                                                }
                                                initial={bankAccount.name ?? ''}
                                                placeholder={bankAccount.iban ?? bankAccount.external_id}
                                                onSave={(name) => renameAccount(bankAccount.id, name)}
                                            />
                                            {rateLimitLabel(bankAccount) && (
                                                <span className="ml-2 text-xs text-neutral-400">
                                                    ({rateLimitLabel(bankAccount)})
                                                </span>
                                            )}
                                        </span>
                                        <label className="text-xs text-neutral-500">
                                            Budsjettkonto
                                            <select
                                                value={bankAccount.account_id ?? ''}
                                                onChange={(e) =>
                                                    setLink(
                                                        bankAccount.id,
                                                        e.target.value ? Number(e.target.value) : null,
                                                    )
                                                }
                                                className="ml-2 rounded-lg border border-neutral-300 px-2 py-1 text-sm focus:border-neutral-900 focus:outline-none"
                                            >
                                                <option value="">Ikke koblet</option>
                                                {accounts.map((account) => (
                                                    <option key={account.id} value={account.id}>
                                                        {account.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </label>
                                    </li>
                                ))}
                            </ul>
                        </section>
                        );
                    })}
                </div>
            )}

            {disconnecting && (
                <DisconnectModal
                    connection={disconnecting}
                    onClose={() => setDisconnecting(null)}
                    onConfirm={confirmDisconnect}
                />
            )}
        </Layout>
    );
}

/** Fargetoner per rapportlinje-status fra banksynk-jobben. */
const REPORT_TONE: Record<string, { dot: string; text: string }> = {
    info: { dot: 'bg-neutral-300', text: 'text-neutral-500' },
    warn: { dot: 'bg-amber-400', text: 'text-amber-700' },
    error: { dot: 'bg-red-500', text: 'text-red-600' },
};

/** Resultatkort etter en banksynk: oppsummering + linjevis rapport, kan lukkes. */
function SyncResultCard({ result, onClose }: { result: SyncResult; onClose: () => void }) {
    const hasErrors = result.report.some((line) => line.status === 'error');
    const count = result.imported_count;

    return (
        <div className="mt-4 overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
            <div className="flex items-start justify-between gap-3 border-b border-neutral-100 px-4 py-3">
                <div className="flex items-center gap-2.5">
                    <span
                        className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg ${
                            hasErrors ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700'
                        }`}
                        aria-hidden
                    >
                        {hasErrors ? '⚠️' : '✅'}
                    </span>
                    <div>
                        <p className="font-medium text-neutral-900">
                            {hasErrors ? 'Synk fullført med merknader' : 'Synk fullført'}
                        </p>
                        <p className="text-xs text-neutral-500">
                            {count} {count === 1 ? 'ny transaksjon' : 'nye transaksjoner'} importert
                        </p>
                    </div>
                </div>
                <button
                    onClick={onClose}
                    aria-label="Lukk"
                    className="-mr-1 rounded-lg p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-900"
                >
                    ✕
                </button>
            </div>
            {result.report.length > 0 && (
                <ul className="px-4 py-2 text-sm">
                    {result.report.map((line, i) =>
                        line.status === 'header' ? (
                            <li
                                key={i}
                                className="pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-neutral-700 first:pt-1"
                            >
                                {line.message}
                            </li>
                        ) : (
                            <li key={i} className="flex items-start gap-2 py-1">
                                <span
                                    className={`mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full ${
                                        (REPORT_TONE[line.status] ?? REPORT_TONE.info).dot
                                    }`}
                                    aria-hidden
                                />
                                <span className={(REPORT_TONE[line.status] ?? REPORT_TONE.info).text}>
                                    {line.message}
                                </span>
                            </li>
                        ),
                    )}
                </ul>
            )}
        </div>
    );
}

/** Bekreftelse før en banktilkobling kobles fra (importerte transaksjoner beholdes). */
function DisconnectModal({
    connection,
    onClose,
    onConfirm,
}: {
    connection: BankConnection;
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
            title="Koble fra banken?"
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
                        {busy ? 'Kobler fra …' : 'Koble fra'}
                    </button>
                </>
            }
        >
            <p className="text-sm text-neutral-600">
                Vil du koble fra <span className="font-medium text-neutral-800">{connection.name}</span>?
                Importerte transaksjoner beholdes, men fremtidig synk stoppes.
            </p>
        </Modal>
    );
}
