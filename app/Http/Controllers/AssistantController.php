<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\BankConnection;
use App\Models\Category;
use App\Models\ScheduledTransaction;
use App\Models\SyncEvent;
use App\Models\Transaction;
use App\Services\BudgetService;
use App\Services\LoanService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Kompakte lese-endepunkter for den personlige assistenten (/api/assistent/*).
 *
 * Bare lesing (AssistantToken slipper bare GET gjennom). Tallene beregnes av de samme
 * tjenestene som appen bruker, men svarene er slanket: navn i stedet for id-er, ingen
 * kontonumre eller bankkoblingsdetaljer, og nuller utelatt – svarene går til en
 * språkmodell, så hver byte koster.
 */
class AssistantController extends Controller
{
    private const MAX_TRANSACTIONS = 200;

    private const MAX_UPCOMING_DAYS = 90;

    public function __construct(
        private readonly BudgetService $budget,
        private readonly ReportService $reports,
        private readonly LoanService $loans,
    ) {}

    /**
     * Oversikt: kontoer med saldo, klart til å fordele, overbrukte og underfinansierte
     * kategorier denne måneden, ukategorisert, siste banksynk og bankkoblinger som utløper.
     */
    public function overview(): JsonResponse
    {
        $view = $this->budget->monthlyView(now()->format('Y-m'));

        $accounts = Account::query()
            ->where('closed', false)
            ->withSum('transactions as balance', 'amount')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $a) => [
                'name' => $a->name,
                'type' => $a->type->value,
                'on_budget' => $a->on_budget,
                'balance' => round((float) $a->balance, 2),
            ]);

        $categories = collect($view['groups'])->flatMap(fn (array $g) => $g['categories']);

        $lastSync = SyncEvent::query()->where('status', '!=', SyncEvent::STATUS_PROCESSING)->latest()->first();
        $expiring = BankConnection::query()
            ->whereNotNull('valid_until')
            ->where('valid_until', '<=', now()->addDays(14))
            ->get()
            ->map(fn (BankConnection $c) => ['name' => $c->name, 'valid_until' => $c->valid_until->toDateString()]);

        $assets = $accounts->where('balance', '>', 0)->sum('balance');
        $debt = -$accounts->where('balance', '<', 0)->sum('balance');

        return response()->json([
            'month' => $view['month'],
            'ready_to_assign' => $view['ready_to_assign'],
            'accounts' => $accounts->values(),
            'totals' => [
                'on_budget' => round($accounts->where('on_budget', true)->sum('balance'), 2),
                'assets' => round($assets, 2),
                'debt' => round($debt, 2),
                'net_worth' => round($assets - $debt, 2),
            ],
            'overspent' => $categories->where('available', '<', 0)
                ->map(fn (array $c) => ['category' => $c['name'], 'available' => $c['available']])->values(),
            'underfunded' => $categories->where('needed', '>', 0)
                ->map(fn (array $c) => ['category' => $c['name'], 'needed' => $c['needed']])->values(),
            'uncategorized' => ['count' => $view['uncategorized_count'], 'total' => $view['uncategorized_total']],
            'last_bank_sync' => $lastSync ? ['at' => $lastSync->created_at->toIso8601String(), 'status' => $lastSync->status] : null,
            'bank_connections_expiring' => $expiring->values(),
        ]);
    }

    /**
     * Budsjettet for en måned, per gruppe og kategori. ?category= (del av navnet)
     * avgrenser til kategoriene som passer.
     */
    public function budget(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
            'category' => ['sometimes', 'string', 'max:100'],
        ]);

        $view = $this->budget->monthlyView($validated['month'] ?? now()->format('Y-m'));
        $needle = isset($validated['category']) ? mb_strtolower($validated['category']) : null;

        $groups = collect($view['groups'])->map(function (array $g) use ($needle) {
            $categories = collect($g['categories'])
                ->filter(fn (array $c) => $needle === null
                    || str_contains(mb_strtolower($c['name']), $needle)
                    || str_contains(mb_strtolower($g['name']), $needle))
                ->map(fn (array $c) => array_filter([
                    'category' => $c['name'],
                    'assigned' => $c['assigned'],
                    'activity' => $c['activity'],
                    'available' => $c['available'],
                    'upcoming' => $c['upcoming'] ?: null,
                    'needed' => $c['needed'] ?: null,
                    'goal' => $c['goal'],
                ], fn ($v) => $v !== null))
                ->values();

            return ['group' => $g['name'], 'available' => $g['available'], 'categories' => $categories];
        })->filter(fn (array $g) => $g['categories']->isNotEmpty())->values();

        return response()->json([
            'month' => $view['month'],
            'ready_to_assign' => $view['ready_to_assign'],
            'upcoming_income' => $view['upcoming_income'],
            'groups' => $groups,
        ]);
    }

    /**
     * Transaksjoner, nyeste først. Fritekst i mottaker, memo og banktekst; konto og
     * kategori som del av navnet (kategori treffer også splittlinjer).
     */
    public function transactions(Request $request): JsonResponse
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'min_amount' => ['nullable', 'numeric'],
            'max_amount' => ['nullable', 'numeric'],
            'account' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'uncategorized' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_TRANSACTIONS],
        ]);

        $query = Transaction::query()
            ->with(['account:id,name,on_budget', 'category:id,name', 'transfer.account:id,name', 'splits.category:id,name'])
            ->where('is_starting_balance', false)
            ->when($v['q'] ?? null, function (Builder $q, string $term): void {
                $like = '%'.$term.'%';
                $q->where(fn (Builder $w) => $w
                    ->where('payee', 'like', $like)
                    ->orWhere('memo', 'like', $like)
                    ->orWhere('bank_description', 'like', $like));
            })
            ->when($v['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('date', '>=', $from))
            ->when($v['to'] ?? null, fn (Builder $q, $to) => $q->whereDate('date', '<=', $to))
            ->when(isset($v['min_amount']), fn (Builder $q) => $q->where('amount', '>=', $v['min_amount']))
            ->when(isset($v['max_amount']), fn (Builder $q) => $q->where('amount', '<=', $v['max_amount']))
            ->when($v['account'] ?? null, fn (Builder $q, string $name) => $q
                ->whereHas('account', fn (Builder $a) => $a->where('name', 'like', '%'.$name.'%')))
            ->when($v['category'] ?? null, function (Builder $q, string $name): void {
                $like = '%'.$name.'%';
                $q->where(fn (Builder $w) => $w
                    ->whereHas('category', fn (Builder $c) => $c->where('name', 'like', $like))
                    ->orWhereHas('splits.category', fn (Builder $c) => $c->where('name', 'like', $like)));
            })
            ->when($v['uncategorized'] ?? false, fn (Builder $q) => $q->needsCategorization());

        $total = (clone $query)->count();
        $sum = round((float) (clone $query)->sum('amount'), 2);
        $rows = $query->orderByDesc('date')->orderByDesc('id')->limit($v['limit'] ?? 50)->get();

        return response()->json([
            'count' => $total,
            'sum' => $sum,
            'returned' => $rows->count(),
            'transactions' => $rows->map(fn (Transaction $t) => $this->transactionRow($t))->values(),
        ]);
    }

    /**
     * Rapporter: spending (forbruk per kategori), income-expense, category-trend
     * (?category= del av navnet) og net-worth. Periode ?from=&to= (YYYY-MM), standard siste 12 mnd.
     */
    public function report(Request $request, string $type): JsonResponse
    {
        $v = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m'],
            'to' => ['sometimes', 'date_format:Y-m'],
            'category' => [Rule::requiredIf($type === 'category-trend'), 'string', 'max:100'],
        ]);

        $to = $v['to'] ?? CarbonImmutable::now()->format('Y-m');
        $from = $v['from'] ?? CarbonImmutable::parse($to.'-01')->subMonths(11)->format('Y-m');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return match ($type) {
            'spending' => response()->json($this->compactSpending($this->reports->spendingByCategory($from, $to))),
            'income-expense' => response()->json($this->reports->incomeVsExpense($from, $to)),
            'net-worth' => response()->json($this->reports->netWorth($from, $to)),
            'category-trend' => $this->categoryTrend($v['category'], $from, $to),
            default => response()->json(['message' => 'Ukjent rapport.'], 404),
        };
    }

    /** Planlagte transaksjoner de neste ?days= dagene (standard 14, høyst 90). */
    public function upcoming(Request $request): JsonResponse
    {
        $v = $request->validate(['days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_UPCOMING_DAYS]]);
        $from = CarbonImmutable::today();
        $to = $from->addDays((int) ($v['days'] ?? 14));

        $items = ScheduledTransaction::with(['account:id,name', 'transferAccount:id,name', 'category:id,name'])
            ->get()
            ->flatMap(fn (ScheduledTransaction $s) => collect($s->occurrencesBetween($from, $to))
                ->map(fn (CarbonImmutable $date) => array_filter([
                    'date' => $date->toDateString(),
                    'amount' => round((float) $s->amount, 2),
                    'account' => $s->account?->name,
                    'payee' => $s->payee,
                    'category' => $s->category?->name,
                    'transfer_to' => $s->transferAccount?->name,
                    'memo' => $s->memo,
                    'frequency' => $s->frequency->value,
                ], fn ($x) => $x !== null && $x !== '')))
            ->sortBy('date')
            ->values();

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'count' => $items->count(),
            'sum' => round($items->sum('amount'), 2),
            'items' => $items,
        ]);
    }

    /** Nedbetalingsprojeksjon for alle åpne lånekontoer (?basis= 3, 6 eller 12 måneder). */
    public function loans(Request $request): JsonResponse
    {
        $v = $request->validate(['basis' => ['sometimes', Rule::in([3, 6, 12])]]);
        $basis = (int) ($v['basis'] ?? 6);

        $loans = Account::query()
            ->where('type', AccountType::Loan)
            ->where('closed', false)
            ->orderBy('name')
            ->get()
            ->map(function (Account $a) use ($basis) {
                $p = $this->loans->projection($a, $basis);

                return [
                    'account' => $a->name,
                    'balance' => $p['balance'],
                    'interest_rate' => $p['interest_rate'],
                    'avg_payment' => $p['avg_payment'],
                    'basis_months' => $p['basis_months'],
                    'payoff_month' => $p['payoff_month'],
                    'months_to_payoff' => $p['months_to_payoff'],
                    'total_interest' => $p['total_interest'],
                    // Saldo ved hvert årsskifte (hele serien blir for lang)
                    'yearly' => collect($p['series'])->filter(fn (array $s) => str_ends_with($s['month'], '-12'))->values(),
                ];
            });

        return response()->json(['loans' => $loans]);
    }

    /* ---------- Hjelpere ---------- */

    /** @return array<string, mixed> */
    private function transactionRow(Transaction $t): array
    {
        $category = match (true) {
            $t->is_split => null,
            $t->category !== null => $t->category->name,
            $t->transfer_id !== null => null,
            $t->rta => 'Klar til å fordele',
            default => null,
        };

        return array_filter([
            'date' => $t->date->toDateString(),
            'amount' => round((float) $t->amount, 2),
            'account' => $t->account?->name,
            'payee' => $t->payee,
            'category' => $category,
            'splits' => $t->is_split
                ? $t->splits->map(fn ($s) => array_filter([
                    'category' => $s->category?->name,
                    'amount' => round((float) $s->amount, 2),
                    'memo' => $s->memo,
                ], fn ($x) => $x !== null && $x !== ''))->values()->all()
                : null,
            'transfer_with' => $t->transfer?->account?->name,
            'memo' => $t->memo,
            'bank_text' => $t->bank_description !== $t->payee ? $t->bank_description : null,
            'pending' => $t->pending ?: null,
            'uncategorized' => $t->relationLoaded('account') && $t->needsCategorization() ? true : null,
        ], fn ($x) => $x !== null && $x !== '');
    }

    /**
     * @param  array{from: string, to: string, total: float, groups: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function compactSpending(array $report): array
    {
        $groups = collect($report['groups'])
            ->map(fn (array $g) => [
                'group' => $g['name'],
                'total' => $g['total'],
                'categories' => collect($g['categories'])
                    ->filter(fn (array $c) => $c['total'] != 0)
                    ->map(fn (array $c) => ['category' => $c['name'], 'total' => $c['total']])
                    ->sortByDesc('total')
                    ->values(),
            ])
            ->filter(fn (array $g) => $g['total'] != 0)
            ->sortByDesc('total')
            ->values();

        return ['from' => $report['from'], 'to' => $report['to'], 'total' => $report['total'], 'groups' => $groups];
    }

    private function categoryTrend(string $name, string $from, string $to): JsonResponse
    {
        /** @var Collection<int, Category> $hits */
        $hits = Category::query()->where('name', 'like', '%'.$name.'%')->orderBy('name')->get();
        $exact = $hits->first(fn (Category $c) => mb_strtolower($c->name) === mb_strtolower($name));

        if ($hits->isEmpty()) {
            return response()->json(['message' => "Fant ingen kategori som heter «{$name}»."], 404);
        }
        if (! $exact && $hits->count() > 1) {
            return response()->json([
                'message' => "«{$name}» passer på flere kategorier – velg én.",
                'categories' => $hits->pluck('name'),
            ], 422);
        }

        $report = $this->reports->categoryTrend($exact ?? $hits->first(), $from, $to);

        return response()->json([
            'category' => $report['category']['name'],
            'from' => $report['from'],
            'to' => $report['to'],
            'total' => round(collect($report['months'])->sum('total'), 2),
            'months' => $report['months'],
        ]);
    }
}
