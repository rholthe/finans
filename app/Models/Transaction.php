<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'category_id',
        'scheduled_transaction_id',
        'transfer_id',
        'external_id',
        'bank_description',
        'rule_id',
        'locked',
        'date',
        'amount',
        'payee',
        'memo',
        'cleared',
        'pending',
        'rta',
        'reconciled_at',
        'is_starting_balance',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'amount' => 'decimal:2',
            'cleared' => 'boolean',
            'pending' => 'boolean',
            'rta' => 'boolean',
            'reconciled_at' => 'datetime',
            'is_starting_balance' => 'boolean',
            'locked' => 'boolean',
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
     * Rader som mangler aktiv kategorisering: på en budsjettkonto, uten kategori,
     * ikke bevisst plassert i RTA, og ikke en overføring, startsaldo eller
     * reservert (pending) post. Dette er det brukeren må rydde i.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeNeedsCategorization(Builder $query): void
    {
        $query->whereHas('account', fn (Builder $q) => $q->where('on_budget', true))
            ->whereNull('category_id')
            ->where('rta', false)
            ->whereNull('transfer_id')
            ->where('is_starting_balance', false)
            ->where('pending', false);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<ScheduledTransaction, $this>
     */
    public function scheduledTransaction(): BelongsTo
    {
        return $this->belongsTo(ScheduledTransaction::class);
    }

    /**
     * @return BelongsTo<Rule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * Det andre benet i en overføring (null for vanlige transaksjoner).
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transfer_id');
    }
}
