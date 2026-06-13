import { useEffect, type ReactNode } from 'react';

/**
 * Enkel modal med bakteppe. Lukkes ved klikk utenfor, Esc eller lukkeknapp.
 */
export default function Modal({
    title,
    onClose,
    children,
}: {
    title?: string;
    onClose: () => void;
    children: ReactNode;
}) {
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', onKey);
        document.body.style.overflow = 'hidden';
        return () => {
            document.removeEventListener('keydown', onKey);
            document.body.style.overflow = '';
        };
    }, [onClose]);

    return (
        <div
            className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 sm:p-8"
            onClick={onClose}
        >
            <div
                className="w-full max-w-2xl rounded-xl bg-white shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-3">
                    <h2 className="text-sm font-semibold text-neutral-800">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Lukk"
                        className="text-neutral-400 hover:text-neutral-900"
                    >
                        ✕
                    </button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}
