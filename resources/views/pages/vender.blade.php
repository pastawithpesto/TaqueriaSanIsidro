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

    public string $method = 'cash';

    public ?string $reference = null;

    public ?string $received = null;

    public ?string $flash = null;

    #[Computed]
    public function products()
    {
        return Product::where('active', true)->orderBy('sort_order')->orderBy('name')->get();
    }

    #[Computed]
    public function total(): float
    {
        return round($this->products->sum(fn ($p) => ($this->cart[$p->id] ?? 0) * $p->price), 2);
    }

    #[Computed]
    public function change(): ?float
    {
        if ($this->method !== 'cash' || ! is_numeric($this->received)) {
            return null;
        }

        return round((float) $this->received - $this->total, 2);
    }

    public function add(int $id): void
    {
        if ($this->products->contains('id', $id)) {
            $this->cart[$id] = ($this->cart[$id] ?? 0) + 1;
            $this->flash = null;
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
    }

    public function clear(): void
    {
        $this->reset('cart', 'reference', 'received');
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

        $products = Product::with('ingredients')->whereIn('id', $ids)->get();

        $order = DB::transaction(function () use ($products) {
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
                $qty = $this->cart[$product->id];
                $subtotal = round($qty * $product->price, 2);
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

        $this->flash = 'Venta registrada: '.Number::currency((float) $order->total);
        $this->reset('cart', 'reference', 'received');
        unset($this->total, $this->change);
    }
};
?>

<div class="space-y-4">
    @if ($flash)
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ $flash }}</div>
    @endif

    <div class="grid grid-cols-2 gap-3 md:grid-cols-3">
        @foreach ($this->products as $product)
            @php $qty = $cart[$product->id] ?? 0; @endphp
            <div wire:key="p-{{ $product->id }}"
                 @class([
                     'relative rounded-xl border p-3 shadow-sm',
                     'border-amber-500 bg-amber-50' => $qty > 0,
                     'border-stone-200 bg-white' => $qty === 0,
                 ])>
                <button type="button" wire:click="add({{ $product->id }})" class="block w-full pb-3 text-left">
                    <span class="block text-base font-semibold leading-tight">{{ $product->name }}</span>
                    <span class="block text-sm text-stone-500">{{ Number::currency((float) $product->price) }}</span>
                </button>
                <div class="flex items-center justify-between">
                    <button type="button" wire:click="remove({{ $product->id }})" @disabled($qty === 0)
                            class="btn h-10 w-12 text-lg">−</button>
                    <span class="text-xl font-semibold tabular-nums">{{ $qty }}</span>
                    <button type="button" wire:click="add({{ $product->id }})" class="btn-primary h-10 w-12 text-lg">+</button>
                </div>
            </div>
        @endforeach
    </div>

    @if ($this->products->isEmpty())
        <p class="text-sm text-stone-500">No hay productos activos. Agrégalos en Productos.</p>
    @endif

    <div class="card sticky bottom-20 space-y-3 md:bottom-4">
        <div class="flex items-center justify-between">
            <span class="text-sm text-stone-500">Total</span>
            <span class="text-2xl font-semibold tabular-nums">{{ Number::currency($this->total) }}</span>
        </div>

        <div class="grid grid-cols-3 gap-2">
            @foreach (App\Enums\PaymentMethod::cases() as $m)
                <button type="button" wire:click="$set('method', '{{ $m->value }}')"
                        @class([
                            'rounded-lg border px-2 py-2 text-sm font-medium',
                            'border-amber-700 bg-amber-700 text-white' => $method === $m->value,
                            'border-stone-300 bg-white text-stone-700' => $method !== $m->value,
                        ])>{{ $m->label() }}</button>
            @endforeach
        </div>

        @if ($method === 'cash')
            <div class="flex items-center gap-3">
                <input type="number" inputmode="decimal" step="0.01" min="0" wire:model.live.debounce.300ms="received"
                       class="input" placeholder="Recibido (opcional)">
                @if ($this->change !== null)
                    <span @class(['whitespace-nowrap text-sm font-medium', 'text-red-600' => $this->change < 0, 'text-stone-700' => $this->change >= 0])>
                        Cambio {{ Number::currency($this->change) }}
                    </span>
                @endif
            </div>
        @else
            <input type="text" wire:model="reference" class="input" placeholder="Referencia (opcional)">
            @error('reference') <p class="error">{{ $message }}</p> @enderror
        @endif

        <div class="flex gap-2">
            <button type="button" wire:click="clear" class="btn" @disabled(! $cart)>Limpiar</button>
            <button type="button" wire:click="charge" class="btn-primary flex-1" @disabled(! $cart)>Cobrar</button>
        </div>
    </div>
</div>
