<?php

namespace App\Models;

use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

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

    /** Categorías en uso, en orden alfabético, para escoger al dar de alta un producto. */
    public static function categories(): Collection
    {
        return static::whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
    }

    /**
     * "tacos" o "Tácos" se guardan como "Tacos" si esa categoría ya existe, para no tener dos iguales en Vender.
     * $exceptId deja fuera al producto que se edita: si es el único en su categoría, puede corregir cómo se escribe.
     */
    public static function normalizeCategory(?string $category, ?int $exceptId = null): ?string
    {
        $category = trim((string) $category);

        if ($category === '') {
            return null;
        }

        $plain = fn (string $text) => Str::lower(Str::ascii($text));

        return static::whereNotNull('category')->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->distinct()->pluck('category')
            ->first(fn (string $existing) => $plain($existing) === $plain($category)) ?? $category;
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'product_ingredient')->withPivot('quantity', 'removable');
    }

    public function isSoldOut(): bool
    {
        return $this->sold_out_on?->isSameDay(BusinessDay::today()) ?? false;
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
     * Costo de una pieza con el costo actual de cada insumo. La receta va en la unidad de uso del insumo.
     *
     * @param  array<int>  $without  insumos que el cliente pidió quitar
     */
    public function unitCost(array $without = []): float
    {
        return $this->ingredients
            ->reject(fn (Ingredient $i) => in_array($i->id, $without) && $i->pivot->removable)
            ->sum(fn (Ingredient $i) => $i->pivot->quantity * $i->costPerUse());
    }
}
