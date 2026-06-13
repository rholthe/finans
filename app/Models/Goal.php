<?php

namespace App\Models;

use App\Enums\GoalType;
use Carbon\CarbonImmutable;
use Database\Factories\GoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'type',
        'target_amount',
        'target_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => GoalType::class,
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Hvor mye mer som må tildeles denne måneden for å være i rute mot målet.
     * Ren domenelogikk – ingenting lagres.
     *
     * @param  string  $month  «YYYY-MM»
     * @param  float  $assignedThisMonth  Allerede tildelt denne måneden
     * @param  float  $available  Tilgjengelig nå (inkl. denne månedens tildeling + rullering)
     */
    public function neededThisMonth(string $month, float $assignedThisMonth, float $available): float
    {
        $target = (float) $this->target_amount;

        $needed = match ($this->type) {
            GoalType::Monthly => $target - $assignedThisMonth,
            GoalType::TargetBalance => $target - $available,
            GoalType::TargetBalanceByDate => $this->pacedNeed($month, $target, $assignedThisMonth, $available),
        };

        return round(max(0, $needed), 2);
    }

    /**
     * Månedlig bidrag for et datofestet sparemål: fordel det som gjenstår
     * (mål − tilgjengelig ved månedsstart) jevnt over gjenstående måneder.
     */
    private function pacedNeed(string $month, float $target, float $assignedThisMonth, float $available): float
    {
        $availableAtStart = $available - $assignedThisMonth;
        $remaining = max(0, $target - $availableAtStart);

        if ($remaining <= 0) {
            return 0;
        }

        $current = CarbonImmutable::parse($month)->startOfMonth();
        $deadline = CarbonImmutable::parse($this->target_date)->startOfMonth();
        $monthsRemaining = max(1, (int) floor($current->diffInMonths($deadline, false)) + 1);

        return ($remaining / $monthsRemaining) - $assignedThisMonth;
    }
}
