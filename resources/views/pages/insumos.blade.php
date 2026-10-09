<?php

use App\Models\Ingredient;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Insumos')] class extends Component
{
    public const UNITS = ['kg', 'g', 'lt', 'ml', 'pza', 'paquete', 'caja'];

    public string $search = '';

    public bool $showForm = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $unit = 'kg';

    public ?string $cost_per_unit = '0';

    #[Computed]
    public function ingredients()
    {
        return Ingredient::withCount('products')
            ->when($this->search !== '', fn ($q) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($this->search).'%']))
            ->orderByDesc('active')->orderBy('name')
            ->get();
    }

    #[Computed]
    public function hasAny(): bool
    {
        return Ingredient::exists();
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
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'editingId', 'name');
        $this->unit = 'kg';
        $this->cost_per_unit = '0';
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'unit' => ['required', 'in:'.implode(',', self::UNITS)],
            'cost_per_unit' => ['required', 'numeric', 'min:0'],
        ]);

        Ingredient::updateOrCreate(['id' => $this->editingId], $data);

        $this->resetForm();
        unset($this->ingredients, $this->hasAny);
        $this->dispatch('toast', message: 'Insumo guardado');
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
            $this->dispatch('toast', message: 'Insumo borrado');
        }

        unset($this->ingredients, $this->hasAny);
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center gap-2">
        <input type="search" wire:model.live.debounce.250ms="search" class="input flex-1" placeholder="Buscar insumo" aria-label="Buscar insumo">
        <button type="button" wire:click="create" class="btn-primary h-12"><x-icon name="plus" class="size-4" /> Nuevo</button>
    </div>

    @if (! $this->hasAny)
        <x-empty icon="package" title="Empieza por tus insumos">
            Son lo que compras para cocinar: carne, tortillas, cebolla, cilantro, gas. Con su costo la app calcula cuánto ganas por cada taco.
            <x-slot:action><button type="button" wire:click="create" class="btn-primary">Agregar el primer insumo</button></x-slot:action>
        </x-empty>
    @elseif ($this->ingredients->isEmpty())
        <p class="py-6 text-center text-sm text-muted">Ningún insumo coincide con "{{ $search }}".</p>
    @else
        <ul class="card divide-y divide-line !p-0">
            @foreach ($this->ingredients as $i)
                <li wire:key="i-{{ $i->id }}" @class(['flex items-center gap-3 px-4 py-3', 'opacity-55' => ! $i->active])>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-semibold">{{ $i->name }} @unless ($i->active) <span class="badge bg-sunken text-muted">Inactivo</span> @endunless</p>
                        <p class="text-sm text-muted">En {{ $i->products_count }} {{ $i->products_count === 1 ? 'receta' : 'recetas' }}</p>
                    </div>
                    <p class="text-right">
                        <span class="num block text-2xl font-bold leading-none">{{ Number::currency((float) $i->cost_per_unit) }}</span>
                        <span class="text-xs text-muted">por {{ $i->unit }}</span>
                    </p>
                    <div x-data="{ menu: false }" class="relative">
                        <button type="button" @click="menu = !menu" @click.outside="menu = false" class="btn-quiet size-9 justify-center !px-0" aria-label="Opciones de {{ $i->name }}"><x-icon name="more" /></button>
                        <div x-show="menu" x-cloak class="absolute right-0 top-10 z-20 w-44 rounded-xl border border-line bg-surface p-1 shadow-xl" @click="menu = false">
                            <button type="button" wire:click="edit({{ $i->id }})" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold hover:bg-sunken"><x-icon name="pencil" class="size-4" /> Editar</button>
                            <button type="button" wire:click="toggle({{ $i->id }})" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold hover:bg-sunken"><x-icon name="check" class="size-4" /> {{ $i->active ? 'Desactivar' : 'Activar' }}</button>
                            @if ($i->products_count === 0)
                                <button type="button" wire:click="delete({{ $i->id }})" wire:confirm="¿Borrar este insumo?" class="flex min-h-10 w-full items-center gap-2 rounded-lg px-3 text-sm font-semibold text-chile hover:bg-chile-soft"><x-icon name="trash" class="size-4" /> Borrar</button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
        <p class="text-center text-sm text-muted">El costo se actualiza solo cuando registras una compra del insumo en Gastos.</p>
    @endif

    @if ($showForm)
        <x-sheet :title="$editingId ? 'Editar insumo' : 'Nuevo insumo'">
            <form wire:submit="save" id="ingredient-form" class="space-y-4">
                <div>
                    <label class="label" for="name">Nombre</label>
                    <input id="name" type="text" wire:model="name" class="input" placeholder="Bistec, tortilla, cilantro" autofocus>
                    @error('name') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" for="unit">Se compra por</label>
                        <select id="unit" wire:model="unit" class="input">
                            @foreach ($this::UNITS as $u)
                                <option value="{{ $u }}">{{ $u }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="cost">Costo por {{ $unit }}</label>
                        <input id="cost" type="number" inputmode="decimal" step="any" min="0" wire:model="cost_per_unit" class="input">
                        @error('cost_per_unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="hint">Si no sabes el costo exacto, pon uno aproximado. Se corrige solo cuando registres la compra.</p>
            </form>
            <x-slot:footer>
                <button type="submit" form="ingredient-form" wire:loading.attr="disabled" class="btn-primary btn-lg w-full">Guardar insumo</button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
