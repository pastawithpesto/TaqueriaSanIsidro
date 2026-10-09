<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pedidos')] class extends Component
{
    /** Activos: primero el que lleva más tiempo esperando. */
    #[Computed]
    public function pending()
    {
        return Order::with('items')->pending()->dueBetween(today())->orderByRaw('COALESCE(pickup_at, sold_at)')->get();
    }

    /** Guardados sin cobrar de días anteriores: ya no se preparan, falta cobrarlos o cancelarlos. */
    #[Computed]
    public function stale()
    {
        return Order::with('items')->where('status', OrderStatus::Open)->dueBetween(null, today())->orderByRaw('COALESCE(pickup_at, sold_at)')->get();
    }

    #[Computed]
    public function delivered()
    {
        return Order::with('items')
            ->where('status', OrderStatus::Paid)
            ->where('delivered_at', '>=', today())
            ->orderByDesc('delivered_at')
            ->limit(15)
            ->get();
    }

    public function deliver(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->update(['delivered_at' => now()]);
        unset($this->pending, $this->delivered);

        $this->dispatch('toast', message: 'Entregado #'.$order->daily_number.($order->label ? ' '.$order->label : ''));
    }

    /** A domicilio: sale con el repartidor. */
    public function dispatchOrder(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->update(['dispatched_at' => now()]);
        unset($this->pending);
        $this->dispatch('toast', message: '#'.$order->daily_number.' en camino');
    }

    public function undeliver(int $id): void
    {
        Order::whereKey($id)->update(['delivered_at' => null]);
        unset($this->pending, $this->delivered);
    }

    public function cancel(int $id): void
    {
        Order::whereKey($id)->where('status', OrderStatus::Open)->update(['status' => OrderStatus::Cancelled]);
        unset($this->pending, $this->stale);
        $this->dispatch('toast', message: 'Pedido cancelado');
    }
};
?>

<div wire:poll.15s.visible x-data="{ now: Date.now() }" x-init="setInterval(() => now = Date.now(), 30000)">
    <x-page-header title="Pedidos" tour="pedidos">
        <x-slot:actions>
            <a href="{{ route('cocina') }}" class="btn" data-tour="cocina"><x-icon name="flame" class="size-4" /> Cocina</a>
            <a href="{{ route('vender') }}" wire:navigate class="btn-primary" data-tour="nuevo-pedido"><x-icon name="plus" class="size-4" /> Pedido</a>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-6">
        @if ($this->pending->isEmpty())
            <x-empty icon="orders" title="No hay pedidos pendientes" data-tour="pedidos-vacio">
                Aquí aparecen los pedidos por entregar y los que guardaste sin cobrar, con su nombre e indicaciones.
                <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Tomar un pedido</a></x-slot:action>
            </x-empty>
        @else
            <ul class="grid gap-3 lg:grid-cols-2">
                @foreach ($this->pending as $order)
                    @php
                        $open = $order->status === OrderStatus::Open;
                        $delivered = $order->delivered_at !== null;
                        $since = $order->sold_at->getTimestampMs();
                        $pickup = $order->pickup_at?->getTimestampMs();
                    @endphp
                    <li wire:key="po-{{ $order->id }}" class="card flex flex-col !p-0" @if ($loop->first) data-tour="pedido" @endif>
                        <div class="flex items-start gap-3 px-4 pt-4">
                            <span class="num grid size-12 shrink-0 place-items-center rounded-xl bg-limon text-2xl font-bold text-on-limon">{{ $order->daily_number }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-display text-[1.5rem] leading-tight font-bold">{{ $order->label ?: 'Sin nombre' }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm">
                                    @if ($open)
                                        <span class="badge bg-limon-soft text-ink">Por cobrar</span>
                                    @else
                                        <span class="badge bg-salsa-soft text-salsa">Pagado</span>
                                    @endif
                                    @if ($order->serviceLabel())
                                        <span @class(['badge', 'bg-naranja text-on-limon' => $order->isDelivery(), 'bg-sunken text-ink' => ! $order->isDelivery()])>{{ $order->serviceLabel() }}</span>
                                    @endif
                                    @if ($order->dispatched_at && ! $delivered)
                                        <span class="badge bg-naranja text-on-limon">En camino</span>
                                    @endif
                                    @if ($order->ready_at && ! $delivered)
                                        <span class="badge bg-salsa text-on-salsa">Listo</span>
                                    @endif
                                    @if ($delivered)
                                        <span class="badge bg-sunken text-muted">Ya entregado</span>
                                    @elseif ($pickup)
                                        <span class="badge bg-naranja-soft text-ink"
                                              :class="({{ $pickup }} - now) < 10 * 60000 && '!bg-chile !text-white'">
                                            <x-icon name="clock" class="size-3.5" />
                                            Recoge {{ $order->pickup_at->format('g:i a') }}
                                            <span x-text="(() => { const m = Math.round(({{ $pickup }} - now) / 60000); return m <= 0 ? '(ya)' : (m < 60 ? '(en ' + m + ' min)' : '(en ' + Math.floor(m / 60) + ' h ' + (m % 60) + ' min)') })()"></span>
                                        </span>
                                    @else
                                        <span class="flex items-center gap-1"
                                              :class="(now - {{ $since }}) > 15 * 60000 ? 'font-semibold text-chile' : 'text-muted'">
                                            <x-icon name="clock" class="size-3.5" />
                                            <span x-text="(() => { const m = Math.max(0, Math.floor((now - {{ $since }}) / 60000)); return m < 1 ? 'recién' : (m < 60 ? 'hace ' + m + ' min' : 'hace ' + Math.floor(m / 60) + ' h ' + (m % 60) + ' min') })()">{{ $order->sold_at->diffForHumans() }}</span>
                                        </span>
                                    @endif
                                </p>
                            </div>
                            <span class="num text-[1.5rem] font-bold">{{ money($order->total) }}</span>
                        </div>

                        @if ($order->isDelivery())
                            <div class="mx-4 mt-3 space-y-2 rounded-xl bg-naranja-soft/70 px-3 py-2.5">
                                <p class="text-[15px] leading-snug font-semibold">{{ $order->delivery_address }}</p>
                                @if ($order->delivery_notes) <p class="text-sm text-muted">{{ $order->delivery_notes }}</p> @endif
                                <div class="flex flex-wrap gap-2">
                                    @if ($order->delivery_phone)
                                        <a href="tel:{{ preg_replace('/[^\d+]/', '', $order->delivery_phone) }}" class="btn !min-h-9 !px-3"><x-icon name="phone" class="size-4" /> Llamar</a>
                                    @endif
                                    @if ($order->whatsappNumber())
                                        <a href="https://wa.me/{{ $order->whatsappNumber() }}" target="_blank" rel="noopener" class="btn !min-h-9 !px-3"><x-icon name="chat" class="size-4" /> WhatsApp</a>
                                    @endif
                                    <a href="https://www.google.com/maps/search/?api=1&query={{ urlencode($order->delivery_address) }}" target="_blank" rel="noopener" class="btn !min-h-9 !px-3"><x-icon name="pin" class="size-4" /> Mapa</a>
                                </div>
                            </div>
                        @endif

                        <ul class="mt-3 flex-1 space-y-2 border-t border-line px-4 py-3">
                            @foreach ($order->items as $item)
                                <li class="flex gap-3">
                                    <span class="num w-7 shrink-0 text-2xl leading-none">{{ $item->quantity }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-[17px] leading-tight font-semibold">{{ $item->product_name }}</span>
                                        @if ($item->modifiers || $item->notes)
                                            <span class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($item->choices() as $choice)
                                                    <span class="badge bg-limon text-on-limon !text-[13px]">{{ $choice['name'] }}</span>
                                                @endforeach
                                                @foreach ($item->withoutNames() as $name)
                                                    <span class="badge bg-chile text-white !text-[13px]">Sin {{ mb_strtolower($name) }}</span>
                                                @endforeach
                                                @foreach ($item->extras() as $extra)
                                                    <span class="badge bg-salsa text-on-salsa !text-[13px]">+ {{ $extra['name'] }}</span>
                                                @endforeach
                                                @if ($item->notes)
                                                    <span class="text-sm text-ink italic">"{{ $item->notes }}"</span>
                                                @endif
                                            </span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="flex items-center gap-2 border-t border-line px-3 py-3" @if ($loop->first) data-tour="pedido-acciones" @endif>
                            @if ($open)
                                <div x-data="{ menu: false }" class="relative">
                                    <button type="button" @click="menu = !menu" @click.outside="menu = false" class="btn-icon" aria-label="Más opciones"><x-icon name="more" /></button>
                                    <div x-show="menu" x-cloak x-transition.origin.bottom.left class="menu !top-auto !right-auto bottom-12 left-0" @click="menu = false">
                                        <a href="{{ route('vender', ['orden' => $order->id]) }}" wire:navigate><x-icon name="pencil" class="size-4 text-muted" /> Editar pedido</a>
                                        <button type="button" class="text-chile"
                                                @click="if (await $store.confirm.ask({ title: @js('¿Cancelar el pedido #'.$order->daily_number.'?'), message: 'No se ha cobrado. Se quitará de la lista.', action: 'Cancelar pedido', cancel: 'No' })) $wire.cancel({{ $order->id }})">
                                            <x-icon name="x" class="size-4" /> Cancelar pedido
                                        </button>
                                    </div>
                                </div>
                                <a href="{{ route('vender', ['orden' => $order->id, 'cobrar' => 1]) }}" wire:navigate class="btn flex-1">Cobrar</a>
                            @endif
                            @if (! $delivered && $order->isDelivery() && ! $order->dispatched_at)
                                <button type="button" wire:click="dispatchOrder({{ $order->id }})" class="btn-primary flex-1 !border-naranja !bg-naranja !text-on-limon hover:!opacity-90">
                                    <x-icon name="right" class="size-4" /> Enviar
                                </button>
                            @elseif (! $delivered)
                                <button type="button" wire:click="deliver({{ $order->id }})" class="btn-primary flex-1 !bg-salsa !border-salsa !text-on-salsa hover:!opacity-90">
                                    <x-icon name="check" class="size-4" /> Entregado
                                </button>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($this->stale->isNotEmpty())
            <section class="space-y-2" x-data="{ open: false }" data-tour="pedidos-anteriores">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between px-1" :aria-expanded="open">
                    <h2 class="section-title">Sin cobrar de días anteriores</h2>
                    <span class="flex items-center gap-1.5 text-sm text-muted">{{ $this->stale->count() }} <x-icon name="down" class="size-4 transition-transform" ::class="open && 'rotate-180'" /></span>
                </button>
                <ul class="list" x-show="open" x-collapse x-cloak>
                    @foreach ($this->stale as $order)
                        <li wire:key="ps-{{ $order->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                            <span class="num w-8 text-center text-lg font-bold text-muted">{{ $order->daily_number }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $order->label ?: $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}</span>
                                <span class="block text-sm text-muted">{{ ucfirst($order->dueAt()->translatedFormat('l j M, g:i a')) }}, {{ money($order->total) }}</span>
                            </span>
                            <a href="{{ route('vender', ['orden' => $order->id, 'cobrar' => 1]) }}" wire:navigate class="btn !min-h-9 !px-3">Cobrar</a>
                            <button type="button" class="btn-quiet size-10 justify-center !px-0 text-chile" aria-label="Cancelar pedido #{{ $order->daily_number }}"
                                    @click="if (await $store.confirm.ask({ title: @js('¿Cancelar el pedido #'.$order->daily_number.'?'), message: 'No se ha cobrado. Se quitará de la lista.', action: 'Cancelar pedido', cancel: 'No' })) $wire.cancel({{ $order->id }})">
                                <x-icon name="x" class="size-4" />
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->delivered->isNotEmpty())
            <section class="space-y-2" x-data="{ open: false }" data-tour="entregados">
                <button type="button" @click="open = !open" class="flex w-full items-center justify-between px-1" :aria-expanded="open">
                    <h2 class="section-title">Entregados hoy</h2>
                    <span class="flex items-center gap-1.5 text-sm text-muted">{{ $this->delivered->count() }} <x-icon name="down" class="size-4 transition-transform" ::class="open && 'rotate-180'" /></span>
                </button>
                <ul class="list" x-show="open" x-collapse x-cloak>
                    @foreach ($this->delivered as $order)
                        <li wire:key="pd-{{ $order->id }}" class="flex items-center gap-3 px-4 py-3">
                            <span class="num w-8 text-center text-lg font-bold text-muted">{{ $order->daily_number }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $order->label ?: $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}</span>
                                <span class="block text-sm text-muted">Entregado {{ $order->delivered_at->format('g:i a') }}</span>
                            </span>
                            <button type="button" wire:click="undeliver({{ $order->id }})" class="btn-quiet"><x-icon name="undo" class="size-4" /> Regresar</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>
</div>
