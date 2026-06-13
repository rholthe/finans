import { useEffect, useMemo, useState } from 'react';
import Layout from '@/components/Layout';
import Modal from '@/components/Modal';
import RuleForm from '@/components/RuleForm';
import { deleteRule, listCategoryGroups, listRules, reorderRules, updateRule } from '@/lib/data';
import { APPLIES_TO_LABELS, type CategoryGroup, type Rule } from '@/types';

export default function Regler() {
    const [rules, setRules] = useState<Rule[]>([]);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);

    function reload() {
        return listRules().then(setRules);
    }

    useEffect(() => {
        Promise.all([reload(), listCategoryGroups().then(setGroups)]).finally(() => setLoading(false));
    }, []);

    const categoryName = useMemo(
        () => new Map(groups.flatMap((g) => g.categories.map((c) => [c.id, c.name] as const))),
        [groups],
    );

    async function move(index: number, dir: -1 | 1) {
        const next = [...rules];
        const target = index + dir;
        if (target < 0 || target >= next.length) return;
        [next[index], next[target]] = [next[target], next[index]];
        setRules(next);
        await reorderRules(next.map((rule, i) => ({ id: rule.id, priority: i })));
    }

    async function remove(rule: Rule) {
        if (!confirm('Slette denne regelen?')) return;
        await deleteRule(rule.id);
        reload();
    }

    async function toggleActive(rule: Rule) {
        await updateRule(rule.id, { active: !rule.active });
        reload();
    }

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Regler</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Sett payee, memo og kategori automatisk fra bankteksten. Øverste matchende regel vinner.
                        Reglene anvendes ved import og på transaksjonene du velger på en kontoside.
                    </p>
                </div>
                <button
                    onClick={() => setEditing((e) => (e === 'new' ? null : 'new'))}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                >
                    {editing === 'new' ? 'Avbryt' : 'Ny regel'}
                </button>
            </div>

            {editing !== null && (
                <Modal
                    title={editing === 'new' ? 'Ny regel' : 'Rediger regel'}
                    onClose={() => setEditing(null)}
                >
                    <RuleForm
                        groups={groups}
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
                <p className="mt-8 text-neutral-500">Ingen regler ennå.</p>
            ) : (
                <ul className="mt-6 space-y-2">
                    {rules.map((rule, index) => (
                        <li key={rule.id}>
                            <div
                                className={`flex items-center gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3 ${
                                    rule.active ? '' : 'opacity-50'
                                }`}
                            >
                                <div className="flex flex-col">
                                    <button
                                        onClick={() => move(index, -1)}
                                        disabled={index === 0}
                                        className="text-xs text-neutral-400 hover:text-neutral-900 disabled:opacity-30"
                                        aria-label="Flytt opp"
                                    >
                                        ▲
                                    </button>
                                    <button
                                        onClick={() => move(index, 1)}
                                        disabled={index === rules.length - 1}
                                        className="text-xs text-neutral-400 hover:text-neutral-900 disabled:opacity-30"
                                        aria-label="Flytt ned"
                                    >
                                        ▼
                                    </button>
                                </div>

                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {rule.name || rule.match_contains || 'Regel'}
                                        </span>
                                        <span className="rounded bg-neutral-100 px-1.5 py-0.5 text-xs text-neutral-500">
                                            {APPLIES_TO_LABELS[rule.applies_to]}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 truncate text-xs text-neutral-500">
                                        inneholder «{rule.match_contains}»
                                        {rule.match_not_contains && `, ikke «${rule.match_not_contains}»`}
                                        {' → '}
                                        {[
                                            rule.set_payee && `payee: ${rule.set_payee}`,
                                            rule.set_memo && 'memo',
                                            rule.category_id && `kategori: ${categoryName.get(rule.category_id) ?? '—'}`,
                                        ]
                                            .filter(Boolean)
                                            .join(', ')}
                                    </p>
                                </div>

                                <label className="flex items-center gap-1 text-xs text-neutral-500">
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
                                    className="text-xs text-neutral-400 hover:text-neutral-900"
                                >
                                    Rediger
                                </button>
                                <button
                                    onClick={() => remove(rule)}
                                    className="text-xs text-neutral-400 hover:text-red-600"
                                >
                                    Slett
                                </button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </Layout>
    );
}
