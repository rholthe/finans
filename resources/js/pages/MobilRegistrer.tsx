import { useEffect, useMemo, useState, type FormEvent } from 'react';
import { Link } from 'react-router-dom';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import { apiErrorMessage, createTransaction, listAccounts, type NewTransaction } from '@/lib/data';
import { formatNok, todayIso } from '@/lib/format';
import { ACCOUNT_TYPE_LABELS, type Account } from '@/types';

/**
 * Mobil hurtigregistrering: store knapper for å føre en transaksjon på en
 * ikke-banksynket budsjettkonto (typisk kredittkort). Kun ikke-koblede
 * budsjettkontoer er velgbare; overvåkede kontoer kan ikke velges. Dato er
 * forvalgt til i dag, mottaker og beløp er obligatoriske, notat er valgfritt.
 * Lagring krever en bekreftelse. Kategorisering gjøres senere (på desktop).
 */
export default function MobilRegistrer() {
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);

    const [accountId, setAccountId] = useState('');
    const [direction, setDirection] = useState<'out' | 'in'>('out');
    const [amount, setAmount] = useState('');
    const [payee, setPayee] = useState('');
    const [date, setDate] = useState(todayIso());
    const [memo, setMemo] = useState('');

    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [savedNotice, setSavedNotice] = useState<string | null>(null);

    // Kun ikke-banksynkede, åpne budsjettkontoer. Overvåkede (off-budget) og
    // banksynkede kontoer er ikke velgbare.
    const selectable = useMemo(
        () => accounts.filter((a) => a.on_budget && !a.bank_synced && !a.closed),
        [accounts],
    );

    useEffect(() => {
        listAccounts()
            .then(setAccounts)
            .finally(() => setLoading(false));
    }, []);

    // Forvelg første velgbare konto når lista er lastet.
    useEffect(() => {
        if (!accountId && selectable.length > 0) {
            setAccountId(String(selectable[0].id));
        }
    }, [selectable, accountId]);

    const magnitude = Math.abs(Number(amount.replace(',', '.')));
    const signedAmount = direction === 'out' ? -magnitude : magnitude;
    const account = selectable.find((a) => String(a.id) === accountId);

    function openConfirm(e: FormEvent) {
        e.preventDefault();
        setError(null);
        if (!account) {
            setError('Velg en konto.');
            return;
        }
        if (!payee.trim()) {
            setError('Mottaker er obligatorisk.');
            return;
        }
        if (!magnitude) {
            setError('Oppgi et beløp.');
            return;
        }
        setConfirming(true);
    }

    async function save() {
        if (!account) return;
        setSaving(true);
        setError(null);
        const payload: NewTransaction = {
            date,
            amount: signedAmount,
            category_id: null,
            rta: false,
            payee: payee.trim(),
            memo: memo.trim() || undefined,
        };
        try {
            await createTransaction(account.id, payload);
            setConfirming(false);
            setSavedNotice(`Lagret ${formatNok(signedAmount)} – ${payee.trim()}.`);
            // Behold konto, dato og retning for raske påfølgende registreringer.
            setAmount('');
            setPayee('');
            setMemo('');
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre transaksjonen.'));
            setConfirming(false);
        } finally {
            setSaving(false);
        }
    }

    const bigField =
        'mt-1 w-full rounded-xl border border-neutral-300 px-4 py-3 text-lg focus:border-neutral-900 focus:outline-none';

    return (
        <Layout>
            <div className="mx-auto max-w-md">
                <h1 className="text-2xl font-semibold">Registrer transaksjon</h1>
                <p className="mt-1 text-sm text-neutral-500">
                    Rask føring på en konto uten banksynk (f.eks. kredittkort). Kategoriser senere.
                </p>

                {savedNotice && (
                    <div className="mt-4 rounded-xl bg-green-50 px-4 py-3 text-sm text-green-800 ring-1 ring-green-100">
                        ✓ {savedNotice}
                    </div>
                )}

                {loading ? (
                    <p className="mt-8 text-neutral-400">Laster …</p>
                ) : selectable.length === 0 ? (
                    <div className="mt-6 rounded-xl border border-dashed border-neutral-300 p-6 text-center text-neutral-500">
                        Ingen ikke-koblede budsjettkontoer å føre på. Opprett en konto under{' '}
                        <Link to="/kontoer" className="font-medium text-neutral-900 underline">
                            Kontoer
                        </Link>
                        .
                    </div>
                ) : (
                    <form onSubmit={openConfirm} className="mt-6 space-y-5">
                        <label className="block text-sm font-medium text-neutral-700">
                            Konto
                            <select
                                value={accountId}
                                onChange={(e) => setAccountId(e.target.value)}
                                className={bigField}
                            >
                                {selectable.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name} ({ACCOUNT_TYPE_LABELS[a.type]})
                                    </option>
                                ))}
                            </select>
                        </label>

                        {/* Retning: store toggle-knapper. */}
                        <div className="grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                onClick={() => setDirection('out')}
                                className={`rounded-xl py-4 text-base font-semibold ring-1 transition ${
                                    direction === 'out'
                                        ? 'bg-red-600 text-white ring-red-600'
                                        : 'bg-white text-neutral-600 ring-neutral-300'
                                }`}
                            >
                                − Utgift
                            </button>
                            <button
                                type="button"
                                onClick={() => setDirection('in')}
                                className={`rounded-xl py-4 text-base font-semibold ring-1 transition ${
                                    direction === 'in'
                                        ? 'bg-green-600 text-white ring-green-600'
                                        : 'bg-white text-neutral-600 ring-neutral-300'
                                }`}
                            >
                                + Inntekt
                            </button>
                        </div>

                        <label className="block text-sm font-medium text-neutral-700">
                            Beløp
                            <input
                                type="number"
                                inputMode="decimal"
                                step="0.01"
                                min="0"
                                value={amount}
                                onChange={(e) => setAmount(e.target.value)}
                                placeholder="0,00"
                                className={`${bigField} text-right text-2xl tabular-nums`}
                            />
                        </label>

                        <label className="block text-sm font-medium text-neutral-700">
                            Mottaker
                            <input
                                value={payee}
                                onChange={(e) => setPayee(e.target.value)}
                                placeholder="Hvor / til hvem"
                                className={bigField}
                            />
                        </label>

                        <label className="block text-sm font-medium text-neutral-700">
                            Dato
                            <input
                                type="date"
                                value={date}
                                onChange={(e) => setDate(e.target.value)}
                                className={bigField}
                            />
                        </label>

                        <label className="block text-sm font-medium text-neutral-700">
                            Notat <span className="font-normal text-neutral-400">(valgfritt)</span>
                            <input
                                value={memo}
                                onChange={(e) => setMemo(e.target.value)}
                                className={bigField}
                            />
                        </label>

                        {error && <p className="text-sm text-red-600">{error}</p>}

                        <button
                            type="submit"
                            className="w-full rounded-xl bg-neutral-900 py-4 text-lg font-semibold text-white hover:bg-neutral-700"
                        >
                            Lagre
                        </button>
                    </form>
                )}
            </div>

            {confirming && account && (
                <Modal
                    title="Bekreft registrering"
                    size="sm"
                    onClose={() => !saving && setConfirming(false)}
                    footer={
                        <>
                            <button
                                type="button"
                                onClick={() => setConfirming(false)}
                                disabled={saving}
                                className="rounded-lg px-4 py-2 text-sm font-medium text-neutral-500 hover:bg-neutral-100 disabled:opacity-50"
                            >
                                Avbryt
                            </button>
                            <button
                                type="button"
                                onClick={save}
                                disabled={saving}
                                className="rounded-lg bg-neutral-900 px-5 py-2 text-sm font-semibold text-white hover:bg-neutral-700 disabled:opacity-50"
                            >
                                {saving ? 'Lagrer …' : 'Bekreft og lagre'}
                            </button>
                        </>
                    }
                >
                    <dl className="space-y-2 text-sm">
                        <div className="flex justify-between gap-4">
                            <dt className="text-neutral-500">Konto</dt>
                            <dd className="font-medium text-neutral-800">{account.name}</dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-neutral-500">Beløp</dt>
                            <dd
                                className={`font-semibold tabular-nums ${
                                    signedAmount < 0 ? 'text-red-600' : 'text-green-700'
                                }`}
                            >
                                {formatNok(signedAmount)}
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-neutral-500">Mottaker</dt>
                            <dd className="font-medium text-neutral-800">{payee.trim()}</dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-neutral-500">Dato</dt>
                            <dd className="text-neutral-800">{date}</dd>
                        </div>
                        {memo.trim() && (
                            <div className="flex justify-between gap-4">
                                <dt className="text-neutral-500">Notat</dt>
                                <dd className="text-neutral-800">{memo.trim()}</dd>
                            </div>
                        )}
                    </dl>
                </Modal>
            )}
        </Layout>
    );
}
