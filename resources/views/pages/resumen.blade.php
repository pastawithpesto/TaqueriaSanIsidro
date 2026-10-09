<?php

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Resumen')] class extends Component
{
    public string $range = 'week';

    public const RANGES = [
        'today' => 'Hoy',
        'week' => '7 días',
        'month' => 'Este mes',
        'last30' => '30 días',
    ];

    #[Computed]
    public function from(): CarbonImmutable
    {
        $today = CarbonImmutable::today();

        return match ($this->range) {
            'today' => $today,
            'month' => $today->startOfMonth(),
            'last30' => $today->subDays(29),
            default => $today->subDays(6),
        };
    }

    #[Computed]
    public function to(): CarbonImmutable
    {
        return CarbonImmutable::today()->endOfDay();
    }

    protected function paidItems()
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereBetween('orders.sold_at', [$this->from, $this->to]);
    }

    #[Computed]
    public function totals(): array
    {
        $sales = (float) Order::where('status', OrderStatus::Paid)->whereBetween('sold_at', [$this->from, $this->to])->sum('total');
        $orders = Order::where('status', OrderStatus::Paid)->whereBetween('sold_at', [$this->from, $this->to])->count();
        $recipeCost = (float) $this->paidItems()->sum(DB::raw('order_items.quantity * order_items.unit_cost'));
        $expenses = (float) Expense::whereDate('spent_on', '>=', $this->from)->whereDate('spent_on', '<=', $this->to)->sum('total');

        return [
            'sales' => $sales,
            'orders' => $orders,
            'recipeCost' => $recipeCost,
            'expenses' => $expenses,
            'recipeProfit' => $sales - $recipeCost,
            'cash' => $sales - $expenses,
        ];
    }

    #[Computed]
    public function topProducts(): Collection
    {
        return $this->paidItems()
            ->select('order_items.product_name')
            ->selectRaw('SUM(order_items.quantity) as qty')
            ->selectRaw('SUM(order_items.subtotal) as revenue')
            ->selectRaw('SUM(order_items.quantity * order_items.unit_cost) as cost')
            ->groupBy('order_items.product_name')
            ->orderByDesc('qty')
            ->get();
    }

    #[Computed]
    public function days(): Collection
    {
        $sales = Order::where('status', OrderStatus::Paid)
            ->whereBetween('sold_at', [$this->from, $this->to])
            ->get(['sold_at', 'total'])
            ->groupBy(fn ($o) => $o->sold_at->toDateString())
            ->map->sum('total');

        $expenses = Expense::whereDate('spent_on', '>=', $this->from)->whereDate('spent_on', '<=', $this->to)
            ->get(['spent_on', 'total'])
            ->groupBy(fn ($e) => $e->spent_on->toDateString())
            ->map->sum('total');

        $rows = collect();
        for ($d = $this->to->startOfDay(); $d >= $this->from; $d = $d->subDay()) {
            $key = $d->toDateString();
            $rows->push([
                'date' => $d,
                'sales' => (float) ($sales[$key] ?? 0),
                'expenses' => (float) ($expenses[$key] ?? 0),
            ]);
        }

        return $rows;
    }
};
?>

<div class="space-y-5">
    <div class="flex flex-wrap gap-2">
        @foreach ($this::RANGES as $key => $label)
            <button type="button" wire:click="$set('range', '{{ $key }}')"
                    @class([
                        'rounded-full border px-3 py-1 text-sm',
                        'border-amber-700 bg-amber-700 text-white' => $range === $key,
                        'border-stone-300 bg-white text-stone-700' => $range !== $key,
                    ])>{{ $label }}</button>
        @endforeach
    </div>

    @php $t = $this->totals; @endphp
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <div class="card">
            <p class="text-xs text-stone-500">Ventas ({{ $t['orders'] }} {{ $t['orders'] === 1 ? 'orden' : 'órdenes' }})</p>
            <p class="text-xl font-semibold tabular-nums">{{ Number::currency($t['sales']) }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-stone-500">Gastos</p>
            <p class="text-xl font-semibold tabular-nums">{{ Number::currency($t['expenses']) }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-stone-500">Ventas menos gastos</p>
            <p @class(['text-xl font-semibold tabular-nums', 'text-red-600' => $t['cash'] < 0])>{{ Number::currency($t['cash']) }}</p>
        </div>
        <div class="card">
            <p class="text-xs text-stone-500">Ganancia por receta</p>
            <p class="text-xl font-semibold tabular-nums">{{ Number::currency($t['recipeProfit']) }}</p>
        </div>
    </div>
    <p class="text-xs text-stone-500">
        Ventas menos gastos usa lo que realmente se pagó. Ganancia por receta resta a cada venta el costo de sus insumos, según las recetas.
    </p>

    <div class="card overflow-x-auto p-0">
        <table class="min-w-full divide-y divide-stone-200">
            <thead>
                <tr>
                    <th class="th">Producto</th>
                    <th class="th text-right">Vendidos</th>
                    <th class="th text-right">Ventas</th>
                    <th class="th text-right">Ganancia</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($this->topProducts as $row)
                    <tr>
                        <td class="td">{{ $row->product_name }}</td>
                        <td class="td text-right tabular-nums">{{ (int) $row->qty }}</td>
                        <td class="td text-right tabular-nums">{{ Number::currency((float) $row->revenue) }}</td>
                        <td class="td text-right tabular-nums">{{ Number::currency((float) $row->revenue - (float) $row->cost) }}</td>
                    </tr>
                @empty
                    <tr><td class="td text-stone-500" colspan="4">Sin ventas en este periodo.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @php $max = max(1, $this->days->max('sales'), $this->days->max('expenses')); @endphp
    <div class="card space-y-2">
        <div class="flex gap-4 text-xs text-stone-600">
            <span class="flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-amber-600"></span> Ventas</span>
            <span class="flex items-center gap-1"><span class="inline-block size-2.5 rounded-sm bg-stone-500"></span> Gastos</span>
        </div>
        @foreach ($this->days as $row)
            <div wire:key="d-{{ $row['date']->toDateString() }}" class="grid grid-cols-[3.5rem_1fr_auto] items-center gap-3 text-xs">
                <span class="text-stone-500">{{ $row['date']->translatedFormat('D d') }}</span>
                <div class="space-y-1">
                    <div class="h-2 rounded bg-amber-600" style="width: {{ $row['sales'] / $max * 100 }}%"></div>
                    <div class="h-2 rounded bg-stone-500" style="width: {{ $row['expenses'] / $max * 100 }}%"></div>
                </div>
                <span class="text-right tabular-nums text-stone-600">
                    {{ Number::currency($row['sales']) }}<br>{{ Number::currency($row['expenses']) }}
                </span>
            </div>
        @endforeach
    </div>
</div>
