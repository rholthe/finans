import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider, useAuth } from '@/auth';
import Login from '@/pages/Login';
import Budget from '@/pages/Budget';
import Planlagte from '@/pages/Planlagte';
import Accounts from '@/pages/Accounts';
import AccountDetail from '@/pages/AccountDetail';
import type { ReactNode } from 'react';

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
                    <Route path="/" element={<RequireAuth><Budget /></RequireAuth>} />
                    <Route path="/planlagte" element={<RequireAuth><Planlagte /></RequireAuth>} />
                    <Route path="/kontoer" element={<RequireAuth><Accounts /></RequireAuth>} />
                    <Route path="/accounts/:id" element={<RequireAuth><AccountDetail /></RequireAuth>} />
                    <Route path="*" element={<Navigate to="/" replace />} />
                </Routes>
            </BrowserRouter>
        </AuthProvider>
    );
}
