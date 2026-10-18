<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\BusinessDay;
use App\Support\OnlineOrder;
use App\Support\OrderFlow;
use App\Support\Store;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pedidos')] class extends Component
{
    /** Cómo está la tienda para los clientes, con un acceso directo para cambiarlo. */
    #[Computed]
    public function store(): array
    {
        return ['open' => Store::isOpen(), 'online' => Store::onlineEnabled(), 'delivery' => Store::deliveryEnabled()];
    }

    /** Pedidos que hicieron los clientes en línea y esperan una respuesta: el que lleva más tiempo, primero. */
    #[Computed]
    public function requested()
    {
        OnlineOrder::expireStale();

        return Order::with('items')->requested()->orderBy('created_at')->get();
    }

    public function accept(int $id): void
    {
        $order = Order::requested()->find($id);

        if (! $order || ! OnlineOrder::accept($order)) {
            $this->dispatch('toast', message: 'Ese pedido ya no está por aceptar. Recarga la lista.', type: 'error');
        } else {
            $this->dispatch('toast', message: 'Pedido de '.$order->label.' aceptado: es el #'.$order->fresh()->daily_number.'.');
        }

        unset($this->requested, $this->pending);
        $this->dispatch('pending-count', count: Order::pendingCount());
    }

    public function reject(int $id, string $reason = ''): void
    {
        $order = Order::requested()->find($id);

        if (! $order || ! OnlineOrder::reject($order, $reason)) {
            $this->dispatch('toast', message: 'Ese pedido ya no está por aceptar. Recarga la lista.', type: 'error');
        } else {
            $this->dispatch('toast', message: 'Pedido de '.$order->label.' rechazado. Se lo decimos en su página.');
        }

        unset($this->requested);
        $this->dispatch('pending-count', count: Order::pendingCount());
    }

    /** Activos: primero el que lleva más tiempo esperando. */
    #[Computed]
    public function pending()
    {
        return Order::with('items')->pending()->dueBetween(BusinessDay::startOfToday())->orderByRaw('COALESCE(pickup_at, sold_at)')->get();
    }

    /** Guardados sin cobrar de días anteriores: ya no se preparan, falta cobrarlos o cancelarlos. */
    #[Computed]
    public function stale()
    {
        return Order::with('items')->where('status', OrderStatus::Open)->dueBetween(null, BusinessDay::startOfToday())->orderByRaw('COALESCE(pickup_at, sold_at)')->get();
    }

    #[Computed]
    public function delivered()
    {
        return Order::with('items')
            ->where('status', OrderStatus::Paid)
            ->where('delivered_at', '>=', BusinessDay::startOfToday())
            ->orderByDesc('delivered_at')
            ->limit(15)
            ->get();
    }

    public function deliver(int $id): void
    {
        $order = Order::find($id);

        if (! OrderFlow::deliver($id)) {
            $this->dispatch('toast', message: 'No se pudo marcar como entregado. Recarga la lista.', type: 'error');

            return;
        }
        unset($this->pending, $this->delivered);
        $this->dispatch('pending-count', count: Order::pendingCount());

        $this->dispatch('toast', message: 'Entregado #'.$order->daily_number.($order->label ? ' '.$order->label : ''));
    }

    /** A domicilio: sale con el repartidor. Si ya salió, ya está preparado: deja la cocina. */
    public function dispatchOrder(int $id): void
    {
        $order = Order::find($id);

        if (! OrderFlow::dispatch($id)) {
            $this->dispatch('toast', message: 'Este pedido no se puede enviar: ya salió, se canceló o no es a domicilio.', type: 'error');

            return;
        }
        unset($this->pending);
        $this->dispatch('toast', message: '#'.$order->daily_number.' en camino');
    }

    public function undeliver(int $id): void
    {
        OrderFlow::undeliver($id);
        unset($this->pending, $this->delivered);
        $this->dispatch('pending-count', count: Order::pendingCount());
    }

    public function cancel(int $id): void
    {
        if (! OrderFlow::cancel($id)) {
            $this->dispatch('toast', message: 'El pedido ya se cobró o se canceló. Recarga la lista.', type: 'error');

            return;
        }
        unset($this->pending, $this->stale);
        $this->dispatch('toast', message: 'Pedido cancelado');
        $this->dispatch('pending-count', count: Order::pendingCount());
    }
};
?>

<div wire:poll.15s.visible x-data="{ now: Date.now(), rejecting: null, reason: '' }" x-init="setInterval(() => now = Date.now(), 30000)"
     @online-order.window="$wire.$refresh()">
    <x-page-header title="Pedidos" tour="pedidos">
        <x-slot:actions>
            {{-- En el celular solo el ícono: con el texto, el título se cortaba a "P...". --}}
            <a href="{{ route('cocina') }}" class="btn max-sm:!px-3" data-tour="cocina" aria-label="Pantalla de cocina"><x-icon name="flame" class="size-4" /> <span class="max-sm:hidden">Cocina</span></a>
            <a href="{{ route('vender') }}" wire:navigate class="btn-primary" data-tour="nuevo-pedido"><x-icon name="plus" class="size-4" /> Pedido</a>
        </x-slot:actions>
    </x-page-header>

    <div class="space-y-6">
        <a href="{{ route('tienda') }}" wire:navigate class="flex items-center gap-2.5 rounded-2xl border border-line bg-surface px-4 py-3 text-sm" data-tour="pedidos-tienda">
            <x-icon name="store" class="size-5 shrink-0 text-muted" />
            <span class="min-w-0 flex-1">
                <span class="font-semibold">{{ $this->store['open'] ? 'Abierta' : 'Cerrada' }}</span>
                <span class="text-muted">· Pedidos en línea {{ $this->store['online'] ? 'activados' : 'apagados' }}{{ $this->store['online'] ? ' · '.($this->store['delivery'] ? 'con domicilio' : 'solo recoger') : '' }}</span>
            </span>
            <span class="font-semibold underline">Cambiar</span>
        </a>

        @if ($this->requested->isNotEmpty())
            <section class="space-y-3" aria-label="Pedidos en línea por aceptar" data-tour="pedidos-en-linea">
                <h2 class="section-title flex items-center gap-2 px-1">
                    <span class="num grid h-7 min-w-7 place-items-center rounded-full bg-chile px-2 text-base text-white">{{ $this->requested->count() }}</span>
                    {{ $this->requested->count() === 1 ? 'Pedido en línea por aceptar' : 'Pedidos en línea por aceptar' }}
                </h2>
                <ul class="grid gap-3 lg:grid-cols-2">
                    @foreach ($this->requested as $order)
                        @php $waiting = $order->created_at->getTimestampMs(); @endphp
                        <li wire:key="pr-{{ $order->id }}" class="card flex flex-col !p-0 ring-2 ring-chile/60">
                            <div class="flex items-start gap-3 px-4 pt-4">
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-chile text-white"><x-icon name="store" class="size-6" /></span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-display text-[1.5rem] leading-tight font-bold">{{ $order->label }}</p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm">
                                        <span class="badge bg-limon-soft text-ink">En línea</span>
                                        <span @class(['badge', 'bg-naranja text-on-limon' => $order->isDelivery(), 'bg-sunken text-ink' => ! $order->isDelivery()])>{{ $order->isDelivery() ? 'A domicilio' : 'Pasa a recoger' }}</span>
                                        <span class="flex items-center gap-1" :class="(now - {{ $waiting }}) > 5 * 60000 ? 'font-semibold text-chile' : 'text-muted'">
                                            <x-icon name="clock" class="size-3.5" />
                                            <span x-text="(() => { const m = Math.max(0, Math.floor((now - {{ $waiting }}) / 60000)); return m < 1 ? 'recién' : (m < 60 ? 'hace ' + m + ' min' : 'hace ' + Math.floor(m / 60) + ' h') })()"></span>
                                        </span>
                                    </p>
                                </div>
                                <span class="num text-[1.5rem] font-bold">{{ money($order->total) }}</span>
                            </div>

                            <div class="mx-4 mt-3 space-y-2 rounded-xl bg-naranja-soft/70 px-3 py-2.5">
                                @if ($order->isDelivery())
                                    <p class="text-[15px] leading-snug font-semibold">{{ $order->delivery_address }}</p>
                                    @if ($order->delivery_notes) <p class="text-sm text-muted">{{ $order->delivery_notes }}</p> @endif
                                @endif
                                <p class="text-sm text-muted">Teléfono: <span class="font-semibold text-ink">{{ $order->customer_phone }}</span></p>
                                @if ($order->notes) <p class="text-sm text-muted">Nota: <span class="text-ink">{{ $order->notes }}</span></p> @endif
                                <div class="flex flex-wrap gap-2">
                                    <a href="tel:{{ $order->customer_phone }}" class="btn !min-h-9 !px-3"><x-icon name="phone" class="size-4" /> Llamar</a>
                                    @if ($order->whatsappNumber())
                                        <a href="https://wa.me/{{ $order->whatsappNumber() }}" target="_blank" rel="noopener" class="btn !min-h-9 !px-3"><x-icon name="chat" class="size-4" /> WhatsApp</a>
                                    @endif
                                </div>
                            </div>

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

                            <div class="flex gap-2 border-t border-line p-3">
                                <button type="button" class="btn flex-1" @click="rejecting = { id: {{ $order->id }}, name: @js($order->label) }; reason = ''">Rechazar</button>
                                <button type="button" wire:click="accept({{ $order->id }})" wire:loading.attr="disabled" wire:target="accept" class="btn-primary flex-1 !border-salsa !bg-salsa !text-on-salsa"><x-icon name="check" class="size-4" /> Aceptar</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if ($this->pending->isEmpty() && $this->requested->isEmpty())
            <x-empty icon="orders" title="No hay pedidos pendientes" data-tour="pedidos-vacio">
                Aquí aparecen los pedidos por entregar y los que guardaste sin cobrar, con su nombre e indicaciones.
                <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Tomar un pedido</a></x-slot:action>
            </x-empty>
        @elseif ($this->pending->isNotEmpty())
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
                                {{-- Sin nombre, el título es el servicio (Aquí, Para llevar): "Sin nombre" en cada tarjeta solo hacía ruido. --}}
                                <p class="truncate font-display text-[1.5rem] leading-tight font-bold">{{ $order->label ?: ($order->serviceLabel() ?? 'Pedido') }}</p>
                                <p class="mt-0.5 flex flex-wrap items-center gap-2 text-sm">
                                    @if ($open)
                                        <span class="badge bg-limon-soft text-ink">Por cobrar</span>
                                    @else
                                        <span class="badge bg-salsa-soft text-salsa">Pagado</span>
                                    @endif
                                    @if ($order->isOnline())
                                        <span class="badge bg-limon-soft text-ink">En línea</span>
                                    @endif
                                    @if ($order->serviceLabel() && ($order->label || $order->isDelivery()))
                                        <span @class(['badge', 'bg-naranja text-on-limon' => $order->isDelivery(), 'bg-sunken text-ink' => ! $order->isDelivery()])>{{ $order->serviceLabel() }}</span>
                                    @endif
                                    @if ($order->dispatched_at && ! $delivered)
                                        <span class="badge bg-naranja text-on-limon">En camino</span>
                                    @endif
                                    @if ($order->ready_at && ! $delivered && ! $order->dispatched_at)
                                        <span class="badge bg-salsa text-on-salsa">Listo</span>
                                    @endif
                                    @if ($delivered)
                                        <span class="badge bg-sunken text-muted">Ya entregado</span>
                                    @elseif ($pickup)
                                        <span class="badge bg-naranja-soft text-ink"
                                              :class="({{ $pickup }} - now) < 10 * 60000 && '!bg-chile !text-white'">
                                            <x-icon name="clock" class="size-3.5" />
                                            {{ $order->scheduleVerb() }} {{ $order->pickup_at->format('g:i a') }}
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

                        @if ($order->isOnline() && ! $order->isDelivery() && $order->customer_phone)
                            <div class="mx-4 mt-3 flex flex-wrap items-center gap-2 rounded-xl bg-sunken px-3 py-2.5">
                                <span class="text-sm text-muted">Teléfono: <span class="font-semibold text-ink">{{ $order->customer_phone }}</span></span>
                                <a href="tel:{{ $order->customer_phone }}" class="btn !min-h-9 !px-3"><x-icon name="phone" class="size-4" /> Llamar</a>
                                @if ($order->whatsappNumber())
                                    <a href="https://wa.me/{{ $order->whatsappNumber() }}" target="_blank" rel="noopener" class="btn !min-h-9 !px-3"><x-icon name="chat" class="size-4" /> WhatsApp</a>
                                @endif
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
                                    <button type="button" x-ref="trigger" @click="menu = !menu" @click.outside="menu = false" @keydown.escape.window="menu = false" class="btn-icon" aria-label="Más opciones"><x-icon name="more" /></button>
                                    <div x-show="menu" x-cloak x-anchor.top-start.offset.6.fixed="$refs.trigger" x-transition.origin.bottom.left class="menu" @click="menu = false">
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
                            @elseif (! $delivered && $open)
                                {{-- Entregar algo sin cobrar casi siempre es un olvido: se pregunta antes. --}}
                                <button type="button" class="btn-primary flex-1 !bg-salsa !border-salsa !text-on-salsa hover:!opacity-90"
                                        @click="if (await $store.confirm.ask({ title: '¿Entregar sin cobrar?', message: 'Este pedido sigue por cobrar. Se queda en la lista hasta que lo cobres.', action: 'Entregar sin cobrar', cancel: 'No', danger: false })) $wire.deliver({{ $order->id }})">
                                    <x-icon name="check" class="size-4" /> Entregado
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
                <button type="button" @click="open = !open" class="flex min-h-11 w-full items-center justify-between px-1" :aria-expanded="open">
                    <h2 class="section-title">Sin cobrar de días anteriores</h2>
                    <span class="flex items-center gap-1.5 text-sm text-muted">{{ $this->stale->count() }} <x-icon name="down" class="size-4 transition-transform" ::class="open && 'rotate-180'" /></span>
                </button>
                <ul class="list" x-show="open" x-collapse x-cloak>
                    @foreach ($this->stale as $order)
                        <li wire:key="ps-{{ $order->id }}" class="flex items-center gap-3 py-3 pr-2 pl-4">
                            <span class="num w-8 text-center text-lg font-bold text-muted">{{ $order->daily_number }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $order->label ?: $order->itemsSummary() }}</span>
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
                <button type="button" @click="open = !open" class="flex min-h-11 w-full items-center justify-between px-1" :aria-expanded="open">
                    <h2 class="section-title">Entregados hoy</h2>
                    <span class="flex items-center gap-1.5 text-sm text-muted">{{ $this->delivered->count() }} <x-icon name="down" class="size-4 transition-transform" ::class="open && 'rotate-180'" /></span>
                </button>
                <ul class="list" x-show="open" x-collapse x-cloak>
                    @foreach ($this->delivered as $order)
                        <li wire:key="pd-{{ $order->id }}" class="flex items-center gap-3 px-4 py-3">
                            <span class="num w-8 text-center text-lg font-bold text-muted">{{ $order->daily_number }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate font-semibold">{{ $order->label ?: $order->itemsSummary() }}</span>
                                <span class="block text-sm text-muted">Entregado {{ $order->delivered_at->format('g:i a') }}</span>
                            </span>
                            <button type="button" wire:click="undeliver({{ $order->id }})" class="btn-quiet"><x-icon name="undo" class="size-4" /> Regresar</button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    </div>

    {{-- Rechazar un pedido en línea: el cliente ve el motivo en su página. --}}
    <x-sheet title="Rechazar pedido" open="rejecting !== null" dismiss="rejecting = null">
        <div class="space-y-4" x-show="rejecting" x-cloak>
            <p class="text-[15px]">Pedido de <span class="font-semibold" x-text="rejecting?.name"></span>. El cliente verá el motivo en su página.</p>
            <div class="flex flex-wrap gap-2">
                @foreach (\App\Support\OnlineOrder::REJECT_REASONS as $option)
                    <button type="button" class="chip" :aria-pressed="reason === @js($option)" @click="reason = @js($option)">{{ $option }}</button>
                @endforeach
            </div>
            <div>
                <label class="label" for="reject-reason">Otro motivo (opcional)</label>
                <input id="reject-reason" type="text" x-model="reason" maxlength="120" class="input" placeholder="Escribe un motivo">
            </div>
        </div>
        <x-slot:footer>
            <button type="button" class="btn-primary btn-lg w-full" @click="$wire.reject(rejecting.id, reason); rejecting = null">Rechazar pedido</button>
        </x-slot:footer>
    </x-sheet>
</div>
