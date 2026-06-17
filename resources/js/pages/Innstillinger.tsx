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
            <h1 className="text-2xl font-semibold">Innstillinger</h1>

            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : (
                <form
                    onSubmit={save}
                    className="mt-6 max-w-lg space-y-5 rounded-xl border border-neutral-200 bg-white p-6"
                >
                    <div>
                        <h2 className="text-sm font-semibold text-neutral-800">Banksynk</h2>
                        <p className="mt-1 text-xs text-neutral-500">
                            Hvor mange dager bakover transaksjoner hentes. Hold tallene lave for å
                            spare på bankenes rate-limit (4 spørringer per konto per døgn).
                        </p>
                    </div>

                    <label className="block text-sm font-medium text-neutral-700">
                        Dager ved manuell synk
                        <input
                            type="number"
                            min="1"
                            max="30"
                            value={manualDays}
                            onChange={(e) => setManualDays(e.target.value)}
                            className="mt-1 w-32 rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                        />
                        <span className="ml-2 text-xs text-neutral-400">1–30</span>
                    </label>

                    <label className="block text-sm font-medium text-neutral-700">
                        Dager ved automatisk (nattlig) synk
                        <input
                            type="number"
                            min="1"
                            max="10"
                            value={autoDays}
                            onChange={(e) => setAutoDays(e.target.value)}
                            className="mt-1 w-32 rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                        />
                        <span className="ml-2 text-xs text-neutral-400">1–10</span>
                    </label>

                    <div className="border-t border-neutral-100 pt-5">
                        <h2 className="text-sm font-semibold text-neutral-800">Synkrapport på e-post</h2>
                        <p className="mt-1 text-xs text-neutral-500">
                            Adressen som mottar rapport etter hver synk, og varsel når en
                            bankgodkjenning snart utløper. La stå tom for å skru av.
                        </p>
                    </div>

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

                    <div className="flex items-center gap-3">
                        <button
                            type="submit"
                            disabled={busy}
                            className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                        >
                            {busy ? 'Lagrer …' : 'Lagre'}
                        </button>
                        {notice && <p className="text-sm text-green-700">{notice}</p>}
                        {error && <p className="text-sm text-red-600">{error}</p>}
                    </div>
                </form>
            )}
        </Layout>
    );
}
