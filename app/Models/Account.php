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
        ];
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
}
