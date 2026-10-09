<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'category', 'price', 'active', 'sort_order'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredient')->withPivot('quantity');
    }

    /** Costo de receta con el costo actual de cada insumo. */
    public function unitCost(): float
    {
        return $this->ingredients->sum(fn (Ingredient $i) => $i->pivot->quantity * $i->cost_per_unit);
    }
}
