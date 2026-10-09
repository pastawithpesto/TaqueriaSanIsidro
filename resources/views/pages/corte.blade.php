<?php

use App\Enums\OrderStatus;
use App\Models\CashClose;
use App\Models\Expense;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Corte de caja')] class extends Component
{
    public string $date = '';

    public ?string $opening = '0';

    public ?string $counted = null;

    public ?string $notes = null;

    public function mount(): void
    {
        $this->date = today()->toDateString();
        $this->loadClose();
    }

    public function updatedDate(): void
    {
        $this->loadClose();
    }

    public function shift(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
        $this->loadClose();
    }

    /** Si ya hay corte guardado para el día, lo muestra; si no, hereda el fondo del anterior. */
    protected function loadClose(): void
    {
        $saved = CashClose::whereDate('closed_on', $this->day())->first();

        if ($saved) {
            $this->opening = $this->plain($saved->opening);
            $this->counted = $this->plain($saved->counted);
            $this->notes = $saved->notes;

            return;
        }

        $this->opening = $this->plain(CashClose::whereDate('closed_on', '<', $this->day())->orderByDesc('closed_on')->value('counted') ?? 0);
        $this->counted = null;
        $this->notes = null;
    }

    protected function plain(mixed $n): string
    {
        $s = rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

        return $s === '' ? '0' : $s;
    }

    protected function day(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->date)->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }

    #[Computed]
    public function cashSales(): float
    {
        $day = $this->day();

        return (float) Order::where('status', OrderStatus::Paid)
            ->where('payment_method', 'cash')
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->sum('total');
    }

    #[Computed]
    public function otherSales(): float
    {
        $day = $this->day();

        return (float) Order::where('status', OrderStatus::Paid)
            ->where('payment_method', '!=', 'cash')
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->sum('total');
    }

    #[Computed]
    public function cashExpenses(): float
    {
        return (float) Expense::whereDate('spent_on', $this->day())->where('from_cash', true)->sum('total');
    }

    #[Computed]
    public function expected(): float
    {
        return round((is_numeric($this->opening) ? (float) $this->opening : 0) + $this->cashSales - $this->cashExpenses, 2);
    }

    #[Computed]
    public function difference(): ?float
    {
        return is_numeric($this->counted) ? round((float) $this->counted - $this->expected, 2) : null;
    }

    #[Computed]
    public function history()
    {
        return CashClose::orderByDesc('closed_on')->limit(7)->get();
    }

    public function save(): void
    {
        $this->validate([
            'opening' => ['required', 'numeric', 'min:0'],
            'counted' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ], attributes: ['opening' => 'fondo inicial', 'counted' => 'efectivo contado']);

        $close = CashClose::whereDate('closed_on', $this->day())->first() ?? new CashClose(['closed_on' => $this->day()->toDateString()]);

        $close->fill([
            'user_id' => auth()->id(),
            'opening' => $this->opening,
            'expected' => $this->expected,
            'counted' => $this->counted,
            'difference' => $this->difference,
            'notes' => $this->notes ?: null,
        ])->save();

        unset($this->history);
        $this->dispatch('toast', message: 'Corte guardado');
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn size-11 !px-0" aria-label="Día anterior"><x-icon name="left" /></button>
        <input type="date" wire:model.live="date" class="input flex-1 text-center font-semibold" max="{{ today()->toDateString() }}" aria-label="Fecha">
        <button type="button" wire:click="shift(1)" class="btn size-11 !px-0" aria-label="Día siguiente" @disabled($date >= today()->toDateString())><x-icon name="right" /></button>
    </div>

    <div class="card space-y-3">
        <div>
            <label class="label" for="opening">Fondo inicial en caja</label>
            <input id="opening" type="number" inputmode="decimal" step="0.01" min="0" wire:model.live.debounce.300ms="opening" class="input">
            @error('opening') <p class="error">{{ $message }}</p> @enderror
        </div>

        <dl class="divide-y divide-line text-[15px]">
            <div class="flex justify-between py-2"><dt class="text-muted">Ventas en efectivo</dt><dd class="num text-xl font-bold">{{ Number::currency($this->cashSales) }}</dd></div>
            <div class="flex justify-between py-2"><dt class="text-muted">Gastos pagados de la caja</dt><dd class="num text-xl font-bold">-{{ Number::currency($this->cashExpenses) }}</dd></div>
            <div class="flex items-end justify-between py-3"><dt class="font-semibold">Debe haber en caja</dt><dd class="num text-4xl font-bold leading-none">{{ Number::currency($this->expected) }}</dd></div>
        </dl>
        @if ($this->otherSales > 0)
            <p class="text-sm text-muted">Además hubo {{ Number::currency($this->otherSales) }} en transferencia o tarjeta, que no entra a la caja.</p>
        @endif
    </div>

    <div class="card space-y-3">
        <div>
            <label class="label" for="counted">Efectivo contado</label>
            <input id="counted" type="number" inputmode="decimal" step="0.01" min="0" wire:model.live.debounce.300ms="counted" class="input" placeholder="Cuenta los billetes y monedas">
            @error('counted') <p class="error">{{ $message }}</p> @enderror
        </div>

        @if ($this->difference !== null)
            @php $diff = $this->difference; @endphp
            <div @class([
                'flex items-center justify-between rounded-xl px-4 py-3',
                'bg-salsa-soft text-salsa' => $diff == 0,
                'bg-limon-soft text-ink' => $diff > 0,
                'bg-chile-soft text-chile' => $diff < 0,
            ])>
                <span class="text-sm font-semibold">{{ $diff == 0 ? 'La caja cuadra' : ($diff > 0 ? 'Sobra' : 'Falta') }}</span>
                <span class="num text-4xl font-bold leading-none">{{ $diff == 0 ? '$0.00' : Number::currency(abs($diff)) }}</span>
            </div>
        @endif

        <div>
            <label class="label" for="notes">Nota</label>
            <input id="notes" type="text" wire:model="notes" class="input" placeholder="Opcional">
        </div>

        <button type="button" wire:click="save" class="btn-primary btn-lg w-full" wire:loading.attr="disabled">Guardar corte</button>
    </div>

    @if ($this->history->isNotEmpty())
        <section class="space-y-2">
            <h2 class="font-display text-xl font-bold">Cortes anteriores</h2>
            <ul class="card divide-y divide-line !p-0">
                @foreach ($this->history as $close)
                    <li class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                        <span class="font-semibold">{{ $close->closed_on->translatedFormat('D d M') }}</span>
                        <span class="num text-lg font-bold">{{ Number::currency((float) $close->counted) }}</span>
                        <span @class([
                            'badge',
                            'bg-salsa-soft text-salsa' => (float) $close->difference == 0,
                            'bg-limon-soft text-ink' => (float) $close->difference > 0,
                            'bg-chile-soft text-chile' => (float) $close->difference < 0,
                        ])>
                            {{ (float) $close->difference == 0 ? 'Cuadra' : ((float) $close->difference > 0 ? 'Sobra ' : 'Falta ').Number::currency(abs((float) $close->difference)) }}
                        </span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
