import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import RuleForm from '@/components/RuleForm';
import { deleteRule, listAccounts, listCategoryGroups, listRules, updateRule } from '@/lib/data';
import {
    APPLIES_TO_LABELS,
    RULE_TARGET_LABELS,
    type Account,
    type CategoryGroup,
    type Rule,
    type RuleTarget,
} from '@/types';

/** Samlet målfilter: alle mål, eller én konkret RuleTarget. */
type TargetFilter = 'all' | RuleTarget;

const TARGET_FILTER_LABELS: Record<TargetFilter, string> = {
    all: 'Alle mål',
    ...RULE_TARGET_LABELS,
};

/** Fargetoner per regelmål for badge. */
const TARGET_BADGE: Record<RuleTarget, string> = {
    category: 'bg-sky-100 text-sky-700',
    rta: 'bg-emerald-100 text-emerald-700',
    transfer: 'bg-violet-100 text-violet-700',
};

export default function Regler() {
    const [rules, setRules] = useState<Rule[]>([]);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [accounts, setAccounts] = useState<Account[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [deleting, setDeleting] = useState<Rule | null>(null);
    const [search, setSearch] = useState('');
    const [targetFilter, setTargetFilter] = useState<TargetFilter>('all');

    function reload() {
        return listRules().then(setRules);
    }

    useEffect(() => {
        Promise.all([
            reload(),
            listCategoryGroups().then(setGroups),
            listAccounts().then(setAccounts),
        ]).finally(() => setLoading(false));
    }, []);

    const categoryName = useMemo(
        () => new Map(groups.flatMap((g) => g.categories.map((c) => [c.id, c.name] as const))),
        [groups],
    );
    const accountName = useMemo(
        () => new Map(accounts.map((a) => [a.id, a.name] as const)),
        [accounts],
    );

    // Søk på all tekst (inneholder, inneholder ikke, payee, memo), filtrer på mål,
    // og sorter på inneholder-teksten.
    const visibleRules = useMemo(() => {
        const q = search.trim().toLowerCase();
        return rules
            .filter((rule) => targetFilter === 'all' || rule.target_type === targetFilter)
            .filter((rule) => {
                if (!q) return true;
                return [rule.match_contains, rule.match_not_contains, rule.set_payee, rule.set_memo]
                    .some((field) => field?.toLowerCase().includes(q));
            })
            .sort((a, b) => (a.match_contains ?? '').localeCompare(b.match_contains ?? '', 'nb'));
    }, [rules, search, targetFilter]);

    async function confirmDelete() {
        if (!deleting) return;
        await deleteRule(deleting.id);
        setDeleting(null);
        reload();
    }

    async function toggleActive(rule: Rule) {
        await updateRule(rule.id, { active: !rule.active });
        reload();
    }

    /** Kompakt oppsummering av hva regelen gjør (høyresiden av pilen). */
    function actionSummary(rule: Rule): string {
        if (rule.target_type === 'rta') return 'Klar til å fordele';
        if (rule.target_type === 'transfer') {
            const to = rule.transfer_account_id ? accountName.get(rule.transfer_account_id) ?? '—' : '—';
            return `Overføring til ${to}`;
        }
        return (
            [
                rule.set_payee && `payee: ${rule.set_payee}`,
                rule.set_memo && 'memo',
                rule.category_id && `kategori: ${categoryName.get(rule.category_id) ?? '—'}`,
            ]
                .filter(Boolean)
                .join(', ') || 'ingen handling'
        );
    }

    return (
        <Layout>
            <div className="flex items-center justify-between gap-4">
                <div>
                    <h1 className="text-2xl font-semibold">Regler</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Sett payee, memo og kategori automatisk fra bankteksten. Ved overlapp vinner den mest
                        spesifikke regelen. Reglene anvendes ved import og på transaksjonene du velger på en kontoside.
                    </p>
                </div>
                <button
                    onClick={() => setEditing('new')}
                    className="shrink-0 rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    Ny regel
                </button>
            </div>

            {/* Søk + filter */}
            <div className="mt-6 flex flex-wrap items-center gap-3">
                <div className="relative min-w-0 flex-1">
                    <span className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-neutral-400">
                        🔍
                    </span>
                    <input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Søk i inneholder, payee, memo …"
                        className="w-full rounded-lg border border-neutral-300 py-2 pl-9 pr-3 text-sm focus:border-neutral-900 focus:outline-none"
                    />
                </div>
                <select
                    value={targetFilter}
                    onChange={(e) => setTargetFilter(e.target.value as TargetFilter)}
                    className="rounded-lg border border-neutral-300 px-3 py-2 text-sm focus:border-neutral-900 focus:outline-none"
                >
                    {(Object.keys(TARGET_FILTER_LABELS) as TargetFilter[]).map((value) => (
                        <option key={value} value={value}>
                            {TARGET_FILTER_LABELS[value]}
                        </option>
                    ))}
                </select>
            </div>

            {editing !== null && (
                <Modal
                    title={editing === 'new' ? 'Ny regel' : 'Rediger regel'}
                    size="lg"
                    onClose={() => setEditing(null)}
                >
                    <RuleForm
                        groups={groups}
                        accounts={accounts}
                        existing={editing === 'new' ? undefined : editing}
                        onSaved={() => {
                            setEditing(null);
                            reload();
                        }}
                        onCancel={() => setEditing(null)}
                    />
                </Modal>
            )}

            {loading ? (
                <p className="mt-8 text-neutral-400">Laster …</p>
            ) : rules.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen regler ennå. Opprett din første regel for å kategorisere transaksjoner automatisk.
                </div>
            ) : visibleRules.length === 0 ? (
                <div className="mt-8 rounded-xl border border-dashed border-neutral-300 p-8 text-center text-neutral-500">
                    Ingen regler matcher søket.
                </div>
            ) : (
                <ul className="mt-4 divide-y divide-neutral-100 overflow-hidden rounded-xl border border-neutral-200 bg-white">
                    {visibleRules.map((rule) => (
                        <li
                            key={rule.id}
                            className={`flex items-center gap-3 px-4 py-2.5 ${rule.active ? '' : 'opacity-50'}`}
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="truncate font-medium">{rule.match_contains || '—'}</span>
                                    <span
                                        className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-medium ${TARGET_BADGE[rule.target_type]}`}
                                    >
                                        {RULE_TARGET_LABELS[rule.target_type]}
                                    </span>
                                    <span className="shrink-0 rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-500">
                                        {APPLIES_TO_LABELS[rule.applies_to]}
                                    </span>
                                </div>
                                <p className="mt-0.5 truncate text-xs text-neutral-500">
                                    {rule.match_not_contains && `ikke «${rule.match_not_contains}» · `}
                                    {'→ '}
                                    {actionSummary(rule)}
                                </p>
                            </div>

                            <label className="flex shrink-0 items-center gap-1.5 text-xs text-neutral-500">
                                <input
                                    type="checkbox"
                                    checked={rule.active}
                                    onChange={() => toggleActive(rule)}
                                    className="h-4 w-4"
                                />
                                Aktiv
                            </label>
                            <button
                                onClick={() => setEditing(rule)}
                                className="shrink-0 text-xs text-neutral-400 hover:text-neutral-900"
                            >
                                Rediger
                            </button>
                            <button
                                onClick={() => setDeleting(rule)}
                                className="shrink-0 text-xs text-neutral-400 hover:text-red-600"
                            >
                                Slett
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {deleting && (
                <DeleteRuleModal
                    rule={deleting}
                    onClose={() => setDeleting(null)}
                    onConfirm={confirmDelete}
                />
            )}
        </Layout>
    );
}

/** Bekreftelse før en regel slettes. */
function DeleteRuleModal({
    rule,
    onClose,
    onConfirm,
}: {
    rule: Rule;
    onClose: () => void;
    onConfirm: () => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);

    async function submit() {
        setBusy(true);
        try {
            await onConfirm();
        } finally {
            setBusy(false);
        }
    }

    return (
        <Modal
            title="Slette regelen?"
            size="sm"
            onClose={onClose}
            footer={
                <>
                    <button
                        type="button"
                        onClick={onClose}
                        disabled={busy}
                        className="rounded-lg px-3 py-1.5 text-sm font-medium text-neutral-500 hover:bg-neutral-100 disabled:opacity-50"
                    >
                        Avbryt
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={busy}
                        className="rounded-lg bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                    >
                        {busy ? 'Sletter …' : 'Slett'}
                    </button>
                </>
            }
        >
            <p className="text-sm text-neutral-600">
                Vil du slette regelen{' '}
                <span className="font-medium text-neutral-800">«{rule.match_contains || 'uten inneholder'}»</span>?
                Allerede kategoriserte transaksjoner endres ikke.
            </p>
        </Modal>
    );
}
