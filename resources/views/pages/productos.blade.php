<?php

use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
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

    /** @var array<int, array{ingredient_id: int, quantity: string, removable: bool}> */
    public array $recipe = [];

    /** @var array<int, array{id: ?int, name: string, price: string, ingredient_id: ?int, quantity: ?string}> */
    public array $extras = [];

    /** Opciones a escoger (ej. Refresco: Coca, Sprite o Fanta): @var array<int, array{id: ?int, name: string, required: bool, choices: array}> */
    public array $options = [];

    /** En el mismo orden que Vender, para poder acomodarlos arrastrando. */
    #[Computed]
    public function products()
    {
        return Product::with('ingredients')->withExists('orderItems')->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::where('active', true)->orderBy('name')->get()->keyBy('id');
    }

    /** Todos, para calcular el costo de recetas que aún usan un insumo inactivo. */
    #[Computed]
    public function allIngredients()
    {
        return Ingredient::orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function categories()
    {
        return Product::categories();
    }

    public function create(): void
    {
        $this->resetForm();
        $this->sort_order = (int) Product::max('sort_order') + 1;
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Product::with(['ingredients', 'extras', 'optionGroups.choices'])->findOrFail($id);

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
            'removable' => (bool) $i->pivot->removable,
        ])->values()->all();
        $this->extras = $p->extras->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'price' => rtrim(rtrim((string) $e->price, '0'), '.'),
            'ingredient_id' => $e->ingredient_id,
            'quantity' => $e->quantity !== null ? rtrim(rtrim((string) $e->quantity, '0'), '.') : null,
        ])->values()->all();
        $this->options = $p->optionGroups->map(fn ($g) => [
            'id' => $g->id,
            'name' => $g->name,
            'required' => $g->required,
            'choices' => $g->choices->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'price' => rtrim(rtrim((string) $c->price, '0'), '.') ?: '0',
            ])->values()->all(),
        ])->values()->all();
        $this->showForm = true;
    }

    /** Copia el producto y su receta: útil para variedades (pastor, tripa). */
    public function duplicate(int $id): void
    {
        $source = Product::with(['ingredients', 'extras', 'optionGroups.choices'])->findOrFail($id);

        $copy = $source->replicate();
        $copy->name = $source->name.' (copia)';
        $copy->sort_order = (int) Product::max('sort_order') + 1;
        $copy->save();
        $copy->ingredients()->sync($source->ingredients->mapWithKeys(
            fn ($i) => [$i->id => ['quantity' => $i->pivot->quantity, 'removable' => $i->pivot->removable]]
        )->all());
        $copy->extras()->createMany($source->extras->map(fn ($e) => $e->only(['name', 'price', 'ingredient_id', 'quantity', 'sort_order']))->all());
        foreach ($source->optionGroups as $group) {
            $copy->optionGroups()->create($group->only(['name', 'required', 'sort_order']))
                ->choices()->createMany($group->choices->map(fn ($c) => $c->only(['name', 'price', 'sort_order']))->all());
        }

        unset($this->products);
        $this->edit($copy->id);
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name', 'category', 'price', 'sort_order', 'recipe', 'extras', 'options');
        $this->active = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->recipe = collect($this->recipe)
            ->filter(fn ($r) => ! empty($r['ingredient_id']))
            ->map(fn ($r) => ['ingredient_id' => (int) $r['ingredient_id'], 'quantity' => (string) $r['quantity'], 'removable' => (bool) ($r['removable'] ?? false)])
            ->values()->all();

        // Extras: se ignoran los renglones sin nombre.
        $this->extras = collect($this->extras)
            ->filter(fn ($e) => trim((string) ($e['name'] ?? '')) !== '')
            ->map(fn ($e) => [
                'id' => $e['id'] ?? null,
                'name' => trim($e['name']),
                'price' => (string) ($e['price'] ?? '0'),
                'ingredient_id' => ($e['ingredient_id'] ?? null) ?: null,
                'quantity' => ($e['ingredient_id'] ?? null) ? (string) ($e['quantity'] ?? '') : null,
            ])
            ->values()->all();

        // Grupos de opciones: sin nombre o sin opciones se descartan.
        $this->options = collect($this->options)
            ->map(fn ($g) => [
                'id' => $g['id'] ?? null,
                'name' => trim((string) ($g['name'] ?? '')),
                'required' => (bool) ($g['required'] ?? true),
                'choices' => collect($g['choices'] ?? [])
                    ->filter(fn ($c) => trim((string) ($c['name'] ?? '')) !== '')
                    ->map(fn ($c) => ['id' => $c['id'] ?? null, 'name' => trim($c['name']), 'price' => (string) (($c['price'] ?? '') === '' ? '0' : $c['price'])])
                    ->values()->all(),
            ])
            ->filter(fn ($g) => $g['name'] !== '' && $g['choices'])
            ->values()->all();

        $this->category = Product::normalizeCategory($this->category, $this->editingId);

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:40'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'active' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
            'recipe.*.ingredient_id' => ['required', 'exists:ingredients,id'],
            'recipe.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999'],
            'extras.*.name' => ['required', 'string', 'max:60'],
            'extras.*.price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'extras.*.ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'extras.*.quantity' => ['nullable', 'required_with:extras.*.ingredient_id', 'numeric', 'gt:0', 'max:99999'],
            'options.*.name' => ['required', 'string', 'max:40'],
            'options.*.choices.*.name' => ['required', 'string', 'max:40'],
            'options.*.choices.*.price' => ['required', 'numeric', 'min:0', 'max:99999'],
        ]);

        $product = DB::transaction(function () use ($data) {
            $product = Product::updateOrCreate(['id' => $this->editingId], collect($data)->except(['recipe', 'extras', 'options'])->all());

            $product->ingredients()->sync(
                collect($this->recipe)->mapWithKeys(fn ($r) => [$r['ingredient_id'] => ['quantity' => $r['quantity'], 'removable' => $r['removable']]])->all()
            );

            // Se conservan los ids de los extras existentes: los pedidos guardados los referencian.
            $keep = [];
            foreach ($this->extras as $i => $e) {
                $extra = $product->extras()->updateOrCreate(
                    ['id' => $e['id']],
                    ['name' => $e['name'], 'price' => $e['price'], 'ingredient_id' => $e['ingredient_id'], 'quantity' => $e['quantity'], 'sort_order' => $i],
                );
                $keep[] = $extra->id;
            }
            $product->extras()->whereNotIn('id', $keep)->delete();

            $keepGroups = [];
            foreach ($this->options as $gi => $g) {
                $group = $product->optionGroups()->updateOrCreate(['id' => $g['id']], ['name' => $g['name'], 'required' => $g['required'], 'sort_order' => $gi]);
                $keepChoices = [];
                foreach ($g['choices'] as $ci => $c) {
                    $keepChoices[] = $group->choices()->updateOrCreate(['id' => $c['id']], ['name' => $c['name'], 'price' => $c['price'], 'sort_order' => $ci])->id;
                }
                $group->choices()->whereNotIn('id', $keepChoices)->delete();
                $keepGroups[] = $group->id;
            }
            $product->optionGroups()->whereNotIn('id', $keepGroups)->delete();

            return $product;
        });

        $this->resetForm();
        unset($this->products, $this->categories);
        $this->dispatch('toast', message: 'Producto guardado');
    }

    /**
     * Borra un producto que nunca se vendió. Si tiene ventas solo se oculta: la lista de compras
     * y los pedidos guardados lo siguen necesitando.
     */
    public function delete(int $id): void
    {
        $product = Product::withExists('orderItems')->findOrFail($id);

        if ($product->order_items_exists) {
            $product->update(['active' => false]);
            $message = 'Tiene ventas, así que se ocultó en lugar de eliminarse';
        } else {
            $product->delete();
            $message = 'Producto eliminado';
        }

        $this->resetForm();
        unset($this->products, $this->categories);
        $this->dispatch('toast', message: $message);
    }

    /** Mueve un producto a otra posición dentro de su categoría. Vender sigue este orden. */
    public function sortProduct(int $id, int $position): void
    {
        $product = Product::findOrFail($id);
        $all = Product::orderBy('sort_order')->orderBy('name')->get();
        $sameCategory = fn (Product $p) => ($p->category ?: null) === ($product->category ?: null);

        $group = $all->filter($sameCategory)->reject(fn (Product $p) => $p->is($product))->values();
        $group->splice(max(0, min($position, $group->count())), 0, [$product]);

        // Los de la categoría se reacomodan en los mismos lugares que ocupaban en la lista completa.
        $all->map(fn (Product $p) => $sameCategory($p) ? $group->shift() : $p)
            ->each(function (Product $p, int $i) {
                if ($p->sort_order !== $i) {
                    $p->update(['sort_order' => $i]);
                }
            });

        unset($this->products);
    }

    /** Subir (-1) o bajar (1) un lugar, para quien no puede arrastrar. */
    public function move(int $id, int $direction): void
    {
        $product = Product::findOrFail($id);
        $position = Product::orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Product $p) => ($p->category ?: null) === ($product->category ?: null))
            ->values()
            ->search(fn (Product $p) => $p->is($product));

        $this->sortProduct($id, $position + ($direction < 0 ? -1 : 1));
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
        ings: @js($this->allIngredients->map(fn ($i) => ['name' => $i->name, 'unit' => $i->unit, 'cost' => (float) $i->cost_per_unit, 'active' => $i->active])),
        newIng: '',
        newQty: '',
        addError: '',
        init() { this.$watch('sheet', () => { this.newIng = ''; this.newQty = ''; this.addError = '' }) },
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
            const row = { ingredient_id: Number(this.newIng), quantity: String(q), removable: false };
            i === -1 ? rows.push(row) : rows.splice(i, 1, row);
            $wire.recipe = rows; this.newIng = ''; this.newQty = ''; this.addError = '';
        },
        // Lo que quedó en Agregar insumo sin darle Agregar también entra a la receta.
        submit() {
            if (this.newIng || this.newQty) {
                this.add();
                if (this.addError) return this.$nextTick(() => this.$refs.addError?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
            }
            $wire.save();
        },
        remove(i) { const rows = [...$wire.recipe]; rows.splice(i, 1); $wire.recipe = rows },
        toggleRemovable(i) { const rows = $wire.recipe.map(r => ({ ...r })); rows[i].removable = ! rows[i].removable; $wire.recipe = rows },
        addExtra() { $wire.extras = [...($wire.extras ?? []), { id: null, name: '', price: '', ingredient_id: null, quantity: null }] },
        removeExtra(i) { const rows = [...$wire.extras]; rows.splice(i, 1); $wire.extras = rows },
        addGroup() { $wire.options = [...($wire.options ?? []), { id: null, name: '', required: true, choices: [{ id: null, name: '', price: '' }, { id: null, name: '', price: '' }] }] },
        removeGroup(gi) { const g = [...$wire.options]; g.splice(gi, 1); $wire.options = g },
        addChoice(gi) { const g = $wire.options.map(x => ({ ...x, choices: [...x.choices] })); g[gi].choices.push({ id: null, name: '', price: '' }); $wire.options = g },
        removeChoice(gi, ci) { const g = $wire.options.map(x => ({ ...x, choices: [...x.choices] })); g[gi].choices.splice(ci, 1); $wire.options = g },
        // Un solo cálculo que lee receta y precio directamente, para que siempre se actualice.
        stats() {
            const cost = ($wire.recipe ?? []).reduce((t, r) => t + (Number(r.quantity) || 0) * (this.ings[r.ingredient_id]?.cost ?? 0), 0);
            const price = Number($wire.price) || 0;
            const profit = price - cost;
            return { cost, price, profit, margin: price > 0 ? Math.round(profit / price * 100) : null };
        },
     }">
    <x-page-header title="Productos" tour="productos">
        <x-slot:actions>
            <button type="button" @click="sheet = true; $wire.create()" class="btn-primary" data-tour="nuevo-producto"><x-icon name="plus" class="size-4" /> Producto</button>
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
                <ul class="list" wire:sort="sortProduct">
                    @foreach ($group as $p)
                        @php
                            $cost = $p->unitCost();
                            $price = (float) $p->price;
                            $margin = $price > 0 ? ($price - $cost) / $price * 100 : 0;
                            $hasRecipe = $p->ingredients->isNotEmpty();
                        @endphp
                        <li wire:key="prod-{{ $p->id }}" wire:sort:item="{{ $p->id }}" class="flex items-center gap-2 py-3 pr-2 pl-2" @if ($loop->first) data-tour="producto" @endif>
                            {{-- La columna del asa se reserva siempre para que los nombres queden alineados entre categorías. --}}
                            @if ($group->count() > 1)
                                <span wire:sort:handle data-tour="producto-orden" class="grid h-10 w-6 shrink-0 cursor-grab touch-none place-items-center text-muted active:cursor-grabbing" title="Arrastra para acomodar"><x-icon name="grip" class="size-4" /></span>
                            @else
                                <span class="w-6 shrink-0"></span>
                            @endif
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
                                <button type="button" x-ref="trigger" @click="menu = !menu" @click.outside="menu = false" @keydown.escape.window="menu = false" class="btn-quiet size-10 justify-center !px-0" aria-label="Opciones de {{ $p->name }}" @if ($loop->first) data-tour="producto-menu" @endif><x-icon name="more" /></button>
                                <div x-show="menu" x-cloak x-anchor.bottom-end.offset.6.fixed="$refs.trigger" x-transition.origin.top.right class="menu" @click="menu = false">
                                    <button type="button" @click="sheet = true; $wire.edit({{ $p->id }})"><x-icon name="pencil" class="size-4 text-muted" /> Editar</button>
                                    <button type="button" @click="sheet = true; $wire.duplicate({{ $p->id }})"><x-icon name="copy" class="size-4 text-muted" /> Duplicar</button>
                                    @unless ($loop->first)
                                        <button type="button" wire:click="move({{ $p->id }}, -1)"><x-icon name="chevron-up" class="size-4 text-muted" /> Subir</button>
                                    @endunless
                                    @unless ($loop->last)
                                        <button type="button" wire:click="move({{ $p->id }}, 1)"><x-icon name="down" class="size-4 text-muted" /> Bajar</button>
                                    @endunless
                                    <button type="button" wire:click="toggle({{ $p->id }})">
                                        <x-icon :name="$p->active ? 'eye-off' : 'eye'" class="size-4 text-muted" /> {{ $p->active ? 'Ocultar en Vender' : 'Mostrar en Vender' }}
                                    </button>
                                    @unless ($p->order_items_exists)
                                        <button type="button" class="text-chile"
                                                @click="if (await $store.confirm.ask({ title: @js('¿Eliminar '.$p->name.'?'), message: 'Nunca se ha vendido. Si solo quieres dejar de venderlo, mejor ocúltalo.', action: 'Eliminar' })) $wire.delete({{ $p->id }})">
                                            <x-icon name="trash" class="size-4" /> Eliminar
                                        </button>
                                    @endunless
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif
    </div>

    @php $ingredientOptions = $this->ingredients->map(fn ($i) => ['value' => $i->id, 'label' => $i->name, 'hint' => $i->unit])->values()->all(); @endphp
    <x-sheet :title="$editingId ? 'Editar producto' : 'Nuevo producto'" open="sheet" dismiss="sheet = false; $wire.cancel()">
        <div wire:loading.block wire:target="edit,duplicate,create" class="space-y-4" aria-hidden="true">
            <div class="h-12 animate-pulse rounded-xl bg-sunken"></div>
            <div class="grid grid-cols-2 gap-3"><div class="h-12 animate-pulse rounded-xl bg-sunken"></div><div class="h-12 animate-pulse rounded-xl bg-sunken"></div></div>
            <div class="h-48 animate-pulse rounded-2xl bg-sunken"></div>
        </div>

        <form @submit.prevent="submit()" id="product-form" class="space-y-5" wire:loading.remove wire:target="edit,duplicate,create">
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
                    <x-combobox id="category" bind="$wire.category" :options="$this->categories->all()" placeholder="Escoge una" create="Crear categoría" clear="Quitar categoría" maxlength="40" />
                    @error('category') <p class="error">{{ $message }}</p> @enderror
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
                        <button type="button" @click="toggleRemovable(index)" :aria-pressed="!! row.removable"
                                class="grid size-9 shrink-0 place-items-center rounded-lg border motion-safe:transition"
                                :class="row.removable ? 'border-chile bg-chile text-white' : 'border-line bg-surface text-muted'"
                                :title="row.removable ? 'Se puede pedir sin este ingrediente' : 'Siempre lo lleva'"
                                :aria-label="'Se puede pedir sin ' + (ings[row.ingredient_id]?.name ?? '')">
                            <x-icon name="x" class="size-4" />
                        </button>
                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="(ings[row.ingredient_id]?.name ?? 'Insumo borrado') + (ings[row.ingredient_id]?.active === false ? ' (inactivo)' : '')"></span>
                        <input type="number" step="any" inputmode="decimal" x-model="row.quantity" class="input !min-h-10 w-24 !px-2.5 text-right" :aria-label="'Cantidad de ' + (ings[row.ingredient_id]?.name ?? '')">
                        <span class="w-12 text-sm text-muted" x-text="ings[row.ingredient_id]?.unit"></span>
                        <button type="button" @click="remove(index)" class="btn-quiet size-9 justify-center !px-0" :aria-label="'Quitar ' + (ings[row.ingredient_id]?.name ?? '')"><x-icon name="x" class="size-4" /></button>
                    </div>
                </template>
                <p x-show="! ($wire.recipe ?? []).length" class="text-sm text-muted">Aún no hay insumos en la receta.</p>
                <p x-show="($wire.recipe ?? []).length" class="flex items-center gap-2 text-sm text-muted">
                    <span class="grid size-5 shrink-0 place-items-center rounded bg-chile text-white"><x-icon name="x" class="size-3" /></span>
                    Marca lo que el cliente puede pedir sin, como cebolla o cilantro. Al vender aparecerá "Sin cebolla".
                </p>
                </div>
                @if ($errors->has('recipe.*'))
                    <p class="error">Revisa las cantidades de la receta: todas deben ser mayores a cero.</p>
                @endif

                @if ($this->ingredients->isEmpty())
                    <p class="text-sm">No hay insumos activos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Agrégalos primero</a>.</p>
                @else
                    <div class="border-t border-line pt-3">
                        <div class="flex flex-wrap items-start gap-2">
                            {{-- Al escoger el insumo, el cursor pasa a la cantidad. --}}
                            <div class="min-w-40 flex-1" @pick="$nextTick(() => $refs.newQty?.focus())">
                                <x-combobox bind="newIng" :options="$ingredientOptions" placeholder="Buscar insumo" aria-label="Insumo" />
                            </div>
                            <div class="relative w-32">
                                <input x-ref="newQty" type="number" step="any" inputmode="decimal" x-model="newQty" @keydown.enter.prevent="add()" class="input pr-12" placeholder="Cantidad" aria-label="Cantidad">
                                <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm text-muted" x-text="ings[newIng]?.unit ?? ''"></span>
                            </div>
                            <button type="button" @click="add()" class="btn h-12">Agregar</button>
                        </div>
                        <p class="error" x-ref="addError" x-show="addError" x-text="addError" x-cloak></p>
                        <p class="hint" x-show="newIng && ings[newIng]?.unit === 'kg'" x-cloak>En kilos: 0.05 kg son 50 gramos.</p>
                    </div>
                @endif

                <dl wire:ignore class="grid grid-cols-3 gap-2 border-t border-line pt-3 text-center" x-show="($wire.recipe ?? []).length" x-cloak>
                    <div><dt class="text-xs font-semibold text-muted">Costo</dt><dd class="num text-2xl font-bold" x-text="fmt(stats().cost)"></dd></div>
                    <div><dt class="text-xs font-semibold text-muted">Ganas</dt><dd class="num text-2xl font-bold" :class="stats().profit > 0 ? 'text-salsa' : (stats().profit < 0 ? 'text-chile' : '')" x-text="stats().price > 0 ? fmt(stats().profit) : '-'"></dd></div>
                    <div><dt class="text-xs font-semibold text-muted">Margen</dt><dd class="num text-2xl font-bold" x-text="stats().margin === null ? '-' : stats().margin + '%'"></dd></div>
                </dl>
            </section>

            <section class="space-y-3 rounded-2xl bg-sunken/60 p-4">
                <div>
                    <h3 class="section-title">Opciones a escoger</h3>
                    <p class="text-sm text-muted">Solo si el cliente escoge entre varias, como Coca, Sprite o Fanta en el combo; al venderlo, la app le pregunta. Lo que siempre lleva va en la receta.</p>
                </div>
                <div wire:ignore class="space-y-3">
                    <template x-for="(group, gi) in $wire.options ?? []" :key="gi">
                        <div class="space-y-2 rounded-xl bg-surface p-3">
                            <div class="flex items-center gap-2">
                                <input type="text" x-model="group.name" maxlength="40" class="input !min-h-11 flex-1 font-semibold" placeholder="Ej. Refresco" aria-label="Nombre del grupo">
                                <button type="button" @click="group.required = ! group.required" class="chip" :aria-pressed="!! group.required" x-text="group.required ? 'Obligatorio' : 'Opcional'"></button>
                                <button type="button" @click="removeGroup(gi)" class="btn-quiet size-11 justify-center !px-0" aria-label="Quitar grupo"><x-icon name="trash" class="size-4" /></button>
                            </div>
                            <template x-for="(choice, ci) in group.choices" :key="ci">
                                <div class="flex items-center gap-2 pl-3">
                                    <input type="text" x-model="choice.name" maxlength="40" class="input !min-h-10 flex-1" placeholder="Ej. Coca-Cola" aria-label="Opción">
                                    <div class="relative w-24">
                                        <span class="num pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-sm font-semibold text-muted">+$</span>
                                        <input type="number" step="0.01" min="0" inputmode="decimal" x-model="choice.price" class="input num !min-h-10 !pl-8" placeholder="0" aria-label="Precio extra de la opción">
                                    </div>
                                    <button type="button" @click="removeChoice(gi, ci)" class="btn-quiet size-10 justify-center !px-0" aria-label="Quitar opción"><x-icon name="x" class="size-4" /></button>
                                </div>
                            </template>
                            <button type="button" @click="addChoice(gi)" class="btn-quiet ml-1"><x-icon name="plus" class="size-4" /> Opción</button>
                        </div>
                    </template>
                </div>
                @if ($errors->has('options.*'))
                    <p class="error">Revisa las opciones: cada grupo y cada opción necesitan nombre.</p>
                @endif
                <button type="button" @click="addGroup()" class="btn w-full"><x-icon name="plus" class="size-4" /> Agregar opciones a escoger</button>
            </section>

            <section class="space-y-3 rounded-2xl bg-sunken/60 p-4">
                <div>
                    <h3 class="section-title">Extras</h3>
                    <p class="text-sm text-muted">Lo que se cobra aparte, como extra queso o aguacate.</p>
                </div>
                <div wire:ignore class="space-y-3">
                    <template x-for="(extra, i) in $wire.extras ?? []" :key="i">
                        <div class="space-y-2 rounded-xl bg-surface p-3">
                            <div class="flex gap-2">
                                <input type="text" x-model="extra.name" maxlength="60" class="input !min-h-11 flex-1" placeholder="Ej. Extra queso" aria-label="Nombre del extra">
                                <div class="relative w-28">
                                    <span class="num pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 font-semibold text-muted">$</span>
                                    <input type="number" step="0.01" min="0" inputmode="decimal" x-model="extra.price" class="input num !min-h-11 !pl-7 text-lg font-semibold" placeholder="0" aria-label="Precio del extra">
                                </div>
                                <button type="button" @click="removeExtra(i)" class="btn-quiet size-11 justify-center !px-0" aria-label="Quitar extra"><x-icon name="x" class="size-4" /></button>
                            </div>
                            <div class="flex gap-2">
                                <div class="min-w-0 flex-1">
                                    <x-combobox bind="extra.ingredient_id" :options="$ingredientOptions" none="No usa insumo" class="!min-h-10" aria-label="Insumo que usa" />
                                </div>
                                <div class="relative w-28" x-show="extra.ingredient_id">
                                    <input type="number" step="any" min="0" inputmode="decimal" x-model="extra.quantity" class="input !min-h-10 pr-11" placeholder="Cant." aria-label="Cantidad de insumo">
                                    <span class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-muted" x-text="ings[extra.ingredient_id]?.unit ?? ''"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                @if ($errors->has('extras.*'))
                    <p class="error">Revisa los extras: cada uno necesita nombre y precio, y cantidad si usa un insumo.</p>
                @endif
                <button type="button" @click="addExtra()" class="btn w-full"><x-icon name="plus" class="size-4" /> Agregar extra</button>
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
