<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * El carrito vive en el navegador (Alpine) para que tocar un producto sea
 * instantáneo. Las propiedades públicas se sincronizan con el servidor sólo
 * cuando se cobra; el servidor vuelve a calcular todo con los precios de la base.
 */
new #[Title('Vender')] class extends Component
{
    /** @var array<int, int> */
    public array $cart = [];

    public string $method = 'cash';

    public ?string $reference = null;

    public ?string $received = null;

    public ?int $lastOrderId = null;

    public float $lastTotal = 0;

    /** @var array<int, int> */
    public array $lastCart = [];

    #[Computed]
    public function products()
    {
        return Product::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function categories()
    {
        return $this->products->pluck('category')->filter()->unique()->values();
    }

    /** Catálogo que usa el navegador para calcular totales sin pedirlos al servidor. */
    #[Computed]
    public function catalog(): array
    {
        return $this->products->mapWithKeys(fn ($p) => [$p->id => [
            'id' => $p->id,
            'name' => $p->name,
            'price' => (float) $p->price,
            'category' => $p->category,
        ]])->all();
    }

    /** @return array<int, int> */
    protected function cleanCart(): array
    {
        return collect($this->cart)
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])
            ->filter(fn ($qty, $id) => $qty > 0 && $this->products->contains('id', $id))
            ->all();
    }

    protected function cartTotal(array $cart): float
    {
        return round($this->products->sum(fn ($p) => ($cart[$p->id] ?? 0) * (float) $p->price), 2);
    }

    public function charge(): void
    {
        $this->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $cart = $this->cleanCart();

        if (! $cart) {
            return;
        }

        $total = $this->cartTotal($cart);

        if ($this->method === 'cash' && is_numeric($this->received) && (float) $this->received < $total) {
            $this->addError('received', 'El monto recibido no alcanza para el total.');

            return;
        }

        $products = Product::with('ingredients')->whereIn('id', array_keys($cart))->get();

        $order = DB::transaction(function () use ($products, $cart) {
            $order = Order::create([
                'user_id' => auth()->id(),
                'status' => OrderStatus::Paid,
                'payment_method' => $this->method,
                'payment_reference' => $this->method === 'cash' ? null : ($this->reference ?: null),
                'total' => 0,
                'sold_at' => now(),
            ]);

            $total = 0;

            foreach ($products as $product) {
                $qty = $cart[$product->id];
                $subtotal = round($qty * (float) $product->price, 2);
                $total += $subtotal;

                $order->items()->create([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'unit_cost' => round($product->unitCost(), 4),
                    'subtotal' => $subtotal,
                ]);
            }

            $order->update(['total' => $total]);

            return $order;
        });

        $this->lastOrderId = $order->id;
        $this->lastTotal = (float) $order->total;
        $this->lastCart = $cart;
        $this->reset('cart', 'reference', 'received');
        $this->method = 'cash';
        $this->resetErrorBag();
    }

    /** Cancela la última venta y regresa el pedido al carrito para corregirlo. */
    public function undo(): void
    {
        $order = $this->lastOrderId ? Order::find($this->lastOrderId) : null;

        if ($order && $order->status === OrderStatus::Paid && $order->sold_at->gt(now()->subMinutes(10))) {
            $order->update(['status' => OrderStatus::Cancelled]);
            $this->cart = $this->lastCart;
            $this->dispatch('toast', message: 'Venta cancelada. El pedido volvió al carrito.');
        }

        $this->reset('lastOrderId', 'lastCart', 'lastTotal');
    }
};
?>

<div
    x-data="{
        cart: $wire.$entangle('cart'),
        method: $wire.$entangle('method'),
        received: $wire.$entangle('received'),
        reference: $wire.$entangle('reference'),
        catalog: @js($this->catalog),
        category: '',
        payOpen: false,
        charging: false,
        fmt(n) {
            n = Math.round(n * 100) / 100;
            const whole = Math.abs(n % 1) < 0.005;
            return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 }).format(n);
        },
        qty(id) { return this.cart[id] ?? 0 },
        add(id) {
            this.cart = { ...this.cart, [id]: this.qty(id) + 1 };
            navigator.vibrate?.(8);
            this.$nextTick(() => bump(document.getElementById('q-' + id)));
        },
        remove(id) {
            const next = { ...this.cart };
            if ((next[id] = this.qty(id) - 1) <= 0) delete next[id];
            this.cart = next;
            if (!this.count) this.payOpen = false;
            this.$nextTick(() => bump(document.getElementById('q-' + id)));
        },
        clear() { this.cart = {}; this.received = null; this.reference = null; this.payOpen = false },
        get lines() {
            return Object.entries(this.cart)
                .filter(([id, q]) => q > 0 && this.catalog[id])
                .map(([id, q]) => ({ id, name: this.catalog[id].name, qty: q, subtotal: Math.round(q * this.catalog[id].price * 100) / 100 }));
        },
        get count() { return this.lines.reduce((n, l) => n + l.qty, 0) },
        get total() { return Math.round(this.lines.reduce((t, l) => t + l.subtotal, 0) * 100) / 100 },
        get change() {
            if (this.method !== 'cash' || this.received === null || this.received === '' || isNaN(this.received)) return null;
            return Math.round((Number(this.received) - this.total) * 100) / 100;
        },
        get quick() { return [50, 100, 200, 500, 1000].filter(b => b > this.total).slice(0, 3) },
        async charge() {
            if (this.charging) return;
            this.charging = true;
            try { await $wire.charge(); } finally { this.charging = false; }
            if (this.count === 0) this.payOpen = false;
        },
    }">
    <x-page-header title="Vender">
        <x-slot:actions>
            <button type="button" x-show="count > 0" x-cloak @click="clear()" class="btn-quiet md:hidden">Vaciar</button>
        </x-slot:actions>
        @if ($this->categories->count() > 1)
            <div class="scroll-fade -mx-4 flex gap-2 overflow-x-auto px-4 [scrollbar-width:none] md:mx-0 md:px-0">
                <button type="button" class="chip" :aria-pressed="category === ''" @click="category = ''">Todo</button>
                @foreach ($this->categories as $cat)
                    <button type="button" class="chip" :aria-pressed="category === @js($cat)" @click="category = @js($cat)">{{ $cat }}</button>
                @endforeach
            </div>
        @endif
    </x-page-header>

    <div class="md:grid md:grid-cols-[1fr_20rem] md:items-start md:gap-6 lg:grid-cols-[1fr_22rem]">
        <div>
            @if ($this->products->isEmpty())
                <x-empty icon="tag" title="Aún no hay productos">
                    Da de alta lo que vendes, con su precio, para poder cobrar.
                    <x-slot:action>
                        <a href="{{ route('productos') }}" wire:navigate class="btn-primary">Agregar productos</a>
                    </x-slot:action>
                </x-empty>
            @else
                <div class="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-3">
                    @foreach ($this->products as $product)
                        <div wire:key="p-{{ $product->id }}"
                             x-show="category === '' || category === @js($product->category)"
                             class="relative min-h-[8.25rem] rounded-2xl border-2 motion-safe:transition motion-safe:duration-150 motion-safe:has-[.add:active]:scale-[0.97]"
                             :class="qty({{ $product->id }}) > 0 ? 'border-chile bg-chile-soft/45' : 'border-transparent bg-surface shadow-[0_0_0_1px_var(--color-line)]'">
                            <button type="button" @click="add({{ $product->id }})" class="add absolute inset-0 w-full touch-manipulation rounded-2xl" aria-label="Agregar {{ $product->name }}"></button>
                            <div class="pointer-events-none relative flex h-full min-h-[8.25rem] flex-col justify-between gap-3 p-3.5">
                                <p class="pr-9 font-display text-[1.4rem] leading-[0.95] font-bold hyphens-auto" lang="es">{{ $product->name }}</p>
                                <span class="num inline-flex w-fit items-center rounded-lg bg-limon px-2.5 py-0.5 text-xl font-bold text-on-limon">{{ money($product->price) }}</span>
                            </div>
                            <template x-if="qty({{ $product->id }}) > 0">
                                <div>
                                    <span id="q-{{ $product->id }}" x-text="qty({{ $product->id }})"
                                          class="num pointer-events-none absolute top-2.5 right-2.5 grid size-9 place-items-center rounded-full bg-chile text-xl font-bold text-white shadow-sm"></span>
                                    <button type="button" @click="remove({{ $product->id }})" class="btn-icon absolute right-2 bottom-2 touch-manipulation" aria-label="Quitar uno de {{ $product->name }}">
                                        <x-icon name="minus" />
                                    </button>
                                </div>
                            </template>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Pedido (escritorio) --}}
        <aside class="sticky top-6 hidden md:block" aria-label="Pedido actual">
            <div class="card space-y-3 !p-5">
                <div class="flex items-center justify-between">
                    <h2 class="section-title">Pedido</h2>
                    <button type="button" x-show="count > 0" x-cloak @click="clear()" class="btn-quiet -mr-2">Vaciar</button>
                </div>
                <div x-show="count === 0" class="flex flex-col items-center gap-2 py-8 text-center text-sm text-muted">
                    <x-icon name="ticket" class="size-7 opacity-60" />
                    Toca un producto para agregarlo.
                </div>
                <ul class="divide-y divide-line" x-show="count > 0" x-cloak>
                    <template x-for="line in lines" :key="line.id">
                        <li class="flex items-center gap-3 py-2">
                            <span class="num w-7 text-2xl font-bold text-chile" x-text="line.qty"></span>
                            <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="line.name"></span>
                            <span class="num text-lg font-semibold" x-text="fmt(line.subtotal)"></span>
                            <button type="button" @click="remove(line.id)" class="btn-quiet -mr-2 size-9 justify-center !px-0" :aria-label="'Quitar uno de ' + line.name"><x-icon name="minus" class="size-4" /></button>
                        </li>
                    </template>
                </ul>
                <div class="flex items-end justify-between border-t border-line pt-4">
                    <span class="text-sm font-semibold text-muted">Total</span>
                    <span class="num text-[2.75rem] leading-none font-bold" x-text="fmt(total)"></span>
                </div>
                <button type="button" @click="payOpen = true" class="btn-primary btn-lg w-full" :disabled="count === 0">Cobrar</button>
            </div>
        </aside>
    </div>

    {{-- Barra de pedido (celular) --}}
    <div x-show="count > 0" x-cloak
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-4 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="translate-y-4 opacity-0"
         class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 md:hidden">
        <button type="button" @click="payOpen = true"
                class="flex w-full touch-manipulation items-center gap-3 rounded-2xl bg-chile py-3 pr-4 pl-3 text-left text-white shadow-[0_10px_30px_-8px_rgb(198_42_29/0.6)] motion-safe:transition motion-safe:active:scale-[0.98]">
            <span class="num grid size-11 place-items-center rounded-xl bg-white/18 text-2xl font-bold" x-text="count"></span>
            <span class="flex-1 text-base font-semibold">Cobrar</span>
            <span class="num text-[2rem] leading-none font-bold" x-text="fmt(total)"></span>
        </button>
    </div>

    {{-- Venta registrada (la pinta el servidor después de cobrar) --}}
    @if ($lastOrderId)
        <div wire:key="done-{{ $lastOrderId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-cloak
             x-transition:leave="transition duration-200" x-transition:leave-end="opacity-0 translate-y-3"
             class="sheet-in fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-3 rounded-2xl bg-ink py-3 pr-3 pl-4 text-bg shadow-xl md:inset-x-auto md:right-6 md:bottom-6 md:w-96"
             role="status">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-salsa text-white"><x-icon name="check" class="size-5" /></span>
            <span class="flex-1 leading-tight">
                <span class="block text-sm opacity-75">Venta registrada</span>
                <span class="num block text-2xl font-bold">{{ money($lastTotal) }}</span>
            </span>
            <button type="button" wire:click="undo" class="inline-flex min-h-11 items-center gap-1.5 rounded-xl bg-bg/12 px-3.5 text-sm font-semibold hover:bg-bg/20">
                <x-icon name="undo" class="size-4" /> Deshacer
            </button>
        </div>
    @endif

    {{-- Cobrar (se abre en el navegador, sin esperar al servidor) --}}
    <x-sheet title="Cobrar" open="payOpen">
        <div class="space-y-5">
            <div class="rounded-2xl bg-sunken px-4 py-4 text-center">
                <p class="text-sm font-semibold text-muted" x-text="count === 1 ? '1 producto' : count + ' productos'"></p>
                <p class="num text-[3.5rem] leading-none font-bold" x-text="fmt(total)"></p>
            </div>

            <ul class="divide-y divide-line">
                <template x-for="line in lines" :key="line.id">
                    <li class="flex items-center gap-3 py-2">
                        <span class="num w-7 text-2xl font-bold text-chile" x-text="line.qty"></span>
                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="line.name"></span>
                        <span class="num text-lg font-semibold" x-text="fmt(line.subtotal)"></span>
                        <button type="button" @click="remove(line.id)" class="btn-quiet -mr-2 size-9 justify-center !px-0" :aria-label="'Quitar uno de ' + line.name"><x-icon name="minus" class="size-4" /></button>
                    </li>
                </template>
            </ul>

            <div>
                <p class="label">Forma de pago</p>
                <div class="segmented" role="group" aria-label="Forma de pago">
                    @foreach (App\Enums\PaymentMethod::cases() as $m)
                        <button type="button" @click="method = '{{ $m->value }}'" :aria-pressed="method === '{{ $m->value }}'">{{ $m->label() }}</button>
                    @endforeach
                </div>
            </div>

            <div x-show="method === 'cash'" class="space-y-2.5">
                <p class="label !mb-0">Recibido</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="chip" :aria-pressed="received !== null && Number(received) === total" @click="received = String(total)">Exacto</button>
                    <template x-for="amount in quick" :key="amount">
                        <button type="button" class="chip num !text-base" :aria-pressed="Number(received) === amount" @click="received = String(amount)" x-text="'$' + amount"></button>
                    </template>
                </div>
                <x-money-input x-model="received" placeholder="Otro monto" aria-label="Monto recibido" />
                @error('received') <p class="error">{{ $message }}</p> @enderror
                <div x-show="change !== null" x-cloak class="flex items-center justify-between rounded-xl px-4 py-3"
                     :class="change >= 0 ? 'bg-salsa-soft text-salsa' : 'bg-chile-soft text-chile'">
                    <span class="text-sm font-bold" x-text="change >= 0 ? 'Cambio' : 'Faltan'"></span>
                    <span class="num text-[2.5rem] leading-none font-bold" x-text="fmt(Math.abs(change ?? 0))"></span>
                </div>
            </div>

            <div x-show="method !== 'cash'" x-cloak>
                <label class="label" for="reference">Referencia <span class="font-normal text-muted">(opcional)</span></label>
                <input id="reference" type="text" x-model="reference" class="input" placeholder="Folio o últimos dígitos">
                @error('reference') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="charge()" class="btn-primary btn-lg w-full touch-manipulation"
                    :disabled="charging || count === 0 || (change !== null && change < 0)">
                <span x-show="!charging">Cobrar <span class="num text-xl" x-text="fmt(total)"></span></span>
                <span x-show="charging" x-cloak>Registrando venta...</span>
            </button>
        </x-slot:footer>
    </x-sheet>
</div>
