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

        return Order::with(['items', 'payments'])
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
        $order = Order::findOrFail($id);
        $order->update(['status' => $order->payment_method ? OrderStatus::Paid : OrderStatus::Open]);
        unset($this->orders, $this->paid);
        $this->dispatch('toast', message: 'Venta restaurada');
    }
};
?>

@php
    $paid = $this->paid;
    $total = (float) $paid->sum('total');
    // Por forma de pago (un pago mixto cuenta en las dos). Incluye propinas.
    $payments = $paid->flatMap->payments;
    $byMethod = collect(PaymentMethod::simple())->map(fn ($m) => [
        'method' => $m,
        'total' => (float) $payments->where('method', $m)->sum('amount'),
    ])->filter(fn ($m) => $m['total'] > 0);
    $collected = (float) $payments->sum('amount');
    $tips = (float) $paid->sum('tip');
    $discounts = (float) $paid->sum('discount') + $paid->flatMap->items->sum(fn ($i) => $i->courtesyValue());
    $cancelled = $this->orders->where('status', OrderStatus::Cancelled)->count();
    $openCount = $this->orders->where('status', OrderStatus::Open)->count();
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
                            <span class="h-full {{ $color($row['method']) }}" style="width: {{ $collected > 0 ? $row['total'] / $collected * 100 : 0 }}%"></span>
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
                @if ($tips > 0 || $discounts > 0)
                    <p class="mt-3 flex flex-wrap gap-x-5 gap-y-1 border-t border-line pt-3 text-sm text-muted">
                        @if ($tips > 0) <span>Propinas <span class="num text-base font-bold text-ink">{{ money($tips) }}</span></span> @endif
                        @if ($discounts > 0) <span>Descuentos y cortesías <span class="num text-base font-bold text-ink">{{ money($discounts) }}</span></span> @endif
                    </p>
                @endif
            </section>

            <div class="flex items-baseline justify-between px-1">
                <h2 class="section-title">Órdenes</h2>
                <span class="text-sm text-muted">
                    @if ($openCount > 0) <a href="{{ route('pedidos') }}" wire:navigate class="font-semibold text-chile underline">{{ $openCount }} por cobrar</a>@endif
                    @if ($openCount > 0 && $cancelled > 0), @endif
                    @if ($cancelled > 0) {{ $cancelled }} {{ $cancelled === 1 ? 'cancelada' : 'canceladas' }} @endif
                </span>
            </div>
            <ul class="list">
                @foreach ($this->orders as $order)
                    @php
                        $isCancelled = $order->status === OrderStatus::Cancelled;
                        $cost = $order->items->sum(fn ($i) => $i->quantity * (float) $i->unit_cost);
                    @endphp
                    <li wire:key="o-{{ $order->id }}" x-data="{ open: false }">
                        @php
                            $isOpenOrder = $order->status === OrderStatus::Open;
                            $summary = $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ');
                        @endphp
                        <button type="button" @click="open = !open" class="flex w-full items-center gap-3 px-4 py-3.5 text-left hover:bg-sunken/50" :aria-expanded="open">
                            <span class="num w-8 shrink-0 text-center text-lg font-bold text-muted">{{ $order->daily_number ?? $order->number }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-[15px] font-semibold', 'text-muted line-through' => $isCancelled])>
                                    {{ $order->label ?: $summary }}
                                </span>
                                <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                                    <span class="shrink-0">{{ $order->sold_at->format('g:i a') }}</span>
                                    @if ($isOpenOrder)
                                        <span class="badge bg-limon-soft text-ink">Por cobrar</span>
                                    @elseif ($order->payment_method && $order->payment_method !== PaymentMethod::Cash)
                                        <span class="badge bg-naranja-soft text-ink">{{ $order->payment_method->label() }}</span>
                                    @endif
                                    @if ($isCancelled) <span class="badge bg-chile-soft text-chile">Cancelada</span> @endif
                                    @if ($order->label) <span class="truncate">{{ $summary }}</span> @endif
                                </span>
                            </span>
                            <span @class(['num text-[1.375rem] font-bold', 'text-muted line-through' => $isCancelled, 'text-muted' => $isOpenOrder])>{{ money($order->total) }}</span>
                            <x-icon name="down" class="size-4 shrink-0 text-muted transition-transform duration-200" ::class="open && 'rotate-180'" />
                        </button>

                        <div x-show="open" x-collapse x-cloak>
                            <div class="space-y-3 border-t border-line bg-sunken/40 px-4 py-3.5 pl-15 text-sm">
                                <ul class="space-y-1.5">
                                    @foreach ($order->items as $item)
                                        <li class="flex justify-between gap-3">
                                            <span>
                                                <span class="num font-bold">{{ $item->quantity }}</span> × {{ $item->product_name }} <span class="text-muted">a {{ money($item->unit_price) }}</span>
                                                @if ($item->modifiers || $item->notes)
                                                    <span class="mt-0.5 flex flex-wrap gap-1">
                                                        @if ($item->isCourtesy()) <span class="badge bg-ink text-bg">Cortesía</span> @endif
                                                        @foreach ($item->choices() as $choice) <span class="badge bg-limon-soft text-ink">{{ $choice['name'] }}</span> @endforeach
                                                        @foreach ($item->withoutNames() as $name) <span class="badge bg-chile-soft text-chile">Sin {{ mb_strtolower($name) }}</span> @endforeach
                                                        @foreach ($item->extras() as $extra) <span class="badge bg-salsa-soft text-salsa">+ {{ $extra['name'] }}</span> @endforeach
                                                        @if ($item->notes) <span class="text-muted italic">{{ $item->notes }}</span> @endif
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="num text-base font-semibold">{{ money($item->subtotal) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ((float) $order->packaging_fee > 0 || (float) $order->discount > 0 || (float) $order->tip > 0 || $order->payments->count() > 1)
                                    <div class="space-y-1 border-t border-line pt-2 text-muted">
                                        @if ((float) $order->packaging_fee > 0) <p class="flex justify-between">Empaque <span class="num text-base font-semibold text-ink">{{ money($order->packaging_fee) }}</span></p> @endif
                                        @if ((float) $order->discount > 0) <p class="flex justify-between">Descuento <span class="num text-base font-semibold text-salsa">−{{ money($order->discount) }}</span></p> @endif
                                        @if ((float) $order->tip > 0) <p class="flex justify-between">Propina <span class="num text-base font-semibold text-ink">{{ money($order->tip) }}</span></p> @endif
                                        @if ($order->payments->count() > 1)
                                            @foreach ($order->payments as $pay)
                                                <p class="flex justify-between">Pagó con {{ mb_strtolower($pay->method->label()) }} <span class="num text-base font-semibold text-ink">{{ money($pay->amount) }}</span></p>
                                            @endforeach
                                        @endif
                                    </div>
                                @endif
                                @if ($cost > 0 && ! $isCancelled)
                                    <p class="flex justify-between border-t border-line pt-2 text-muted">Ganancia por receta <span class="num text-base font-bold text-salsa">{{ money((float) $order->total - $cost) }}</span></p>
                                @endif
                                @if ($order->payment_reference)
                                    <p class="text-muted">Referencia: <span class="font-semibold text-ink">{{ $order->payment_reference }}</span></p>
                                @endif
                                <div class="flex justify-end">
                                    @if ($isOpenOrder)
                                        <a href="{{ route('vender', ['orden' => $order->id, 'cobrar' => 1]) }}" wire:navigate class="btn">Cobrar</a>
                                    @elseif ($isCancelled)
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
