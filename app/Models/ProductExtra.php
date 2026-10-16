<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'name', 'price', 'ingredient_id', 'quantity', 'sort_order'])]
class ProductExtra extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'quantity' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    /** La cantidad va en la unidad de uso del insumo, como en las recetas. */
    public function cost(): float
    {
        return $this->ingredient && $this->quantity ? (float) $this->quantity * $this->ingredient->costPerUse() : 0.0;
    }
}
