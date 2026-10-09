<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use App\Support\OrderBuilder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * El pedido se arma en el navegador (Alpine) para que todo sea inmediato.
 * Al cobrar o guardar, el servidor recalcula precios y costos con la base.
 */
new #[Title('Vender')] class extends Component
{
    /** Pedido guardado que se está editando o cobrando (?orden=12). */
    #[Url(as: 'orden')]
    public ?int $orderId = null;

    /** @var array<int, array{product_id: int, qty: int, without: array<int>, extras: array<int>, notes: string}> */
    public array $lines = [];

    public ?string $label = null;

    public string $method = 'cash';

    public ?string $reference = null;

    public ?string $received = null;

    public bool $openPay = false;

    public ?int $lastOrderId = null;

    public float $lastTotal = 0;

    public ?string $lastTitle = null;

    public function mount(): void
    {
        if (! $this->orderId) {
            return;
        }

        $order = Order::with('items')->where('status', OrderStatus::Open)->find($this->orderId);

        if (! $order) {
            $this->orderId = null;

            return;
        }

        $this->lines = OrderBuilder::linesFrom($order);
        $this->label = $order->label;
        // Desde Pedidos, "Cobrar" abre directo el cuadro de cobro.
        $this->openPay = request()->boolean('cobrar');
    }

    #[Computed]
    public function editing(): ?Order
    {
        return $this->orderId ? Order::find($this->orderId) : null;
    }

    #[Computed]
    public function products()
    {
        return Product::with(['ingredients', 'extras'])->where('active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function categories()
    {
        return $this->products->pluck('category')->filter()->unique()->values();
    }

    /** Catálogo para el navegador: precios, "sin" posibles (de la receta) y extras. */
    #[Computed]
    public function catalog(): array
    {
        return $this->products->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'without' => $p->ingredients->filter(fn ($i) => $i->pivot->removable)
                ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values()->all(),
            'extras' => $p->extras->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'price' => (float) $e->price])->values()->all(),
        ]])->all();
    }

    protected function title(Order $order): string
    {
        return '#'.$order->daily_number.($order->label ? ' '.$order->label : '');
    }

    protected function store(OrderStatus $status, array $built): Order
    {
        return DB::transaction(function () use ($status, $built) {
            $order = $this->orderId
                ? Order::whereKey($this->orderId)->where('status', OrderStatus::Open)->lockForUpdate()->first()
                : null;

            $order ??= new Order([
                'user_id' => auth()->id(),
                'daily_number' => Order::nextDailyNumber(),
                'sold_at' => now(),
            ]);

            $paid = $status === OrderStatus::Paid;

            $order->fill([
                'status' => $status,
                'label' => trim((string) $this->label) ?: null,
                'payment_method' => $paid ? $this->method : null,
                'payment_reference' => $paid && $this->method !== 'cash' ? ($this->reference ?: null) : null,
                'paid_at' => $paid ? now() : null,
                'total' => $built['total'],
            ])->save();

            OrderBuilder::fill($order, $built);

            return $order;
        });
    }

    protected function finish(): void
    {
        $this->reset('lines', 'label', 'reference', 'received', 'orderId', 'openPay');
        $this->method = 'cash';
        unset($this->editing);
    }

    /** @return array{ok: bool, error?: string} */
    public function charge(): array
    {
        if (! PaymentMethod::tryFrom($this->method)) {
            return ['ok' => false, 'error' => 'Elige la forma de pago.'];
        }

        $built = OrderBuilder::items($this->lines);

        if (! $built['items']) {
            return ['ok' => false, 'error' => 'El pedido está vacío.'];
        }

        if ($this->method === 'cash' && is_numeric($this->received) && (float) $this->received < $built['total']) {
            return ['ok' => false, 'error' => 'El monto recibido no alcanza para el total.'];
        }

        $order = $this->store(OrderStatus::Paid, $built);

        $this->lastOrderId = $order->id;
        $this->lastTotal = (float) $order->total;
        $this->lastTitle = $this->title($order);
        $this->finish();

        return ['ok' => true];
    }

    /** Guarda el pedido con su nombre para cobrarlo después. */
    public function saveOpen(): array
    {
        if (trim((string) $this->label) === '') {
            return ['ok' => false, 'error' => 'Escribe para quién es (un nombre o el carro) para encontrarlo después.'];
        }

        $built = OrderBuilder::items($this->lines);

        if (! $built['items']) {
            return ['ok' => false, 'error' => 'El pedido está vacío.'];
        }

        $order = $this->store(OrderStatus::Open, $built);
        $this->dispatch('toast', message: 'Pedido '.$this->title($order).' guardado sin cobrar');
        $this->reset('lastOrderId');
        $this->finish();

        return ['ok' => true];
    }

    public function cancelEdit(): void
    {
        $this->finish();
    }

    /** Cancela la última venta y regresa el pedido para corregirlo. */
    public function undo(): void
    {
        $order = $this->lastOrderId ? Order::with('items')->find($this->lastOrderId) : null;

        if ($order && $order->status === OrderStatus::Paid && $order->paid_at?->gt(now()->subMinutes(10))) {
            $order->update(['status' => OrderStatus::Cancelled]);
            $this->lines = OrderBuilder::linesFrom($order);
            $this->label = $order->label;
            $this->dispatch('toast', message: 'Venta cancelada. El pedido volvió para corregirlo.');
        }

        $this->reset('lastOrderId', 'lastTotal', 'lastTitle');
    }
};
?>

<div
    x-init="if (new URLSearchParams(location.search).has('cobrar')) { const u = new URL(location.href); u.searchParams.delete('cobrar'); history.replaceState(history.state, '', u) }"
    x-data="{
        catalog: @js($this->catalog),
        category: '',
        sheet: @js($openPay),
        editor: null,
        busy: false,
        error: '',
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
        // Renglones del pedido: viven en $wire.lines (se mandan al servidor solo al cobrar o guardar).
        list() { return Array.isArray($wire.lines) ? $wire.lines : Object.values($wire.lines ?? {}) },
        isPlain(l) { return ! l.without?.length && ! l.extras?.length && ! (l.notes ?? '').trim() },
        same(a, b) {
            const s = x => [...(x ?? [])].map(Number).sort().join('.');
            return a.product_id == b.product_id && s(a.without) === s(b.without) && s(a.extras) === s(b.extras) && (a.notes ?? '').trim().toLowerCase() === (b.notes ?? '').trim().toLowerCase();
        },
        qtyOf(pid) { return this.list().filter(l => l.product_id == pid).reduce((n, l) => n + l.qty, 0) },
        add(pid) {
            const lines = this.list().map(l => ({ ...l }));
            const i = lines.findIndex(l => l.product_id == pid && this.isPlain(l));
            i === -1 ? lines.push({ product_id: pid, qty: 1, without: [], extras: [], notes: '' }) : lines[i].qty++;
            $wire.lines = lines;
            navigator.vibrate?.(8);
            this.$nextTick(() => bump(document.getElementById('q-' + pid)));
        },
        removeOne(pid) {
            const lines = this.list().map(l => ({ ...l }));
            let i = lines.findIndex(l => l.product_id == pid && this.isPlain(l));
            if (i === -1) i = lines.map(l => l.product_id == pid).lastIndexOf(true);
            if (i === -1) return;
            if (--lines[i].qty <= 0) lines.splice(i, 1);
            $wire.lines = lines;
            if (! lines.length) this.sheet = false;
            this.$nextTick(() => bump(document.getElementById('q-' + pid)));
        },
        removeLine(i) {
            const lines = this.list().map(l => ({ ...l }));
            lines.splice(i, 1);
            $wire.lines = lines;
            if (! lines.length) this.sheet = false;
        },
        unit(l) {
            const p = this.catalog[l.product_id];
            return (p?.price ?? 0) + (l.extras ?? []).reduce((t, id) => t + (p?.extras.find(e => e.id == id)?.price ?? 0), 0);
        },
        mods(l) {
            const p = this.catalog[l.product_id];
            return {
                without: (l.without ?? []).map(id => 'Sin ' + (p?.without.find(w => w.id == id)?.name ?? '').toLowerCase()),
                extras: (l.extras ?? []).map(id => p?.extras.find(e => e.id == id)?.name).filter(Boolean),
                notes: (l.notes ?? '').trim(),
            };
        },
        count() { return this.list().reduce((n, l) => n + l.qty, 0) },
        total() { return Math.round(this.list().reduce((t, l) => t + this.unit(l) * l.qty, 0) * 100) / 100 },
        change() {
            const r = $wire.received;
            if ($wire.method !== 'cash' || r === null || r === '' || isNaN(r)) return null;
            return Math.round((Number(r) - this.total()) * 100) / 100;
        },
        quick() { return [50, 100, 200, 500, 1000].filter(b => b > this.total()).slice(0, 3) },
        // Editor de indicaciones de un renglón
        edit(i) {
            const l = this.list()[i];
            this.editor = { index: i, max: l.qty, count: l.qty, product_id: l.product_id, without: [...(l.without ?? [])], extras: [...(l.extras ?? [])], notes: l.notes ?? '' };
        },
        toggle(key, id) {
            const arr = this.editor[key];
            const i = arr.findIndex(x => x == id);
            i === -1 ? arr.push(id) : arr.splice(i, 1);
        },
        saveEdit() {
            const e = this.editor;
            const lines = this.list().map(l => ({ ...l }));
            const original = lines[e.index];
            const edited = { product_id: e.product_id, qty: e.count, without: e.without, extras: e.extras, notes: e.notes.trim() };
            if (e.count < original.qty) { original.qty -= e.count; } else { lines.splice(e.index, 1); }
            const twin = lines.findIndex(l => this.same(l, edited));
            twin === -1 ? lines.splice(Math.min(e.index + 1, lines.length), 0, edited) : lines[twin].qty += edited.qty;
            $wire.lines = lines;
            this.editor = null;
        },
        clear() { $wire.lines = []; $wire.received = null; $wire.reference = null; this.sheet = false },
        async confirmClear() {
            if (await this.$store.confirm.ask({ title: '¿Vaciar el pedido?', message: 'Se quitarán ' + this.count() + (this.count() === 1 ? ' producto.' : ' productos.'), action: 'Vaciar' })) this.clear();
        },
        async submit(action) {
            if (this.busy) return;
            this.busy = true; this.error = '';
            try {
                const r = await $wire[action]();
                if (r?.ok) { this.sheet = false } else { this.error = r?.error ?? 'No se pudo guardar.' }
            } finally { this.busy = false }
        },
    }">
    <x-page-header :title="$this->editing ? 'Pedido #'.$this->editing->daily_number : 'Vender'">
        <x-slot:actions>
            @if ($this->editing)
                <button type="button" wire:click="cancelEdit" class="btn">Salir</button>
            @else
                <button type="button" x-show="count() > 0" x-cloak @click="confirmClear()" class="btn-quiet md:hidden">Vaciar</button>
            @endif
        </x-slot:actions>
        @if ($this->editing)
            <p class="mb-3 rounded-xl bg-surface/80 px-3 py-2 text-sm">
                Editando <span class="font-semibold">{{ $this->editing->label }}</span>, por cobrar. Agrega o quita productos y guarda, o cóbralo.
            </p>
        @endif
        @if ($this->categories->count() > 1)
            <div class="scroll-fade -mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0">
                <button type="button" class="chip" :aria-pressed="category === ''" @click="category = ''">Todo</button>
                @foreach ($this->categories as $cat)
                    <button type="button" class="chip" :aria-pressed="category === @js($cat)" @click="category = @js($cat)">{{ $cat }}</button>
                @endforeach
            </div>
        @endif
    </x-page-header>

    <div class="md:grid md:grid-cols-[1fr_20rem] md:items-start md:gap-6 lg:grid-cols-[1fr_23rem]">
        <div>
            @if ($this->products->isEmpty())
                <x-empty icon="tag" title="Aún no hay productos">
                    Da de alta lo que vendes, con su precio, para poder cobrar.
                    <x-slot:action>
                        <a href="{{ route('productos') }}" wire:navigate class="btn-primary">Agregar productos</a>
                    </x-slot:action>
                </x-empty>
            @else
                @php
                    // Un tono de la paleta por categoría para reconocerlas de un vistazo.
                    $tints = ['bg-tint-1', 'bg-tint-2', 'bg-tint-3', 'bg-tint-4', 'bg-tint-5'];
                    $catIndex = $this->categories->flip();
                @endphp
                <div class="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-3">
                    @foreach ($this->products as $product)
                        @php $tint = $tints[($catIndex[$product->category] ?? 0) % count($tints)]; @endphp
                        <div wire:key="p-{{ $product->id }}"
                             x-show="category === '' || category === @js($product->category)"
                             class="{{ $tint }} relative min-h-[8.25rem] rounded-2xl border-2 motion-safe:transition motion-safe:duration-150 motion-safe:has-[.add:active]:scale-[0.97]"
                             :class="qtyOf({{ $product->id }}) > 0 ? 'border-chile shadow-[0_6px_16px_-8px_rgb(224_36_36/0.55)]' : 'border-transparent'">
                            <button type="button" @click="add({{ $product->id }})" class="add absolute inset-0 w-full touch-manipulation rounded-2xl" aria-label="Agregar {{ $product->name }}"></button>
                            <div class="pointer-events-none relative flex h-full min-h-[8.25rem] flex-col justify-between gap-3 p-3.5">
                                <p class="pr-10 font-display {{ mb_strlen($product->name) > 10 ? 'text-[1.25rem]' : 'text-[1.4rem]' }} leading-[0.95] font-bold hyphens-auto" lang="es">{{ $product->name }}</p>
                                <span class="num inline-flex w-fit items-center rounded-lg bg-ink px-2.5 py-0.5 text-xl font-bold text-bg">{{ money($product->price) }}</span>
                            </div>
                            <template x-if="qtyOf({{ $product->id }}) > 0">
                                <div>
                                    <span id="q-{{ $product->id }}" x-text="qtyOf({{ $product->id }})"
                                          class="num pointer-events-none absolute top-2.5 right-2.5 grid size-9 place-items-center rounded-full bg-chile text-xl font-bold text-white shadow-sm"></span>
                                    <button type="button" @click="removeOne({{ $product->id }})" class="btn-icon absolute right-2 bottom-2 touch-manipulation" aria-label="Quitar uno de {{ $product->name }}">
                                        <x-icon name="minus" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Pedido (escritorio). Lo maneja el navegador. --}}
        <aside class="sticky top-6 hidden md:block" aria-label="Pedido actual" wire:ignore>
            <div class="card space-y-3 !p-5">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Pedido</h2>
                    <button type="button" x-show="count() > 0" x-cloak @click="confirmClear()" class="btn-quiet -mr-2">Vaciar</button>
                </div>
                <div x-show="count() === 0" class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted">
                    <x-icon name="ticket" class="size-7 opacity-60" />
                    Toca un producto para agregarlo.
                </div>
                <div x-show="count() > 0" x-cloak>
                    @include('partials.order-lines')
                </div>
                <div class="flex items-end justify-between border-t border-line pt-4">
                    <span class="text-sm font-semibold text-muted">Total</span>
                    <span class="num text-[2.75rem] leading-none font-bold" x-text="fmt(total())"></span>
                </div>
                <button type="button" @click="error = ''; sheet = true" class="btn-primary btn-lg w-full" :disabled="count() === 0">Continuar</button>
            </div>
        </aside>
    </div>

    {{-- Barra de pedido (celular) --}}
    <div wire:ignore>
        <div x-show="count() > 0" x-cloak
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-4 opacity-0"
             x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="translate-y-4 opacity-0"
             class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 md:hidden">
            <button type="button" @click="error = ''; sheet = true"
                    class="flex w-full touch-manipulation items-center gap-3 rounded-2xl bg-chile py-3 pr-4 pl-3 text-left text-white shadow-[0_10px_30px_-8px_rgb(224_36_36/0.6)] motion-safe:transition motion-safe:active:scale-[0.98]">
                <span class="num grid size-11 place-items-center rounded-xl bg-white/18 text-2xl font-bold" x-text="count()"></span>
                <span class="flex-1 text-base font-semibold">Ver pedido</span>
                <span class="num text-[2rem] leading-none font-bold" x-text="fmt(total())"></span>
            </button>
        </div>
    </div>

    {{-- Venta registrada (la pinta el servidor después de cobrar) --}}
    @if ($lastOrderId)
        <div wire:key="done-{{ $lastOrderId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-cloak
             x-transition:leave="transition duration-200" x-transition:leave-end="opacity-0 translate-y-3"
             class="sheet-in fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-3 rounded-2xl bg-ink py-3 pr-3 pl-4 text-bg shadow-xl md:inset-x-auto md:right-6 md:bottom-6 md:w-96"
             role="status">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-salsa text-white"><x-icon name="check" class="size-5" /></span>
            <span class="min-w-0 flex-1 leading-tight">
                <span class="block truncate text-sm opacity-75">Cobrado {{ $lastTitle }}</span>
                <span class="num block text-2xl font-bold">{{ money($lastTotal) }}</span>
            </span>
            <button type="button" wire:click="undo" class="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-bg/12 px-3.5 text-sm font-semibold hover:bg-bg/20">
                <x-icon name="undo" class="size-4" /> Deshacer
            </button>
        </div>
    @endif

    {{-- Pedido: nombre, renglones y forma de pago --}}
    <div wire:ignore>
        <x-sheet title="Pedido" open="sheet">
            <div class="space-y-5">
                <div>
                    <label class="label" for="label">¿Para quién? <span class="font-normal text-muted">(opcional al cobrar)</span></label>
                    <input id="label" type="text" x-model="$wire.label" maxlength="60" class="input" placeholder="Ej. camioneta negra, Juan, mesa 2" autocomplete="off">
                </div>

                <div>
                    <div class="mb-1 flex items-baseline justify-between">
                        <p class="label !mb-0">Productos</p>
                        <p class="text-sm text-muted">Toca uno para indicaciones</p>
                    </div>
                    @include('partials.order-lines')
                </div>

                <div class="flex items-end justify-between rounded-2xl bg-sunken px-4 py-3">
                    <span class="text-sm font-semibold text-muted" x-text="count() === 1 ? '1 producto' : count() + ' productos'"></span>
                    <span class="num text-[2.75rem] leading-none font-bold" x-text="fmt(total())"></span>
                </div>

                <div>
                    <p class="label">Forma de pago</p>
                    <div class="segmented" role="group" aria-label="Forma de pago">
                        @foreach (PaymentMethod::cases() as $m)
                            <button type="button" @click="$wire.method = '{{ $m->value }}'" :aria-pressed="$wire.method === '{{ $m->value }}'">{{ $m->label() }}</button>
                        @endforeach
                    </div>
                </div>

                <div x-show="$wire.method === 'cash'" class="space-y-2.5">
                    <p class="label !mb-0">Recibido</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="chip" :aria-pressed="$wire.received !== null && Number($wire.received) === total()" @click="$wire.received = String(total())">Exacto</button>
                        <template x-for="amount in quick()" :key="amount">
                            <button type="button" class="chip num !text-base" :aria-pressed="Number($wire.received) === amount" @click="$wire.received = String(amount)" x-text="'$' + amount"></button>
                        </template>
                    </div>
                    <x-money-input x-model="$wire.received" placeholder="Otro monto" aria-label="Monto recibido" />
                    <div x-show="change() !== null" x-cloak class="flex items-center justify-between rounded-xl px-4 py-3"
                         :class="change() >= 0 ? 'bg-salsa-soft text-salsa' : 'bg-chile-soft text-chile'">
                        <span class="text-sm font-bold" x-text="change() >= 0 ? 'Cambio' : 'Faltan'"></span>
                        <span class="num text-[2.5rem] leading-none font-bold" x-text="fmt(Math.abs(change() ?? 0))"></span>
                    </div>
                </div>

                <div x-show="$wire.method !== 'cash'" x-cloak>
                    <label class="label" for="reference">Referencia <span class="font-normal text-muted">(opcional)</span></label>
                    <input id="reference" type="text" x-model="$wire.reference" class="input" placeholder="Folio o últimos dígitos">
                </div>

                <p x-show="error" x-cloak class="flex items-start gap-2 rounded-xl bg-chile-soft px-3 py-2.5 text-sm font-semibold text-chile">
                    <x-icon name="alert" class="mt-0.5 size-4 shrink-0" /> <span x-text="error"></span>
                </p>
            </div>

            <x-slot:footer>
                <div class="grid grid-cols-[auto_1fr] gap-2">
                    <button type="button" @click="submit('saveOpen')" class="btn btn-lg !px-4" :disabled="busy || count() === 0">Guardar sin cobrar</button>
                    <button type="button" @click="submit('charge')" class="btn-primary btn-lg touch-manipulation"
                            :disabled="busy || count() === 0 || (change() !== null && change() < 0)">
                        <span x-show="!busy">Cobrar <span class="num text-xl" x-text="fmt(total())"></span></span>
                        <span x-show="busy" x-cloak>Guardando...</span>
                    </button>
                </div>
            </x-slot:footer>
        </x-sheet>

        {{-- Indicaciones de un renglón --}}
        <x-sheet title="Indicaciones" open="editor" dismiss="editor = null" x-text="editor ? catalog[editor.product_id]?.name : 'Indicaciones'">
            <template x-if="editor">
                <div class="space-y-5">
                    <div x-show="editor.max > 1" class="flex items-center justify-between gap-3 rounded-2xl bg-sunken px-4 py-3">
                        <span class="text-[15px] font-semibold">Aplicar a</span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="btn-icon" @click="editor.count = Math.max(1, editor.count - 1)" :disabled="editor.count <= 1" aria-label="Menos"><x-icon name="minus" /></button>
                            <span class="num w-14 text-center text-2xl font-bold"><span x-text="editor.count"></span><span class="text-base text-muted" x-text="' de ' + editor.max"></span></span>
                            <button type="button" class="btn-icon" @click="editor.count = Math.min(editor.max, editor.count + 1)" :disabled="editor.count >= editor.max" aria-label="Más"><x-icon name="plus" /></button>
                        </div>
                    </div>

                    <div x-show="catalog[editor.product_id]?.without.length">
                        <p class="label">Sin</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="w in catalog[editor.product_id]?.without ?? []" :key="w.id">
                                <button type="button" @click="toggle('without', w.id)"
                                        class="chip"
                                        :class="editor.without.some(x => x == w.id) && '!border-chile !bg-chile !text-white'"
                                        x-text="'Sin ' + w.name.toLowerCase()"></button>
                            </template>
                        </div>
                    </div>

                    <div x-show="catalog[editor.product_id]?.extras.length">
                        <p class="label">Extras</p>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="x in catalog[editor.product_id]?.extras ?? []" :key="x.id">
                                <button type="button" @click="toggle('extras', x.id)"
                                        class="chip gap-1.5"
                                        :class="editor.extras.some(y => y == x.id) && '!border-salsa !bg-salsa !text-white'">
                                    <span x-text="x.name"></span>
                                    <span class="num opacity-80" x-show="x.price > 0" x-text="'+' + fmt(x.price)"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div>
                        <label class="label" for="line-notes">Nota</label>
                        <input id="line-notes" type="text" x-model="editor.notes" maxlength="120" class="input" placeholder="Ej. bien dorada, salsa aparte" @keydown.enter.prevent="saveEdit()">
                    </div>

                    <p x-show="! catalog[editor.product_id]?.without.length && ! catalog[editor.product_id]?.extras.length" class="flex gap-2 text-sm text-muted">
                        <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                        <span>Si le agregas receta y extras a este producto en Productos, aquí aparecerán botones como "Sin cebolla".</span>
                    </p>
                </div>
            </template>

            <x-slot:footer>
                <div class="grid grid-cols-[auto_1fr] gap-2">
                    <button type="button" class="btn btn-lg !px-4 text-chile" @click="removeLine(editor.index); editor = null">
                        <x-icon name="trash" class="size-4" /> Quitar
                    </button>
                    <button type="button" class="btn-primary btn-lg" @click="saveEdit()">Listo</button>
                </div>
            </x-slot:footer>
        </x-sheet>
    </div>
</div>
