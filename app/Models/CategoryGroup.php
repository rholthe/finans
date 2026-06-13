<?php

namespace App\Models;

use Database\Factories\CategoryGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoryGroup extends Model
{
    /** @use HasFactory<CategoryGroupFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * @return HasMany<Category, $this>
     */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class)->orderBy('sort_order')->orderBy('name');
    }
}
