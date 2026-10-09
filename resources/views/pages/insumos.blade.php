<?php

use App\Models\Ingredient;
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

<div x-data="{ sheet: $wire.$entangle('showForm') }">
    <x-page-header title="Insumos">
        <x-slot:actions>
            <button type="button" @click="sheet = true" class="btn-primary"><x-icon name="plus" class="size-4" /> Insumo</button>
        </x-slot:actions>
        @if ($this->hasAny)
            <div class="relative">
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
                <li wire:key="i-{{ $i->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                    <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})" class="min-w-0 flex-1 text-left">
                        <span @class(['block truncate font-semibold', 'text-muted' => ! $i->active])>{{ $i->name }}</span>
                        <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                            @unless ($i->active) <span class="badge bg-sunken text-muted">Inactivo</span> @endunless
                            {{ $i->products_count === 0 ? 'Sin usar en recetas' : 'En '.$i->products_count.' '.($i->products_count === 1 ? 'receta' : 'recetas') }}
                        </span>
                    </button>
                    <span class="text-right">
                        <span class="num block text-[1.375rem] leading-tight font-bold">{{ money($i->cost_per_unit) }}</span>
                        <span class="block text-xs text-muted">por {{ $i->unit }}</span>
                    </span>
                    <div x-data="{ menu: false }" class="relative">
                        <button type="button" @click="menu = !menu" @click.outside="menu = false" class="btn-quiet size-10 justify-center !px-0" aria-label="Opciones de {{ $i->name }}"><x-icon name="more" /></button>
                        <div x-show="menu" x-cloak x-transition.origin.top.right class="menu" @click="menu = false">
                            <button type="button" @click="sheet = true; $wire.edit({{ $i->id }})"><x-icon name="pencil" class="size-4 text-muted" /> Editar</button>
                            <button type="button" wire:click="toggle({{ $i->id }})"><x-icon :name="$i->active ? 'eye-off' : 'eye'" class="size-4 text-muted" /> {{ $i->active ? 'Desactivar' : 'Activar' }}</button>
                            @if ($i->products_count === 0)
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
                        <select id="unit" wire:model="unit" class="input">
                            @foreach ($this::UNITS as $u)
                                <option value="{{ $u }}">{{ $u }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label" for="cost" x-text="'Costo por ' + $wire.unit">Costo por {{ $unit }}</label>
                        <x-money-input id="cost" step="any" wire:model="cost_per_unit" />
                        @error('cost_per_unit') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p class="hint">Si no sabes el costo exacto, pon uno aproximado. Se corrige solo cuando registres la compra.</p>
            </form>
            <x-slot:footer>
                <button type="submit" form="ingredient-form" wire:loading.attr="disabled" wire:target="save,edit" class="btn-primary btn-lg w-full">Guardar insumo</button>
            </x-slot:footer>
        </x-sheet>

</div>
