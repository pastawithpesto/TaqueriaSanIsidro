<?php

use App\Models\Ingredient;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Insumos')] class extends Component
{
    public const UNITS = ['kg', 'g', 'lt', 'ml', 'pza', 'paquete', 'caja'];

    public ?int $editingId = null;

    public string $name = '';

    public string $unit = 'kg';

    public ?string $cost_per_unit = '0';

    #[Computed]
    public function ingredients()
    {
        return Ingredient::withCount('products')->orderByDesc('active')->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $this->editingId = $i->id;
        $this->name = $i->name;
        $this->unit = $i->unit;
        $this->cost_per_unit = rtrim(rtrim((string) $i->cost_per_unit, '0'), '.') ?: '0';
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'name', 'cost_per_unit');
        $this->unit = 'kg';
        $this->cost_per_unit = '0';
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'in:'.implode(',', self::UNITS)],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
        ]);

        Ingredient::updateOrCreate(['id' => $this->editingId], $data);

        $this->cancelEdit();
        unset($this->ingredients);
    }

    public function toggle(int $id): void
    {
        $i = Ingredient::findOrFail($id);
        $i->update(['active' => ! $i->active]);
        unset($this->ingredients);
    }

    public function delete(int $id): void
    {
        $i = Ingredient::withCount('products')->findOrFail($id);

        if ($i->products_count === 0) {
            $i->delete();
        }

        unset($this->ingredients);
    }
};
?>

<div class="space-y-5">
    <form wire:submit="save" class="card grid gap-3 md:grid-cols-5">
        <div class="md:col-span-2">
            <label class="label" for="name">Nombre</label>
            <input id="name" type="text" wire:model="name" class="input" placeholder="Bistec, tortilla, cilantro...">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="unit">Unidad</label>
            <select id="unit" wire:model="unit" class="input">
                @foreach ($this::UNITS as $u)
                    <option value="{{ $u }}">{{ $u }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label" for="cost">Costo por unidad</label>
            <input id="cost" type="number" inputmode="decimal" step="any" wire:model="cost_per_unit" class="input">
            @error('cost_per_unit') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="flex items-end gap-2">
            <button class="btn-primary flex-1">{{ $editingId ? 'Actualizar' : 'Agregar' }}</button>
            @if ($editingId)
                <button type="button" wire:click="cancelEdit" class="btn">Cancelar</button>
            @endif
        </div>
    </form>

    <p class="text-sm text-stone-500">El costo se actualiza solo cuando registras una compra del insumo en Gastos.</p>

    <div class="space-y-2">
        @forelse ($this->ingredients as $i)
            <div wire:key="i-{{ $i->id }}" @class(['card flex items-center justify-between gap-3 text-sm', 'opacity-60' => ! $i->active])>
                <div>
                    <p class="font-medium">{{ $i->name }}</p>
                    <p class="text-stone-500">
                        {{ Number::currency((float) $i->cost_per_unit) }} por {{ $i->unit }}
                        · en {{ $i->products_count }} {{ $i->products_count === 1 ? 'receta' : 'recetas' }}
                    </p>
                </div>
                <div class="flex gap-3 text-xs">
                    <button type="button" wire:click="edit({{ $i->id }})" class="text-stone-700 hover:underline">Editar</button>
                    <button type="button" wire:click="toggle({{ $i->id }})" class="text-stone-700 hover:underline">{{ $i->active ? 'Desactivar' : 'Activar' }}</button>
                    @if ($i->products_count === 0)
                        <button type="button" wire:click="delete({{ $i->id }})" wire:confirm="¿Borrar este insumo?" class="text-red-600 hover:underline">Borrar</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-500">Aún no hay insumos.</p>
        @endforelse
    </div>
</div>
