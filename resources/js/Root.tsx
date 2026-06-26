import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/auth';
import Login from '@/pages/Login';
import Budget from '@/pages/Budget';
import Planlagte from '@/pages/Planlagte';
import Bank from '@/pages/Bank';
import Regler from '@/pages/Regler';
import Innstillinger from '@/pages/Innstillinger';
import Accounts from '@/pages/Accounts';
import AccountDetail from '@/pages/AccountDetail';
import Sok from '@/pages/Sok';
import MobilRegistrer from '@/pages/MobilRegistrer';
import { lazy, Suspense, type ReactNode } from 'react';

/** Smal skjerm = mobiltelefon (under Tailwind `md`). */
function isMobileViewport(): boolean {
    return typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches;
}

// Når appen åpnes på mobil sendes man én gang til hurtigregistreringen – den er
// «første side» på telefon. Etterpå viser «/» budsjettet som vanlig (flagget
// nullstilles kun ved full sideinnlasting, dvs. neste gang appen åpnes).
let mobileLandingDone = false;

function Home() {
    if (!mobileLandingDone && isMobileViewport()) {
        mobileLandingDone = true;
        return <Navigate to="/registrer" replace />;
    }
    return <Budget />;
}

// Rapporter drar inn recharts; lastes først når siden besøkes (code-splitting).
const Rapporter = lazy(() => import('@/pages/Rapporter'));

function RequireAuth({ children }: { children: ReactNode }) {
    const { authenticated, loading } = useAuth();
    if (loading) {
        return (
            <div className="flex min-h-screen items-center justify-center text-neutral-400">
                Laster …
            </div>
        );
    }
    return authenticated ? <>{children}</> : <Navigate to="/login" replace />;
}

function GuestOnly({ children }: { children: ReactNode }) {
    const { authenticated, loading } = useAuth();
    if (loading) return null;
    return authenticated ? <Navigate to="/" replace /> : <>{children}</>;
}

export default function App() {
    return (
        <AuthProvider>
            <BrowserRouter>
                <Routes>
                    <Route path="/login" element={<GuestOnly><Login /></GuestOnly>} />
                    <Route path="/" element={<RequireAuth><Home /></RequireAuth>} />
                    <Route path="/registrer" element={<RequireAuth><MobilRegistrer /></RequireAuth>} />
                    <Route path="/planlagte" element={<RequireAuth><Planlagte /></RequireAuth>} />
                    <Route path="/kontoer" element={<RequireAuth><Accounts /></RequireAuth>} />
                    <Route path="/sok" element={<RequireAuth><Sok /></RequireAuth>} />
                    <Route
                        path="/rapporter"
                        element={
                            <RequireAuth>
                                <Suspense fallback={<div className="p-8 text-neutral-400">Laster …</div>}>
                                    <Rapporter />
                                </Suspense>
                            </RequireAuth>
                        }
                    />
                    <Route path="/bank" element={<RequireAuth><Bank /></RequireAuth>} />
                    <Route path="/regler" element={<RequireAuth><Regler /></RequireAuth>} />
                    <Route path="/innstillinger" element={<RequireAuth><Innstillinger /></RequireAuth>} />
                    <Route path="/accounts/:id" element={<RequireAuth><AccountDetail /></RequireAuth>} />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    );
}
