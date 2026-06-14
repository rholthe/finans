<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankConnection extends Model
{
    protected $fillable = [
        'provider',
        'institution_id',
        'name',
        'consent_id',
        'status',
    ];

    /**
     * @return HasMany<BankAccount, $this>
     */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
