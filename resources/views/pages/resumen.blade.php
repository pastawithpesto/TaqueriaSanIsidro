<?php

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /** Fechas de días de trabajo: lo vendido de madrugada cuenta en el día anterior si así se configuró (BusinessDay). */
    #[Computed]
    public function from(): CarbonImmutable
    {
        $today = BusinessDay::today();

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
        return BusinessDay::today()->endOfDay();
    }

    /** Periodo anterior de la misma duración, para comparar. */
    protected function previous(): array
    {
        $days = $this->from->diffInDays($this->to->startOfDay()) + 1;

        return [$this->from->subDays($days), $this->from->subSecond()];
    }

    /** Partidas de lo cobrado en el rango (por la hora del cobro, igual que Ventas y el corte). */
    protected function paidItems(CarbonImmutable $from, CarbonImmutable $to)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereRaw('COALESCE(orders.paid_at, orders.sold_at) BETWEEN ? AND ?', [BusinessDay::start($from), BusinessDay::end($to)]);
    }

    protected function paidOrders(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::paidBetween(BusinessDay::start($from), BusinessDay::end($to));
    }

    #[Computed]
    public function totals(): array
    {
        $sales = (float) $this->paidOrders($this->from, $this->to)->sum('total');
        $orders = $this->paidOrders($this->from, $this->to)->count();
        $recipeCost = (float) $this->paidItems($this->from, $this->to)->sum(DB::raw('order_items.quantity * order_items.unit_cost'));
        $expenses = (float) Expense::whereDate('spent_on', '>=', $this->from)->whereDate('spent_on', '<=', $this->to)->sum('total');

        $paidOrders = $this->paidOrders($this->from, $this->to);
        $tips = (float) (clone $paidOrders)->sum('tip');
        $delivery = (float) (clone $paidOrders)->sum('delivery_fee');
        $discounts = (float) (clone $paidOrders)->sum('discount')
            + (float) $this->paidItems($this->from, $this->to)->where('order_items.modifiers', 'like', '%courtesy%')->get(['order_items.modifiers', 'order_items.quantity'])
                ->sum(fn ($i) => (float) (collect(json_decode($i->modifiers, true))->firstWhere('type', 'courtesy')['value'] ?? 0) * $i->quantity);

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
            'tips' => $tips,
            'discounts' => $discounts,
            'delivery' => $delivery,
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
            ->get(['sold_at', 'paid_at', 'total'])
            ->groupBy(fn ($o) => BusinessDay::of($o->countedAt())->toDateString())
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
        // Las horas se acomodan desde que empieza el día de trabajo: de 6 pm a 2 am quedan seguidas.
        $endHour = BusinessDay::endHour();
        $byHour = $this->paidOrders($this->from, $this->to)
            ->get(['sold_at', 'total'])
            ->groupBy(fn ($o) => ($o->sold_at->hour - $endHour + 24) % 24)
            ->map->sum('total');

        if ($byHour->isEmpty()) {
            return collect();
        }

        return collect(range($byHour->keys()->min(), $byHour->keys()->max()))
            ->map(fn ($h) => ['hour' => ($h + $endHour) % 24, 'sales' => (float) ($byHour[$h] ?? 0)]);
    }

    /** Promedio de venta por día de la semana en las últimas 8 semanas (sin contar hoy). */
    #[Computed]
    public function weekdays(): array
    {
        $from = BusinessDay::today()->subWeeks(8);
        $to = BusinessDay::today()->subSecond();
        $day = 'DATE(COALESCE(orders.paid_at, orders.sold_at) - INTERVAL \''.BusinessDay::endHour().' hours\')';
        $weekday = fn ($row) => CarbonImmutable::parse($row->day)->dayOfWeekIso;

        // Se suma en la base: un renglón por día y uno por producto y día, no todas las órdenes.
        $sales = DB::table('orders')
            ->where('status', OrderStatus::Paid->value)
            ->whereRaw('COALESCE(orders.paid_at, orders.sold_at) BETWEEN ? AND ?', [BusinessDay::start($from), BusinessDay::end($to)])
            ->selectRaw("{$day} as day, SUM(total) as total")
            ->groupByRaw($day)
            ->get()
            ->groupBy($weekday);

        $products = $this->paidItems($from, $to)
            ->selectRaw("{$day} as day, order_items.product_name, SUM(order_items.quantity) as qty")
            ->groupByRaw("{$day}, order_items.product_name")
            ->get()
            ->groupBy($weekday);

        $names = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

        return collect($names)->map(function ($label, $iso) use ($sales, $products) {
            $days = $sales->get($iso, collect())->count();
            $top = $products->get($iso, collect())->groupBy('product_name')->map(fn ($rows) => $rows->sum('qty'))->sortDesc();

            return [
                'label' => $label,
                'avg' => $days ? (float) $sales->get($iso)->sum('total') / $days : 0.0,
                'days' => $days,
                'top' => $top->keys()->first(),
                'topQty' => $days ? (int) round($top->first() / $days) : 0,
            ];
        })->all();
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

    /**
     * Las etiquetas de las barras de las orillas se alinean hacia adentro para no salirse de la tarjeta.
     *
     * @return array<int, string>
     */
    public function tipAlign(object $loop): array
    {
        return match (true) {
            $loop->index < 2 => ['left-0'],
            $loop->remaining < 2 => ['right-0'],
            default => ['left-1/2', '-translate-x-1/2'],
        };
    }

    public function hourLabel(int $h): string
    {
        return ($h % 12 === 0 ? 12 : $h % 12).($h < 12 ? 'am' : 'pm');
    }

    #[Computed]
    public function comparedTo(): string
    {
        return match ($this->range) {
            'today' => 'vs ayer',
            'week' => 'vs los 7 días anteriores',
            'last30' => 'vs los 30 días anteriores',
            default => 'vs los días previos',
        };
    }
};
?>

@php
    $t = $this->totals;
    $steps = $this->setup;
    $pending = collect($steps)->contains(fn ($s) => ! $s[2]);
@endphp

<div>
    <x-page-header title="Resumen" tour="resumen">
        <div data-tour="periodo" class="segmented md:max-w-md" role="group" aria-label="Periodo" x-data="{ range: $wire.$entangle('range', true) }">
            @foreach ($this::RANGES as $key => $label)
                <button type="button" @click="range = '{{ $key }}'" :aria-pressed="range === '{{ $key }}'">{{ $label }}</button>
            @endforeach
        </div>
    </x-page-header>

    <div class="space-y-5 transition-opacity duration-200" wire:loading.class="pointer-events-none animate-pulse opacity-50" wire:target="range">
        @if ($pending)
            <section class="card !p-5" data-tour="para-empezar">
                <h2 class="section-title">Para empezar</h2>
                <p class="mt-0.5 mb-2 text-sm text-muted">Con estos pasos la app calcula cuánto ganas.</p>
                <ol class="divide-y divide-line">
                    @foreach ($steps as $i => [$title, $text, $done, $route])
                        <li>
                            <a href="{{ route($route) }}" wire:navigate class="flex items-center gap-3 py-3">
                                <span @class([
                                    'num grid size-9 shrink-0 place-items-center rounded-full text-lg font-bold',
                                    'bg-salsa-soft text-salsa' => $done,
                                    'bg-limon text-on-limon' => ! $done,
                                ])>@if ($done)<x-icon name="check" class="size-5" />@else{{ $i + 1 }}@endif</span>
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

        {{-- Ventas del periodo --}}
        <section class="card !p-5" data-tour="resumen-ventas">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm font-semibold text-muted">Ventas</p>
                @if ($t['delta'] !== null)
                    <span @class([
                        'badge !text-[13px]',
                        'bg-salsa-soft text-salsa' => $t['delta'] >= 0,
                        'bg-chile-soft text-chile' => $t['delta'] < 0,
                    ])>
                        <x-icon :name="$t['delta'] >= 0 ? 'up' : 'trend-down'" class="size-3.5" />
                        {{ ($t['delta'] >= 0 ? '+' : '').round($t['delta']) }}% {{ $this->comparedTo }}
                    </span>
                @endif
            </div>
            <p class="num mt-1 text-[3.25rem] leading-none font-bold md:text-[4rem]">{{ money(round($t['sales'])) }}</p>
            <dl class="mt-4 grid grid-cols-3 divide-x divide-line border-t border-line pt-3.5">
                <div class="pr-3">
                    <dt class="text-xs font-semibold text-muted">Órdenes</dt>
                    <dd class="num text-2xl font-bold">{{ $t['orders'] }}</dd>
                </div>
                <div class="px-3">
                    <dt class="text-xs font-semibold text-muted">Por orden</dt>
                    <dd class="num text-2xl font-bold">{{ money(round($t['average'])) }}</dd>
                </div>
                <div class="pl-3">
                    <dt class="text-xs font-semibold text-muted">Margen</dt>
                    <dd class="num text-2xl font-bold">{{ $t['margin'] !== null ? round($t['margin']).'%' : '-' }}</dd>
                </div>
            </dl>
        </section>

        {{-- Estado de resultados --}}
        <section class="card !p-0" data-tour="resumen-ganancia">
            <h2 class="section-title px-5 pt-4 pb-2">Ganancia</h2>
            <dl class="text-[15px]">
                <div class="flex items-center justify-between px-5 py-2">
                    <dt class="text-muted">
                        Ventas
                        @if ($t['delivery'] > 0) <span class="block text-xs">incluye {{ money(round($t['delivery'])) }} de envíos a domicilio</span> @endif
                    </dt>
                    <dd class="num text-lg font-semibold">{{ money(round($t['sales'])) }}</dd>
                </div>
                <div class="flex items-center justify-between px-5 py-2">
                    <dt class="text-muted">Costo de insumos, según recetas</dt>
                    <dd class="num text-lg font-semibold">−{{ money(round($t['recipeCost'])) }}</dd>
                </div>
                <div class="mx-5 flex items-center justify-between border-t border-line py-3">
                    <dt class="font-semibold">Ganancia por receta</dt>
                    <dd @class(['num text-[1.75rem] leading-none font-bold', 'text-salsa' => $t['recipeProfit'] > 0, 'text-chile' => $t['recipeProfit'] < 0])>{{ money(round($t['recipeProfit'])) }}</dd>
                </div>
                <div class="mt-1 flex items-center justify-between gap-4 bg-sunken/60 px-5 py-3">
                    <dt>
                        <span class="block font-semibold">Ventas menos gastos</span>
                        <span class="block text-sm text-muted">Lo que entró menos lo que pagaste ({{ money(round($t['expenses'])) }})</span>
                    </dt>
                    <dd @class(['num text-[1.75rem] leading-none font-bold', 'text-chile' => $t['cash'] < 0])>{{ money(round($t['cash'])) }}</dd>
                </div>
            </dl>
            @if ($t['tips'] > 0 || $t['discounts'] > 0)
                <div class="flex flex-wrap gap-x-6 gap-y-1 border-t border-line px-5 py-3 text-sm text-muted">
                    @if ($t['discounts'] > 0) <span>Descuentos y cortesías <span class="num text-base font-bold text-ink">{{ money(round($t['discounts'])) }}</span></span> @endif
                    @if ($t['tips'] > 0) <span>Propinas, aparte de las ventas <span class="num text-base font-bold text-ink">{{ money($t['tips']) }}</span></span> @endif
                </div>
            @endif
            <details class="group border-t border-line px-5 py-3 text-sm">
                <summary class="flex cursor-pointer list-none items-center gap-1.5 font-semibold text-muted [&::-webkit-details-marker]:hidden">
                    <x-icon name="info" class="size-4" /> ¿Por qué son distintas?
                </summary>
                <p class="mt-2 text-muted">La ganancia por receta resta a cada venta lo que costaron sus ingredientes. Ventas menos gastos compara el dinero que entró con lo que pagaste en el periodo, así que baja los días que compras mucha mercancía y sube los demás.</p>
            </details>
        </section>

        <div class="grid gap-5 lg:grid-cols-2 lg:items-start">
            {{-- Productos --}}
            <section class="space-y-2" data-tour="resumen-top">
                <h2 class="section-title px-1">Lo que más se vende</h2>
                @if ($this->topProducts->isEmpty())
                    <x-empty icon="receipt" title="Sin ventas en este periodo">Cuando cobres pedidos verás aquí qué productos dejan más.</x-empty>
                @else
                    @php $maxQty = max(1, (int) $this->topProducts->max('qty')); @endphp
                    <ol class="list">
                        @foreach ($this->topProducts as $row)
                            @php $profit = (float) $row->revenue - (float) $row->cost; @endphp
                            <li class="flex items-center gap-3 px-4 py-3">
                                <span class="num w-5 text-lg font-bold text-muted">{{ $loop->iteration }}</span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="truncate font-semibold">{{ $row->product_name }}</span>
                                        <span class="num shrink-0 text-lg font-bold">{{ money(round((float) $row->revenue)) }}</span>
                                    </div>
                                    <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-sunken"><div class="h-full rounded-full bg-sales" style="width: {{ (int) $row->qty / $maxQty * 100 }}%"></div></div>
                                    <div class="mt-1 flex justify-between text-sm text-muted">
                                        <span>{{ (int) $row->qty }} {{ (int) $row->qty === 1 ? 'vendido' : 'vendidos' }}</span>
                                        @if ((float) $row->cost > 0)
                                            <span>ganancia <span @class(['font-semibold', $profit >= 0 ? 'text-salsa' : 'text-chile'])>{{ money(round($profit)) }}</span></span>
                                        @else
                                            <span>sin receta</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <div class="space-y-5">
                @if ($this->days->count() > 1)
                    @php
                        $days = $this->days;
                        $maxDay = max(1, $days->max('sales'), $days->max('expenses'));
                        $peakDay = $days->sortByDesc('sales')->first();
                        $showAll = $days->count() <= 7;
                    @endphp
                    <section class="space-y-2" data-tour="resumen-dias">
                        <h2 class="section-title px-1">Por día</h2>
                        <div class="card !p-5" role="img" aria-label="Ventas y gastos por día. El mejor día fue {{ $peakDay['date']->translatedFormat('l j') }} con {{ money(round($peakDay['sales'])) }}.">
                            <div class="mb-4 flex items-center justify-between text-sm">
                                <div class="flex gap-4">
                                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-sales"></span> Ventas</span>
                                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-spend"></span> Gastos</span>
                                </div>
                                <span class="text-muted">Mejor: <span class="font-semibold text-ink">{{ $peakDay['date']->translatedFormat('D j') }}</span></span>
                            </div>
                            <div class="mt-6 flex h-40 items-end gap-1 border-b border-line">
                                @foreach ($days as $row)
                                    <div class="group relative flex h-full min-w-0 flex-1 items-end justify-center gap-[2px]" tabindex="0"
                                         @pointerdown="if (document.activeElement === $el) { $event.preventDefault(); $el.blur() }">
                                        <div class="relative w-full max-w-5 rounded-t-[4px] bg-sales" style="height: {{ $row['sales'] / $maxDay * 100 }}%">
                                            @if ($row === $peakDay && $row['sales'] > 0)
                                                <span class="num absolute bottom-full left-1/2 mb-1 -translate-x-1/2 text-xs font-bold whitespace-nowrap">{{ money(round($row['sales'])) }}</span>
                                            @endif
                                        </div>
                                        <div class="w-full max-w-5 rounded-t-[4px] bg-spend" style="height: {{ $row['expenses'] / $maxDay * 100 }}%"></div>
                                        <div @class(['pointer-events-none absolute bottom-full z-10 mb-1 hidden rounded-lg bg-ink px-2.5 py-1.5 text-xs whitespace-nowrap text-bg shadow-lg group-hover:block group-focus:block', ...$this->tipAlign($loop)])>
                                            <span class="font-semibold">{{ ucfirst($row['date']->translatedFormat('D j M')) }}</span><br>
                                            Ventas {{ money($row['sales']) }}<br>Gastos {{ money($row['expenses']) }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-1.5 flex gap-1 text-[11px] text-muted">
                                @foreach ($days as $row)
                                    <span class="min-w-0 flex-1 text-center">{{ $showAll || $loop->first || $loop->last || $loop->index % 7 === 0 ? $row['date']->translatedFormat($showAll ? 'D j' : 'j M') : '' }}</span>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif

                @php
                    $week = collect($this->weekdays);
                    $maxAvg = max(1, $week->max('avg'));
                    $bestDay = $week->sortByDesc('avg')->keys()->first();
                @endphp
                @if ($week->sum('days') > 0)
                    <section class="space-y-2" data-tour="resumen-semana">
                        <h2 class="section-title px-1">Por día de la semana</h2>
                        <div class="card space-y-3 !p-5">
                            <p class="text-sm text-muted">Promedio de venta de las últimas 8 semanas, y lo que más se vende ese día.</p>
                            <ul class="space-y-2.5">
                                @foreach ($week as $iso => $d)
                                    <li class="grid grid-cols-[5.5rem_1fr_auto] items-center gap-3 text-sm">
                                        <span @class(['font-semibold', 'text-muted' => ! $d['days']])>{{ $d['label'] }}</span>
                                        <span class="min-w-0">
                                            <span class="block h-2.5 overflow-hidden rounded-full bg-sunken">
                                                <span @class(['block h-full rounded-full', 'bg-sales' => $iso === $bestDay, 'bg-sales/45' => $iso !== $bestDay]) style="width: {{ $d['avg'] / $maxAvg * 100 }}%"></span>
                                            </span>
                                            @if ($d['top'])
                                                <span class="mt-0.5 block text-xs leading-snug text-muted">Lo que más sale: {{ $d['top'] }} ({{ $d['topQty'] }} al día)</span>
                                            @endif
                                        </span>
                                        <span class="num text-right text-base font-bold">{{ $d['days'] ? money(round($d['avg'])) : '-' }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </section>
                @endif

                @if ($this->hours->isNotEmpty())
                    @php
                        $hours = $this->hours;
                        $maxHour = max(1, $hours->max('sales'));
                        $peak = $hours->sortByDesc('sales')->first();
                    @endphp
                    <section class="space-y-2" data-tour="resumen-horas">
                        <h2 class="section-title px-1">Horas fuertes</h2>
                        <div class="card !p-5" role="img" aria-label="Ventas por hora. La hora con más ventas es {{ $this->hourLabel($peak['hour']) }}.">
                            <p class="mb-4 text-sm text-muted">Se vende más a las <span class="font-semibold text-ink">{{ $this->hourLabel($peak['hour']) }}</span>, con {{ money(round($peak['sales'])) }} en el periodo.</p>
                            <div class="mt-6 flex h-28 items-end gap-1.5 border-b border-line">
                                @foreach ($hours as $h)
                                    @php $isPeak = $h['hour'] === $peak['hour']; @endphp
                                    <div class="group relative flex h-full min-w-0 flex-1 items-end justify-center" tabindex="0"
                                         @pointerdown="if (document.activeElement === $el) { $event.preventDefault(); $el.blur() }">
                                        <div @class(['relative w-full max-w-7 rounded-t-[4px]', 'bg-sales' => $isPeak, 'bg-sales/35' => ! $isPeak]) style="height: {{ max(2, $h['sales'] / $maxHour * 100) }}%">
                                            @if ($isPeak)
                                                <span class="num absolute bottom-full left-1/2 mb-1 -translate-x-1/2 text-xs font-bold whitespace-nowrap">{{ money(round($h['sales'])) }}</span>
                                            @endif
                                        </div>
                                        <div @class(['pointer-events-none absolute bottom-full z-10 mb-1 hidden rounded-lg bg-ink px-2.5 py-1.5 text-xs whitespace-nowrap text-bg shadow-lg group-hover:block group-focus:block', ...$this->tipAlign($loop)])>
                                            {{ $this->hourLabel($h['hour']) }}: {{ money($h['sales']) }}
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-1.5 flex gap-1.5 text-[11px] text-muted">
                                @foreach ($hours as $h)
                                    <span class="min-w-0 flex-1 text-center">{{ $hours->count() <= 10 || $loop->index % 2 === 0 ? $this->hourLabel($h['hour']) : '' }}</span>
                                @endforeach
                            </div>
                        </div>
                    </section>
                @endif
            </div>
        </div>
    </div>
</div>
