import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import { formatNok, formatNokShort } from '@/lib/format';

/** «2027-03» → «mar. 27». */
function monthLabel(ym: string): string {
    const [year, month] = ym.split('-').map(Number);
    const label = new Date(year, month - 1, 1).toLocaleDateString('nb-NO', { month: 'short' });
    return `${label} ${String(year).slice(2)}`;
}

/**
 * Projisert lånesaldo (negativ = gjeld) framover mot null. Lazy-lastet så
 * Recharts ikke havner i hovedbundelen (kun her og på Rapporter-siden).
 */
export default function LoanProjectionChart({
    series,
}: {
    series: { month: string; balance: number }[];
}) {
    const rows = series.map((s) => ({ ...s, label: monthLabel(s.month) }));

    return (
        <ResponsiveContainer width="100%" height={280}>
            <AreaChart data={rows}>
                <defs>
                    <linearGradient id="loanFill" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="5%" stopColor="#dc2626" stopOpacity={0.25} />
                        <stop offset="95%" stopColor="#dc2626" stopOpacity={0} />
                    </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                <XAxis dataKey="label" fontSize={12} minTickGap={24} />
                <YAxis tickFormatter={formatNokShort} fontSize={12} width={56} />
                <Tooltip formatter={(v) => formatNok(Number(v))} />
                <Area
                    dataKey="balance"
                    name="Saldo"
                    stroke="#dc2626"
                    strokeWidth={2}
                    fill="url(#loanFill)"
                />
            </AreaChart>
        </ResponsiveContainer>
    );
}
