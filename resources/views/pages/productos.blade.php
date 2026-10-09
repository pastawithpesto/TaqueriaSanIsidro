<?php

use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Support\Number;
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

    public ?int $newIngredient = null;

    public ?string $newQuantity = null;

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

    #[Computed]
    public function formCost(): float
    {
        return round(collect($this->recipe)->sum(function ($row) {
            $ingredient = $this->ingredients[$row['ingredient_id']] ?? null;

            return $ingredient ? (float) $row['quantity'] * (float) $ingredient->cost_per_unit : 0;
        }), 2);
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
        $this->reset('showForm', 'editingId', 'name', 'category', 'price', 'sort_order', 'recipe', 'newIngredient', 'newQuantity');
        $this->active = true;
        $this->resetErrorBag();
    }

    public function addIngredient(): void
    {
        $this->validate([
            'newIngredient' => ['required', 'exists:ingredients,id'],
            'newQuantity' => ['required', 'numeric', 'gt:0'],
        ], attributes: ['newIngredient' => 'insumo', 'newQuantity' => 'cantidad']);

        $row = ['ingredient_id' => $this->newIngredient, 'quantity' => $this->newQuantity];
        $index = collect($this->recipe)->search(fn ($r) => $r['ingredient_id'] === $this->newIngredient);

        if ($index === false) {
            $this->recipe[] = $row;
        } else {
            $this->recipe[$index] = $row;
        }

        $this->reset('newIngredient', 'newQuantity');
    }

    public function removeIngredient(int $index): void
    {
        unset($this->recipe[$index]);
        $this->recipe = array_values($this->recipe);
    }

    public function save(): void
    {
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

    public function toggle(int $id): void
    {
        $p = Product::findOrFail($id);
        $p->update(['active' => ! $p->active]);
        unset($this->products);
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-muted">{{ $this->products->where('active', true)->count() }} activos en la pantalla de venta</p>
        <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Nuevo producto</button>
    </div>

    @if ($this->products->isEmpty())
        <x-empty icon="tag" title="Aún no hay productos">
            Agrega lo que vendes con su precio. Si también das de alta tus insumos, puedes armar la receta y ver cuánto ganas por pieza.
            <x-slot:action><button type="button" wire:click="create" class="btn-primary">Agregar el primer producto</button></x-slot:action>
        </x-empty>
    @else
        @if ($this->ingredients->isEmpty())
            <div class="flex items-start gap-3 rounded-2xl bg-limon-soft p-4 text-sm">
                <x-icon name="alert" class="mt-0.5 size-5 shrink-0" />
                <p>Para calcular la ganancia necesitas insumos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold underline">Agrégalos aquí</a> y luego arma la receta de cada producto.</p>
            </div>
        @endif

        @foreach ($this->products->groupBy(fn ($p) => $p->category ?: 'Sin categoría') as $cat => $group)
            <section class="space-y-2" wire:key="cat-{{ $cat }}">
                <h2 class="px-1 font-display text-xl font-bold">{{ $cat }}</h2>
                <ul class="card divide-y divide-line !p-0">
                    @foreach ($group as $p)
                        @php
                            $cost = $p->unitCost();
                            $margin = (float) $p->price > 0 ? ((float) $p->price - $cost) / (float) $p->price * 100 : 0;
                        @endphp
                        <li wire:key="prod-{{ $p->id }}" @class(['flex items-center gap-3 px-4 py-3', 'opacity-55' => ! $p->active])>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">{{ $p->name }} @unless ($p->active) <span class="badge bg-sunken text-muted">Oculto</span> @endunless</p>
                                @if ($p->ingredients->isEmpty())
                                    <p class="text-sm"><span class="badge bg-limon-soft text-ink">Sin receta</span> <span class="text-muted">no se calcula la ganancia</span></p>
                                @else
                                    <p class="text-sm text-muted">Costo {{ Number::currency($cost) }}, ganas <span class="font-semibold text-salsa">{{ Number::currency((float) $p->price - $cost) }}</span> ({{ round($margin) }}%)</p>
                                @endif
                            </div>
                            <span class="num inline-flex items-center rounded-lg bg-limon px-2.5 py-0.5 text-xl font-bold text-on-limon">{{ Number::currency((float) $p->price) }}</span>
                            <div x-data="{ menu: false }" class="relative">
                                <button type="button" @click="menu = !menu" @click.outside="menu = false" class="btn-quiet size-9 justify-center !px-0" aria-label="Opciones de {{ $p->name }}"><x-icon name="more" /></button>
                                <div x-show="menu" x-cloak class="absolute right-0 top-10 z-20 w-48 rounded-xl border border-line bg-surface p-1 shadow-xl" @click="menu = false">
                                    <button type="button" wire:click="edit({{ $p->id }})" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold hover:bg-sunken"><x-icon name="pencil" class="size-4" /> Editar</button>
                                    <button type="button" wire:click="duplicate({{ $p->id }})" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold hover:bg-sunken"><x-icon name="copy" class="size-4" /> Duplicar</button>
                                    <button type="button" wire:click="toggle({{ $p->id }})" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold hover:bg-sunken"><x-icon name="check" class="size-4" /> {{ $p->active ? 'Ocultar en ventas' : 'Mostrar en ventas' }}</button>
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif

    @if ($showForm)
        <x-sheet :title="$editingId ? 'Editar producto' : 'Nuevo producto'">
            <form wire:submit="save" id="product-form" class="space-y-5">
                <div class="grid grid-cols-2 gap-3">
                    <div class="col-span-2">
                        <label class="label" for="name">Nombre</label>
                        <input id="name" type="text" wire:model="name" class="input" placeholder="Taco de pastor">
                        @error('name') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label" for="price">Precio</label>
                        <input id="price" type="number" inputmode="decimal" step="0.01" min="0" wire:model.live.debounce.300ms="price" class="input" placeholder="0.00">
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
                        <h3 class="font-display text-xl font-bold">Receta</h3>
                        <p class="text-sm text-muted">Lo que lleva una pieza. Sirve para calcular el costo y la ganancia.</p>
                    </div>

                    @forelse ($recipe as $index => $row)
                        @php $ing = $this->ingredients[$row['ingredient_id']] ?? null; @endphp
                        <div wire:key="r-{{ $row['ingredient_id'] }}">
                            <div class="flex items-center gap-2">
                                <span class="min-w-0 flex-1 truncate text-[15px] font-medium">{{ $ing?->name ?? 'Insumo inactivo' }}</span>
                                <input type="number" step="any" inputmode="decimal" wire:model.live.debounce.300ms="recipe.{{ $index }}.quantity" class="input !min-h-10 w-24 !px-2.5 text-right" aria-label="Cantidad de {{ $ing?->name }}">
                                <span class="w-10 text-sm text-muted">{{ $ing?->unit }}</span>
                                <button type="button" wire:click="removeIngredient({{ $index }})" class="btn-quiet size-9 justify-center !px-0" aria-label="Quitar {{ $ing?->name }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                            @error("recipe.$index.quantity") <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @empty
                        <p class="text-sm text-muted">Aún no hay insumos en la receta.</p>
                    @endforelse

                    @if ($this->ingredients->isEmpty())
                        <p class="text-sm">No hay insumos activos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Agrégalos primero</a>.</p>
                    @else
                        <div class="flex flex-wrap items-start gap-2 border-t border-line pt-3">
                            <div class="min-w-40 flex-1">
                                <select wire:model.live="newIngredient" class="input" aria-label="Insumo">
                                    <option value="">Agregar insumo</option>
                                    @foreach ($this->ingredients as $i)
                                        <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                                    @endforeach
                                </select>
                                @error('newIngredient') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <div class="w-32">
                                <input type="number" step="any" inputmode="decimal" wire:model="newQuantity" class="input" placeholder="Cantidad" aria-label="Cantidad">
                                @error('newQuantity') <p class="error">{{ $message }}</p> @enderror
                            </div>
                            <button type="button" wire:click="addIngredient" class="btn h-12">Agregar</button>
                        </div>
                        @if ($newIngredient && isset($this->ingredients[$newIngredient]))
                            <p class="hint !mt-0">La cantidad va en {{ $this->ingredients[$newIngredient]->unit }}. Ejemplo: 0.05 kg son 50 gramos.</p>
                        @endif
                    @endif

                    @if ($recipe)
                        @php
                            $priceNum = is_numeric($price) ? (float) $price : 0;
                            $profit = $priceNum - $this->formCost;
                        @endphp
                        <dl class="grid grid-cols-3 gap-2 border-t border-line pt-3 text-center">
                            <div><dt class="text-xs text-muted">Costo</dt><dd class="num text-2xl font-bold">{{ Number::currency($this->formCost) }}</dd></div>
                            <div><dt class="text-xs text-muted">Ganas</dt><dd @class(['num text-2xl font-bold', 'text-salsa' => $profit > 0, 'text-chile' => $profit < 0])>{{ $priceNum > 0 ? Number::currency($profit) : '-' }}</dd></div>
                            <div><dt class="text-xs text-muted">Margen</dt><dd class="num text-2xl font-bold">{{ $priceNum > 0 ? round($profit / $priceNum * 100).'%' : '-' }}</dd></div>
                        </dl>
                    @endif
                </section>

                <label class="flex items-center justify-between gap-4 rounded-xl bg-sunken px-4 py-3">
                    <span>
                        <span class="block text-sm font-semibold">Mostrar en la pantalla de venta</span>
                        <span class="block text-sm text-muted">Apágalo para ocultarlo sin borrarlo.</span>
                    </span>
                    <input type="checkbox" wire:model="active" class="switch">
                </label>
            </form>
            <x-slot:footer>
                <button type="submit" form="product-form" wire:loading.attr="disabled" class="btn-primary btn-lg w-full">Guardar producto</button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
