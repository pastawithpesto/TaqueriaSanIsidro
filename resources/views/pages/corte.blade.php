<?php

use App\Enums\OrderStatus;
use App\Models\CashClose;
use App\Models\CashMovement;
use App\Models\OrderPayment;
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

    /** Totales del día; el navegador los usa para calcular al instante. */
    public float $cashSales = 0;

    public float $otherSales = 0;

    public float $cashExpenses = 0;

    /** Entradas menos retiros de efectivo del día. */
    public float $movementsNet = 0;

    public float $tips = 0;

    /** Formulario de movimiento de caja. */
    public string $moveType = 'out';

    public ?string $moveAmount = null;

    public ?string $moveReason = null;

    public function mount(): void
    {
        $this->date = today()->toDateString();
        $this->loadClose();
    }

    public function updatedDate(): void
    {
        $this->loadClose();
    }

    /** Si ya hay corte guardado para el día, lo muestra; si no, hereda el fondo del anterior. */
    protected function loadClose(): void
    {
        $this->loadTotals();
        unset($this->saved);

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

    protected function loadTotals(): void
    {
        $day = $this->day();
        $range = [$day, $day->endOfDay()];

        // Pagos de órdenes pagadas, por el día en que se cobraron.
        $payments = OrderPayment::whereBetween('created_at', $range)
            ->whereHas('order', fn ($q) => $q->where('status', OrderStatus::Paid));

        $this->cashSales = (float) (clone $payments)->where('method', 'cash')->sum('amount');
        $this->otherSales = (float) (clone $payments)->where('method', '!=', 'cash')->sum('amount');
        $this->tips = (float) Order::where('status', OrderStatus::Paid)->whereBetween('paid_at', $range)->sum('tip');
        $this->cashExpenses = (float) Expense::whereDate('spent_on', $day)->where('from_cash', true)->sum('total');
        $this->movementsNet = (float) CashMovement::whereBetween('moved_at', $range)->get()->sum(fn ($m) => $m->signed());
    }

    #[Computed]
    public function movements()
    {
        $day = $this->day();

        return CashMovement::whereBetween('moved_at', [$day, $day->endOfDay()])->orderBy('moved_at')->get();
    }

    public function addMovement(): void
    {
        $this->validate([
            'moveType' => ['required', 'in:in,out'],
            'moveAmount' => ['required', 'numeric', 'gt:0'],
            'moveReason' => ['nullable', 'string', 'max:120'],
        ], attributes: ['moveAmount' => 'monto', 'moveReason' => 'motivo']);

        $day = $this->day();
        CashMovement::create([
            'user_id' => auth()->id(),
            'type' => $this->moveType,
            'amount' => $this->moveAmount,
            'reason' => $this->moveReason ?: null,
            'moved_at' => $day->isToday() ? now() : $day->setTime(23, 59),
        ]);

        $this->reset('moveAmount', 'moveReason');
        $this->loadTotals();
        unset($this->movements);
        $this->dispatch('toast', message: 'Movimiento registrado');
        $this->dispatch('movement-saved');
    }

    public function deleteMovement(int $id): void
    {
        CashMovement::whereKey($id)->delete();
        $this->loadTotals();
        unset($this->movements);
    }

    #[Computed]
    public function expected(): float
    {
        return round((is_numeric($this->opening) ? (float) $this->opening : 0) + $this->cashSales + $this->movementsNet - $this->cashExpenses, 2);
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

<div x-data="{
        get expected() { return Math.round(((Number($wire.opening) || 0) + $wire.cashSales + $wire.movementsNet - $wire.cashExpenses) * 100) / 100 },
        moveOpen: false,
        get diff() {
            if ($wire.counted === null || $wire.counted === '' || isNaN($wire.counted)) return null;
            return Math.round((Number($wire.counted) - this.expected) * 100) / 100;
        },
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
     }">
    <x-page-header title="Corte de caja">
        <x-date-nav :max="today()->toDateString()" />
    </x-page-header>

    <div class="mx-auto max-w-xl space-y-4">
        <section class="card !p-0">
            <dl class="divide-y divide-line">
                <div class="flex items-center justify-between gap-4 px-4 py-3">
                    <dt>
                        <label for="opening" class="font-semibold">Fondo inicial</label>
                        <p class="text-sm text-muted">Con lo que abriste la caja</p>
                    </dt>
                    <dd class="w-36"><x-money-input id="opening" wire:model="opening" class="!min-h-11 text-right !text-lg" /></dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Cobrado en efectivo @if ($tips > 0)<span class="block text-xs">incluye propinas</span>@endif</dt>
                    <dd class="num text-xl font-bold" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ $cashSales > 0 ? '+' : '' }}{{ money($cashSales) }}</dd>
                </div>
                <div class="flex items-center justify-between px-4 py-3">
                    <dt class="text-muted">Gastos pagados de la caja</dt>
                    <dd class="num text-xl font-bold" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ $cashExpenses > 0 ? '−'.money($cashExpenses) : money(0) }}</dd>
                </div>
                @if ($movementsNet != 0)
                    <div class="flex items-center justify-between px-4 py-3">
                        <dt class="text-muted">Entradas y retiros</dt>
                        <dd class="num text-xl font-bold">{{ $movementsNet > 0 ? '+' : '−' }}{{ money(abs($movementsNet)) }}</dd>
                    </div>
                @endif
                <div class="flex items-end justify-between bg-sunken/60 px-4 py-4">
                    <dt class="font-semibold">Debe haber en caja</dt>
                    <dd class="num text-[2.5rem] leading-none font-bold" x-text="fmt(expected)" wire:loading.class="animate-pulse opacity-40" wire:target="date">{{ money($this->expected) }}</dd>
                </div>
            </dl>
            @error('opening') <p class="error px-4 pb-3">{{ $message }}</p> @enderror
        </section>

        @php $unpaid = \App\Models\Order::where('status', \App\Enums\OrderStatus::Open)->get(['total']); @endphp
        @if ($unpaid->isNotEmpty())
            <a href="{{ route('pedidos') }}" wire:navigate class="flex items-center gap-3 rounded-2xl bg-limon-soft px-4 py-3 text-sm">
                <x-icon name="alert" class="size-5 shrink-0" />
                <span class="flex-1">Hay <span class="font-semibold">{{ $unpaid->count() }} {{ $unpaid->count() === 1 ? 'pedido' : 'pedidos' }} sin cobrar</span> por {{ money($unpaid->sum('total')) }}. Cóbralos antes de cerrar.</span>
                <x-icon name="right" class="size-4" />
            </a>
        @endif

        @if ($otherSales > 0)
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Además se cobraron {{ money($otherSales) }} por transferencia o tarjeta. Ese dinero no entra a la caja.</span>
            </p>
        @endif

        @if ($tips > 0)
            <p class="flex gap-2 px-1 text-sm text-muted">
                <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                <span>Propinas del día: <span class="font-semibold text-ink">{{ money($tips) }}</span>. No cuentan como venta.</span>
            </p>
        @endif

        {{-- Movimientos de caja --}}
        <section class="space-y-2">
            <div class="flex items-center justify-between px-1">
                <h2 class="section-title">Movimientos de caja</h2>
                <div class="flex gap-2">
                    <button type="button" class="btn !min-h-9 !px-3" @click="$wire.moveType = 'out'; moveOpen = true">Sacar</button>
                    <button type="button" class="btn !min-h-9 !px-3" @click="$wire.moveType = 'in'; moveOpen = true">Meter</button>
                </div>
            </div>
            @if ($this->movements->isEmpty())
                <p class="px-1 text-sm text-muted">Si sacas efectivo de la caja (para pagar algo personal, depositar) o metes cambio, anótalo aquí para que el corte cuadre.</p>
            @else
                <ul class="list">
                    @foreach ($this->movements as $m)
                        <li wire:key="mv-{{ $m->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                            <span @class(['grid size-9 shrink-0 place-items-center rounded-xl', 'bg-salsa-soft text-salsa' => $m->type === 'in', 'bg-chile-soft text-chile' => $m->type === 'out'])>
                                <x-icon :name="$m->type === 'in' ? 'plus' : 'minus'" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $m->reason ?: ($m->type === 'in' ? 'Entrada de efectivo' : 'Retiro de efectivo') }}</span>
                                <span class="block text-sm text-muted">{{ $m->moved_at->format('g:i a') }}</span>
                            </span>
                            <span class="num text-xl font-bold">{{ $m->type === 'in' ? '+' : '−' }}{{ money($m->amount) }}</span>
                            <button type="button" class="btn-quiet size-10 justify-center !px-0" aria-label="Borrar movimiento"
                                    @click="if (await $store.confirm.ask({ title: '¿Borrar este movimiento?', message: @js(($m->reason ?: 'Movimiento').' por '.money($m->amount)), action: 'Borrar' })) $wire.deleteMovement({{ $m->id }})">
                                <x-icon name="trash" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div wire:ignore>
            <x-sheet title="Movimiento de caja" open="moveOpen" x-text="$wire.moveType === 'out' ? 'Sacar efectivo' : 'Meter efectivo'" @movement-saved.window="moveOpen = false">
                <form @submit.prevent="$wire.addMovement()" id="move-form" class="space-y-4">
                    <div class="segmented" role="group" aria-label="Tipo de movimiento">
                        <button type="button" @click="$wire.moveType = 'out'" :aria-pressed="$wire.moveType === 'out'">Sacar</button>
                        <button type="button" @click="$wire.moveType = 'in'" :aria-pressed="$wire.moveType === 'in'">Meter</button>
                    </div>
                    <div>
                        <label class="label" for="move-amount">Monto</label>
                        <x-money-input id="move-amount" x-model="$wire.moveAmount" placeholder="0" class="!min-h-14 !text-2xl" />
                    </div>
                    <div>
                        <label class="label" for="move-reason">Motivo <span class="font-normal text-muted">(opcional)</span></label>
                        <input id="move-reason" type="text" x-model="$wire.moveReason" maxlength="120" class="input" placeholder="Ej. depósito al banco, cambio para la caja">
                    </div>
                </form>
                <x-slot:footer>
                    <button type="submit" form="move-form" class="btn-primary btn-lg w-full" :disabled="! (Number($wire.moveAmount) > 0)">Guardar movimiento</button>
                </x-slot:footer>
            </x-sheet>
        </div>

        <section class="card space-y-4 !p-5">
            <div>
                <label class="label" for="counted">Efectivo contado</label>
                <x-money-input id="counted" wire:model="counted" placeholder="0" class="!min-h-14 !text-2xl" />
                <p class="hint">Cuenta billetes y monedas al cerrar.</p>
                @error('counted') <p class="error">{{ $message }}</p> @enderror
            </div>

            <div x-show="diff !== null" x-cloak class="flex items-center justify-between gap-3 rounded-xl px-4 py-3.5"
                 :class="Math.abs(diff) < 0.005 ? 'bg-salsa-soft text-salsa' : (diff > 0 ? 'bg-limon-soft text-ink' : 'bg-chile-soft text-chile')">
                <span class="flex items-center gap-2 text-sm font-bold">
                    <span x-show="Math.abs(diff) < 0.005"><x-icon name="check" class="size-5" /></span>
                    <span x-show="Math.abs(diff) >= 0.005"><x-icon name="alert" class="size-5" /></span>
                    <span x-text="Math.abs(diff) < 0.005 ? 'La caja cuadra' : (diff > 0 ? 'Sobra dinero' : 'Falta dinero')"></span>
                </span>
                <span x-show="Math.abs(diff) >= 0.005" class="num text-[2rem] leading-none font-bold" x-text="fmt(Math.abs(diff ?? 0))"></span>
            </div>

            <div>
                <label class="label" for="notes">Nota <span class="font-normal text-muted">(opcional)</span></label>
                <input id="notes" type="text" wire:model="notes" class="input" placeholder="Ej. se pagó el gas con efectivo de la caja">
            </div>

            <button type="button" wire:click="save" class="btn-primary btn-lg w-full" wire:loading.attr="disabled" wire:target="save">
                <span wire:loading.remove wire:target="save">{{ $this->saved ? 'Actualizar corte' : 'Guardar corte' }}</span>
                <span wire:loading wire:target="save">Guardando...</span>
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
