import { useState, type FormEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/auth';

export default function Login() {
    const { login } = useAuth();
    const navigate = useNavigate();
    const [password, setPassword] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    async function onSubmit(e: FormEvent) {
        e.preventDefault();
        setError(null);
        setBusy(true);
        try {
            await login(password);
            navigate('/', { replace: true });
        } catch {
            setError('Feil passord.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-neutral-50 px-4">
            <form
                onSubmit={onSubmit}
                className="w-full max-w-sm rounded-2xl bg-white p-8 shadow-sm ring-1 ring-neutral-200"
            >
                <h1 className="text-xl font-semibold text-neutral-900">Finans</h1>
                <p className="mt-1 text-sm text-neutral-500">Logg inn for å fortsette</p>

                <label className="mt-6 block text-sm font-medium text-neutral-700">
                    Passord
                    <input
                        type="password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        autoFocus
                        className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 text-neutral-900 focus:border-neutral-900 focus:outline-none"
                    />
                </label>

                {error && <p className="mt-3 text-sm text-red-600">{error}</p>}

                <button
                    type="submit"
                    disabled={busy}
                    className="mt-6 w-full rounded-lg bg-neutral-900 px-4 py-2 font-medium text-white transition hover:bg-neutral-700 disabled:opacity-50"
                >
                    {busy ? 'Logger inn …' : 'Logg inn'}
                </button>
            </form>
        </div>
    );
}
