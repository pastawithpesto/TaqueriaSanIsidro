<?php

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Resumen')] class extends Component
{
    public const RANGES = [
        'today' => 'Hoy',
        'week' => '7 días',
        'month' => 'Este mes',
        'last30' => '30 días',
    ];

    public string $range = 'week';

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

    /** Periodo anterior de la misma duración, para comparar. */
    protected function previous(): array
    {
        $days = $this->from->diffInDays($this->to->startOfDay()) + 1;

        return [$this->from->subDays($days), $this->from->subSecond()];
    }

    protected function paidItems(CarbonImmutable $from, CarbonImmutable $to)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereBetween('orders.sold_at', [$from, $to]);
    }

    protected function paidOrders(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::where('status', OrderStatus::Paid)->whereBetween('sold_at', [$from, $to]);
    }

    #[Computed]
    public function totals(): array
    {
        $sales = (float) $this->paidOrders($this->from, $this->to)->sum('total');
        $orders = $this->paidOrders($this->from, $this->to)->count();
        $recipeCost = (float) $this->paidItems($this->from, $this->to)->sum(DB::raw('order_items.quantity * order_items.unit_cost'));
        $expenses = (float) Expense::whereDate('spent_on', '>=', $this->from)->whereDate('spent_on', '<=', $this->to)->sum('total');

        [$pFrom, $pTo] = $this->previous();
        $prevSales = (float) $this->paidOrders($pFrom, $pTo)->sum('total');

        return [
            'sales' => $sales,
            'orders' => $orders,
            'average' => $orders > 0 ? $sales / $orders : 0,
            'recipeCost' => $recipeCost,
            'expenses' => $expenses,
            'recipeProfit' => $sales - $recipeCost,
            'margin' => $sales > 0 ? ($sales - $recipeCost) / $sales * 100 : null,
            'cash' => $sales - $expenses,
            'delta' => $prevSales > 0 ? ($sales - $prevSales) / $prevSales * 100 : null,
        ];
    }

    #[Computed]
    public function topProducts(): Collection
    {
        return $this->paidItems($this->from, $this->to)
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
        $sales = $this->paidOrders($this->from, $this->to)
            ->get(['sold_at', 'total'])
            ->groupBy(fn ($o) => $o->sold_at->toDateString())
            ->map->sum('total');

        $expenses = Expense::whereDate('spent_on', '>=', $this->from)->whereDate('spent_on', '<=', $this->to)
            ->get(['spent_on', 'total'])
            ->groupBy(fn ($e) => $e->spent_on->toDateString())
            ->map->sum('total');

        $rows = collect();
        for ($d = $this->from->startOfDay(); $d <= $this->to; $d = $d->addDay()) {
            $key = $d->toDateString();
            $rows->push([
                'date' => $d,
                'sales' => (float) ($sales[$key] ?? 0),
                'expenses' => (float) ($expenses[$key] ?? 0),
            ]);
        }

        return $rows;
    }

    /** Ventas acumuladas por hora del día, para ver cuándo se vende más. */
    #[Computed]
    public function hours(): Collection
    {
        $byHour = $this->paidOrders($this->from, $this->to)
            ->get(['sold_at', 'total'])
            ->groupBy(fn ($o) => $o->sold_at->hour)
            ->map->sum('total');

        if ($byHour->isEmpty()) {
            return collect();
        }

        return collect(range($byHour->keys()->min(), $byHour->keys()->max()))
            ->map(fn ($h) => ['hour' => $h, 'sales' => (float) ($byHour[$h] ?? 0)]);
    }

    #[Computed]
    public function setup(): array
    {
        $ingredients = Ingredient::count();
        $withRecipe = Product::has('ingredients')->count();

        return [
            ['Da de alta tus insumos', 'Carne, tortillas, verdura. Con su costo.', $ingredients > 0, 'insumos'],
            ['Arma la receta de tus productos', 'Para saber cuánto ganas por cada uno.', $withRecipe > 0, 'productos'],
            ['Registra tu primera venta', 'Toca los productos y cobra.', Order::exists(), 'vender'],
        ];
    }

    protected function hourLabel(int $h): string
    {
        return ($h % 12 === 0 ? 12 : $h % 12).($h < 12 ? 'am' : 'pm');
    }
};
?>

<div class="space-y-5">
    <div class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 [scrollbar-width:none]" role="group" aria-label="Periodo">
        @foreach ($this::RANGES as $key => $label)
            <button type="button" wire:click="$set('range', '{{ $key }}')" aria-pressed="{{ $range === $key ? 'true' : 'false' }}" class="chip shrink-0">{{ $label }}</button>
        @endforeach
    </div>

    @php
        $steps = $this->setup;
        $pending = collect($steps)->contains(fn ($s) => ! $s[2]);
    @endphp
    @if ($pending)
        <section class="card space-y-1">
            <h2 class="font-display text-2xl font-bold">Primeros pasos</h2>
            <p class="pb-2 text-sm text-muted">Con esto la app empieza a calcular tus ganancias.</p>
            <ol class="divide-y divide-line">
                @foreach ($steps as $i => [$title, $text, $done, $route])
                    <li>
                        <a href="{{ route($route) }}" wire:navigate class="flex items-center gap-3 py-3">
                            <span @class([
                                'num grid size-9 shrink-0 place-items-center rounded-full text-lg font-bold',
                                'bg-salsa text-white' => $done,
                                'bg-limon text-on-limon' => ! $done,
                            ])>@if ($done) <x-icon name="check" class="size-5" /> @else {{ $i + 1 }} @endif</span>
                            <span class="flex-1">
                                <span @class(['block font-semibold', 'text-muted line-through' => $done])>{{ $title }}</span>
                                <span class="block text-sm text-muted">{{ $text }}</span>
                            </span>
                            <x-icon name="right" class="size-4 text-muted" />
                        </a>
                    </li>
                @endforeach
            </ol>
        </section>
    @endif

    @php $t = $this->totals; @endphp
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat label="Ventas" :value="Number::currency($t['sales'])"
                :note="$t['orders'].' '.($t['orders'] === 1 ? 'orden' : 'órdenes').($t['delta'] !== null ? ', '.($t['delta'] >= 0 ? '+' : '').round($t['delta']).'% vs periodo anterior' : '')" />
        <x-stat label="Ganancia por receta" :value="Number::currency($t['recipeProfit'])" :tone="$t['recipeProfit'] > 0 ? 'good' : null"
                :note="$t['margin'] !== null ? round($t['margin']).'% de margen' : null" />
        <x-stat label="Gastos" :value="Number::currency($t['expenses'])" />
        <x-stat label="Ventas menos gastos" :value="Number::currency($t['cash'])" :tone="$t['cash'] < 0 ? 'bad' : null" />
    </div>
    <p class="text-sm text-muted">
        La ganancia por receta resta a cada venta el costo de sus insumos. Ventas menos gastos usa lo que realmente pagaste, por eso baja el día que compras mucha mercancía.
        @if ($t['orders'] > 0) Ticket promedio: <span class="font-semibold text-ink">{{ Number::currency($t['average']) }}</span>. @endif
    </p>

    <section class="space-y-2">
        <h2 class="px-1 font-display text-2xl font-bold">Lo que más se vende</h2>
        @if ($this->topProducts->isEmpty())
            <x-empty icon="receipt" title="Sin ventas en este periodo">Cuando cobres pedidos verás aquí qué productos dejan más.</x-empty>
        @else
            @php $maxQty = max(1, (int) $this->topProducts->max('qty')); @endphp
            <ul class="card divide-y divide-line !p-0">
                @foreach ($this->topProducts as $row)
                    <li class="px-4 py-3">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="truncate font-semibold">{{ $row->product_name }}</span>
                            <span class="num text-lg font-bold">{{ (int) $row->qty }} <span class="text-sm font-medium text-muted">vendidos</span></span>
                        </div>
                        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-sunken"><div class="h-full rounded-full bg-sales" style="width: {{ (int) $row->qty / $maxQty * 100 }}%"></div></div>
                        <p class="mt-1.5 text-sm text-muted">
                            {{ Number::currency((float) $row->revenue) }} en ventas@if ((float) $row->cost > 0), deja <span class="font-semibold text-salsa">{{ Number::currency((float) $row->revenue - (float) $row->cost) }}</span>@endif
                        </p>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    @if ($this->days->count() > 1)
        @php
            $days = $this->days;
            $maxDay = max(1, $days->max('sales'), $days->max('expenses'));
            $peakDay = $days->sortByDesc('sales')->first();
        @endphp
        <section class="space-y-2">
            <h2 class="px-1 font-display text-2xl font-bold">Ventas y gastos por día</h2>
            <div class="card space-y-3" role="img" aria-label="Ventas y gastos por día. El mejor día fue {{ $peakDay['date']->translatedFormat('l d') }} con {{ Number::currency($peakDay['sales']) }} en ventas.">
                <div class="flex gap-4 text-sm">
                    <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-sales"></span> Ventas</span>
                    <span class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-spend"></span> Gastos</span>
                </div>
                <div class="flex h-36 items-end gap-[3px]">
                    @foreach ($days as $row)
                        <div class="flex h-full min-w-0 flex-1 items-end justify-center gap-px" title="{{ $row['date']->translatedFormat('D d M') }}: ventas {{ Number::currency($row['sales']) }}, gastos {{ Number::currency($row['expenses']) }}">
                            <div class="w-full max-w-4 rounded-t bg-sales" style="height: {{ $row['sales'] / $maxDay * 100 }}%"></div>
                            <div class="w-full max-w-4 rounded-t bg-spend" style="height: {{ $row['expenses'] / $maxDay * 100 }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-between text-xs text-muted">
                    <span>{{ $days->first()['date']->translatedFormat('d M') }}</span>
                    <span>Mejor día: <span class="font-semibold text-ink">{{ $peakDay['date']->translatedFormat('D d') }}, {{ Number::currency($peakDay['sales']) }}</span></span>
                    <span>{{ $days->last()['date']->translatedFormat('d M') }}</span>
                </div>
            </div>
        </section>
    @endif

    @if ($this->hours->isNotEmpty())
        @php
            $hours = $this->hours;
            $maxHour = max(1, $hours->max('sales'));
            $peak = $hours->sortByDesc('sales')->first();
        @endphp
        <section class="space-y-2">
            <h2 class="px-1 font-display text-2xl font-bold">Cuándo se vende más</h2>
            <div class="card space-y-3" role="img" aria-label="Ventas por hora. La hora con más ventas es {{ $this->hourLabel($peak['hour']) }}.">
                <div class="flex h-28 items-end gap-1">
                    @foreach ($hours as $h)
                        <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $this->hourLabel($h['hour']) }}: {{ Number::currency($h['sales']) }}">
                            <div @class(['w-full max-w-6 rounded-t', 'bg-sales' => $h['hour'] === $peak['hour'], 'bg-sales/45' => $h['hour'] !== $peak['hour']]) style="height: {{ max(2, $h['sales'] / $maxHour * 100) }}%"></div>
                        </div>
                    @endforeach
                </div>
                <div class="flex gap-1 text-[11px] text-muted">
                    @foreach ($hours as $h)
                        <span class="min-w-0 flex-1 text-center">{{ $loop->first || $loop->last || $h['hour'] === $peak['hour'] || $hours->count() <= 8 ? $this->hourLabel($h['hour']) : '' }}</span>
                    @endforeach
                </div>
                <p class="text-sm text-muted">La hora fuerte es a las <span class="font-semibold text-ink">{{ $this->hourLabel($peak['hour']) }}</span> con {{ Number::currency($peak['sales']) }} en el periodo.</p>
            </div>
        </section>
    @endif
</div>
