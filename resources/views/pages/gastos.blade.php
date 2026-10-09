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

    public string $spent_on = '';

    public string $category = 'ingredients';

    public ?int $ingredient_id = null;

    public ?string $description = null;

    public ?string $supplier = null;

    public ?string $quantity = null;

    public ?string $total = null;

    public function mount(): void
    {
        $this->month = today()->format('Y-m');
        $this->spent_on = today()->toDateString();
    }

    #[Computed]
    public function ingredients()
    {
        return Ingredient::where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function expenses()
    {
        try {
            $start = CarbonImmutable::createFromFormat('Y-m', $this->month)->startOfMonth();
        } catch (Throwable) {
            $start = CarbonImmutable::today()->startOfMonth();
        }

        return Expense::with('ingredient')
            ->whereDate('spent_on', '>=', $start)->whereDate('spent_on', '<=', $start->endOfMonth())
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->get();
    }

    public function updatedCategory(): void
    {
        if ($this->category !== 'ingredients') {
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
        ]);

        Expense::create($data + ['user_id' => auth()->id()]);

        // El costo por unidad del insumo sigue a la última compra.
        if ($this->ingredient_id && (float) $this->quantity > 0) {
            Ingredient::whereKey($this->ingredient_id)
                ->update(['cost_per_unit' => round((float) $this->total / (float) $this->quantity, 4)]);
        }

        $this->reset('description', 'supplier', 'quantity', 'total', 'ingredient_id');
        unset($this->expenses, $this->ingredients);
    }

    public function delete(int $id): void
    {
        Expense::whereKey($id)->delete();
        unset($this->expenses);
    }
};
?>

<div class="space-y-5">
    <form wire:submit="save" class="card grid gap-3 md:grid-cols-6">
        <div class="md:col-span-2">
            <label class="label" for="spent_on">Fecha</label>
            <input id="spent_on" type="date" wire:model="spent_on" class="input">
            @error('spent_on') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-2">
            <label class="label" for="category">Categoría</label>
            <select id="category" wire:model.live="category" class="input">
                @foreach (App\Enums\ExpenseCategory::cases() as $c)
                    <option value="{{ $c->value }}">{{ $c->label() }}</option>
                @endforeach
            </select>
        </div>

        @if ($category === 'ingredients')
            <div class="md:col-span-2">
                <label class="label" for="ingredient_id">Insumo</label>
                <select id="ingredient_id" wire:model.live="ingredient_id" class="input">
                    <option value="">Otro / sin especificar</option>
                    @foreach ($this->ingredients as $i)
                        <option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</option>
                    @endforeach
                </select>
            </div>
            @if ($ingredient_id)
                <div class="md:col-span-2">
                    <label class="label" for="quantity">Cantidad comprada</label>
                    <input id="quantity" type="number" inputmode="decimal" step="any" wire:model="quantity" class="input">
                    @error('quantity') <p class="error">{{ $message }}</p> @enderror
                </div>
            @endif
        @endif

        <div class="md:col-span-2">
            <label class="label" for="total">Total pagado</label>
            <input id="total" type="number" inputmode="decimal" step="0.01" wire:model="total" class="input" placeholder="0.00">
            @error('total') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="md:col-span-2">
            <label class="label" for="supplier">Proveedor</label>
            <input id="supplier" type="text" wire:model="supplier" class="input" placeholder="Opcional">
        </div>
        <div class="md:col-span-2">
            <label class="label" for="description">Nota</label>
            <input id="description" type="text" wire:model="description" class="input" placeholder="Opcional">
        </div>
        <div class="md:col-span-6">
            <button class="btn-primary w-full md:w-auto">Guardar gasto</button>
        </div>
    </form>

    <div class="flex items-center justify-between gap-3">
        <input type="month" wire:model.live="month" class="input max-w-48">
        <p class="text-sm text-stone-600">
            Total del mes: <span class="font-semibold tabular-nums">{{ Number::currency((float) $this->expenses->sum('total')) }}</span>
        </p>
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach ($this->expenses->groupBy(fn ($e) => $e->category->label()) as $label => $group)
            <span class="rounded-full bg-stone-200 px-3 py-1 text-xs text-stone-700">
                {{ $label }}: {{ Number::currency((float) $group->sum('total')) }}
            </span>
        @endforeach
    </div>

    <div class="space-y-2">
        @forelse ($this->expenses as $e)
            <div wire:key="e-{{ $e->id }}" class="card flex items-start justify-between gap-3 text-sm">
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ $e->ingredient?->name ?? $e->category->label() }}
                        @if ($e->ingredient && $e->quantity)
                            <span class="font-normal text-stone-500">{{ rtrim(rtrim((string) $e->quantity, '0'), '.') }} {{ $e->ingredient->unit }}</span>
                        @endif
                    </p>
                    <p class="text-stone-500">
                        {{ $e->spent_on->translatedFormat('d M') }}
                        @if ($e->supplier) · {{ $e->supplier }} @endif
                        @if ($e->description) · {{ $e->description }} @endif
                    </p>
                </div>
                <div class="text-right">
                    <p class="font-semibold tabular-nums">{{ Number::currency((float) $e->total) }}</p>
                    <button type="button" wire:click="delete({{ $e->id }})" wire:confirm="¿Borrar este gasto?"
                            class="text-xs text-red-600 hover:underline">Borrar</button>
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-500">Sin gastos este mes.</p>
        @endforelse
    </div>
</div>
