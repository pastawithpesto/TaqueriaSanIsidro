<?php

use App\Enums\OrderStatus;
use App\Models\CashClose;
use App\Models\Expense;
use App\Models\Order;
use Carbon\CarbonImmutable;
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
    public function dayLabel(): string
    {
        $day = $this->day();

        return match (true) {
            $day->isToday() => 'Hoy',
            $day->isYesterday() => 'Ayer',
            default => ucfirst($day->translatedFormat('l')),
        };
    }

    #[Computed]
    public function saved(): ?CashClose
    {
        return CashClose::whereDate('closed_on', $this->day())->first();
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

        unset($this->history, $this->saved);
        $this->dispatch('toast', message: 'Corte guardado');
    }
};
?>

@php
    $day = \Carbon\CarbonImmutable::parse($date);
    $diff = $this->difference;
@endphp

<div>
    <x-page-header title="Corte de caja">
        <x-date-nav :label="$this->dayLabel" :detail="$day->translatedFormat('j M')" :max="today()->toDateString()" :at-max="$day->gte(today())" />
    </x-page-header>

    <div class="mx-auto max-w-xl space-y-4">
        <section class="card !p-0">
            <dl class="divide-y divide-line">
                <div class="flex items-center justify-between gap-4 px-4 py-3">
                    <dt>
                        <label for="opening" class="font-semibold">Fondo inicial</label>
                        <p class="text-sm text-muted">Con lo que abriste la caja</p>
                    </dt>
                    <dd class="w-36"><x-money-input id="opening" wire:model.live.debounce.300ms="opening" class="!min-h-11 text-right !text-lg" /></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Ventas en efectivo</dt>
                    <dd class="num text-xl font-bold">{{ $this->cashSales > 0 ? '+' : '' }}{{ money($this->cashSales) }}</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Gastos pagados de la caja</dt>
                    <dd class="num text-xl font-bold">{{ $this->cashExpenses > 0 ? '−'.money($this->cashExpenses) : money(0) }}</dd>
                </div>
                <div class="flex items-end justify-between bg-sunken/60 px-4 py-4">
                    <dt class="font-semibold">Debe haber en caja</dt>
                    <dd class="num text-[2.5rem] leading-none font-bold">{{ money($this->expected) }}</dd>
                </div>
            </dl>
            @error('opening') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
        </section>

        @if ($this->otherSales > 0)
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Además se cobraron {{ money($this->otherSales) }} por transferencia o tarjeta. Ese dinero no entra a la caja.</span>
            </p>
        @endif

        <section class="card space-y-4 !p-5">
            <div>
                <label class="label" for="counted">Efectivo contado</label>
                <x-money-input id="counted" wire:model.live.debounce.300ms="counted" placeholder="0" class="!min-h-14 !text-2xl" />
                <p class="hint">Cuenta billetes y monedas al cerrar.</p>
                @error('counted') <p class="error">{{ $message }}</p> @enderror
            </div>

            @if ($diff !== null)
                <div @class([
                    'flex items-center justify-between gap-3 rounded-xl px-4 py-3.5',
                    'bg-salsa-soft text-salsa' => abs($diff) < 0.005,
                    'bg-limon-soft text-ink' => $diff >= 0.005,
                    'bg-chile-soft text-chile' => $diff <= -0.005,
                ])>
                    <span class="flex items-center gap-2 text-sm font-bold">
                        <x-icon :name="abs($diff) < 0.005 ? 'check' : 'alert'" class="size-5" />
                        {{ abs($diff) < 0.005 ? 'La caja cuadra' : ($diff > 0 ? 'Sobra dinero' : 'Falta dinero') }}
                    </span>
                    @if (abs($diff) >= 0.005)
                        <span class="num text-[2rem] leading-none font-bold">{{ money(abs($diff)) }}</span>
                    @endif
                </div>
            @endif

            <div>
                <label class="label" for="notes">Nota <span class="font-normal text-muted">(opcional)</span></label>
                <input id="notes" type="text" wire:model="notes" class="input" placeholder="Ej. se pagó al gas con efectivo de la caja">
            </div>

            <button type="button" wire:click="save" class="btn-primary btn-lg w-full" wire:loading.attr="disabled">
                {{ $this->saved ? 'Actualizar corte' : 'Guardar corte' }}
            </button>
            @if ($this->saved)
                <p class="-mt-2 text-center text-sm text-muted">Guardado a las {{ $this->saved->updated_at->format('g:i a') }}</p>
            @endif
        </section>

        @if ($this->history->isNotEmpty())
            <section class="space-y-2 pt-2">
                <h2 class="section-title px-1">Cortes anteriores</h2>
                <ul class="list">
                    @foreach ($this->history as $close)
                        @php $d = (float) $close->difference; @endphp
                        <li class="flex items-center gap-3 px-4 py-3">
                            <span class="flex-1">
                                <span class="block font-semibold">{{ ucfirst($close->closed_on->translatedFormat('l j M')) }}</span>
                                <span class="block text-sm text-muted">Contado {{ money($close->counted) }}</span>
                            </span>
                            <span @class([
                                'badge !text-sm',
                                'bg-salsa-soft text-salsa' => abs($d) < 0.005,
                                'bg-limon-soft text-ink' => $d >= 0.005,
                                'bg-chile-soft text-chile' => $d <= -0.005,
                            ])>
                                {{ abs($d) < 0.005 ? 'Cuadró' : ($d > 0 ? 'Sobró ' : 'Faltó ').money(abs($d)) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
