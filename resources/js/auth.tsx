import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import api from '@/lib/api';

type AuthState = {
    authenticated: boolean;
    loading: boolean;
    login: (password: string) => Promise<void>;
    logout: () => Promise<void>;
};

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
    const [authenticated, setAuthenticated] = useState(false);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        api.get('/me')
            .then((res) => setAuthenticated(Boolean(res.data.authenticated)))
            .catch(() => setAuthenticated(false))
            .finally(() => setLoading(false));
    }, []);

    async function login(password: string) {
        await api.post('/login', { password });
        setAuthenticated(true);
    }

    async function logout() {
        await api.post('/logout');
        setAuthenticated(false);
    }

    return (
        <AuthContext.Provider value={{ authenticated, loading, login, logout }}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth(): AuthState {
    const ctx = useContext(AuthContext);
    if (!ctx) throw new Error('useAuth må brukes innenfor AuthProvider');
    return ctx;
}
