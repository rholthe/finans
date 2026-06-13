import { useState, type FormEvent } from 'react';
import { apiErrorMessage, createRule, updateRule, type RuleInput } from '@/lib/data';
import { APPLIES_TO_LABELS, type CategoryGroup, type Rule, type RuleApplies } from '@/types';

/**
 * Skjema for å opprette/redigere en regel. Brukes både på Regler-siden og for
 * «ny regel fra transaksjon» (med prefillMatch).
 */
export default function RuleForm({
    groups,
    existing,
    prefillMatch,
    onSaved,
    onCancel,
}: {
    groups: CategoryGroup[];
    existing?: Rule;
    prefillMatch?: string;
    onSaved: (rule: Rule) => void;
    onCancel: () => void;
}) {
    const [name, setName] = useState(existing?.name ?? '');
    const [matchContains, setMatchContains] = useState(existing?.match_contains ?? prefillMatch ?? '');
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
            const rule = existing ? await updateRule(existing.id, payload) : await createRule(payload);
            onSaved(rule);
        } catch (err) {
            setError(apiErrorMessage(err, 'Kunne ikke lagre regelen.'));
        } finally {
            setBusy(false);
        }
    }

    return (
        <form onSubmit={submit} className="grid gap-4 sm:grid-cols-2">
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
                    {busy ? 'Lagrer …' : 'Lagre regel'}
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
