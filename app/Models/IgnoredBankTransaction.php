<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tombstone for en slettet bankimport: «ikke importer (account_id, external_id)
 * på nytt». Opprettes når en bokført bankrad slettes, og seedes inn i dedup-
 * settet ved banksynk. Permanent – det finnes ingen «tillat re-import» i dag.
 */
class IgnoredBankTransaction extends Model
{
    protected $fillable = [
        'account_id',
        'external_id',
    ];
}
