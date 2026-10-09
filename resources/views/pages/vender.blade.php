<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
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
        money: new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }),
        fmt(n) { return this.money.format(n) },
        qty(id) { return this.cart[id] ?? 0 },
        add(id) {
            this.cart = { ...this.cart, [id]: this.qty(id) + 1 };
            navigator.vibrate?.(8);
        },
        remove(id) {
            const next = { ...this.cart };
            if ((next[id] = this.qty(id) - 1) <= 0) delete next[id];
            this.cart = next;
            if (!this.count) this.payOpen = false;
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
    }"
    class="md:grid md:grid-cols-[1fr_21rem] md:items-start md:gap-6">
    <div class="space-y-4">
        @if ($this->categories->count() > 1)
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
                <button type="button" class="chip shrink-0" :aria-pressed="category === ''" @click="category = ''">Todo</button>
                @foreach ($this->categories as $cat)
                    <button type="button" class="chip shrink-0" :aria-pressed="category === @js($cat)" @click="category = @js($cat)">{{ $cat }}</button>
                @endforeach
            </div>
        @endif

        @if ($this->products->isEmpty())
            <x-empty icon="tag" title="Aún no hay productos">
                Da de alta lo que vendes (tacos, tortas, bebidas) para poder cobrar.
                <x-slot:action>
                    <a href="{{ route('productos') }}" wire:navigate class="btn-primary">Agregar productos</a>
                </x-slot:action>
            </x-empty>
        @else
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-3">
                @foreach ($this->products as $product)
                    <div wire:key="p-{{ $product->id }}"
                         x-show="category === '' || category === @js($product->category)"
                         class="relative min-h-32 overflow-hidden rounded-2xl border-2 bg-surface motion-safe:transition"
                         :class="qty({{ $product->id }}) > 0 ? 'border-chile' : 'border-line'">
                        <button type="button" @click="add({{ $product->id }})" class="absolute inset-0 w-full touch-manipulation" aria-label="Agregar {{ $product->name }}"></button>
                        <div class="pointer-events-none relative flex h-full min-h-32 flex-col justify-between gap-3 p-3.5">
                            <p class="pr-9 font-display text-[1.4rem] font-bold leading-[0.95] hyphens-auto" lang="es">{{ $product->name }}</p>
                            <span class="num inline-flex w-fit items-center rounded-lg bg-limon px-2.5 py-0.5 text-xl font-bold text-on-limon">
                                {{ Number::currency((float) $product->price) }}
                            </span>
                        </div>
                        <template x-if="qty({{ $product->id }}) > 0">
                            <div>
                                <span x-text="qty({{ $product->id }})"
                                      x-effect="qty({{ $product->id }}); $el.classList.remove('bump'); void $el.offsetWidth; $el.classList.add('bump')"
                                      class="bump num pointer-events-none absolute right-2.5 top-2.5 grid size-9 place-items-center rounded-full bg-chile text-xl font-bold text-white"></span>
                                <button type="button" @click="remove({{ $product->id }})" class="btn absolute bottom-2 right-2 size-11 touch-manipulation !px-0" aria-label="Quitar uno de {{ $product->name }}">
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
    <aside class="card sticky top-6 hidden space-y-3 md:block" aria-label="Pedido actual">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-2xl font-bold">Pedido</h2>
            <button type="button" x-show="count > 0" x-cloak @click="clear()" class="btn-quiet">Vaciar</button>
        </div>
        <p x-show="count === 0" class="py-6 text-center text-sm text-muted">Toca un producto para agregarlo.</p>
        <ul class="divide-y divide-line" x-show="count > 0">
            <template x-for="line in lines" :key="line.id">
                <li class="flex items-center gap-3 py-2">
                    <span class="num w-8 text-2xl font-bold text-chile" x-text="line.qty"></span>
                    <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="line.name"></span>
                    <span class="num text-lg font-semibold" x-text="fmt(line.subtotal)"></span>
                    <button type="button" @click="remove(line.id)" class="btn-quiet -mr-2 size-9 justify-center !px-0" :aria-label="'Quitar uno de ' + line.name"><x-icon name="minus" class="size-4" /></button>
                </li>
            </template>
        </ul>
        <div class="flex items-end justify-between border-t border-line pt-3">
            <span class="text-sm font-medium text-muted">Total</span>
            <span class="num text-4xl font-bold leading-none" x-text="fmt(total)"></span>
        </div>
        <button type="button" @click="payOpen = true" class="btn-primary btn-lg w-full" :disabled="count === 0">Cobrar</button>
    </aside>

    {{-- Barra de pedido (celular) --}}
    <div x-show="count > 0" x-cloak class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 md:hidden">
        <button type="button" @click="payOpen = true"
                class="flex w-full touch-manipulation items-center gap-3 rounded-2xl bg-chile px-4 py-3 text-left text-white shadow-xl motion-safe:transition motion-safe:active:scale-[0.99]">
            <span class="grid size-9 place-items-center rounded-full bg-white/20"><x-icon name="ticket" class="size-5" /></span>
            <span class="flex-1 text-sm font-semibold leading-tight"><span x-text="count + (count === 1 ? ' producto' : ' productos')"></span><br><span class="font-medium text-white/80">Toca para cobrar</span></span>
            <span class="num text-3xl font-bold leading-none" x-text="fmt(total)"></span>
        </button>
    </div>

    {{-- Venta registrada (la pinta el servidor después de cobrar) --}}
    @if ($lastOrderId)
        <div wire:key="done-{{ $lastOrderId }}" x-data="{ show: true }" x-init="setTimeout(() => show = false, 10000)" x-show="show" x-cloak
             class="sheet-in fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 flex items-center gap-3 rounded-2xl bg-salsa px-4 py-3 text-white shadow-xl md:inset-x-auto md:bottom-6 md:right-6 md:w-96"
             role="status">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-white/20"><x-icon name="check" class="size-5" /></span>
            <span class="flex-1 text-sm font-semibold leading-tight">Venta registrada<br><span class="num text-xl font-bold">{{ Number::currency($lastTotal) }}</span></span>
            <button type="button" wire:click="undo" class="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-white/20 px-3 text-sm font-semibold hover:bg-white/30">
                <x-icon name="undo" class="size-4" /> Deshacer
            </button>
        </div>
    @endif

    {{-- Cobrar (se abre en el navegador, sin esperar al servidor) --}}
    <x-sheet title="Cobrar" open="payOpen">
        <div class="space-y-4">
            <div class="text-center">
                <p class="num text-6xl font-bold leading-none" x-text="fmt(total)"></p>
                <p class="mt-1 text-sm text-muted" x-text="count + (count === 1 ? ' producto' : ' productos')"></p>
            </div>

            <ul class="divide-y divide-line">
                <template x-for="line in lines" :key="line.id">
                    <li class="flex items-center gap-3 py-2">
                        <span class="num w-8 text-2xl font-bold text-chile" x-text="line.qty"></span>
                        <span class="min-w-0 flex-1 truncate text-[15px] font-medium" x-text="line.name"></span>
                        <span class="num text-lg font-semibold" x-text="fmt(line.subtotal)"></span>
                        <button type="button" @click="remove(line.id)" class="btn-quiet -mr-2 size-9 justify-center !px-0" :aria-label="'Quitar uno de ' + line.name"><x-icon name="minus" class="size-4" /></button>
                    </li>
                </template>
            </ul>

            <div class="grid grid-cols-3 gap-2" role="group" aria-label="Método de pago">
                @foreach (App\Enums\PaymentMethod::cases() as $m)
                    <button type="button" @click="method = '{{ $m->value }}'" :aria-pressed="method === '{{ $m->value }}'" class="chip min-h-12 justify-center px-2">{{ $m->label() }}</button>
                @endforeach
            </div>

            <div x-show="method === 'cash'" class="space-y-2">
                <p class="label !mb-0">Recibido</p>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="chip" @click="received = String(total)">Exacto</button>
                    <template x-for="amount in quick" :key="amount">
                        <button type="button" class="chip num text-lg" @click="received = String(amount)" x-text="'$' + amount"></button>
                    </template>
                </div>
                <input type="number" inputmode="decimal" step="0.01" min="0" x-model="received" class="input" placeholder="Otro monto">
                @error('received') <p class="error">{{ $message }}</p> @enderror
                <div x-show="change !== null" x-cloak class="flex items-center justify-between rounded-xl px-4 py-3"
                     :class="change >= 0 ? 'bg-salsa-soft text-salsa' : 'bg-chile-soft text-chile'">
                    <span class="text-sm font-semibold" x-text="change >= 0 ? 'Cambio' : 'Faltan'"></span>
                    <span class="num text-4xl font-bold leading-none" x-text="fmt(Math.abs(change ?? 0))"></span>
                </div>
            </div>

            <div x-show="method !== 'cash'" x-cloak>
                <label class="label" for="reference">Referencia (opcional)</label>
                <input id="reference" type="text" x-model="reference" class="input" placeholder="Folio o últimos dígitos">
                @error('reference') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="charge()" class="btn-primary btn-lg w-full touch-manipulation"
                    :disabled="charging || count === 0 || (change !== null && change < 0)">
                <span x-show="!charging">Confirmar cobro <span x-text="fmt(total)"></span></span>
                <span x-show="charging" x-cloak>Cobrando...</span>
            </button>
        </x-slot:footer>
    </x-sheet>
</div>
