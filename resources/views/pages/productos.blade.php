<?php

use App\Models\Ingredient;
use App\Models\Product;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Productos')] class extends Component
{
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public ?string $category = null;

    public ?string $price = null;

    public bool $active = true;

    public int $sort_order = 0;

    /** @var array<int, array{ingredient_id: int, quantity: string}> */
    public array $recipe = [];

    #[Computed]
    public function products()
    {
        return Product::with('ingredients')->orderByDesc('active')->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::where('active', true)->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function categories()
    {
        return Product::whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
    }

    public function create(): void
    {
        $this->resetForm();
        $this->sort_order = (int) Product::max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Product::with('ingredients')->findOrFail($id);

        $this->resetForm();
        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->category = $p->category;
        $this->price = rtrim(rtrim((string) $p->price, '0'), '.');
        $this->active = $p->active;
        $this->sort_order = $p->sort_order;
        $this->recipe = $p->ingredients->map(fn ($i) => [
            'ingredient_id' => $i->id,
            'quantity' => rtrim(rtrim((string) $i->pivot->quantity, '0'), '.'),
        ])->values()->all();
        $this->showForm = true;
    }

    /** Copia el producto y su receta: útil para variedades (pastor, tripa). */
    public function duplicate(int $id): void
    {
        $source = Product::with('ingredients')->findOrFail($id);

        $copy = $source->replicate();
        $copy->name = $source->name.' (copia)';
        $copy->sort_order = (int) Product::max('sort_order') + 1;
        $copy->save();
        $copy->ingredients()->sync($source->ingredients->mapWithKeys(
            fn ($i) => [$i->id => ['quantity' => $i->pivot->quantity]]
        )->all());

        unset($this->products);
        $this->edit($copy->id);
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'category', 'price', 'sort_order', 'recipe');
        $this->active = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->recipe = collect($this->recipe)
            ->filter(fn ($r) => ! empty($r['ingredient_id']))
            ->map(fn ($r) => ['ingredient_id' => (int) $r['ingredient_id'], 'quantity' => (string) $r['quantity']])
            ->values()->all();

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'recipe.*.ingredient_id' => ['required', 'exists:ingredients,id'],
            'recipe.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $product = Product::updateOrCreate(['id' => $this->editingId], collect($data)->except('recipe')->all());

        $product->ingredients()->sync(
            collect($this->recipe)->mapWithKeys(fn ($r) => [$r['ingredient_id'] => ['quantity' => $r['quantity']]])->all()
        );

        $this->resetForm();
        unset($this->products, $this->categories);
        $this->dispatch('toast', message: 'Producto guardado');
    }

    /** Borra el producto. Las ventas pasadas conservan su nombre y precio. */
    public function delete(int $id): void
    {
        Product::whereKey($id)->delete();
        $this->resetForm();
        unset($this->products, $this->categories);
        $this->dispatch('toast', message: 'Producto eliminado');
    }

    public function toggle(int $id): void
    {
        $p = Product::findOrFail($id);
        $p->update(['active' => ! $p->active]);
        unset($this->products);
    }
};
?>

<div x-data="{
        sheet: $wire.$entangle('showForm'),
        ings: @js($this->ingredients->map(fn ($i) => ['name' => $i->name, 'unit' => $i->unit, 'cost' => (float) $i->cost_per_unit])),
        newIng: '',
        newQty: '',
        addError: '',
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
        add() {
            const q = Number(this.newQty);
            if (! this.newIng) return this.addError = 'Elige un insumo.';
            if (! (q > 0)) return this.addError = 'Escribe la cantidad.';
            const rows = [...($wire.recipe ?? [])];
            const i = rows.findIndex(r => String(r.ingredient_id) === String(this.newIng));
            const row = { ingredient_id: Number(this.newIng), quantity: String(q) };
            i === -1 ? rows.push(row) : rows.splice(i, 1, row);
            $wire.recipe = rows; this.newIng = ''; this.newQty = ''; this.addError = '';
        },
        remove(i) { const rows = [...$wire.recipe]; rows.splice(i, 1); $wire.recipe = rows },
        // Un solo cálculo que lee receta y precio directamente, para que siempre se actualice.
        stats() {
            const cost = ($wire.recipe ?? []).reduce((t, r) => t + (Number(r.quantity) || 0) * (this.ings[r.ingredient_id]?.cost ?? 0), 0);
            const price = Number($wire.price) || 0;
            const profit = price - cost;
            return { cost, price, profit, margin: price > 0 ? Math.round(profit / price * 100) : null };
        },
     }">
    <x-page-header title="Productos">
        <x-slot:actions>
            <button type="button" @click="sheet = true; $wire.create()" class="btn-primary"><x-icon name="plus" class="size-4" /> Producto</button>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-5">
    @if ($this->products->isEmpty())
        <x-empty icon="tag" title="Aún no hay productos">
            Agrega lo que vendes con su precio. Si das de alta tus insumos, puedes armar la receta y ver cuánto ganas por pieza.
            <x-slot:action><button type="button" @click="sheet = true; $wire.create()" class="btn-primary">Agregar el primer producto</button></x-slot:action>
        </x-empty>
    @else
        @if ($this->ingredients->isEmpty())
            <div class="flex items-start gap-3 rounded-2xl bg-limon-soft p-4 text-sm">
                <x-icon name="info" class="mt-0.5 size-5 shrink-0" />
                <p>Para calcular la ganancia necesitas insumos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold underline">Agrégalos aquí</a> y luego arma la receta de cada producto.</p>
            </div>
        @endif

        @foreach ($this->products->groupBy(fn ($p) => $p->category ?: 'Sin categoría') as $cat => $group)
            <section class="space-y-2" wire:key="cat-{{ $cat }}">
                <h2 class="section-title px-1">{{ $cat }}</h2>
                <ul class="list">
                    @foreach ($group as $p)
                        @php
                            $cost = $p->unitCost();
                            $price = (float) $p->price;
                            $margin = $price > 0 ? ($price - $cost) / $price * 100 : 0;
                            $hasRecipe = $p->ingredients->isNotEmpty();
                        @endphp
                        <li wire:key="prod-{{ $p->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                            <button type="button" @click="sheet = true; $wire.edit({{ $p->id }})" class="min-w-0 flex-1 text-left">
                                <span @class(['block truncate font-semibold', 'text-muted' => ! $p->active])>{{ $p->name }}</span>
                                <span class="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted">
                                    @unless ($p->active)
                                        <span class="badge bg-sunken text-muted"><x-icon name="eye-off" class="size-3.5" /> Oculto</span>
                                    @endunless
                                    @if ($hasRecipe)
                                        <span>Costo {{ money($cost) }}</span>
                                        <span>Ganas <span class="font-semibold text-ink">{{ money($price - $cost) }}</span></span>
                                        <span @class([
                                            'badge',
                                            'bg-salsa-soft text-salsa' => $margin >= 50,
                                            'bg-limon-soft text-ink' => $margin >= 25 && $margin < 50,
                                            'bg-chile-soft text-chile' => $margin < 25,
                                        ])>{{ round($margin) }}%</span>
                                    @else
                                        <span class="badge bg-limon-soft text-ink">Sin receta</span>
                                    @endif
                                </span>
                            </button>
                            <span class="num text-[1.375rem] font-bold">{{ money($price) }}</span>
                            <div x-data="{ menu: false }" class="relative">
                                <button type="button" @click="menu = !menu" @click.outside="menu = false" class="btn-quiet size-10 justify-center !px-0" aria-label="Opciones de {{ $p->name }}"><x-icon name="more" /></button>
                                <div x-show="menu" x-cloak x-transition.origin.top.right class="menu" @click="menu = false">
                                    <button type="button" @click="sheet = true; $wire.edit({{ $p->id }})"><x-icon name="pencil" class="size-4 text-muted" /> Editar</button>
                                    <button type="button" @click="sheet = true; $wire.duplicate({{ $p->id }})"><x-icon name="copy" class="size-4 text-muted" /> Duplicar</button>
                                    <button type="button" wire:click="toggle({{ $p->id }})">
                                        <x-icon :name="$p->active ? 'eye-off' : 'eye'" class="size-4 text-muted" /> {{ $p->active ? 'Ocultar en Vender' : 'Mostrar en Vender' }}
                                    </button>
                                    <button type="button" class="text-chile"
                                            @click="if (await $store.confirm.ask({ title: @js('¿Eliminar '.$p->name.'?'), message: 'Las ventas pasadas se conservan. Si solo quieres dejar de venderlo, mejor ocúltalo.', action: 'Eliminar' })) $wire.delete({{ $p->id }})">
                                        <x-icon name="trash" class="size-4" /> Eliminar
                                    </button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
    </div>

    <x-sheet :title="$editingId ? 'Editar producto' : 'Nuevo producto'" open="sheet" dismiss="sheet = false; $wire.cancel()">
        <div wire:loading.block wire:target="edit,duplicate,create" class="space-y-4" aria-hidden="true">
            <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
            <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            <div class="h-48 animate-pulse rounded-2xl bg-sunken"></div>
        </div>

        <form wire:submit="save" id="product-form" class="space-y-5" wire:loading.remove wire:target="edit,duplicate,create">
            <div class="grid grid-cols-2 gap-3">
                <div class="col-span-2">
                    <label class="label" for="name">Nombre</label>
                    <input id="name" type="text" wire:model="name" class="input" placeholder="Taco de pastor">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="price">Precio</label>
                    <x-money-input id="price" wire:model="price" placeholder="0" />
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="category">Categoría</label>
                    <input id="category" type="text" list="categories" wire:model="category" class="input" placeholder="Tacos">
                    <datalist id="categories">
                        @foreach ($this->categories as $c) <option value="{{ $c }}"> @endforeach
                    </datalist>
                </div>
            </div>

            <section class="space-y-3 rounded-2xl bg-sunken/60 p-4">
                <div>
                    <h3 class="section-title">Receta</h3>
                    <p class="text-sm text-muted">Lo que lleva una pieza. Con esto se calcula el costo y la ganancia.</p>
                </div>

                {{-- Lo maneja el navegador: Livewire no debe reemplazarlo al responder. --}}
                <div wire:ignore class="space-y-3">
                <template x-for="(row, index) in $wire.recipe ?? []" :key="row.ingredient_id">
                    <div class="flex items-center gap-2">
                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="ings[row.ingredient_id]?.name ?? 'Insumo inactivo'"></span>
                        <input type="number" step="any" inputmode="decimal" x-model="row.quantity" class="input !min-h-10 w-24 !px-2.5 text-right" :aria-label="'Cantidad de ' + (ings[row.ingredient_id]?.name ?? '')">
                        <span class="w-12 text-sm text-muted" x-text="ings[row.ingredient_id]?.unit"></span>
                        <button type="button" @click="remove(index)" class="btn-quiet size-9 justify-center !px-0" :aria-label="'Quitar ' + (ings[row.ingredient_id]?.name ?? '')"><x-icon name="x" class="size-4" /></button>
                    </div>
                </template>
                <p x-show="! ($wire.recipe ?? []).length" class="text-sm text-muted">Aún no hay insumos en la receta.</p>
                </div>
                @if ($errors->has('recipe.*'))
                    <p class="error">Revisa las cantidades de la receta: todas deben ser mayores a cero.</p>
                @endif

                @if ($this->ingredients->isEmpty())
                    <p class="text-sm">No hay insumos activos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Agrégalos primero</a>.</p>
                @else
                    <div class="border-t border-line pt-3">
                        <div class="flex flex-wrap items-start gap-2">
                            <select x-model="newIng" class="input min-w-40 flex-1" aria-label="Insumo">
                                <option value="">Agregar insumo</option>
                                @foreach ($this->ingredients as $i)
                                    <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                                @endforeach
                            </select>
                            <div class="relative w-32">
                                <input type="number" step="any" inputmode="decimal" x-model="newQty" @keydown.enter.prevent="add()" class="input pr-12" placeholder="Cantidad" aria-label="Cantidad">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted" x-text="ings[newIng]?.unit ?? ''"></span>
                            </div>
                            <button type="button" @click="add()" class="btn h-12">Agregar</button>
                        </div>
                        <p class="error" x-show="addError" x-text="addError" x-cloak></p>
                        <p class="hint" x-show="newIng && ings[newIng]?.unit === 'kg'" x-cloak>En kilos: 0.05 kg son 50 gramos.</p>
                    </div>
                @endif

                <dl wire:ignore class="grid grid-cols-3 gap-2 border-t border-line pt-3 text-center" x-show="($wire.recipe ?? []).length" x-cloak>
                    <div><dt class="text-xs font-semibold text-muted">Costo</dt><dd class="num text-2xl font-bold" x-text="fmt(stats().cost)"></dd></div>
                    <div><dt class="text-xs font-semibold text-muted">Ganas</dt><dd class="num text-2xl font-bold" :class="stats().profit > 0 ? 'text-salsa' : (stats().profit < 0 ? 'text-chile' : '')" x-text="stats().price > 0 ? fmt(stats().profit) : '-'"></dd></div>
                    <div><dt class="text-xs font-semibold text-muted">Margen</dt><dd class="num text-2xl font-bold" x-text="stats().margin === null ? '-' : stats().margin + '%'"></dd></div>
                </dl>
            </section>

            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                <span>
                    <span class="block font-semibold">Mostrar en Vender</span>
                    <span class="block text-sm text-muted">Apágalo para ocultarlo sin borrarlo.</span>
                </span>
                <input type="checkbox" wire:model="active" class="switch">
            </label>
        </form>
        <x-slot:footer>
            <button type="submit" form="product-form" wire:loading.attr="disabled" wire:target="save,edit,duplicate,create" class="btn-primary btn-lg w-full">
                <span wire:loading.remove wire:target="save">Guardar producto</span>
                <span wire:loading wire:target="save">Guardando...</span>
            </button>
        </x-slot:footer>
    </x-sheet>
</div>
