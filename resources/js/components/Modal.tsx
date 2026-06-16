import { useEffect, type ReactNode } from 'react';

const SIZES = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
} as const;

/**
 * Enkel modal med bakteppe. Lukkes ved klikk utenfor, Esc eller lukkeknapp.
 * Valgfri `footer` rendres i en egen stripe nederst (fast plass til knapper).
 */
export default function Modal({
    title,
    size = 'lg',
    onClose,
    children,
    footer,
}: {
    title?: string;
    size?: keyof typeof SIZES;
    onClose: () => void;
    children: ReactNode;
    footer?: ReactNode;
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
            className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 backdrop-blur-sm sm:p-8"
            onClick={onClose}
        >
            <div
                className={`w-full ${SIZES[size]} overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5`}
                onClick={(e) => e.stopPropagation()}
            >
                <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-3.5">
                    <h2 className="text-sm font-semibold text-neutral-800">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Lukk"
                        className="-mr-1 rounded-lg p-1 text-neutral-400 transition hover:bg-neutral-100 hover:text-neutral-900"
                    >
                        ✕
                    </button>
                </div>
                <div className="p-5">{children}</div>
                {footer && (
                    <div className="flex items-center justify-end gap-2 border-t border-neutral-200 bg-neutral-50 px-5 py-3">
                        {footer}
                    </div>
                )}
            </div>
        </div>
    );
}
