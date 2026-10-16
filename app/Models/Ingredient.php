<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'unit', 'cost_per_unit', 'use_unit', 'use_per_unit', 'active'])]
class Ingredient extends Model
{
    /** Cómo se compra. */
    public const UNITS = ['kg', 'g', 'lt', 'ml', 'pza', 'paquete', 'caja', 'bote', 'bolsa', 'manojo'];

    /** Cómo se suele usar en las recetas; se puede escribir otra, como "untada". */
    public const USE_UNITS = ['g', 'ml', 'pza', 'cucharada', 'porción', 'rebanada'];

    /** Equivalencias que no hay que preguntar: cantidad en la otra unidad = cantidad × factor. */
    public const CONVERSIONS = ['kg' => ['g' => 1000], 'g' => ['kg' => 0.001], 'lt' => ['ml' => 1000], 'ml' => ['lt' => 0.001]];

    /** Lo que se escribe completo y se guarda abreviado. */
    protected const ALIASES = ['gramo' => 'g', 'kilo' => 'kg', 'kilogramo' => 'kg', 'litro' => 'lt', 'mililitro' => 'ml', 'pieza' => 'pza'];

    /** Abreviaturas, que no cambian en plural. */
    protected const ABBREVIATIONS = ['kg', 'g', 'lt', 'ml', 'pza'];

    protected function casts(): array
    {
        return [
            'cost_per_unit' => 'decimal:4',
            'use_per_unit' => 'decimal:4',
            'active' => 'boolean',
        ];
    }

    /** Cuántas $to son una $from (1 kg = 1000 g); null si no se puede saber sin preguntar. */
    public static function factorBetween(string $from, string $to): ?float
    {
        return $from === $to ? 1.0 : (self::CONVERSIONS[$from][$to] ?? null);
    }

    /** "porciones" para 4 y "porción" para 1. Las abreviaturas no cambian: 4 pza, 100 g. */
    public static function unitLabel(string $unit, float $quantity = 1): string
    {
        if (abs($quantity - 1) < 0.00001 || in_array($unit, self::ABBREVIATIONS, true)) {
            return $unit;
        }

        return match (true) {
            str_ends_with($unit, 'ión') => mb_substr($unit, 0, -3).'iones',
            str_ends_with($unit, 'z') => mb_substr($unit, 0, -1).'ces',
            (bool) preg_match('/[aeiouáéó]$/u', $unit) => $unit.'s',
            default => $unit.'es',
        };
    }

    /**
     * Una unidad escrita a mano se guarda como la que ya existe, para no tener "untada" y "untadas" en la lista:
     * sin importar mayúsculas, acentos ni plural, y "gramos" como g.
     *
     * @param  iterable<string>  $known
     */
    public static function normalizeUnit(?string $unit, iterable $known): ?string
    {
        $plain = fn (string $text) => Str::lower(Str::ascii(trim($text)));
        $typed = $plain((string) $unit);

        if ($typed === '') {
            return null;
        }

        $candidates = collect(self::ALIASES)->merge(collect($known)->mapWithKeys(fn (string $u) => [$u => $u]));

        foreach ($candidates as $name => $standard) {
            if (in_array($typed, [$plain($name), $plain(self::unitLabel($name, 2))], true)) {
                return $standard;
            }
        }

        // Una nueva se guarda en singular ("untadas" como untada); el plural se arma al mostrarla.
        $unit = Str::lower(trim((string) $unit));

        return match (true) {
            str_ends_with($unit, 'iones') => mb_substr($unit, 0, -5).'ión',
            (bool) preg_match('/[aeo]s$/u', $unit) => mb_substr($unit, 0, -1),
            default => $unit,
        };
    }

    /** En qué va en las recetas y los extras: la unidad de uso o, si no tiene, la de compra. */
    public function recipeUnit(): string
    {
        return $this->use_unit ?? $this->unit;
    }

    /** Cuántas unidades de receta trae lo que se compra: 8 si el paquete trae 8 pza, 1 si se usa como se compra. */
    public function usesPerUnit(): float
    {
        return $this->use_unit && (float) $this->use_per_unit > 0 ? (float) $this->use_per_unit : 1.0;
    }

    /** Costo de una unidad de receta: con el paquete a $45 y 8 pza, $5.625. */
    public function costPerUse(): float
    {
        return (float) $this->cost_per_unit / $this->usesPerUnit();
    }

    /** "$5.63 por pza" o "$18 por 100 g" (por g si lo comprado pesa menos de 100); null si se usa como se compra. */
    public function useCostLabel(): ?string
    {
        if (! $this->use_unit) {
            return null;
        }

        return in_array($this->use_unit, ['g', 'ml'], true) && $this->usesPerUnit() >= 100
            ? money($this->costPerUse() * 100).' por 100 '.$this->use_unit
            : money($this->costPerUse()).' por '.$this->use_unit;
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
