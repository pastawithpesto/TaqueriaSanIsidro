<?php

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cuánto insumo se usó, a partir de lo vendido y las recetas actuales.
 * Respeta los "sin" (no se usó ese ingrediente) y los extras con insumo.
 */
class Consumption
{
    /** @return Collection<string, array<int, float>> fecha => [ingredient_id => cantidad] */
    public static function byDay(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $items = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.status', OrderStatus::Paid->value)
            ->whereBetween('orders.sold_at', [$from, $to])
            ->whereNotNull('order_items.product_id')
            ->get(['order_items.*', 'orders.sold_at']);

        $products = Product::with(['ingredients', 'extras'])
            ->whereIn('id', $items->pluck('product_id')->unique())
            ->get()->keyBy('id');

        $days = collect();

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if (! $product) {
                continue;
            }

            $day = BusinessDay::of(CarbonImmutable::parse($item->sold_at))->toDateString();
            $without = collect($item->modifiers ?? [])->where('type', 'without')->pluck('id')->all();
            $extraIds = collect($item->modifiers ?? [])->where('type', 'extra')->pluck('id')->all();
            $usage = $days->get($day, []);

            foreach ($product->ingredients as $ingredient) {
                if (in_array($ingredient->id, $without) && $ingredient->pivot->removable) {
                    continue;
                }
                $usage[$ingredient->id] = ($usage[$ingredient->id] ?? 0) + (float) $ingredient->pivot->quantity * $item->quantity;
            }

            foreach ($product->extras->whereIn('id', $extraIds) as $extra) {
                if ($extra->ingredient_id && $extra->quantity) {
                    $usage[$extra->ingredient_id] = ($usage[$extra->ingredient_id] ?? 0) + (float) $extra->quantity * $item->quantity;
                }
            }

            $days->put($day, $usage);
        }

        return $days;
    }

    /**
     * Uso promedio por día para preparar una fecha: se toman los mismos días
     * de la semana con ventas en las últimas 4 semanas; si no hay, los últimos 14 días con ventas.
     *
     * @return array{usage: array<int, float>, basis: string, days: int}
     */
    public static function forecast(CarbonImmutable $date): array
    {
        $byDay = static::byDay(BusinessDay::start($date->subWeeks(4)), BusinessDay::end($date->subDay()));

        $sameWeekday = $byDay->filter(fn ($u, $day) => CarbonImmutable::parse($day)->dayOfWeek === $date->dayOfWeek);
        $sample = $sameWeekday->isNotEmpty() ? $sameWeekday : $byDay->sortKeysDesc()->take(14);

        if ($sample->isEmpty()) {
            return ['usage' => [], 'basis' => '', 'days' => 0];
        }

        $totals = [];
        foreach ($sample as $usage) {
            foreach ($usage as $id => $qty) {
                $totals[$id] = ($totals[$id] ?? 0) + $qty;
            }
        }

        $n = $sample->count();
        $weekday = $date->locale('es')->dayName;

        return [
            'usage' => array_map(fn ($q) => $q / $n, $totals),
            'basis' => $sameWeekday->isNotEmpty()
                ? ($n === 1 ? "el último {$weekday}" : "los últimos {$n} {$weekday}s")
                : ($n === 1 ? 'el último día con ventas' : "los últimos {$n} días con ventas"),
            'days' => $n,
        ];
    }
}
