import { useState } from 'react';

/**
 * Inline-redigerbart visningsnavn. Viser navnet med en diskret blyant ved hover;
 * klikk bytter til et felt med lagre/avbryt (Enter lagrer, Escape avbryter).
 * Brukes for bank/bankkonto, kategorigrupper og kategorier.
 */
export default function InlineNameEdit({
    display,
    initial,
    placeholder,
    onSave,
    className,
    inputClassName,
}: {
    display: string;
    initial: string;
    placeholder?: string;
    onSave: (name: string) => Promise<void>;
    className?: string;
    inputClassName?: string;
}) {
    const [editing, setEditing] = useState(false);
    const [value, setValue] = useState(initial);
    const [busy, setBusy] = useState(false);

    async function submit() {
        const trimmed = value.trim();
        if (!trimmed) {
            setEditing(false);
            return;
        }
        setBusy(true);
        try {
            await onSave(trimmed);
            setEditing(false);
        } finally {
            setBusy(false);
        }
    }

    if (!editing) {
        return (
            <span className={`group inline-flex items-center gap-1.5 ${className ?? ''}`}>
                <span className="truncate">{display}</span>
                <button
                    type="button"
                    onClick={() => {
                        setValue(initial);
                        setEditing(true);
                    }}
                    aria-label="Endre navn"
                    className="shrink-0 text-neutral-300 opacity-0 transition group-hover:opacity-100 hover:text-neutral-700"
                >
                    ✏️
                </button>
            </span>
        );
    }

    return (
        <span className="inline-flex min-w-0 items-center gap-1.5">
            <input
                autoFocus
                value={value}
                placeholder={placeholder}
                disabled={busy}
                onChange={(e) => setValue(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') submit();
                    if (e.key === 'Escape') setEditing(false);
                }}
                className={`min-w-0 rounded-lg border border-neutral-300 px-2 py-1 text-sm focus:border-neutral-900 focus:outline-none ${inputClassName ?? ''}`}
            />
            <button
                type="button"
                onClick={submit}
                disabled={busy}
                className="shrink-0 text-xs font-medium text-sky-600 hover:text-sky-800 disabled:opacity-50"
            >
                Lagre
            </button>
            <button
                type="button"
                onClick={() => setEditing(false)}
                disabled={busy}
                className="shrink-0 text-xs text-neutral-400 hover:text-neutral-700"
            >
                Avbryt
            </button>
        </span>
    );
}
