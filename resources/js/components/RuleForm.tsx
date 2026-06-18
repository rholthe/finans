import { useState, type FormEvent } from 'react';
import { apiErrorMessage, createRule, updateRule, type RuleInput } from '@/lib/data';
import {
    APPLIES_TO_LABELS,
    RULE_TARGET_LABELS,
    type Account,
    type CategoryGroup,
    type Rule,
    type RuleApplies,
    type RuleTarget,
} from '@/types';

/**
 * Skjema for å opprette/redigere en regel. Brukes både på Regler-siden og for
 * «ny regel fra transaksjon» (med prefillMatch).
 */
export default function RuleForm({
    groups,
    accounts,
    existing,
    prefillMatch,
    onSaved,
    onCancel,
}: {
    groups: CategoryGroup[];
    accounts: Account[];
    existing?: Rule;
    prefillMatch?: string;
    onSaved: (rule: Rule) => void;
    onCancel: () => void;
}) {
    const [matchContains, setMatchContains] = useState(existing?.match_contains ?? prefillMatch ?? '');
    const [matchNotContains, setMatchNotContains] = useState(existing?.match_not_contains ?? '');
    const [appliesTo, setAppliesTo] = useState<RuleApplies>(existing?.applies_to ?? 'both');
    const [target, setTarget] = useState<RuleTarget>(existing?.target_type ?? 'category');
    const [setPayee, setSetPayee] = useState(existing?.set_payee ?? '');
    const [setMemo, setSetMemo] = useState(existing?.set_memo ?? '');
    const [categoryId, setCategoryId] = useState(String(existing?.category_id ?? ''));
    const [transferAccountId, setTransferAccountId] = useState(String(existing?.transfer_account_id ?? ''));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Overføringsmål kan kun peke på en konto uten banksynk.
    const transferTargets = accounts.filter((a) => !a.bank_synced && !a.closed);

    async function submit(e: FormEvent) {
        e.preventDefault();
        if (!matchContains.trim()) {
            setError('Oppgi minst én term i «inneholder».');
            return;
        }
        if (target === 'category' && !setPayee.trim() && !setMemo.trim() && !categoryId) {
            setError('Sett minst én av payee, memo eller kategori.');
            return;
        }
        if (target === 'transfer' && !transferAccountId) {
            setError('Velg en mottakerkonto for overføringen.');
            return;
        }
        setBusy(true);
        setError(null);
        const payload: RuleInput = {
            match_contains: matchContains.trim(),
            match_not_contains: matchNotContains.trim() || null,
            applies_to: appliesTo,
            set_payee: setPayee.trim() || null,
            set_memo: setMemo.trim() || null,
            target_type: target,
            category_id: target === 'rta' ? null : categoryId ? Number(categoryId) : null,
            transfer_account_id: target === 'transfer' ? Number(transferAccountId) : null,
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
                Inneholder (komma-separert, alle må finnes)
                <input
                    value={matchContains}
                    onChange={(e) => setMatchContains(e.target.value)}
                    placeholder="f.eks. REMA, OSLO"
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
                Mål
                <select
                    value={target}
                    onChange={(e) => setTarget(e.target.value as RuleTarget)}
                    className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                >
                    {(Object.keys(RULE_TARGET_LABELS) as RuleTarget[]).map((value) => (
                        <option key={value} value={value}>
                            {RULE_TARGET_LABELS[value]}
                        </option>
                    ))}
                </select>
            </label>

            {target === 'category' && (
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
            )}

            {target === 'rta' && (
                <p className="self-end text-sm text-neutral-500">
                    Markeres som «Klar til å fordele» (typisk lønn).
                </p>
            )}

            {target === 'transfer' && (
                <label className="text-sm font-medium text-neutral-700">
                    Overfør til konto
                    <select
                        value={transferAccountId}
                        onChange={(e) => setTransferAccountId(e.target.value)}
                        className="mt-1 w-full rounded-lg border border-neutral-300 px-3 py-2 focus:border-neutral-900 focus:outline-none"
                    >
                        <option value="">Velg konto …</option>
                        {transferTargets.map((account) => (
                            <option key={account.id} value={account.id}>
                                {account.name}
                            </option>
                        ))}
                    </select>
                    <span className="mt-1 block text-xs font-normal text-neutral-400">
                        Kun kontoer uten banksynk (unngår dobbeltpostering).
                    </span>
                </label>
            )}

            {target === 'transfer' && (
                <label className="text-sm font-medium text-neutral-700">
                    Kategori (kun ved overføring ut av budsjettet)
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
            )}

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
