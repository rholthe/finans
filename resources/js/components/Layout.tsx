import { Link, NavLink } from 'react-router-dom';
import type { ReactNode } from 'react';
import { useAuth } from '@/auth';

const navClass = ({ isActive }: { isActive: boolean }) =>
    `rounded-lg px-3 py-1.5 text-sm font-medium ${
        isActive ? 'bg-neutral-900 text-white' : 'text-neutral-600 hover:bg-neutral-100'
    }`;

export default function Layout({ children }: { children: ReactNode }) {
    const { logout } = useAuth();

    return (
        <div className="min-h-screen bg-neutral-50 text-neutral-900">
            <header className="border-b border-neutral-200 bg-white">
                <div className="mx-auto flex max-w-5xl items-center justify-between px-6 py-4">
                    <div className="flex items-center gap-6">
                        <Link to="/" className="text-lg font-semibold">
                            Finans
                        </Link>
                        <nav className="flex items-center gap-1">
                            <NavLink to="/" className={navClass} end>
                                Budsjett
                            </NavLink>
                            <NavLink to="/kontoer" className={navClass}>
                                Kontoer
                            </NavLink>
                            <NavLink to="/planlagte" className={navClass}>
                                Planlagte
                            </NavLink>
                            <NavLink to="/rapporter" className={navClass}>
                                Rapporter
                            </NavLink>
                            <NavLink to="/bank" className={navClass}>
                                Bank
                            </NavLink>
                            <NavLink to="/regler" className={navClass}>
                                Regler
                            </NavLink>
                            <NavLink to="/innstillinger" className={navClass}>
                                Innstillinger
                            </NavLink>
                        </nav>
                    </div>
                    <button
                        onClick={() => logout()}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-600 hover:bg-neutral-100"
                    >
                        Logg ut
                    </button>
                </div>
            </header>
            <main className="mx-auto max-w-5xl px-6 py-8">{children}</main>
        </div>
    );
}
