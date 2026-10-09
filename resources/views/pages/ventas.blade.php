<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use Carbon\CarbonImmutable;
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

    public function updatedDate(): void
    {
        $this->open = null;
    }

    public function shift(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
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

@php
    $day = \Carbon\CarbonImmutable::parse($date);
    $paid = $this->paid;
    $total = (float) $paid->sum('total');
    $byMethod = collect(PaymentMethod::cases())->map(fn ($m) => [
        'method' => $m,
        'total' => (float) $paid->where('payment_method', $m)->sum('total'),
    ])->filter(fn ($m) => $m['total'] > 0);
    $cancelled = $this->orders->count() - $paid->count();
@endphp

<div>
    <x-page-header title="Ventas">
        <x-slot:actions>
            <a href="{{ route('corte') }}" wire:navigate class="btn"><x-icon name="banknote" class="size-4" /> Corte</a>
        </x-slot:actions>
        <x-date-nav :label="$this->dayLabel" :detail="$day->translatedFormat('j M')" :max="today()->toDateString()" :at-max="$day->gte(today())" />
    </x-page-header>

    <div class="space-y-4">
        @if ($this->orders->isNotEmpty())
        <section class="card !p-5">
            <div class="flex items-end justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-muted">Vendido</p>
                    <p class="num text-[3rem] leading-none font-bold">{{ money($total) }}</p>
                </div>
                <p class="pb-1 text-right text-sm text-muted">
                    <span class="num block text-2xl leading-none font-bold text-ink">{{ $paid->count() }}</span>
                    {{ $paid->count() === 1 ? 'orden' : 'órdenes' }}
                </p>
            </div>
            @if ($total > 0)
                <div class="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
                    @foreach ($byMethod as $row)
                        <span class="h-full {{ $row['method'] === PaymentMethod::Cash ? 'bg-ink' : ($row['method'] === PaymentMethod::Transfer ? 'bg-sales' : 'bg-spend') }}" style="width: {{ $row['total'] / $total * 100 }}%"></span>
                    @endforeach
                </div>
                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    @foreach ($byMethod as $row)
                        <div class="flex items-center gap-2">
                            <span class="size-2.5 rounded-sm {{ $row['method'] === PaymentMethod::Cash ? 'bg-ink' : ($row['method'] === PaymentMethod::Transfer ? 'bg-sales' : 'bg-spend') }}"></span>
                            <dt class="text-muted">{{ $row['method']->label() }}</dt>
                            <dd class="num text-base font-bold">{{ money($row['total']) }}</dd>
                        </div>
                    @endforeach
                </dl>
            @endif
        </section>
        @endif

        @if ($this->orders->isEmpty())
            <x-empty icon="receipt" title="{{ $this->dayLabel === 'Hoy' ? 'Todavía no hay ventas hoy' : 'Sin ventas este día' }}">
                @if ($this->dayLabel === 'Hoy')
                    Cada pedido que cobres aparecerá aquí, con su hora y forma de pago.
                    <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Ir a vender</a></x-slot:action>
                @else
                    No se registraron órdenes en esta fecha.
                @endif
            </x-empty>
        @else
            <div class="flex items-baseline justify-between px-1">
                <h2 class="section-title">Órdenes</h2>
                @if ($cancelled > 0)
                    <span class="text-sm text-muted">{{ $cancelled }} {{ $cancelled === 1 ? 'cancelada' : 'canceladas' }}</span>
                @endif
            </div>
            <ul class="list">
                @foreach ($this->orders as $order)
                    @php
                        $isCancelled = $order->status === OrderStatus::Cancelled;
                        $cost = $order->items->sum(fn ($i) => $i->quantity * (float) $i->unit_cost);
                        $isOpen = $open === $order->id;
                    @endphp
                    <li wire:key="o-{{ $order->id }}">
                        <button type="button" wire:click="toggle({{ $order->id }})" class="flex w-full items-center gap-3 px-4 py-3.5 text-left hover:bg-sunken/50" aria-expanded="{{ $isOpen ? 'true' : 'false' }}">
                            <span class="num w-8 shrink-0 text-center text-lg font-bold text-muted">{{ $order->number }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-[15px] font-semibold', 'text-muted line-through' => $isCancelled])>
                                    {{ $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}
                                </span>
                                <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                                    <span>{{ $order->sold_at->format('g:i a') }}</span>
                                    @if ($order->payment_method !== PaymentMethod::Cash)
                                        <span class="badge bg-sales/12 text-sales">{{ $order->payment_method->label() }}</span>
                                    @endif
                                    @if ($isCancelled) <span class="badge bg-chile-soft text-chile">Cancelada</span> @endif
                                </span>
                            </span>
                            <span @class(['num text-[1.375rem] font-bold', 'text-muted line-through' => $isCancelled])>{{ money($order->total) }}</span>
                            <x-icon name="down" class="size-4 shrink-0 text-muted transition-transform {{ $isOpen ? 'rotate-180' : '' }}" />
                        </button>

                        @if ($isOpen)
                            <div class="space-y-3 border-t border-line bg-sunken/40 px-4 py-3.5 pl-15 text-sm">
                                <ul class="space-y-1.5">
                                    @foreach ($order->items as $item)
                                        <li class="flex justify-between gap-3">
                                            <span><span class="num font-bold">{{ $item->quantity }}</span> × {{ $item->product_name }} <span class="text-muted">a {{ money($item->unit_price) }}</span></span>
                                            <span class="num text-base font-semibold">{{ money($item->subtotal) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($cost > 0 && ! $isCancelled)
                                    <p class="flex justify-between border-t border-line pt-2 text-muted">Ganancia por receta <span class="num text-base font-bold text-salsa">{{ money((float) $order->total - $cost) }}</span></p>
                                @endif
                                @if ($order->payment_reference)
                                    <p class="text-muted">Referencia: <span class="font-semibold text-ink">{{ $order->payment_reference }}</span></p>
                                @endif
                                <div class="flex justify-end">
                                    @if ($isCancelled)
                                        <button type="button" wire:click="restore({{ $order->id }})" class="btn">Restaurar venta</button>
                                    @else
                                        <button type="button" wire:click="cancel({{ $order->id }})" wire:confirm="¿Cancelar esta venta? Dejará de contar en los totales." class="btn-quiet-danger -mr-2">
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
    </div>
</div>
