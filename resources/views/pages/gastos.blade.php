<?php

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Gastos')] class extends Component
{
    public string $month = '';

    public bool $showForm = false;

    public string $spent_on = '';

    public string $category = 'ingredients';

    public ?int $ingredient_id = null;

    public ?string $description = null;

    public ?string $supplier = null;

    public ?string $quantity = null;

    public ?string $total = null;

    public bool $from_cash = true;

    public function mount(): void
    {
        $this->month = today()->format('Y-m');
        $this->spent_on = today()->toDateString();
    }

    protected function start(): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01')->startOfMonth();
        } catch (Throwable) {
            return CarbonImmutable::today()->startOfMonth();
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = $this->start()->addMonths($by)->format('Y-m');
        unset($this->expenses);
    }

    #[Computed]
    public function monthLabel(): string
    {
        return ucfirst($this->start()->translatedFormat('F Y'));
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::where('active', true)->orderBy('name')->get()->keyBy('id');
    }

    #[Computed]
    public function suppliers()
    {
        return Expense::whereNotNull('supplier')->distinct()->orderBy('supplier')->pluck('supplier');
    }

    #[Computed]
    public function expenses()
    {
        return Expense::with('ingredient')
            ->whereDate('spent_on', '>=', $this->start())
            ->whereDate('spent_on', '<=', $this->start()->endOfMonth())
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->get();
    }

    #[Computed]
    public function unitPrice(): ?string
    {
        $ingredient = $this->ingredient_id ? ($this->ingredients[$this->ingredient_id] ?? null) : null;

        if (! $ingredient || ! is_numeric($this->quantity) || ! is_numeric($this->total) || (float) $this->quantity <= 0) {
            return null;
        }

        return money((float) $this->total / (float) $this->quantity).' por '.$ingredient->unit;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset('showForm', 'ingredient_id', 'description', 'supplier', 'quantity', 'total');
        $this->category = 'ingredients';
        $this->from_cash = true;
        $this->spent_on = today()->toDateString();
        $this->resetErrorBag();
    }

    public function setCategory(string $value): void
    {
        $this->category = $value;

        if ($value !== 'ingredients') {
            $this->reset('ingredient_id', 'quantity');
        }
    }

    public function save(): void
    {
        $data = $this->validate([
            'spent_on' => ['required', 'date'],
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'ingredient_id' => ['nullable', 'exists:ingredients,id'],
            'description' => ['nullable', 'string', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:255'],
            'quantity' => [$this->ingredient_id ? 'required' : 'nullable', 'numeric', 'gt:0'],
            'total' => ['required', 'numeric', 'gt:0'],
            'from_cash' => ['boolean'],
        ]);

        Expense::create($data + ['user_id' => auth()->id()]);

        // El costo por unidad del insumo sigue a la última compra.
        if ($this->ingredient_id && (float) $this->quantity > 0) {
            Ingredient::whereKey($this->ingredient_id)
                ->update(['cost_per_unit' => round((float) $this->total / (float) $this->quantity, 4)]);
        }

        $this->resetForm();
        unset($this->expenses, $this->ingredients, $this->suppliers);
        $this->dispatch('toast', message: 'Gasto guardado');
    }

    public function delete(int $id): void
    {
        Expense::whereKey($id)->delete();
        unset($this->expenses);
        $this->dispatch('toast', message: 'Gasto borrado');
    }
};
?>

@php
    $total = (float) $this->expenses->sum('total');
    $byCategory = $this->expenses->groupBy(fn ($e) => $e->category->value)
        ->map(fn ($g, $key) => ['category' => \App\Enums\ExpenseCategory::from($key), 'total' => (float) $g->sum('total')])
        ->sortByDesc('total');
@endphp

<div>
    <x-page-header title="Gastos">
        <x-slot:actions>
            <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Gasto</button>
        </x-slot:actions>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="shiftMonth(-1)" class="btn-icon" aria-label="Mes anterior"><x-icon name="left" /></button>
            <p class="flex min-h-11 flex-1 items-center justify-center rounded-xl border border-line bg-surface font-semibold">{{ $this->monthLabel }}</p>
            <button type="button" wire:click="shiftMonth(1)" class="btn-icon" aria-label="Mes siguiente" @disabled($month >= today()->format('Y-m'))><x-icon name="right" /></button>
        </div>
    </x-page-header>

    <div class="space-y-5">
        <section class="card !p-5">
            <p class="text-sm font-semibold text-muted">Gastado en el mes</p>
            <p class="num text-[3rem] leading-none font-bold">{{ money($total) }}</p>
            @if ($byCategory->isNotEmpty())
                <ul class="mt-4 space-y-2.5 border-t border-line pt-4">
                    @foreach ($byCategory as $row)
                        <li class="grid grid-cols-[1.25rem_6rem_1fr_auto] items-center gap-2.5 text-sm">
                            <x-icon :name="$row['category']->icon()" class="size-4 text-muted" />
                            <span class="truncate font-medium">{{ $row['category']->label() }}</span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-sunken"><span class="block h-full rounded-full bg-spend" style="width: {{ $total > 0 ? $row['total'] / $total * 100 : 0 }}%"></span></span>
                            <span class="num w-20 text-right text-base font-bold">{{ money($row['total']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($this->expenses->isEmpty())
            <x-empty icon="wallet" title="Sin gastos este mes">
                Registra lo que compras, como carne, tortilla o gas, para saber cuánto cuesta operar.
                <x-slot:action><button type="button" wire:click="create" class="btn-primary">Registrar un gasto</button></x-slot:action>
            </x-empty>
        @else
            @foreach ($this->expenses->groupBy(fn ($e) => $e->spent_on->toDateString()) as $day => $group)
                <section wire:key="day-{{ $day }}" class="space-y-2">
                    <div class="flex items-baseline justify-between px-1">
                        <h2 class="font-semibold">{{ ucfirst(\Carbon\CarbonImmutable::parse($day)->translatedFormat('l j')) }}</h2>
                        <span class="num text-base font-bold text-muted">{{ money($group->sum('total')) }}</span>
                    </div>
                    <ul class="list">
                        @foreach ($group as $e)
                            @php
                                $title = $e->ingredient?->name ?? $e->description ?? $e->category->label();
                                $meta = array_filter([
                                    $e->ingredient && $e->quantity ? rtrim(rtrim((string) $e->quantity, '0'), '.').' '.$e->ingredient->unit : null,
                                    $e->supplier,
                                    $e->ingredient && $e->description ? $e->description : null,
                                ]);
                            @endphp
                            <li wire:key="e-{{ $e->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                                <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sunken text-muted"><x-icon :name="$e->category->icon()" class="size-5" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-semibold">{{ $title }}</p>
                                    <p class="flex items-center gap-2 truncate text-sm text-muted">
                                        @if ($meta) <span class="truncate">{{ implode(', ', $meta) }}</span> @elseif ($title !== $e->category->label()) <span>{{ $e->category->label() }}</span> @endif
                                        @unless ($e->from_cash) <span class="badge bg-sunken text-muted">Fuera de caja</span> @endunless
                                    </p>
                                </div>
                                <span class="num text-[1.375rem] font-bold">{{ money($e->total) }}</span>
                                <button type="button" wire:click="delete({{ $e->id }})" wire:confirm="¿Borrar este gasto?" class="btn-quiet size-10 justify-center !px-0" aria-label="Borrar gasto">
                                    <x-icon name="trash" class="size-4" />
                                </button>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        @endif
    </div>

    @if ($showForm)
        <x-sheet title="Nuevo gasto">
            <form wire:submit="save" id="expense-form" class="space-y-5">
                <div>
                    <p class="label">Tipo</p>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                        @foreach (\App\Enums\ExpenseCategory::cases() as $c)
                            <button type="button" wire:click="setCategory('{{ $c->value }}')" aria-pressed="{{ $category === $c->value ? 'true' : 'false' }}"
                                    class="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border text-sm font-semibold motion-safe:transition {{ $category === $c->value ? 'border-ink bg-ink text-bg' : 'border-line bg-surface text-ink hover:bg-sunken' }}">
                                <x-icon :name="$c->icon()" class="size-5" />
                                {{ $c->label() }}
                            </button>
                        @endforeach
                    </div>
                </div>

                @if ($category === 'ingredients')
                    <div class="grid grid-cols-[1fr_8rem] gap-3">
                        <div>
                            <label class="label" for="ingredient_id">Insumo</label>
                            <select id="ingredient_id" wire:model.live="ingredient_id" class="input">
                                <option value="">Otro</option>
                                @foreach ($this->ingredients as $i)
                                    <option value="{{ $i->id }}">{{ $i->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label" for="quantity">Cantidad</label>
                            <div class="relative">
                                <input id="quantity" type="number" inputmode="decimal" step="any" wire:model.live.debounce.300ms="quantity" @disabled(! $ingredient_id) class="input pr-12 disabled:opacity-50">
                                <span class="pointer-events-none absolute top-1/2 right-3.5 -translate-y-1/2 text-sm text-muted">{{ $ingredient_id ? ($this->ingredients[$ingredient_id]->unit ?? '') : '' }}</span>
                            </div>
                        </div>
                    </div>
                    @error('quantity') <p class="error -mt-3">{{ $message }}</p> @enderror
                    @if ($this->ingredients->isEmpty())
                        <p class="hint -mt-3">Aún no tienes insumos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Da de alta uno</a> para que el costo de tus recetas se actualice solo.</p>
                    @endif
                @endif

                <div>
                    <label class="label" for="total">Total pagado</label>
                    <x-money-input id="total" wire:model.live.debounce.300ms="total" placeholder="0" class="!min-h-14 !text-2xl" />
                    @error('total') <p class="error">{{ $message }}</p> @enderror
                    @if ($this->unitPrice)
                        <p class="hint">Sale a <span class="font-semibold text-ink">{{ $this->unitPrice }}</span>. Se guardará como costo del insumo.</p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="supplier">Proveedor <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="supplier" type="text" list="suppliers" wire:model="supplier" class="input">
                        <datalist id="suppliers">
                            @foreach ($this->suppliers as $s) <option value="{{ $s }}"> @endforeach
                        </datalist>
                    </div>
                    <div>
                        <label class="label" for="spent_on">Fecha</label>
                        <input id="spent_on" type="date" wire:model="spent_on" class="input" max="{{ today()->toDateString() }}">
                        @error('spent_on') <p class="error">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="label" for="description">Nota <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="description" type="text" wire:model="description" class="input">
                </div>

                <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                    <span>
                        <span class="block font-semibold">Se pagó con dinero de la caja</span>
                        <span class="block text-sm text-muted">Se descuenta del efectivo en el corte.</span>
                    </span>
                    <input type="checkbox" wire:model="from_cash" class="switch">
                </label>
            </form>

            <x-slot:footer>
                <button type="submit" form="expense-form" wire:loading.attr="disabled" class="btn-primary btn-lg w-full">Guardar gasto</button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
