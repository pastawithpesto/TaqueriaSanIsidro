<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category', 'price', 'active', 'sold_out_on', 'sort_order'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
            'sold_out_on' => 'date',
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredient')->withPivot('quantity', 'removable');
    }

    public function isSoldOut(): bool
    {
        return $this->sold_out_on?->isToday() ?? false;
    }

    public function optionGroups(): HasMany
    {
        return $this->hasMany(ProductOptionGroup::class)->orderBy('sort_order')->orderBy('id');
    }

    public function extras(): HasMany
    {
        return $this->hasMany(ProductExtra::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Costo de una pieza con el costo actual de cada insumo.
     *
     * @param  array<int>  $without  insumos que el cliente pidió quitar
     */
    public function unitCost(array $without = []): float
    {
        return $this->ingredients
            ->reject(fn (Ingredient $i) => in_array($i->id, $without) && $i->pivot->removable)
            ->sum(fn (Ingredient $i) => $i->pivot->quantity * $i->cost_per_unit);
    }
}
