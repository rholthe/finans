import { useEffect, useState, type FormEvent } from 'react';
import Layout from '@/components/Layout';
import { apiErrorMessage, getSettings, updateSettings } from '@/lib/data';

export default function Innstillinger() {
    const [manualDays, setManualDays] = useState('10');
    const [autoDays, setAutoDays] = useState('5');
    const [reportEmail, setReportEmail] = useState('');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [notice, setNotice] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        getSettings()
            .then((s) => {
                setManualDays(String(s.manual_sync_days));
                setAutoDays(String(s.auto_sync_days));
                setReportEmail(s.report_email ?? '');
            })
            .finally(() => setLoading(false));
    }, []);

    async function save(e: FormEvent) {
        e.preventDefault();
        setBusy(true);
        setNotice(null);
        setError(null);
        try {
            await updateSettings({
                manual_sync_days: Number(manualDays),
                auto_sync_days: Number(autoDays),
                report_email: reportEmail.trim() || null,
            });
            setNotice('Innstillinger lagret.');
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre innstillingene.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <Layout>
            <div>
                <h1 className="text-2xl font-semibold">Innstillinger</h1>
                <p className="mt-1 text-sm text-neutral-500">
                    Banksynk og e-postvarsler for appen.
                </p>
            </div>

            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : (
                <form onSubmit={save} className="mt-6 max-w-2xl space-y-6">
                    {/* Banksynk */}
                    <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                        <header className="flex items-center gap-2.5 border-b border-neutral-100 px-5 py-3">
                            <span
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-sky-100 text-lg text-sky-700"
                                aria-hidden
                            >
                                🔄
                            </span>
                            <div>
                                <h2 className="font-semibold text-neutral-900">Banksynk</h2>
                                <p className="text-xs text-neutral-500">
                                    Hvor mange dager bakover transaksjoner hentes. Hold tallene lave for å
                                    spare på bankenes rate-limit (4 spørringer per konto per døgn).
                                </p>
                            </div>
                        </header>
                        <div className="grid gap-5 px-5 py-5 sm:grid-cols-2">
                            <label className="block text-sm font-medium text-neutral-700">
                                Dager ved manuell synk
                                <input
                                    type="number"
                                    min="1"
                                    max="30"
                                    value={manualDays}
                                    onChange={(e) => setManualDays(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                                />
                                <span className="mt-1 block text-xs font-normal text-neutral-400">1–30 dager</span>
                            </label>

                            <label className="block text-sm font-medium text-neutral-700">
                                Dager ved automatisk (nattlig) synk
                                <input
                                    type="number"
                                    min="1"
                                    max="10"
                                    value={autoDays}
                                    onChange={(e) => setAutoDays(e.target.value)}
                                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                                />
                                <span className="mt-1 block text-xs font-normal text-neutral-400">1–10 dager</span>
                            </label>
                        </div>
                    </section>

                    {/* E-postvarsler */}
                    <section className="overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-sm">
                        <header className="flex items-center gap-2.5 border-b border-neutral-100 px-5 py-3">
                            <span
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-lg text-amber-700"
                                aria-hidden
                            >
                                ✉️
                            </span>
                            <div>
                                <h2 className="font-semibold text-neutral-900">E-postvarsler</h2>
                                <p className="text-xs text-neutral-500">
                                    Adressen som mottar rapport etter hver synk, og varsel når en
                                    bankgodkjenning snart utløper. La stå tom for å skru av.
                                </p>
                            </div>
                        </header>
                        <div className="px-5 py-5">
                            <label className="block text-sm font-medium text-neutral-700">
                                Mottaker
                                <input
                                    type="email"
                                    value={reportEmail}
                                    onChange={(e) => setReportEmail(e.target.value)}
                                    placeholder="din@epost.no"
                                    className="mt-1 w-full max-w-sm rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                                />
                            </label>
                        </div>
                    </section>

                    {/* Lagre */}
                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                        >
                            {busy ? 'Lagrer …' : 'Lagre'}
                        </button>
                        {notice && (
                            <span className="rounded-lg bg-green-50 px-3 py-1.5 text-sm text-green-800 ring-1 ring-green-100">
                                ✓ {notice}
                            </span>
                        )}
                        {error && (
                            <span className="rounded-lg bg-red-50 px-3 py-1.5 text-sm text-red-700 ring-1 ring-red-100">
                                {error}
                            </span>
                        )}
                    </div>
                </form>
            )}
        </Layout>
    );
}
