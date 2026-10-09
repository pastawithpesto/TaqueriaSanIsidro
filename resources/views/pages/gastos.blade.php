<?php

use App\Enums\ExpenseCategory;
use App\Models\Expense;
use App\Models\Ingredient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
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

        return Number::currency((float) $this->total / (float) $this->quantity).' por '.$ingredient->unit;
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

<div class="space-y-5">
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shiftMonth(-1)" class="btn size-11 !px-0" aria-label="Mes anterior"><x-icon name="left" /></button>
        <p class="flex-1 text-center font-display text-2xl font-bold">{{ $this->monthLabel }}</p>
        <button type="button" wire:click="shiftMonth(1)" class="btn size-11 !px-0" aria-label="Mes siguiente"><x-icon name="right" /></button>
    </div>

    @php
        $total = (float) $this->expenses->sum('total');
        $byCategory = $this->expenses->groupBy(fn ($e) => $e->category->value)
            ->map(fn ($g, $key) => ['label' => ExpenseCategory::from($key)->label(), 'total' => (float) $g->sum('total')])
            ->sortByDesc('total');
    @endphp

    <div class="card space-y-3">
        <div class="flex items-end justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-muted">Gastado en el mes</p>
                <p class="num text-5xl font-bold leading-none">{{ Number::currency($total) }}</p>
            </div>
            <button type="button" wire:click="create" class="btn-primary"><x-icon name="plus" class="size-4" /> Nuevo gasto</button>
        </div>
        @if ($byCategory->isNotEmpty())
            <ul class="space-y-2 border-t border-line pt-3">
                @foreach ($byCategory as $row)
                    <li class="grid grid-cols-[6.5rem_1fr_auto] items-center gap-3 text-sm">
                        <span class="truncate text-muted">{{ $row['label'] }}</span>
                        <span class="h-2 overflow-hidden rounded-full bg-sunken"><span class="block h-full rounded-full bg-spend" style="width: {{ $total > 0 ? $row['total'] / $total * 100 : 0 }}%"></span></span>
                        <span class="num text-base font-bold">{{ Number::currency($row['total']) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($this->expenses->isEmpty())
        <x-empty icon="wallet" title="Sin gastos este mes">
            Registra lo que compras (carne, tortilla, gas) para saber cuánto cuesta operar.
            <x-slot:action><button type="button" wire:click="create" class="btn-primary">Registrar el primer gasto</button></x-slot:action>
        </x-empty>
    @else
        @foreach ($this->expenses->groupBy(fn ($e) => $e->spent_on->toDateString()) as $day => $group)
            <section wire:key="day-{{ $day }}" class="space-y-2">
                <div class="flex items-baseline justify-between px-1">
                    <h2 class="font-semibold">{{ ucfirst(\Carbon\CarbonImmutable::parse($day)->translatedFormat('l d')) }}</h2>
                    <span class="num text-lg font-bold text-muted">{{ Number::currency((float) $group->sum('total')) }}</span>
                </div>
                <ul class="card divide-y divide-line !p-0">
                    @foreach ($group as $e)
                        <li wire:key="e-{{ $e->id }}" class="flex items-center gap-3 px-4 py-3">
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-semibold">
                                    {{ $e->ingredient?->name ?? $e->description ?? $e->category->label() }}
                                    @if ($e->ingredient && $e->quantity)
                                        <span class="font-normal text-muted">{{ rtrim(rtrim((string) $e->quantity, '0'), '.') }} {{ $e->ingredient->unit }}</span>
                                    @endif
                                </p>
                                <p class="truncate text-sm text-muted">
                                    {{ $e->category->label() }}@if ($e->supplier), {{ $e->supplier }}@endif
                                    @unless ($e->from_cash) <span class="badge bg-sunken text-muted">No salió de caja</span> @endunless
                                </p>
                            </div>
                            <span class="num text-2xl font-bold">{{ Number::currency((float) $e->total) }}</span>
                            <button type="button" wire:click="delete({{ $e->id }})" wire:confirm="¿Borrar este gasto?" class="btn-quiet size-9 justify-center !px-0" aria-label="Borrar gasto">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    @endif

    @if ($showForm)
        <x-sheet title="Nuevo gasto">
            <form wire:submit="save" id="expense-form" class="space-y-4">
                <div>
                    <p class="label">¿Qué compraste?</p>
                    <div class="flex flex-wrap gap-2">
                        @foreach (App\Enums\ExpenseCategory::cases() as $c)
                            <button type="button" wire:click="setCategory('{{ $c->value }}')" aria-pressed="{{ $category === $c->value ? 'true' : 'false' }}" class="chip">{{ $c->label() }}</button>
                        @endforeach
                    </div>
                </div>

                @if ($category === 'ingredients')
                    <div>
                        <label class="label" for="ingredient_id">Insumo</label>
                        <select id="ingredient_id" wire:model.live="ingredient_id" class="input">
                            <option value="">Otro / sin especificar</option>
                            @foreach ($this->ingredients as $i)
                                <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                            @endforeach
                        </select>
                        @if ($this->ingredients->isEmpty())
                            <p class="hint">Aún no tienes insumos. <a href="{{ route('insumos') }}" wire:navigate class="font-semibold text-chile underline">Da de alta uno</a> para que el costo de tus recetas se actualice solo.</p>
                        @endif
                    </div>
                    @if ($ingredient_id)
                        <div>
                            <label class="label" for="quantity">Cantidad comprada ({{ $this->ingredients[$ingredient_id]->unit ?? '' }})</label>
                            <input id="quantity" type="number" inputmode="decimal" step="any" wire:model.live.debounce.300ms="quantity" class="input">
                            @error('quantity') <p class="error">{{ $message }}</p> @enderror
                        </div>
                    @endif
                @endif

                <div>
                    <label class="label" for="total">Total pagado</label>
                    <input id="total" type="number" inputmode="decimal" step="0.01" wire:model.live.debounce.300ms="total" class="input" placeholder="0.00">
                    @error('total') <p class="error">{{ $message }}</p> @enderror
                    @if ($this->unitPrice)
                        <p class="hint">Sale a <span class="font-semibold text-ink">{{ $this->unitPrice }}</span>. Se usará como costo del insumo.</p>
                    @endif
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="label" for="supplier">Proveedor</label>
                        <input id="supplier" type="text" list="suppliers" wire:model="supplier" class="input" placeholder="Opcional">
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
                    <label class="label" for="description">Nota</label>
                    <input id="description" type="text" wire:model="description" class="input" placeholder="Opcional">
                </div>

                <label class="flex items-center justify-between gap-4 rounded-xl bg-sunken px-4 py-3">
                    <span>
                        <span class="block text-sm font-semibold">Salió de la caja</span>
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
