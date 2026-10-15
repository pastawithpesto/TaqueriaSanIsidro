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

    /** Extras que gastan este insumo (ej. "Extra queso"). */
    public function extras(): HasMany
    {
        return $this->hasMany(ProductExtra::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** "En 2 recetas y 1 extra". Requiere withCount(['products', 'extras']). */
    public function usageLabel(): string
    {
        $parts = array_filter([
            $this->products_count ? $this->products_count.' '.($this->products_count === 1 ? 'receta' : 'recetas') : null,
            $this->extras_count ? $this->extras_count.' '.($this->extras_count === 1 ? 'extra' : 'extras') : null,
        ]);

        return $parts ? 'En '.implode(' y ', $parts) : 'Sin usar en recetas ni extras';
    }

    /** El costo sigue a la compra más reciente con cantidad. Si ya no queda ninguna, se deja como está. */
    public function syncCostFromPurchases(): void
    {
        $latest = $this->expenses()->where('quantity', '>', 0)->orderByDesc('spent_on')->orderByDesc('id')->first();

        if ($latest) {
            $this->update(['cost_per_unit' => round((float) $latest->total / (float) $latest->quantity, 4)]);
        }
    }
}
