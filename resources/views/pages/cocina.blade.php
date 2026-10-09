<?php

use App\Models\Order;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Vista para una tablet en la plancha: pedidos por preparar en letra grande.
 * "Listo" lo marca como preparado; la entrega se confirma en Pedidos.
 */
new #[Layout('layouts::kitchen')] #[Title('Cocina')] class extends Component
{
    #[Computed]
    public function queue()
    {
        // Solo lo de hoy: lo que se recoge mañana o quedó sin cobrar de otro día no se prepara ahora.
        return Order::with('items')->pending()->dueBetween(today(), today()->addDay())
            ->whereNull('ready_at')->whereNull('delivered_at')
            ->orderByRaw('COALESCE(pickup_at, sold_at)')
            ->get();
    }

    #[Computed]
    public function ready()
    {
        return Order::pending()->dueBetween(today(), today()->addDay())->whereNotNull('ready_at')->whereNull('delivered_at')->orderByDesc('ready_at')->limit(8)->get();
    }

    public function markReady(int $id): void
    {
        $order = Order::findOrFail($id);
        $order->update(['ready_at' => now()]);
        unset($this->queue, $this->ready);
        $this->dispatch('toast', message: '#'.$order->daily_number.' listo');
    }

    public function unready(int $id): void
    {
        Order::whereKey($id)->update(['ready_at' => null]);
        unset($this->queue, $this->ready);
    }
};
?>

<div wire:poll.10s
     x-data="{ now: Date.now(), lock: null }"
     x-init="
        setInterval(() => now = Date.now(), 15000);
        // Mantener la pantalla encendida mientras esta vista esté abierta.
        const keep = async () => { try { lock = await navigator.wakeLock?.request('screen') } catch (e) {} };
        keep(); document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && keep());
     "
     class="flex min-h-dvh flex-col">
    <header class="flex items-center justify-between gap-4 px-5 pt-[max(1rem,env(safe-area-inset-top))] pb-3">
        <div class="flex items-center gap-3">
            <a href="{{ route('pedidos') }}" class="grid size-11 place-items-center rounded-xl bg-white/10 hover:bg-white/15" aria-label="Volver a Pedidos" data-tour="cocina-volver"><x-icon name="left" /></a>
            <h1 class="font-display text-4xl font-bold">Cocina</h1>
            <button type="button" data-tour-start="cocina" class="grid size-11 place-items-center rounded-full text-white/60 hover:bg-white/10 hover:text-white"
                    aria-label="Cómo funciona esta pantalla" title="Cómo funciona esta pantalla"><x-icon name="help" class="size-6" /></button>
            <span class="num rounded-full bg-[#ffda58] px-3 py-0.5 text-2xl font-bold text-[#4e2c11]">{{ $this->queue->count() }}</span>
        </div>
        <span class="num text-3xl font-bold text-white/70" x-text="new Date(now).toLocaleTimeString('es-MX', { hour: 'numeric', minute: '2-digit' })"></span>
    </header>

    <main class="flex-1 px-5 pb-28">
        @if ($this->queue->isEmpty())
            <div class="grid h-[60dvh] place-items-center text-center">
                <div>
                    <x-icon name="check" class="mx-auto size-16 text-[#4cc07a]" />
                    <p class="mt-3 font-display text-4xl font-bold">Todo al día</p>
                    <p class="mt-1 text-lg text-white/60">Los pedidos nuevos aparecen aquí solos.</p>
                </div>
            </div>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($this->queue as $order)
                    @php
                        $since = $order->sold_at->getTimestampMs();
                        $pickup = $order->pickup_at?->getTimestampMs();
                    @endphp
                    <article wire:key="k-{{ $order->id }}" class="flex flex-col rounded-3xl bg-[#2b1d12] ring-1 ring-white/10" @if ($loop->first) data-tour="cocina-pedido" @endif>
                        <div class="flex items-start gap-3 p-4 pb-3">
                            <span class="num grid size-14 shrink-0 place-items-center rounded-2xl bg-[#ffda58] text-3xl font-bold text-[#4e2c11]">{{ $order->daily_number }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-display text-3xl leading-tight font-bold">{{ $order->label ?: 'Sin nombre' }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-2 text-base">
                                    @if ($order->serviceLabel())
                                        <span @class(['rounded-md px-2 py-0.5 font-semibold', 'bg-[#fe964d] text-[#4e2c11]' => $order->isDelivery(), 'bg-white/12' => ! $order->isDelivery()])>{{ $order->serviceLabel() }}</span>
                                    @endif
                                    @if ($pickup)
                                        <span class="rounded-md bg-[#fe964d] px-2 py-0.5 font-semibold text-[#4e2c11]">Recoge {{ $order->pickup_at->format('g:i a') }}</span>
                                    @else
                                        <span :class="(now - {{ $since }}) > 15 * 60000 ? 'font-bold text-[#ff6b5e]' : 'text-white/60'"
                                              x-text="(() => { const m = Math.max(0, Math.floor((now - {{ $since }}) / 60000)); return m < 1 ? 'recién' : (m < 60 ? 'hace ' + m + ' min' : 'hace ' + Math.floor(m / 60) + ' h ' + (m % 60) + ' min') })()"></span>
                                    @endif
                                </p>
                            </div>
                        </div>
                        <ul class="flex-1 space-y-3 border-t border-white/10 px-4 py-4">
                            @foreach ($order->items as $item)
                                <li class="flex gap-3">
                                    <span class="num w-10 shrink-0 text-4xl leading-none font-bold text-[#ffda58]">{{ $item->quantity }}</span>
                                    <span class="min-w-0">
                                        <span class="block text-2xl leading-tight font-semibold">{{ $item->product_name }}</span>
                                        @if ($item->modifiers || $item->notes)
                                            <span class="mt-1.5 flex flex-wrap gap-1.5">
                                                @foreach ($item->choices() as $choice)
                                                    <span class="rounded-lg bg-[#ffda58] px-2 py-0.5 text-lg font-bold text-[#4e2c11]">{{ $choice['name'] }}</span>
                                                @endforeach
                                                @foreach ($item->withoutNames() as $name)
                                                    <span class="rounded-lg bg-[#e02424] px-2 py-0.5 text-lg font-bold">SIN {{ mb_strtoupper($name) }}</span>
                                                @endforeach
                                                @foreach ($item->extras() as $extra)
                                                    <span class="rounded-lg bg-[#17803d] px-2 py-0.5 text-lg font-bold">+ {{ $extra['name'] }}</span>
                                                @endforeach
                                                @if ($item->notes)
                                                    <span class="text-lg text-[#ffda58] italic">"{{ $item->notes }}"</span>
                                                @endif
                                            </span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                        <div class="p-3 pt-0">
                            <button type="button" wire:click="markReady({{ $order->id }})" @if ($loop->first) data-tour="cocina-listo" @endif class="flex min-h-16 w-full items-center justify-center gap-2 rounded-2xl bg-[#17803d] text-2xl font-bold text-white hover:bg-[#156f35] motion-safe:active:scale-[0.98]">
                                <x-icon name="check" class="size-7" /> Listo
                            </button>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </main>

    @if ($this->ready->isNotEmpty())
        <footer data-tour="cocina-listos" class="fixed inset-x-0 bottom-0 flex items-center gap-2 overflow-x-auto border-t border-white/10 bg-[#120b06]/95 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur">
            <span class="shrink-0 text-sm font-semibold text-white/60">Listos:</span>
            @foreach ($this->ready as $order)
                <button type="button" wire:click="unready({{ $order->id }})" title="Regresar a preparación"
                        class="flex shrink-0 items-center gap-2 rounded-xl bg-white/10 px-3 py-2 hover:bg-white/15">
                    <span class="num text-xl font-bold text-[#ffda58]">{{ $order->daily_number }}</span>
                    <span class="max-w-40 truncate font-semibold">{{ $order->label }}</span>
                    <x-icon name="undo" class="size-4 text-white/50" />
                </button>
            @endforeach
        </footer>
    @endif
</div>
