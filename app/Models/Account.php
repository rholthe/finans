<?php

namespace App\Models;

use App\Enums\AccountType;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'on_budget',
        'currency',
        'closed',
        'note',
        'interest_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'on_budget' => 'boolean',
            'closed' => 'boolean',
            'interest_rate' => 'decimal:2',
        ];
    }

    /** Effektiv årsrente (prosent) omregnet til månedsrente, eller null. */
    public function monthlyInterestRate(): ?float
    {
        if ($this->interest_rate === null) {
            return null;
        }

        return (1 + (float) $this->interest_rate / 100) ** (1 / 12) - 1;
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * @return HasMany<Reconciliation, $this>
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(Reconciliation::class);
    }

    /**
     * Koblede bank-kontoer (banksynk). Tom = kontoen synkes ikke.
     *
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }

    /** Maks tillatt flyttall-støy før app-total og banksaldo regnes som ulike. */
    public const BALANCE_MISMATCH_THRESHOLD = 0.005;

    /**
     * Bankens tilgjengelige saldo (bokført + reservert), aggregert over de
     * koblede bankkontoene som har en synket saldo. Null hvis ingen er synket.
     * Krever at `bankAccounts` er lastet.
     */
    public function bankAvailableBalance(): ?float
    {
        $synced = $this->bankAccounts
            ->whereNotNull('balance_synced_at')
            ->whereNotNull('balance_available');

        if ($synced->isEmpty()) {
            return null;
        }

        return round((float) $synced->sum('balance_available'), 2);
    }

    /**
     * Signert avvik mellom app-total (sum av alle transaksjoner, inkl.
     * reserverte) og bankens saldo inkl. reservert, eller null når de matcher
     * (eller banksaldo mangler). Positivt = appen viser mer enn banken.
     *
     * @param  float  $appTotal  Sum av alle transaksjoner på kontoen.
     */
    public function bankBalanceMismatch(float $appTotal): ?float
    {
        $bankAvailable = $this->bankAvailableBalance();

        if ($bankAvailable === null) {
            return null;
        }

        $diff = round($appTotal - $bankAvailable, 2);

        return abs($diff) >= self::BALANCE_MISMATCH_THRESHOLD ? $diff : null;
    }
}
