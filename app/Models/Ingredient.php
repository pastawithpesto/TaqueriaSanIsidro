<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'unit', 'cost_per_unit', 'active'])]
class Ingredient extends Model
{
    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_ingredient')->withPivot('quantity');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
