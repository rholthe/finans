import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import Layout from '@/components/Layout';
import {
    apiErrorMessage,
    connectBank,
    deleteBankConnection,
    getSyncStatus,
    linkBankAccount,
    listAccounts,
    listBankConnections,
    listInstitutions,
    syncBank,
} from '@/lib/data';
import type { Account, BankAccountLink, BankConnection, Institution, SyncResult } from '@/types';

/** «3 av 4 igjen» + når kvoten nullstilles, fra sist kjente rate-limit. */
function rateLimitLabel(a: BankAccountLink): string | null {
    if (a.rate_limit_remaining === null) return null;
    const total = a.rate_limit ? ` av ${a.rate_limit}` : '';
    let reset = '';
    if (a.rate_limit_reset_at) {
        const hours = Math.max(0, Math.round((new Date(a.rate_limit_reset_at).getTime() - Date.now()) / 3_600_000));
        reset = hours > 0 ? `, nullstilles om ~${hours}t` : '';
    }
    return `${a.rate_limit_remaining}${total} synk igjen i dag${reset}`;
}

const SANDBOX_ID = 'SANDBOXFINANCE_SFIN0000';

const CALLBACK_MESSAGES: Record<string, { tone: 'ok' | 'error'; text: string }> = {
    connected: { tone: 'ok', text: 'Banken ble koblet til. Koble kontoene til budsjettkontoer nedenfor.' },
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
    const [chosen, setChosen] = useState(SANDBOX_ID);
    const [connecting, setConnecting] = useState(false);
    const [syncing, setSyncing] = useState(false);
    const [syncResult, setSyncResult] = useState<SyncResult | null>(null);
    const [error, setError] = useState<string | null>(null);

    const status = searchParams.get('status');
    const reason = searchParams.get('reason');
    const banner = status
        ? CALLBACK_MESSAGES[status === 'error' && reason ? `error:${reason}` : status]
        : undefined;

    function reloadConnections() {
        return listBankConnections().then(setConnections);
    }

    useEffect(() => {
        Promise.all([
            listInstitutions().then(setInstitutions).catch(() => setInstitutions([])),
            reloadConnections(),
            listAccounts().then(setAccounts),
        ]).finally(() => setLoading(false));
    }, []);

    async function connect() {
        setConnecting(true);
        setError(null);
        try {
            const link = await connectBank(chosen);
            window.location.href = link; // topp-nivå navigasjon til bankens samtykkeside
        } catch (e) {
            setError(apiErrorMessage(e, 'Kunne ikke starte banktilkobling.'));
            setConnecting(false);
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

    async function toggleIgnore(bankAccountId: number, ignored: boolean) {
        await linkBankAccount(bankAccountId, { ignored });
        reloadConnections();
    }

    async function removeConnection(connection: BankConnection) {
        if (!confirm(`Koble fra ${connection.name}? Importerte transaksjoner beholdes.`)) return;
        await deleteBankConnection(connection.id);
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
                    className={`mt-4 rounded-lg px-4 py-3 text-sm ${
                        banner.tone === 'ok' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-700'
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

            {syncResult && (
                <div className="mt-4 rounded-lg border border-neutral-200 bg-white p-4 text-sm">
                    <p className="font-medium">
                        Synk fullført ({syncResult.status}): {syncResult.imported_count} nye transaksjon(er).
                    </p>
                    {syncResult.report.length > 0 && (
                        <ul className="mt-2 space-y-0.5 text-xs text-neutral-500">
                            {syncResult.report.map((line, i) => (
                                <li key={i}>
                                    <span className="uppercase">{line.status}:</span> {line.message}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {error && <p className="mt-4 text-sm text-red-600">{error}</p>}

            {/* Koble til ny bank */}
            <div className="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-neutral-200 bg-white p-5">
                <label className="flex-1 text-sm font-medium text-neutral-700">
                    Velg bank
                    <select
                        value={chosen}
                        onChange={(e) => setChosen(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                    >
                        <option value={SANDBOX_ID}>Sandbox (test)</option>
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
                <p className="mt-8 text-neutral-500">Ingen tilkoblede banker ennå.</p>
            ) : (
                <div className="mt-8 space-y-6">
                    {connections.map((connection) => (
                        <section key={connection.id} className="rounded-xl border border-neutral-200 bg-white">
                            <div className="flex items-center justify-between border-b border-neutral-100 px-5 py-3">
                                <div className="flex items-center gap-2">
                                    <span className="font-semibold">{connection.name}</span>
                                    <span
                                        className={`rounded px-1.5 py-0.5 text-xs ${
                                            connection.status === 'LN'
                                                ? 'bg-green-100 text-green-700'
                                                : 'bg-amber-100 text-amber-700'
                                        }`}
                                    >
                                        {connection.status === 'LN' ? 'Tilkoblet' : connection.status}
                                    </span>
                                </div>
                                <button
                                    onClick={() => removeConnection(connection)}
                                    className="text-xs text-neutral-400 hover:text-red-600"
                                >
                                    Koble fra
                                </button>
                            </div>
                            <ul className="divide-y divide-neutral-100">
                                {connection.accounts.map((bankAccount) => (
                                    <li
                                        key={bankAccount.id}
                                        className="flex flex-wrap items-center justify-between gap-3 px-5 py-3"
                                    >
                                        <span className="text-sm text-neutral-600">
                                            {bankAccount.iban ?? bankAccount.external_id}
                                            {rateLimitLabel(bankAccount) && (
                                                <span className="ml-2 text-xs text-neutral-400">
                                                    ({rateLimitLabel(bankAccount)})
                                                </span>
                                            )}
                                        </span>
                                        <div className="flex items-center gap-3">
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
                                            <label className="flex items-center gap-1 text-xs text-neutral-500">
                                                <input
                                                    type="checkbox"
                                                    checked={bankAccount.ignored}
                                                    onChange={(e) => toggleIgnore(bankAccount.id, e.target.checked)}
                                                    className="h-4 w-4"
                                                />
                                                Ignorer
                                            </label>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </Layout>
    );
}
