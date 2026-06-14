<?php

namespace App\Models;

use Database\Factories\ReconciliationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reconciliation extends Model
{
    /** @use HasFactory<ReconciliationFactory> */
    use HasFactory;

    protected $fillable = [
        'account_id',
        'reconciled_at',
        'statement_balance',
        'cleared_balance',
        'adjustment_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reconciled_at' => 'datetime',
            'statement_balance' => 'decimal:2',
            'cleared_balance' => 'decimal:2',
            'adjustment_amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
