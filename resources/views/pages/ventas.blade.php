<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ventas')] class extends Component
{
    public string $date = '';

    public ?int $open = null;

    public function mount(): void
    {
        $this->date = today()->toDateString();
    }

    public function shift(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
        $this->open = null;
    }

    public function today(): void
    {
        $this->date = today()->toDateString();
        $this->open = null;
    }

    public function toggle(int $id): void
    {
        $this->open = $this->open === $id ? null : $id;
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
    public function orders()
    {
        $day = $this->day();

        return Order::with('items')
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->orderBy('sold_at')
            ->get()
            ->each(fn ($o, $i) => $o->number = $i + 1)
            ->reverse()
            ->values();
    }

    #[Computed]
    public function paid()
    {
        return $this->orders->where('status', OrderStatus::Paid);
    }

    public function cancel(int $id): void
    {
        Order::whereKey($id)->update(['status' => OrderStatus::Cancelled]);
        unset($this->orders, $this->paid);
        $this->dispatch('toast', message: 'Venta cancelada');
    }

    public function restore(int $id): void
    {
        Order::whereKey($id)->update(['status' => OrderStatus::Paid]);
        unset($this->orders, $this->paid);
        $this->dispatch('toast', message: 'Venta restaurada');
    }
};
?>

<div class="space-y-5">
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn size-11 !px-0" aria-label="Día anterior"><x-icon name="left" /></button>
        <label class="relative flex-1">
            <span class="sr-only">Fecha</span>
            <input type="date" wire:model.live="date" class="input text-center font-semibold" max="{{ today()->toDateString() }}">
        </label>
        <button type="button" wire:click="shift(1)" class="btn size-11 !px-0" aria-label="Día siguiente" @disabled($date >= today()->toDateString())><x-icon name="right" /></button>
        @unless ($this->dayLabel === 'Hoy')
            <button type="button" wire:click="today" class="btn">Hoy</button>
        @endunless
    </div>

    @php
        $paid = $this->paid;
        $methods = collect(PaymentMethod::cases())->map(fn ($m) => [
            'label' => $m->label(),
            'total' => (float) $paid->where('payment_method', $m)->sum('total'),
            'count' => $paid->where('payment_method', $m)->count(),
        ])->filter(fn ($m) => $m['count'] > 0);
    @endphp

    <div class="card">
        <p class="text-sm font-medium text-muted">{{ $this->dayLabel }}</p>
        <div class="flex items-end justify-between gap-3">
            <p class="num text-5xl font-bold leading-none">{{ Number::currency((float) $paid->sum('total')) }}</p>
            <p class="pb-1 text-sm text-muted">{{ $paid->count() }} {{ $paid->count() === 1 ? 'orden' : 'órdenes' }}</p>
        </div>
        @if ($methods->isNotEmpty())
            <div class="mt-3 flex flex-wrap gap-x-5 gap-y-1 border-t border-line pt-3 text-sm">
                @foreach ($methods as $m)
                    <span class="text-muted">{{ $m['label'] }} <span class="num text-base font-bold text-ink">{{ Number::currency($m['total']) }}</span></span>
                @endforeach
            </div>
        @endif
    </div>

    @if ($this->orders->isEmpty())
        <x-empty icon="receipt" title="Sin ventas este día">
            @if ($this->dayLabel === 'Hoy')
                Cuando cobres un pedido aparecerá aquí.
                <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Ir a vender</a></x-slot:action>
            @else
                No hay órdenes registradas en esta fecha.
            @endif
        </x-empty>
    @else
        <ul class="space-y-2">
            @foreach ($this->orders as $order)
                @php
                    $cancelled = $order->status === OrderStatus::Cancelled;
                    $cost = $order->items->sum(fn ($i) => $i->quantity * (float) $i->unit_cost);
                @endphp
                <li wire:key="o-{{ $order->id }}" class="card !p-0 overflow-hidden">
                    <button type="button" wire:click="toggle({{ $order->id }})" class="flex w-full items-center gap-3 p-4 text-left" aria-expanded="{{ $open === $order->id ? 'true' : 'false' }}">
                        <span class="num grid size-11 shrink-0 place-items-center rounded-xl bg-sunken text-xl font-bold text-muted">{{ $order->number }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-semibold {{ $cancelled ? 'line-through opacity-60' : '' }}">
                                {{ $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}
                            </span>
                            <span class="block text-sm text-muted">
                                {{ $order->sold_at->format('g:i a') }} · {{ $order->payment_method->label() }}
                                @if ($cancelled) <span class="badge bg-chile-soft text-chile">Cancelada</span> @endif
                            </span>
                        </span>
                        <span class="num text-2xl font-bold {{ $cancelled ? 'line-through opacity-50' : '' }}">{{ Number::currency((float) $order->total) }}</span>
                    </button>

                    @if ($open === $order->id)
                        <div class="space-y-3 border-t border-line bg-sunken/50 p-4 text-sm">
                            <ul class="space-y-1">
                                @foreach ($order->items as $item)
                                    <li class="flex justify-between gap-3">
                                        <span>{{ $item->quantity }} × {{ $item->product_name }}</span>
                                        <span class="num text-base font-semibold">{{ Number::currency((float) $item->subtotal) }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            @if ($cost > 0 && ! $cancelled)
                                <p class="text-muted">Ganancia por receta: <span class="font-semibold text-salsa">{{ Number::currency((float) $order->total - $cost) }}</span></p>
                            @endif
                            @if ($order->payment_reference)
                                <p class="text-muted">Referencia: {{ $order->payment_reference }}</p>
                            @endif
                            <div class="flex justify-end">
                                @if ($cancelled)
                                    <button type="button" wire:click="restore({{ $order->id }})" class="btn">Restaurar venta</button>
                                @else
                                    <button type="button" wire:click="cancel({{ $order->id }})" wire:confirm="¿Cancelar esta venta? Dejará de contar en los totales." class="btn-quiet-danger">
                                        <x-icon name="x" class="size-4" /> Cancelar venta
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    <div class="text-center">
        <a href="{{ route('corte') }}" wire:navigate class="btn-quiet"><x-icon name="banknote" class="size-4" /> Hacer corte de caja</a>
    </div>
</div>
