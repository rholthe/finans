<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $fillable = [
        'bank_connection_id',
        'account_id',
        'external_id',
        'name',
        'iban',
        'ignored',
        'rate_limit',
        'rate_limit_remaining',
        'rate_limit_reset_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ignored' => 'boolean',
            'rate_limit_reset_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BankConnection, $this>
     */
    public function bankConnection(): BelongsTo
    {
        return $this->belongsTo(BankConnection::class);
    }

    /**
     * Den koblede budsjettkontoen importerte transaksjoner lander på.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Visningsnavn: brukervalgt navn, ellers IBAN, ellers den eksterne konto-id-en.
     */
    public function displayName(): string
    {
        return $this->name ?: ($this->iban ?? $this->external_id);
    }

    /**
     * Om kontoen er klar til synk: koblet til en budsjettkonto, ikke ignorert,
     * og ikke for tiden rate-limited.
     */
    public function isSyncable(): bool
    {
        if ($this->account_id === null || $this->ignored) {
            return false;
        }

        return ! ($this->rate_limit_remaining !== null
            && $this->rate_limit_remaining <= 0
            && $this->rate_limit_reset_at?->isFuture());
    }
}
