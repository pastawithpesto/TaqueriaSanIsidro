<?php

use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductExtra;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Insumos')] class extends Component
{
    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $unit = 'kg';

    public ?string $cost_per_unit = '0';

    /** Cómo se usa en las recetas si no es como se compra: el pan se compra por paquete y se usa por pza. */
    public ?string $use_unit = null;

    /** Cuántas de esas trae lo que se compra: 8 pza por paquete. */
    public ?string $use_per_unit = null;

    /** El insumo que se edita ya está en recetas, extras o compras. */
    public bool $inUse = false;

    /** Vender un insumo tal cual se compra (refrescos, aguas): se crea el producto con ese insumo en su receta. */
    public bool $showSell = false;

    public ?int $sellId = null;

    /** Producto que ya vende este insumo y se está editando; null para crear otro. */
    public ?int $sellProductId = null;

    public string $sellName = '';

    public ?string $sellPrice = null;

    public ?string $sellCategory = null;

    public string $sellQuantity = '1';

    /** La búsqueda ignora mayúsculas y acentos: "limon" encuentra "Limón". Son pocos insumos, se filtra aquí. */
    #[Computed]
    public function ingredients()
    {
        $plain = fn (string $text) => Str::lower(Str::ascii($text));
        $search = $plain(trim($this->search));

        return Ingredient::withCount(['products', 'extras'])
            ->orderByDesc('active')->orderBy('name')
            ->get()
            ->when($search !== '', fn ($all) => $all->filter(fn ($i) => str_contains($plain($i->name), $search))->values());
    }

    #[Computed]
    public function hasAny(): bool
    {
        return Ingredient::exists();
    }

    #[Computed]
    public function sellIngredient(): ?Ingredient
    {
        return $this->sellId ? Ingredient::find($this->sellId) : null;
    }

    /** Las de siempre más las que ya se escribieron en otros insumos ("untada"). */
    #[Computed]
    public function useUnits()
    {
        return collect(Ingredient::USE_UNITS)
            ->merge(Ingredient::whereNotNull('use_unit')->distinct()->pluck('use_unit'))
            ->unique()->values();
    }

    #[Computed]
    public function categories()
    {
        return Product::categories();
    }

    /** Productos que ya venden este insumo tal cual (con él como único ingrediente): puede haber varios sabores. */
    #[Computed]
    public function soldAs()
    {
        return $this->sellId
            ? Product::with('ingredients')->whereHas('ingredients', fn ($q) => $q->whereKey($this->sellId))->has('ingredients', '=', 1)->orderBy('name')->get()
            : collect();
    }

    /** Si ya se vende, abre ese producto para cambiar su precio; si no, uno nuevo. */
    public function startSelling(int $id): void
    {
        $this->sellId = Ingredient::findOrFail($id)->id;
        unset($this->sellIngredient, $this->soldAs);
        $this->pickSellProduct($this->soldAs->first()?->id);
        $this->showSell = true;
    }

    public function pickSellProduct(?int $productId): void
    {
        $ingredient = $this->sellIngredient;
        $product = $productId ? $this->soldAs->firstWhere('id', $productId) : null;
        $plain = fn (string $number) => rtrim(rtrim($number, '0'), '.');

        $this->resetErrorBag();
        $this->sellProductId = $product?->id;

        if ($product) {
            $this->sellName = $product->name;
            $this->sellPrice = $plain((string) $product->price);
            $this->sellCategory = $product->category;
            $this->sellQuantity = $plain((string) $product->ingredients->first()->pivot->quantity);

            return;
        }

        $this->sellName = Product::where('name', $ingredient->name)->exists() ? '' : $ingredient->name;
        $this->sellPrice = null;
        $this->sellCategory = $this->soldAs->first()?->category;
        // Por pieza o porción lo normal es una por venta; por kilo o litro hay que decir cuánto lleva cada una.
        $this->sellQuantity = in_array($ingredient->recipeUnit(), ['kg', 'g', 'lt', 'ml'], true) ? '' : '1';
    }

    public function sell(): void
    {
        $ingredient = Ingredient::findOrFail($this->sellId);
        $editing = $this->sellProductId ? $this->soldAs->firstWhere('id', $this->sellProductId) ?? abort(404) : null;
        $this->sellName = trim($this->sellName);
        $this->sellCategory = Product::normalizeCategory($this->sellCategory, $editing?->id);

        $this->validate([
            'sellName' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->ignore($editing?->id)],
            'sellPrice' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'sellCategory' => ['nullable', 'string', 'max:40'],
            'sellQuantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
        ], [
            'sellName.unique' => 'Ya hay un producto con ese nombre.',
        ], ['sellName' => 'nombre', 'sellPrice' => 'precio', 'sellCategory' => 'categoría', 'sellQuantity' => 'cantidad']);

        $product = DB::transaction(function () use ($ingredient, $editing) {
            $fields = ['name' => $this->sellName, 'price' => $this->sellPrice, 'category' => $this->sellCategory];

            if ($editing) {
                $editing->update($fields);
                $editing->ingredients()->updateExistingPivot($ingredient->id, ['quantity' => $this->sellQuantity]);

                return $editing;
            }

            $product = Product::create($fields + ['sort_order' => (int) Product::max('sort_order') + 1]);
            $product->ingredients()->attach($ingredient->id, ['quantity' => $this->sellQuantity, 'removable' => false]);

            return $product;
        });

        $this->showSell = false;
        unset($this->ingredients, $this->categories, $this->soldAs);
        $this->dispatch('toast', message: $editing ? $product->name.' guardado' : $product->name.' ya aparece en Vender');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $this->resetForm();
        $this->editingId = $i->id;
        $this->name = $i->name;
        $this->unit = $i->unit;
        $this->cost_per_unit = rtrim(rtrim((string) $i->cost_per_unit, '0'), '.') ?: '0';
        $this->use_unit = $i->use_unit;
        $this->use_per_unit = $i->use_unit ? rtrim(rtrim((string) $i->use_per_unit, '0'), '.') : null;
        $this->inUse = $this->isInUse($i);
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'use_unit', 'use_per_unit', 'inUse');
        $this->unit = 'kg';
        $this->cost_per_unit = '0';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        // Usarlo como se compra es no tener unidad de uso; de kg a g la equivalencia no se pregunta.
        $this->use_unit = Str::lower(trim((string) $this->use_unit)) ?: null;
        if ($this->use_unit === $this->unit) {
            $this->use_unit = null;
        }
        $known = $this->use_unit ? Ingredient::factorBetween($this->unit, $this->use_unit) : null;
        $this->use_per_unit = $this->use_unit ? ($known !== null ? (string) $known : $this->use_per_unit) : null;

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', Rule::in(Ingredient::UNITS)],
            'cost_per_unit' => ['required', 'numeric', 'min:0', 'max:999999'],
            'use_unit' => ['nullable', 'string', 'max:20'],
            'use_per_unit' => ['nullable', 'required_with:use_unit', 'numeric', 'gt:0', 'max:999999'],
        ], [
            'use_per_unit.required_with' => 'Escribe cuántas rinde.',
        ], ['use_unit' => 'unidad', 'use_per_unit' => 'cantidad']);

        $ingredient = $this->editingId ? Ingredient::findOrFail($this->editingId) : null;

        if (! $ingredient) {
            Ingredient::create($data);
            $this->saved('Insumo guardado');

            return;
        }

        // Las recetas van en la unidad de uso y las compras en la de compra: si cambian, se convierten las cantidades.
        $old = ['unit' => $ingredient->unit, 'use' => $ingredient->recipeUnit(), 'per' => $ingredient->usesPerUnit()];
        $new = ['unit' => $data['unit'], 'use' => $data['use_unit'] ?? $data['unit'], 'per' => $data['use_unit'] ? (float) $data['use_per_unit'] : 1.0];
        $purchaseFactor = $this->purchaseFactor($old, $new);
        $recipeFactor = Ingredient::factorBetween($old['use'], $new['use'])
            ?? ($purchaseFactor !== null ? $purchaseFactor * $new['per'] / $old['per'] : null);

        $changes = fn (?float $factor) => $factor === null || abs($factor - 1) > 0.000001;
        $convertRecipes = $changes($recipeFactor) && $this->isInRecipes($ingredient);
        $convertPurchases = $changes($purchaseFactor) && $ingredient->expenses()->whereNotNull('quantity')->exists();

        if (($convertRecipes && $recipeFactor === null) || ($convertPurchases && $purchaseFactor === null)) {
            $this->addError('unit', "Ya se usa por {$old['unit']}. Déjalo así y abajo di cómo lo usas, o crea otro insumo.");

            return;
        }

        // Si no se tocó el costo, también se convierte: $6 por pza son $48 por paquete de 8.
        if ($changes($purchaseFactor) && abs((float) $data['cost_per_unit'] - (float) $ingredient->cost_per_unit) < 0.00005) {
            $data['cost_per_unit'] = round((float) $ingredient->cost_per_unit / $purchaseFactor, 4);
        }

        DB::transaction(function () use ($ingredient, $data, $convertRecipes, $recipeFactor, $convertPurchases, $purchaseFactor) {
            if ($convertRecipes) {
                $scale = ['quantity' => DB::raw('quantity * '.sprintf('%.10F', $recipeFactor))];
                DB::table('product_ingredient')->where('ingredient_id', $ingredient->id)->update($scale);
                ProductExtra::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')->update($scale);
            }
            if ($convertPurchases) {
                Expense::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')
                    ->update(['quantity' => DB::raw('quantity * '.sprintf('%.10F', $purchaseFactor))]);
            }
            $ingredient->update($data);
        });

        $moved = array_filter([
            $convertRecipes ? 'las recetas pasaron a '.Ingredient::unitLabel($new['use'], 2) : null,
            $convertPurchases ? 'las compras pasaron a '.Ingredient::unitLabel($new['unit'], 2) : null,
        ]);
        $this->saved($moved ? 'Insumo guardado: '.implode(' y ', $moved).'.' : 'Insumo guardado');
    }

    protected function saved(string $message): void
    {
        $this->resetForm();
        unset($this->ingredients, $this->hasAny, $this->useUnits);
        $this->dispatch('toast', message: $message);
    }

    /**
     * Cuántas unidades de compra nuevas es una de las anteriores, pasando por la de uso si hace falta:
     * de pza a paquete de 8 pza, una pza es 0.125 paquetes. Null si no hay forma de saberlo.
     *
     * @param  array{unit: string, use: string, per: float}  $old
     * @param  array{unit: string, use: string, per: float}  $new
     */
    protected function purchaseFactor(array $old, array $new): ?float
    {
        foreach ([[$old['unit'], 1.0], [$old['use'], $old['per']]] as [$from, $amount]) {
            foreach ([[$new['unit'], 1.0], [$new['use'], $new['per']]] as [$to, $per]) {
                if (($factor = Ingredient::factorBetween($from, $to)) !== null) {
                    return $amount * $factor / $per;
                }
            }
        }

        return null;
    }

    protected function isInRecipes(Ingredient $ingredient): bool
    {
        return $ingredient->products()->exists() || ProductExtra::where('ingredient_id', $ingredient->id)->whereNotNull('quantity')->exists();
    }

    protected function isInUse(Ingredient $ingredient): bool
    {
        return $this->isInRecipes($ingredient) || $ingredient->expenses()->whereNotNull('quantity')->exists();
    }

    public function toggle(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $i->update(['active' => ! $i->active]);
        unset($this->ingredients);
    }

    public function delete(int $id): void
    {
        $i = Ingredient::withCount(['products', 'extras'])->findOrFail($id);

        // En una receta o un extra no se borra: su costo dejaría de contar sin aviso.
        if ($i->products_count === 0 && $i->extras_count === 0) {
            $i->delete();
            $this->dispatch('toast', message: 'Insumo borrado');
        }

        unset($this->ingredients, $this->hasAny);
    }
};
?>

<div x-data="{ sheet: $wire.$entangle('showForm'), sellSheet: $wire.$entangle('showSell') }">
    <x-page-header title="Insumos" tour="insumos">
        <x-slot:actions>
            <button type="button" @click="sheet = true" class="btn-primary" data-tour="nuevo-insumo"><x-icon name="plus" class="size-4" /> Insumo</button>
        </x-slot:actions>
        @if ($this->hasAny)
            <div class="relative" data-tour="buscar">
                <x-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" />
                <input type="search" wire:model.live.debounce.250ms="search" class="input !min-h-11 !pl-10" placeholder="Buscar insumo" aria-label="Buscar insumo">
            </div>
        @endif
    </x-page-header>

    <div class="space-y-4">
    @if (! $this->hasAny)
        <x-empty icon="package" title="Empieza por tus insumos">
            Son lo que compras para cocinar: carne, tortillas, cebolla, cilantro. Con su costo la app calcula cuánto ganas por cada taco.
            <x-slot:action><button type="button" @click="sheet = true" class="btn-primary">Agregar el primer insumo</button></x-slot:action>
        </x-empty>
    @elseif ($this->ingredients->isEmpty())
        <p class="py-8 text-center text-sm text-muted">Ningún insumo coincide con "{{ $search }}".</p>
    @else
        <ul class="list">
            @foreach ($this->ingredients as $i)
                <li wire:key="i-{{ $i->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4" @if ($loop->first) data-tour="insumo" @endif>
                    <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})" class="min-w-0 flex-1 text-left">
                        <span @class(['block truncate font-semibold', 'text-muted' => ! $i->active])>{{ $i->name }}</span>
                        <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                            @unless ($i->active) <span class="badge bg-sunken text-muted">Inactivo</span> @endunless
                            {{ $i->usageLabel() }}
                        </span>
                        @if ($useCost = $i->useCostLabel())
                            <span class="mt-0.5 block text-sm text-muted">{{ $useCost }}</span>
                        @endif
                    </button>
                    <span class="text-right">
                        <span class="num block text-[1.375rem] leading-tight font-bold">{{ money($i->cost_per_unit) }}</span>
                        <span class="block text-xs text-muted">por {{ $i->unit }}</span>
                    </span>
                    <div x-data="{ menu: false }" class="relative">
                        <button type="button" x-ref="trigger" @click="menu = !menu" @click.outside="menu = false" @keydown.escape.window="menu = false" class="btn-quiet size-10 justify-center !px-0" aria-label="Opciones de {{ $i->name }}"><x-icon name="more" /></button>
                        <div x-show="menu" x-cloak x-anchor.bottom-end.offset.6.fixed="$refs.trigger" x-transition.origin.top.right class="menu" @click="menu = false">
                            <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})"><x-icon name="pencil" class="size-4 text-muted" /> Editar</button>
                            @if ($i->active)
                                <button type="button" @click="sellSheet = true; $wire.startSelling({{ $i->id }})"><x-icon name="sale" class="size-4 text-muted" /> Vender como producto</button>
                            @endif
                            <button type="button" wire:click="toggle({{ $i->id }})"><x-icon :name="$i->active ? 'eye-off' : 'eye'" class="size-4 text-muted" /> {{ $i->active ? 'Desactivar' : 'Activar' }}</button>
                            @if ($i->products_count === 0 && $i->extras_count === 0)
                                <button type="button" class="text-chile" @click="if (await $store.confirm.ask({ title: @js('¿Borrar '.$i->name.'?'), message: 'Las compras ya registradas se conservan.', action: 'Borrar' })) $wire.delete({{ $i->id }})"><x-icon name="trash" class="size-4" /> Borrar</button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="flex justify-center gap-2 px-4 text-center text-sm text-muted"><x-icon name="info" class="mt-0.5 size-4 shrink-0" /> El costo se actualiza solo al registrar una compra en Gastos.</p>
    @endif
    </div>

        <x-sheet :title="$editingId ? 'Editar insumo' : 'Nuevo insumo'" open="sheet" dismiss="sheet = false; $wire.cancel()">
            <div wire:loading.block wire:target="edit" class="space-y-4" aria-hidden="true">
                <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
                <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            </div>
            <form wire:submit="save" id="ingredient-form" class="space-y-4" wire:loading.remove wire:target="edit">
                <div>
                    <label class="label" for="name">Nombre</label>
                    <input id="name" type="text" wire:model="name" class="input" placeholder="Bistec, tortilla, cilantro">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="unit">Se compra por</label>
                        {{-- Al editar, pasar de kg a g (o de lt a ml) también convierte el costo a la vista: $35 por kg son $0.035 por g. --}}
                        <select id="unit" wire:model="unit" class="input"
                                x-data="{ from: '' }" @focus="from = $el.value"
                                @change="
                                    const factor = @js(\App\Models\Ingredient::CONVERSIONS)[from]?.[$el.value];
                                    if ($wire.editingId && factor && Number($wire.cost_per_unit) > 0) $wire.cost_per_unit = String(Math.round(Number($wire.cost_per_unit) / factor * 10000) / 10000);
                                    from = $el.value;
                                ">
                            @foreach (\App\Models\Ingredient::UNITS as $u)
                                <option value="{{ $u }}">{{ $u }}</option>
                            @endforeach
                        </select>
                        @error('unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="cost" x-text="'Costo por ' + $wire.unit">Costo por {{ $unit }}</label>
                        <x-money-input id="cost" step="any" wire:model="cost_per_unit" />
                        @error('cost_per_unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="hint">Si no sabes el costo exacto, pon uno aproximado. Se corrige solo cuando registres la compra.</p>

                {{-- Lo que no se usa como se compra: la receta dice "1 pza" de pan aunque se compre por paquete. --}}
                <section class="space-y-3 rounded-2xl bg-sunken/60 p-4"
                         x-data="{
                            conversions: @js(\App\Models\Ingredient::CONVERSIONS),
                            get use() { return String($wire.use_unit ?? '').trim().toLowerCase() },
                            get same() { return ! this.use || this.use === $wire.unit },
                            get known() { return this.same ? null : (this.conversions[$wire.unit]?.[this.use] ?? null) },
                            get per() { return this.known ?? Number($wire.use_per_unit) },
                            money(n) {
                                const whole = Math.abs(n % 1) < 0.005;
                                return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
                            },
                            get costText() {
                                const cost = Number($wire.cost_per_unit);
                                if (this.same || ! (this.per > 0) || ! (cost > 0)) return '';
                                const each = cost / this.per;
                                return ['g', 'ml'].includes(this.use)
                                    ? '100 ' + this.use + ' te cuestan ' + this.money(each * 100) + '.'
                                    : 'Cada ' + this.use + ' te cuesta ' + this.money(each) + '.';
                            },
                         }">
                    <div>
                        <h3 class="section-title">En las recetas</h3>
                        <p class="text-sm text-muted">Si no lo usas como lo compras. El pan se compra por paquete y se usa por pza; la mayonesa, por bote y por cucharada.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div :class="{ 'col-span-2': same }">
                            <label class="label" for="use-unit">Se usa por</label>
                            <x-combobox id="use-unit" bind="$wire.use_unit" :options="$this->useUnits->all()" none="Como se compra" create="Usar" maxlength="20" />
                            @error('use_unit') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div x-show="! same && known === null" x-cloak>
                            <label class="label" for="use-per-unit" x-text="'Cada ' + $wire.unit + ' rinde'"></label>
                            <div class="relative">
                                <input id="use-per-unit" type="number" inputmode="decimal" step="any" min="0" wire:model="use_per_unit" class="input pr-24">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted" x-text="unitLabel(use, 2)"></span>
                            </div>
                            @error('use_per_unit') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <p x-show="known !== null" x-cloak class="self-end pb-3 text-[15px]" x-text="'1 ' + $wire.unit + ' son ' + known + ' ' + use + '.'"></p>
                    </div>
                    <p class="font-semibold" x-show="costText" x-cloak x-text="costText"></p>
                    <p class="hint" x-show="! same && known === null" x-cloak>Si no sabes cuántas salen, calcula. Lo corriges cuando veas cuánto te dura.</p>
                </section>
                @if ($inUse)
                    <p class="hint">Ya se usa en recetas o compras. Si cambias cómo se compra o se usa, las cantidades se convierten solas.</p>
                @endif
            </form>
            <x-slot:footer>
                <button type="submit" form="ingredient-form" wire:loading.attr="disabled" wire:target="save,edit" class="btn-primary btn-lg w-full">Guardar insumo</button>
            </x-slot:footer>
        </x-sheet>

        {{-- Lo que se vende tal cual se compra: se vuelve un producto con este insumo en su receta. --}}
        <x-sheet title="Vender como producto" open="sellSheet">
            <div wire:loading.block wire:target="startSelling" class="space-y-4" aria-hidden="true">
                <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
                <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            </div>
            @if ($ingredient = $this->sellIngredient)
                <form wire:submit="sell" id="sell-form" class="space-y-4" wire:loading.remove wire:target="startSelling"
                      x-data="{
                        cost: {{ $ingredient->costPerUse() }},
                        money(n) {
                            const whole = Math.abs(n % 1) < 0.005;
                            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
                        },
                        get profitText() {
                            const c = this.cost * (Number($wire.sellQuantity) || 0), p = Number($wire.sellPrice);
                            if (! (p > 0) || ! (c > 0)) return '';
                            return p >= c
                                ? 'Te cuesta ' + this.money(c) + ' y ganas ' + this.money(p - c) + ' por cada uno.'
                                : 'Ojo: te cuesta ' + this.money(c) + ', más de lo que lo cobras.';
                        },
                      }">
                    @if ($this->soldAs->isEmpty())
                        <p class="text-sm text-muted">Para lo que se vende como se compra, como refrescos o aguas. Aparece en Vender y su costo sale de {{ $ingredient->name }}, así ves cuánto ganas.</p>
                    @else
                        <div>
                            <p class="label">Ya se vende como</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($this->soldAs as $p)
                                    <button type="button" wire:click="pickSellProduct({{ $p->id }})" wire:key="sold-{{ $p->id }}"
                                            class="chip gap-1.5" aria-pressed="{{ $sellProductId === $p->id ? 'true' : 'false' }}">
                                        {{ $p->name }} <span class="num font-normal">{{ money($p->price) }}</span>
                                    </button>
                                @endforeach
                                <button type="button" wire:click="pickSellProduct(null)" class="chip gap-1.5" aria-pressed="{{ $sellProductId === null ? 'true' : 'false' }}">
                                    <x-icon name="plus" class="size-4" /> Otro
                                </button>
                            </div>
                            <p class="hint mt-2">{{ $sellProductId ? 'Aquí cambias su precio o cuánto lleva cada venta.' : 'Otro producto con el mismo insumo, por ejemplo de otro tamaño o sabor.' }}</p>
                        </div>
                    @endif
                    <div>
                        <label class="label" for="sell-name">Nombre en Vender</label>
                        <input id="sell-name" type="text" wire:model="sellName" class="input" maxlength="255">
                        @error('sellName') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label" for="sell-price">Precio de venta</label>
                            <x-money-input id="sell-price" wire:model="sellPrice" placeholder="0" />
                            @error('sellPrice') <p class="error">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label" for="sell-quantity">Cada venta usa</label>
                            <div class="relative">
                                <input id="sell-quantity" type="number" inputmode="decimal" step="any" min="0" wire:model="sellQuantity" class="input pr-24">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted" x-text="unitLabel(@js($ingredient->recipeUnit()), Number($wire.sellQuantity) || 1)"></span>
                            </div>
                            @error('sellQuantity') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @if ($example = ['kg' => 'Si vendes el kilo entero, cada venta usa 1; medio kilo, 0.5.', 'lt' => 'Si vendes el litro entero, cada venta usa 1; medio litro, 0.5.', 'g' => 'Un cuarto de kilo son 250 g.', 'ml' => 'Medio litro son 500 ml.'][$ingredient->recipeUnit()] ?? null)
                        <p class="hint -mt-2">{{ $example }}</p>
                    @endif
                    <div>
                        <label class="label" for="sell-category">Categoría</label>
                        <x-combobox id="sell-category" bind="$wire.sellCategory" :options="$this->categories->all()" placeholder="Ej. Bebidas" create="Crear categoría" clear="Quitar categoría" maxlength="40" />
                        @error('sellCategory') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <p class="hint" x-show="profitText" x-cloak x-text="profitText"
                       :class="profitText.startsWith('Ojo') && '!text-chile font-semibold'"></p>
                </form>
            @endif
            <x-slot:footer>
                <button type="submit" form="sell-form" wire:loading.attr="disabled" wire:target="sell,startSelling,pickSellProduct" class="btn-primary btn-lg w-full">{{ $sellProductId ? 'Guardar cambios' : 'Crear producto' }}</button>
            </x-slot:footer>
        </x-sheet>

</div>
