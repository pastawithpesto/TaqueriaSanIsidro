<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
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

    public function shift(int $days): void
    {
        $this->date = $this->day()->addDays($days)->toDateString();
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
    public function orders()
    {
        $day = $this->day();

        return Order::with('items')
            ->whereBetween('sold_at', [$day, $day->endOfDay()])
            ->orderByDesc('sold_at')
            ->get();
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
    }

    public function restore(int $id): void
    {
        Order::whereKey($id)->update(['status' => OrderStatus::Paid]);
        unset($this->orders, $this->paid);
    }
};
?>

<div class="space-y-4">
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shift(-1)" class="btn" aria-label="Día anterior">←</button>
        <input type="date" wire:model.live="date" class="input max-w-48">
        <button type="button" wire:click="shift(1)" class="btn" aria-label="Día siguiente">→</button>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="card">
            <p class="text-xs text-stone-500">Ventas</p>
            <p class="text-xl font-semibold tabular-nums">{{ Number::currency((float) $this->paid->sum('total')) }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-stone-500">Órdenes</p>
            <p class="text-xl font-semibold tabular-nums">{{ $this->paid->count() }}</p>
        </div>
        @foreach (App\Enums\PaymentMethod::cases() as $m)
            @if ($m !== App\Enums\PaymentMethod::Card || $this->paid->where('payment_method', $m)->isNotEmpty())
                <div class="card">
                    <p class="text-xs text-stone-500">{{ $m->label() }}</p>
                    <p class="text-xl font-semibold tabular-nums">{{ Number::currency((float) $this->paid->where('payment_method', $m)->sum('total')) }}</p>
                </div>
            @endif
        @endforeach
    </div>

    <div class="space-y-2">
        @forelse ($this->orders as $order)
            <div wire:key="o-{{ $order->id }}" @class(['card flex items-start justify-between gap-3', 'opacity-60' => $order->status === OrderStatus::Cancelled])>
                <div class="min-w-0 text-sm">
                    <p class="font-medium">
                        {{ $order->sold_at->format('H:i') }}
                        <span class="font-normal text-stone-500">{{ $order->payment_method->label() }}</span>
                        @if ($order->status === OrderStatus::Cancelled)
                            <span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-700">Cancelada</span>
                        @endif
                    </p>
                    <p class="text-stone-600">
                        {{ $order->items->map(fn ($i) => $i->quantity.' '.$i->product_name)->join(', ') }}
                    </p>
                    @if ($order->payment_reference)
                        <p class="text-xs text-stone-400">Ref. {{ $order->payment_reference }}</p>
                    @endif
                </div>
                <div class="text-right">
                    <p class="font-semibold tabular-nums">{{ Number::currency((float) $order->total) }}</p>
                    @if ($order->status === OrderStatus::Paid)
                        <button type="button" wire:click="cancel({{ $order->id }})" wire:confirm="¿Cancelar esta venta?"
                                class="text-xs text-red-600 hover:underline">Cancelar</button>
                    @else
                        <button type="button" wire:click="restore({{ $order->id }})" class="text-xs text-stone-600 hover:underline">Restaurar</button>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-sm text-stone-500">Sin ventas este día.</p>
        @endforelse
    </div>
</div>
