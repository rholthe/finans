const nok = new Intl.NumberFormat('nb-NO', {
    style: 'currency',
    currency: 'NOK',
    minimumFractionDigits: 2,
});

export function formatNok(amount: number): string {
    return nok.format(amount);
}

/** Kompakt beløp for akse-etiketter, f.eks. «12 k» / «1,5 mill». */
export function formatNokShort(amount: number): string {
    const abs = Math.abs(amount);
    if (abs >= 1_000_000) {
        return `${(amount / 1_000_000).toLocaleString('nb-NO', { maximumFractionDigits: 1 })} mill`;
    }
    if (abs >= 1_000) {
        return `${Math.round(amount / 1_000)} k`;
    }
    return String(Math.round(amount));
}

export function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('nb-NO', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function todayIso(): string {
    return new Date().toISOString().slice(0, 10);
}

/** Inneværende måned som «YYYY-MM». */
export function currentMonth(): string {
    return new Date().toISOString().slice(0, 7);
}

/** Forskyv en «YYYY-MM» med et antall måneder (kan være negativt). */
export function shiftMonth(ym: string, delta: number): string {
    const [year, month] = ym.split('-').map(Number);
    const date = new Date(year, month - 1 + delta, 1);
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** «2026-06» → «juni 2026». */
export function monthLabel(ym: string): string {
    const [year, month] = ym.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString('nb-NO', {
        month: 'long',
        year: 'numeric',
    });
}
