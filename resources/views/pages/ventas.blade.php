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

    public function mount(): void
    {
        $this->date = today()->toDateString();
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
    $paid = $this->paid;
    $total = (float) $paid->sum('total');
    $byMethod = collect(PaymentMethod::cases())->map(fn ($m) => [
        'method' => $m,
        'total' => (float) $paid->where('payment_method', $m)->sum('total'),
    ])->filter(fn ($m) => $m['total'] > 0);
    $cancelled = $this->orders->count() - $paid->count();
    $isToday = $this->dayLabel === 'Hoy';
    $color = fn ($m) => $m === PaymentMethod::Cash ? 'bg-ink' : ($m === PaymentMethod::Transfer ? 'bg-naranja' : 'bg-limon');
@endphp

<div>
    <x-page-header title="Ventas">
        <x-slot:actions>
            <a href="{{ route('corte') }}" wire:navigate class="btn"><x-icon name="banknote" class="size-4" /> Corte</a>
        </x-slot:actions>
        <x-date-nav :max="today()->toDateString()" />
    </x-page-header>

    {{-- Mientras llega el nuevo día --}}
    <div wire:loading.delay.short wire:target="date" class="w-full space-y-4">
        <div class="card h-[8.5rem] animate-pulse !bg-sunken/60"></div>
        <x-skeleton-list :rows="5" />
    </div>

    <div wire:loading.remove.delay.short wire:target="date" class="space-y-4">
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
                            <span class="h-full {{ $color($row['method']) }}" style="width: {{ $row['total'] / $total * 100 }}%"></span>
                        @endforeach
                    </div>
                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        @foreach ($byMethod as $row)
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-sm {{ $color($row['method']) }}"></span>
                                <dt class="text-muted">{{ $row['method']->label() }}</dt>
                                <dd class="num text-base font-bold">{{ money($row['total']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
            </section>

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
                    @endphp
                    <li wire:key="o-{{ $order->id }}" x-data="{ open: false }">
                        <button type="button" @click="open = !open" class="flex w-full items-center gap-3 px-4 py-3.5 text-left hover:bg-sunken/50" :aria-expanded="open">
                            <span class="num w-8 shrink-0 text-center text-lg font-bold text-muted">{{ $order->number }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-[15px] font-semibold', 'text-muted line-through' => $isCancelled])>
                                    {{ $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}
                                </span>
                                <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                                    <span>{{ $order->sold_at->format('g:i a') }}</span>
                                    @if ($order->payment_method !== PaymentMethod::Cash)
                                        <span class="badge bg-naranja-soft text-ink">{{ $order->payment_method->label() }}</span>
                                    @endif
                                    @if ($isCancelled) <span class="badge bg-chile-soft text-chile">Cancelada</span> @endif
                                </span>
                            </span>
                            <span @class(['num text-[1.375rem] font-bold', 'text-muted line-through' => $isCancelled])>{{ money($order->total) }}</span>
                            <x-icon name="down" class="size-4 shrink-0 text-muted transition-transform duration-200" ::class="open && 'rotate-180'" />
                        </button>

                        <div x-show="open" x-collapse x-cloak>
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
                                        <button type="button" class="btn-quiet-danger -mr-2"
                                                @click="if (await $store.confirm.ask({ title: '¿Cancelar la orden {{ $order->number }}?', message: 'Dejará de contar en ventas y en el corte. Puedes restaurarla después.', action: 'Cancelar orden', cancel: 'No' })) $wire.cancel({{ $order->id }})">
                                            <x-icon name="x" class="size-4" /> Cancelar venta
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <x-empty icon="receipt" :title="$isToday ? 'Todavía no hay ventas hoy' : 'Sin ventas este día'">
                @if ($isToday)
                    Cada pedido que cobres aparecerá aquí, con su hora y forma de pago.
                    <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Ir a vender</a></x-slot:action>
                @else
                    No se registraron órdenes en esta fecha.
                @endif
            </x-empty>
        @endif
    </div>
</div>
