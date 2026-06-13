<?php

namespace App\Models;

use App\Enums\RuleApplies;
use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    /**
     * Standardverdier slik at en nyopprettet modell har dem i minnet (ikke bare
     * som DB-default), så casts og serialisering virker uten en ekstra refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'applies_to' => 'both',
        'priority' => 0,
        'active' => true,
    ];

    protected $fillable = [
        'name',
        'priority',
        'active',
        'match_contains',
        'match_not_contains',
        'applies_to',
        'set_payee',
        'set_memo',
        'category_id',
        'last_applied_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'applies_to' => RuleApplies::class,
            'last_applied_at' => 'datetime',
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
     * Avgjør om regelen matcher en gitt info-tekst og (signert) beløp.
     */
    public function matches(string $description, float $amount): bool
    {
        if (! $this->active || ! $this->applies_to->matchesAmount($amount)) {
            return false;
        }

        foreach ($this->terms($this->match_contains) as $term) {
            if (! Str::contains($description, $term, ignoreCase: true)) {
                return false;
            }
        }

        foreach ($this->terms($this->match_not_contains) as $term) {
            if (Str::contains($description, $term, ignoreCase: true)) {
                return false;
            }
        }

        // En regel uten match_contains-termer regnes ikke som en treff (unngå
        // at en tom regel fanger alt).
        return $this->terms($this->match_contains) !== [];
    }

    /**
     * Del en komma-/linjeseparert streng i rensede termer.
     *
     * @return list<string>
     */
    private function terms(?string $value): array
    {
        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[,\n]+/', (string) $value) ?: [],
        )));
    }
}
