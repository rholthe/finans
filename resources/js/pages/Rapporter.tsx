import { useEffect, useMemo, useState, type ReactNode } from 'react';
import {
    Area,
    AreaChart,
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
    Legend,
    Line,
    LineChart,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import Layout from '@/components/Layout';
import {
    getAgeOfMoneyReport,
    getCategoryTrendReport,
    getIncomeExpenseReport,
    getNetWorthReport,
    getSpendingReport,
    listCategoryGroups,
    type ReportPeriod,
} from '@/lib/data';
import { currentMonth, formatNok, formatNokShort, shiftMonth } from '@/lib/format';
import type {
    AgeOfMoneyReport,
    Category,
    CategoryTrendReport,
    IncomeExpenseReport,
    NetWorthReport,
    SpendingReport,
} from '@/types';

const COLORS = [
    '#2563eb', '#16a34a', '#dc2626', '#d97706', '#7c3aed',
    '#0891b2', '#db2777', '#65a30d', '#9333ea', '#ea580c',
];

/** «2026-06» → «jun.». */
function monthShort(ym: string): string {
    const [year, month] = ym.split('-').map(Number);
    return new Date(year, month - 1, 1).toLocaleDateString('nb-NO', { month: 'short' });
}

export default function Rapporter() {
    const [from, setFrom] = useState(() => shiftMonth(currentMonth(), -11));
    const [to, setTo] = useState(currentMonth());

    const period = useMemo<ReportPeriod>(() => ({ from, to }), [from, to]);

    return (
        <Layout>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-2xl font-semibold">Rapporter</h1>
                <div className="flex items-end gap-2">
                    <label className="text-xs font-medium text-neutral-600">
                        Fra
                        <input
                            type="month"
                            value={from}
                            max={to}
                            onChange={(e) => setFrom(e.target.value)}
                            className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                        />
                    </label>
                    <label className="text-xs font-medium text-neutral-600">
                        Til
                        <input
                            type="month"
                            value={to}
                            min={from}
                            onChange={(e) => setTo(e.target.value)}
                            className="mt-1 block rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                        />
                    </label>
                </div>
            </div>

            <div className="mt-6 space-y-6">
                <SpendingCard period={period} />
                <IncomeExpenseCard period={period} />
                <AgeOfMoneyCard period={period} />
                <CategoryTrendCard period={period} />
                <NetWorthCard period={period} />
            </div>
        </Layout>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border border-neutral-200 bg-white p-5">
            <h2 className="mb-4 text-sm font-semibold text-neutral-700">{title}</h2>
            {children}
        </section>
    );
}

function Empty({ text = 'Ingen data i perioden.' }: { text?: string }) {
    return <p className="py-12 text-center text-sm text-neutral-400">{text}</p>;
}

function nokTooltip(value: number | string | readonly (number | string)[] | undefined): string {
    return formatNok(Number(value));
}

function SpendingCard({ period }: { period: ReportPeriod }) {
    const [data, setData] = useState<SpendingReport | null>(null);

    useEffect(() => {
        getSpendingReport(period).then(setData).catch(() => setData(null));
    }, [period]);

    const pie = (data?.groups ?? []).map((g) => ({ name: g.name, value: g.total }));

    return (
        <Card title="Forbruk per kategori">
            {!data || data.groups.length === 0 ? (
                <Empty />
            ) : (
                <div className="grid gap-6 md:grid-cols-2">
                    <ResponsiveContainer width="100%" height={280}>
                        <PieChart>
                            <Pie data={pie} dataKey="value" nameKey="name" outerRadius={100} innerRadius={55}>
                                {pie.map((_, i) => (
                                    <Cell key={i} fill={COLORS[i % COLORS.length]} />
                                ))}
                            </Pie>
                            <Tooltip formatter={nokTooltip} />
                        </PieChart>
                    </ResponsiveContainer>
                    <div className="self-center">
                        <p className="mb-2 text-sm text-neutral-500">
                            Totalt forbruk:{' '}
                            <span className="font-semibold tabular-nums text-neutral-900">
                                {formatNok(data.total)}
                            </span>
                        </p>
                        <ul className="space-y-2">
                            {data.groups.map((g, i) => (
                                <li key={g.id}>
                                    <div className="flex items-center justify-between text-sm">
                                        <span className="flex items-center gap-2 font-medium text-neutral-700">
                                            <span
                                                className="inline-block h-2.5 w-2.5 rounded-full"
                                                style={{ background: COLORS[i % COLORS.length] }}
                                            />
                                            {g.name}
                                        </span>
                                        <span className="tabular-nums">{formatNok(g.total)}</span>
                                    </div>
                                    <ul className="ml-[18px] mt-0.5 space-y-0.5">
                                        {g.categories.map((c) => (
                                            <li
                                                key={c.id}
                                                className="flex items-center justify-between text-xs text-neutral-400"
                                            >
                                                <span>{c.name}</span>
                                                <span className="tabular-nums">{formatNok(c.total)}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </Card>
    );
}

function IncomeExpenseCard({ period }: { period: ReportPeriod }) {
    const [data, setData] = useState<IncomeExpenseReport | null>(null);

    useEffect(() => {
        getIncomeExpenseReport(period).then(setData).catch(() => setData(null));
    }, [period]);

    const rows = (data?.months ?? []).map((m) => ({ ...m, label: monthShort(m.month) }));
    const hasData = rows.some((r) => r.income !== 0 || r.expense !== 0);

    return (
        <Card title="Inntekt vs. forbruk">
            {!hasData ? (
                <Empty />
            ) : (
                <ResponsiveContainer width="100%" height={300}>
                    <ComposedChart data={rows}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                        <XAxis dataKey="label" fontSize={12} />
                        <YAxis tickFormatter={formatNokShort} fontSize={12} width={48} />
                        <Tooltip formatter={nokTooltip} />
                        <Legend />
                        <Bar dataKey="income" name="Inntekt" fill="#16a34a" radius={[3, 3, 0, 0]} />
                        <Bar dataKey="expense" name="Forbruk" fill="#dc2626" radius={[3, 3, 0, 0]} />
                        <Line dataKey="net" name="Netto" stroke="#2563eb" strokeWidth={2} dot={false} />
                    </ComposedChart>
                </ResponsiveContainer>
            )}
        </Card>
    );
}

function daysTooltip(value: number | string | readonly (number | string)[] | undefined): string {
    const n = Number(value);
    return `${n} ${n === 1 ? 'dag' : 'dager'}`;
}

function AgeOfMoneyCard({ period }: { period: ReportPeriod }) {
    const [data, setData] = useState<AgeOfMoneyReport | null>(null);

    useEffect(() => {
        getAgeOfMoneyReport(period).then(setData).catch(() => setData(null));
    }, [period]);

    const rows = (data?.months ?? []).map((m) => ({ ...m, label: monthShort(m.month) }));
    const hasData = rows.some((r) => r.age !== null);

    return (
        <Card title="Pengenes alder (Age of Money)">
            {!data || !hasData ? (
                <Empty text="Ikke nok inn-/utbetalinger til å beregne ennå." />
            ) : (
                <>
                    <p className="mb-4 text-sm text-neutral-500">
                        Pengene du bruker nå er i snitt{' '}
                        <span className="text-2xl font-semibold tabular-nums text-cyan-700">
                            {data.current ?? '–'}
                        </span>{' '}
                        dager gamle. Høyere tall betyr at du lever mindre «fra hånd til munn».
                    </p>
                    <ResponsiveContainer width="100%" height={260}>
                        <LineChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                            <XAxis dataKey="label" fontSize={12} />
                            <YAxis
                                tickFormatter={(v) => `${v} d`}
                                fontSize={12}
                                width={48}
                                allowDecimals={false}
                            />
                            <Tooltip formatter={daysTooltip} />
                            <Line
                                dataKey="age"
                                name="Pengenes alder"
                                stroke="#0891b2"
                                strokeWidth={2}
                                dot={{ r: 3 }}
                                connectNulls
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </>
            )}
        </Card>
    );
}

function CategoryTrendCard({ period }: { period: ReportPeriod }) {
    const [categories, setCategories] = useState<Category[]>([]);
    const [categoryId, setCategoryId] = useState<number | null>(null);
    const [data, setData] = useState<CategoryTrendReport | null>(null);

    useEffect(() => {
        listCategoryGroups()
            .then((groups) => {
                const flat = groups.flatMap((g) => g.categories);
                setCategories(flat);
                setCategoryId((prev) => prev ?? flat[0]?.id ?? null);
            })
            .catch(() => setCategories([]));
    }, []);

    useEffect(() => {
        if (categoryId === null) return;
        getCategoryTrendReport(categoryId, period).then(setData).catch(() => setData(null));
    }, [categoryId, period]);

    const rows = (data?.months ?? []).map((m) => ({ ...m, label: monthShort(m.month) }));

    return (
        <Card title="Trend per kategori">
            {categories.length === 0 ? (
                <Empty text="Ingen kategorier ennå." />
            ) : (
                <>
                    <select
                        value={categoryId ?? ''}
                        onChange={(e) => setCategoryId(Number(e.target.value))}
                        className="mb-4 rounded-lg border border-neutral-300 px-2 py-1.5 text-sm focus:border-neutral-900 focus:outline-none"
                    >
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    <ResponsiveContainer width="100%" height={260}>
                        <LineChart data={rows}>
                            <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                            <XAxis dataKey="label" fontSize={12} />
                            <YAxis tickFormatter={formatNokShort} fontSize={12} width={48} />
                            <Tooltip formatter={nokTooltip} />
                            <Line
                                dataKey="total"
                                name="Forbruk"
                                stroke="#7c3aed"
                                strokeWidth={2}
                                dot={{ r: 3 }}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </>
            )}
        </Card>
    );
}

function NetWorthCard({ period }: { period: ReportPeriod }) {
    const [data, setData] = useState<NetWorthReport | null>(null);

    useEffect(() => {
        getNetWorthReport(period).then(setData).catch(() => setData(null));
    }, [period]);

    const rows = (data?.months ?? []).map((m) => ({ ...m, label: monthShort(m.month) }));
    const hasData = rows.some((r) => r.assets !== 0 || r.debt !== 0);

    return (
        <Card title="Nettoformue">
            {!hasData ? (
                <Empty />
            ) : (
                <ResponsiveContainer width="100%" height={300}>
                    <AreaChart data={rows}>
                        <defs>
                            <linearGradient id="netFill" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor="#2563eb" stopOpacity={0.25} />
                                <stop offset="95%" stopColor="#2563eb" stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" stroke="#f0f0f0" />
                        <XAxis dataKey="label" fontSize={12} />
                        <YAxis tickFormatter={formatNokShort} fontSize={12} width={56} />
                        <Tooltip formatter={nokTooltip} />
                        <Legend />
                        <Area
                            dataKey="net"
                            name="Nettoformue"
                            stroke="#2563eb"
                            strokeWidth={2}
                            fill="url(#netFill)"
                        />
                    </AreaChart>
                </ResponsiveContainer>
            )}
        </Card>
    );
}
