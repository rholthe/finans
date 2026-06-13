import { useEffect, useMemo, useState, type FormEvent } from 'react';
import Layout from '@/components/Layout';
import {
    apiErrorMessage,
    createRule,
    deleteRule,
    listCategoryGroups,
    listRules,
    reapplyRules,
    reorderRules,
    updateRule,
    type RuleInput,
} from '@/lib/data';
import { APPLIES_TO_LABELS, type CategoryGroup, type Rule, type RuleApplies } from '@/types';

export default function Regler() {
    const [rules, setRules] = useState<Rule[]>([]);
    const [groups, setGroups] = useState<CategoryGroup[]>([]);
    const [loading, setLoading] = useState(true);
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

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

    async function runReapply() {
        setNotice(null);
        try {
            const updated = await reapplyRules();
            setNotice(`Oppdaterte ${updated} transaksjon(er) basert på reglene.`);
        } catch (e) {
            setNotice(apiErrorMessage(e, 'Kunne ikke kjøre reglene på nytt.'));
        }
    }

    return (
        <Layout>
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-2xl font-semibold">Regler</h1>
                    <p className="mt-1 text-sm text-neutral-500">
                        Sett payee, memo og kategori automatisk fra bankteksten. Øverste matchende regel vinner.
                    </p>
                </div>
                <div className="flex gap-2">
                    <button
                        onClick={runReapply}
                        className="rounded-lg border border-neutral-300 px-3 py-2 text-sm font-medium text-neutral-700 hover:bg-neutral-100"
                    >
                        Bruk på eksisterende
                    </button>
                    <button
                        onClick={() => setEditing((e) => (e === 'new' ? null : 'new'))}
                        className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700"
                    >
                        {editing === 'new' ? 'Avbryt' : 'Ny regel'}
                    </button>
                </div>
            </div>

            {notice && <p className="mt-4 rounded-lg bg-neutral-100 px-4 py-2 text-sm text-neutral-700">{notice}</p>}

            {editing === 'new' && (
                <RuleForm
                    groups={groups}
                    onSaved={() => {
                        setEditing(null);
                        reload();
                    }}
                    onCancel={() => setEditing(null)}
                />
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

                            {editing !== 'new' && editing?.id === rule.id && (
                                <RuleForm
                                    groups={groups}
                                    existing={rule}
                                    onSaved={() => {
                                        setEditing(null);
                                        reload();
                                    }}
                                    onCancel={() => setEditing(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </Layout>
    );
}

function RuleForm({
    groups,
    existing,
    onSaved,
    onCancel,
}: {
    groups: CategoryGroup[];
    existing?: Rule;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [name, setName] = useState(existing?.name ?? '');
    const [matchContains, setMatchContains] = useState(existing?.match_contains ?? '');
    const [matchNotContains, setMatchNotContains] = useState(existing?.match_not_contains ?? '');
    const [appliesTo, setAppliesTo] = useState<RuleApplies>(existing?.applies_to ?? 'both');
    const [setPayee, setSetPayee] = useState(existing?.set_payee ?? '');
    const [setMemo, setSetMemo] = useState(existing?.set_memo ?? '');
    const [categoryId, setCategoryId] = useState(String(existing?.category_id ?? ''));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!matchContains.trim()) {
            setError('Oppgi minst én term i «inneholder».');
            return;
        }
        if (!setPayee.trim() && !setMemo.trim() && !categoryId) {
            setError('Sett minst én av payee, memo eller kategori.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: RuleInput = {
            name: name.trim() || null,
            match_contains: matchContains.trim(),
            match_not_contains: matchNotContains.trim() || null,
            applies_to: appliesTo,
            set_payee: setPayee.trim() || null,
            set_memo: setMemo.trim() || null,
            category_id: categoryId ? Number(categoryId) : null,
        };
        try {
            if (existing) {
                await updateRule(existing.id, payload);
            } else {
                await createRule(payload);
            }
            onSaved();
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre regelen.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form
            onSubmit={submit}
            className="mt-2 grid gap-4 rounded-xl border border-neutral-200 bg-neutral-50 p-5 sm:grid-cols-2"
        >
            <label className="text-sm font-medium text-neutral-700">
                Navn (valgfritt)
                <input
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Gjelder
                <select
                    value={appliesTo}
                    onChange={(e) => setAppliesTo(e.target.value as RuleApplies)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    {(Object.keys(APPLIES_TO_LABELS) as RuleApplies[]).map((value) => (
                        <option key={value} value={value}>
                            {APPLIES_TO_LABELS[value]}
                        </option>
                    ))}
                </select>
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Inneholder (komma-separert, alle må finnes)
                <input
                    value={matchContains}
                    onChange={(e) => setMatchContains(e.target.value)}
                    placeholder="f.eks. REMA, OSLO"
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Inneholder ikke (valgfritt)
                <input
                    value={matchNotContains}
                    onChange={(e) => setMatchNotContains(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Sett payee
                <input
                    value={setPayee}
                    onChange={(e) => setSetPayee(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <label className="text-sm font-medium text-neutral-700">
                Sett kategori
                <select
                    value={categoryId}
                    onChange={(e) => setCategoryId(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    <option value="">Ingen</option>
                    {groups.map((group) => (
                        <optgroup key={group.id} label={group.name}>
                            {group.categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </optgroup>
                    ))}
                </select>
            </label>

            <label className="text-sm font-medium text-neutral-700 sm:col-span-2">
                Sett memo (valgfritt)
                <input
                    value={setMemo}
                    onChange={(e) => setSetMemo(e.target.value)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                />
            </label>

            <div className="flex items-center gap-3 sm:col-span-2">
                <button
                    type="submit"
                    disabled={busy}
                    className="rounded-lg bg-neutral-900 px-4 py-2 text-sm font-medium text-white hover:bg-neutral-700 disabled:opacity-50"
                >
                    {busy ? 'Lagrer …' : 'Lagre'}
                </button>
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-lg px-4 py-2 text-sm font-medium text-neutral-500 hover:bg-neutral-200"
                >
                    Avbryt
                </button>
                {error && <p className="text-sm text-red-600">{error}</p>}
            </div>
        </form>
    );
}
