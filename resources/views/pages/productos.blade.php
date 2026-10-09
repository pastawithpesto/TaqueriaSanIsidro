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
        $this->showForm = true;
    }

    public function edit(int $id): void
    {
        $p = Product::with('ingredients')->findOrFail($id);

        $this->resetForm();
        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->category = $p->category;
        $this->price = (string) $p->price;
        $this->active = $p->active;
        $this->sort_order = $p->sort_order;
        $this->recipe = $p->ingredients->map(fn ($i) => [
            'ingredient_id' => $i->id,
            'quantity' => rtrim(rtrim((string) $i->pivot->quantity, '0'), '.'),
        ])->values()->all();
        $this->showForm = true;
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
        unset($this->products);
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
    @if (! $showForm)
        <button type="button" wire:click="create" class="btn-primary">Nuevo producto</button>
    @else
        <form wire:submit="save" class="card space-y-4">
            <div class="grid gap-3 md:grid-cols-4">
                <div class="md:col-span-2">
                    <label class="label" for="name">Nombre</label>
                    <input id="name" type="text" wire:model="name" class="input">
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="category">Categoría</label>
                    <input id="category" type="text" wire:model="category" class="input" placeholder="Tacos">
                </div>
                <div>
                    <label class="label" for="price">Precio</label>
                    <input id="price" type="number" inputmode="decimal" step="0.01" wire:model.live.debounce.300ms="price" class="input">
                    @error('price') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label" for="sort_order">Orden</label>
                    <input id="sort_order" type="number" min="0" wire:model="sort_order" class="input">
                </div>
                <label class="flex items-center gap-2 text-sm text-stone-700 md:self-end md:pb-2">
                    <input type="checkbox" wire:model="active" class="rounded border-stone-300"> Activo
                </label>
            </div>

            <div class="space-y-2">
                <p class="label">Receta (por pieza vendida)</p>

                @foreach ($recipe as $index => $row)
                    @php $ing = $this->ingredients[$row['ingredient_id']] ?? null; @endphp
                    <div wire:key="r-{{ $row['ingredient_id'] }}" class="flex items-center gap-3 text-sm">
                        <span class="flex-1">{{ $ing?->name ?? 'Insumo inactivo' }}</span>
                        <input type="number" step="any" wire:model.live.debounce.300ms="recipe.{{ $index }}.quantity" class="input max-w-28">
                        <span class="w-14 text-stone-500">{{ $ing?->unit }}</span>
                        <button type="button" wire:click="removeIngredient({{ $index }})" class="text-xs text-red-600 hover:underline">Quitar</button>
                    </div>
                    @error("recipe.$index.quantity") <p class="error">{{ $message }}</p> @enderror
                @endforeach

                <div class="flex flex-wrap items-start gap-2">
                    <div>
                        <select wire:model="newIngredient" class="input">
                            <option value="">Insumo...</option>
                            @foreach ($this->ingredients as $i)
                                <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                            @endforeach
                        </select>
                        @error('newIngredient') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <input type="number" step="any" wire:model="newQuantity" class="input max-w-32" placeholder="Cantidad">
                        @error('newQuantity') <p class="error">{{ $message }}</p> @enderror
                    </div>
                    <button type="button" wire:click="addIngredient" class="btn">Agregar</button>
                </div>

                @if ($recipe)
                    <p class="text-sm text-stone-600">
                        Costo {{ Number::currency($this->formCost) }}
                        @if (is_numeric($price) && (float) $price > 0)
                            · margen {{ Number::currency((float) $price - $this->formCost) }}
                            ({{ round(((float) $price - $this->formCost) / (float) $price * 100) }}%)
                        @endif
                    </p>
                @endif
            </div>

            <div class="flex gap-2">
                <button class="btn-primary">Guardar</button>
                <button type="button" wire:click="cancel" class="btn">Cancelar</button>
            </div>
        </form>
    @endif

    <div class="space-y-2">
        @forelse ($this->products as $p)
            @php
                $cost = $p->unitCost();
                $margin = (float) $p->price > 0 ? ((float) $p->price - $cost) / (float) $p->price * 100 : 0;
            @endphp
            <div wire:key="prod-{{ $p->id }}" @class(['card flex items-center justify-between gap-3 text-sm', 'opacity-60' => ! $p->active])>
                <div>
                    <p class="font-medium">{{ $p->name }} <span class="font-normal text-stone-500">{{ $p->category }}</span></p>
                    <p class="text-stone-500">
                        @if ($p->ingredients->isEmpty())
                            Sin receta
                        @else
                            Costo {{ Number::currency($cost) }} · margen {{ round($margin) }}%
                        @endif
                    </p>
                </div>
                <div class="text-right">
                    <p class="font-semibold tabular-nums">{{ Number::currency((float) $p->price) }}</p>
                    <div class="flex gap-3 text-xs">
                        <button type="button" wire:click="edit({{ $p->id }})" class="text-stone-700 hover:underline">Editar</button>
                        <button type="button" wire:click="toggle({{ $p->id }})" class="text-stone-700 hover:underline">{{ $p->active ? 'Desactivar' : 'Activar' }}</button>
                    </div>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-500">Aún no hay productos.</p>
        @endforelse
    </div>
</div>
