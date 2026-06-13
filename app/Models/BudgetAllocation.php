<?php

namespace App\Models;

use Database\Factories\BudgetAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetAllocation extends Model
{
    /** @use HasFactory<BudgetAllocationFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'month',
        'assigned',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // «month» lagres bevisst som ren Y-m-d-streng (den 1. i måneden), ikke
        // som datetime, slik at streng-/datosammenligninger i BudgetService blir
        // konsistente på tvers av databasedrivere.
        return [
            'assigned' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
