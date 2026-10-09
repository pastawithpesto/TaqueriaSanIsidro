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

new #[Title('Vender')] class extends Component
{
    /** @var array<int, int> */
    public array $cart = [];

    public string $category = '';

    public string $method = 'cash';

    public ?string $reference = null;

    public ?string $received = null;

    public bool $showPay = false;

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

    #[Computed]
    public function visible()
    {
        return $this->category === ''
            ? $this->products
            : $this->products->where('category', $this->category);
    }

    #[Computed]
    public function lines(): array
    {
        return $this->products
            ->filter(fn ($p) => ($this->cart[$p->id] ?? 0) > 0)
            ->map(fn ($p) => [
                'product' => $p,
                'qty' => $this->cart[$p->id],
                'subtotal' => round($this->cart[$p->id] * (float) $p->price, 2),
            ])
            ->values()
            ->all();
    }

    #[Computed]
    public function count(): int
    {
        return array_sum($this->cart);
    }

    #[Computed]
    public function total(): float
    {
        return round(collect($this->lines)->sum('subtotal'), 2);
    }

    #[Computed]
    public function change(): ?float
    {
        if ($this->method !== 'cash' || ! is_numeric($this->received)) {
            return null;
        }

        return round((float) $this->received - $this->total, 2);
    }

    /** Billetes sugeridos: los primeros que alcanzan para cubrir el total. */
    #[Computed]
    public function quickAmounts(): array
    {
        return collect([50, 100, 200, 500, 1000])
            ->filter(fn ($b) => $b > $this->total)
            ->take(3)
            ->values()
            ->all();
    }

    public function add(int $id): void
    {
        if ($this->products->contains('id', $id)) {
            $this->cart[$id] = ($this->cart[$id] ?? 0) + 1;
            $this->lastOrderId = null;
        }
    }

    public function remove(int $id): void
    {
        if (! isset($this->cart[$id])) {
            return;
        }

        if (--$this->cart[$id] <= 0) {
            unset($this->cart[$id]);
        }

        if (! $this->cart) {
            $this->showPay = false;
        }
    }

    public function clear(): void
    {
        $this->reset('cart', 'reference', 'received', 'showPay');
    }

    public function openPay(): void
    {
        if ($this->cart) {
            $this->showPay = true;
        }
    }

    public function closePay(): void
    {
        $this->showPay = false;
    }

    public function charge(): void
    {
        $this->validate([
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        $ids = collect($this->cart)->filter(fn ($qty) => $qty > 0)->keys();

        if ($ids->isEmpty()) {
            return;
        }

        if ($this->change !== null && $this->change < 0) {
            $this->addError('received', 'El monto recibido no alcanza para el total.');

            return;
        }

        $products = Product::with('ingredients')->whereIn('id', $ids)->get();
        $cart = $this->cart;

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
        $this->reset('cart', 'reference', 'received', 'showPay');
        $this->method = 'cash';
        unset($this->lines, $this->count, $this->total, $this->change, $this->quickAmounts);
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
        unset($this->lines, $this->count, $this->total, $this->change, $this->quickAmounts);
    }
};
?>

<div class="md:grid md:grid-cols-[1fr_21rem] md:items-start md:gap-6">
    <div class="space-y-4">
        @if ($this->categories->count() > 1)
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]">
                <button type="button" class="chip shrink-0" aria-pressed="{{ $category === '' ? 'true' : 'false' }}" wire:click="$set('category', '')">Todo</button>
                @foreach ($this->categories as $cat)
                    <button type="button" class="chip shrink-0" aria-pressed="{{ $category === $cat ? 'true' : 'false' }}" wire:click="$set('category', @js($cat))">{{ $cat }}</button>
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
                @foreach ($this->visible as $product)
                    @php $qty = $cart[$product->id] ?? 0; @endphp
                    <div wire:key="p-{{ $product->id }}"
                         @class([
                             'relative min-h-32 overflow-hidden rounded-2xl border-2 bg-surface motion-safe:transition',
                             'border-chile' => $qty > 0,
                             'border-line' => $qty === 0,
                         ])>
                        <button type="button" wire:click="add({{ $product->id }})" @click="navigator.vibrate?.(8)"
                                class="absolute inset-0 w-full" aria-label="Agregar {{ $product->name }}"></button>
                        <div class="pointer-events-none relative flex h-full min-h-32 flex-col justify-between gap-3 p-3.5">
                            <p class="pr-9 font-display text-[1.4rem] font-bold leading-[0.95] hyphens-auto" lang="es">{{ $product->name }}</p>
                            <span class="num inline-flex w-fit items-center rounded-lg bg-limon px-2.5 py-0.5 text-xl font-bold text-on-limon">
                                {{ Number::currency((float) $product->price) }}
                            </span>
                        </div>
                        @if ($qty > 0)
                            <span wire:key="q-{{ $product->id }}-{{ $qty }}"
                                  class="bump num pointer-events-none absolute right-2.5 top-2.5 grid size-9 place-items-center rounded-full bg-chile text-xl font-bold text-white">{{ $qty }}</span>
                            <button type="button" wire:click="remove({{ $product->id }})"
                                    class="btn absolute bottom-2 right-2 size-11 !px-0" aria-label="Quitar uno de {{ $product->name }}">
                                <x-icon name="minus" />
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Ticket (escritorio) --}}
    <aside class="card sticky top-6 hidden space-y-3 md:block" aria-label="Pedido actual">
        <div class="flex items-center justify-between">
            <h2 class="font-display text-2xl font-bold">Pedido</h2>
            @if ($cart)
                <button type="button" wire:click="clear" class="btn-quiet">Vaciar</button>
            @endif
        </div>
        @if ($this->lines)
            <x-ticket-lines :lines="$this->lines" />
        @else
            <p class="py-6 text-center text-sm text-muted">Toca un producto para agregarlo.</p>
        @endif
        <div class="flex items-end justify-between border-t border-line pt-3">
            <span class="text-sm font-medium text-muted">Total</span>
            <span class="num text-4xl font-bold leading-none">{{ Number::currency($this->total) }}</span>
        </div>
        <button type="button" wire:click="openPay" class="btn-primary btn-lg w-full" @disabled(! $cart)>Cobrar</button>
    </aside>

    {{-- Barra de pedido (celular) --}}
    @if ($cart)
        <div class="fixed inset-x-3 bottom-[calc(4.5rem+env(safe-area-inset-bottom))] z-30 md:hidden">
            <button type="button" wire:click="openPay"
                    class="flex w-full items-center gap-3 rounded-2xl bg-chile px-4 py-3 text-left text-white shadow-xl motion-safe:transition motion-safe:active:scale-[0.99]">
                <span class="grid size-9 place-items-center rounded-full bg-white/20"><x-icon name="ticket" class="size-5" /></span>
                <span class="flex-1 text-sm font-semibold leading-tight">{{ $this->count }} {{ $this->count === 1 ? 'producto' : 'productos' }}<br><span class="font-medium text-white/80">Toca para cobrar</span></span>
                <span class="num text-3xl font-bold leading-none">{{ Number::currency($this->total) }}</span>
            </button>
        </div>
    @endif

    {{-- Venta registrada --}}
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

    {{-- Cobrar --}}
    @if ($showPay)
        <x-sheet title="Cobrar" close="closePay">
            <div class="space-y-4">
                <div class="text-center">
                    <p class="num text-6xl font-bold leading-none">{{ Number::currency($this->total) }}</p>
                    <p class="mt-1 text-sm text-muted">{{ $this->count }} {{ $this->count === 1 ? 'producto' : 'productos' }}</p>
                </div>

                <x-ticket-lines :lines="$this->lines" />

                <div class="grid grid-cols-3 gap-2" role="group" aria-label="Método de pago">
                    @foreach (App\Enums\PaymentMethod::cases() as $m)
                        <button type="button" wire:click="$set('method', '{{ $m->value }}')" aria-pressed="{{ $method === $m->value ? 'true' : 'false' }}"
                                class="chip min-h-12 justify-center px-2">{{ $m->label() }}</button>
                    @endforeach
                </div>

                @if ($method === 'cash')
                    <div class="space-y-2">
                        <p class="label !mb-0">Recibido</p>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="$set('received', '{{ $this->total }}')" class="chip">Exacto</button>
                            @foreach ($this->quickAmounts as $amount)
                                <button type="button" wire:click="$set('received', '{{ $amount }}')" class="chip num text-lg">${{ $amount }}</button>
                            @endforeach
                        </div>
                        <input type="number" inputmode="decimal" step="0.01" min="0" wire:model.live.debounce.250ms="received" class="input" placeholder="Otro monto">
                        @error('received') <p class="error">{{ $message }}</p> @enderror
                        @if ($this->change !== null)
                            <div @class([
                                'flex items-center justify-between rounded-xl px-4 py-3',
                                'bg-salsa-soft text-salsa' => $this->change >= 0,
                                'bg-chile-soft text-chile' => $this->change < 0,
                            ])>
                                <span class="text-sm font-semibold">{{ $this->change >= 0 ? 'Cambio' : 'Faltan' }}</span>
                                <span class="num text-4xl font-bold leading-none">{{ Number::currency(abs($this->change)) }}</span>
                            </div>
                        @endif
                    </div>
                @else
                    <div>
                        <label class="label" for="reference">Referencia (opcional)</label>
                        <input id="reference" type="text" wire:model="reference" class="input" placeholder="Folio o últimos dígitos">
                        @error('reference') <p class="error">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <x-slot:footer>
                <button type="button" wire:click="charge" wire:loading.attr="disabled" class="btn-primary btn-lg w-full"
                        @disabled($this->change !== null && $this->change < 0)>
                    Confirmar cobro {{ Number::currency($this->total) }}
                </button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>
