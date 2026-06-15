<?php

namespace App\Models;

use App\Enums\ScheduleFrequency;
use Carbon\CarbonImmutable;
use Database\Factories\ScheduledTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledTransaction extends Model
{
    /** @use HasFactory<ScheduledTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'transfer_account_id',
        'category_id',
        'rta',
        'amount',
        'payee',
        'memo',
        'frequency',
        'start_date',
        'next_date',
        'end_date',
        'last_posted_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'rta' => 'boolean',
            'frequency' => ScheduleFrequency::class,
            'start_date' => 'date',
            'next_date' => 'date',
            'end_date' => 'date',
            'last_posted_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Mottakerkonto for en planlagt overføring (null for vanlige planlagte).
     *
     * @return BelongsTo<Account, $this>
     */
    public function transferAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'transfer_account_id');
    }

    public function isTransfer(): bool
    {
        return $this->transfer_account_id !== null;
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Forekomster i [$from, $to] som ennå ikke er postert (dvs. fra og med
     * next_date), begrenset av en eventuell end_date. Brukes til projeksjon av
     * kommende poster i budsjettet.
     *
     * @return list<CarbonImmutable>
     */
    public function occurrencesBetween(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $occurrences = [];
        $cursor = CarbonImmutable::parse($this->next_date);
        $limit = $this->end_date ? CarbonImmutable::parse($this->end_date) : null;

        // Hopp fram til intervallets start, med et tak for å unngå evig løkke.
        $guard = 0;
        while ($cursor->lt($from) && $guard++ < 1000) {
            if ($limit && $cursor->gt($limit)) {
                return $occurrences;
            }
            $cursor = $this->frequency->advance($cursor);
        }

        while ($cursor->lte($to) && $guard++ < 1000) {
            if ($limit && $cursor->gt($limit)) {
                break;
            }
            $occurrences[] = $cursor;
            $cursor = $this->frequency->advance($cursor);
        }

        return $occurrences;
    }
}
