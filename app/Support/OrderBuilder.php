<?php

namespace App\Support;

use App\Models\Order;
use App\Models\Product;

/**
 * Convierte los renglones que arma el navegador en partidas de la orden.
 * El servidor recalcula precios y costos con la base: lo que mande el
 * navegador solo dice qué producto, cuántos y con qué indicaciones.
 */
class OrderBuilder
{
    /**
     * @param  array<int, array{product_id: int, qty: int, without?: array<int>, extras?: array<int>, notes?: ?string}>  $lines
     * @return array{items: array<int, array<string, mixed>>, total: float}
     */
    public static function items(array $lines): array
    {
        $lines = collect($lines)
            ->filter(fn ($l) => is_array($l) && (int) ($l['qty'] ?? 0) > 0 && isset($l['product_id']))
            ->values();

        $products = Product::with(['ingredients', 'extras.ingredient'])
            ->where('active', true)
            ->whereIn('id', $lines->pluck('product_id')->map(fn ($id) => (int) $id))
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0.0;

        foreach ($lines as $line) {
            $product = $products->get((int) $line['product_id']);

            if (! $product) {
                continue;
            }

            $qty = min(999, (int) $line['qty']);
            $removable = $product->ingredients->filter(fn ($i) => $i->pivot->removable)->keyBy('id');
            $without = collect($line['without'] ?? [])->map(fn ($id) => (int) $id)->filter(fn ($id) => $removable->has($id))->unique()->values();
            $extras = $product->extras->whereIn('id', collect($line['extras'] ?? [])->map(fn ($id) => (int) $id))->values();
            $notes = trim((string) ($line['notes'] ?? '')) ?: null;

            $unitPrice = round((float) $product->price + $extras->sum(fn ($e) => (float) $e->price), 2);
            $unitCost = round($product->unitCost($without->all()) + $extras->sum(fn ($e) => $e->cost()), 4);
            $subtotal = round($unitPrice * $qty, 2);
            $total += $subtotal;

            $modifiers = $without->map(fn ($id) => ['type' => 'without', 'id' => $id, 'name' => $removable[$id]->name])
                ->concat($extras->map(fn ($e) => ['type' => 'extra', 'id' => $e->id, 'name' => $e->name, 'price' => (float) $e->price]))
                ->values()->all();

            $items[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'modifiers' => $modifiers ?: null,
                'notes' => $notes ? mb_substr($notes, 0, 120) : null,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
            ];
        }

        return ['items' => $items, 'total' => round($total, 2)];
    }

    /** Reemplaza las partidas de la orden y actualiza su total. */
    public static function fill(Order $order, array $built): void
    {
        $order->items()->delete();
        $order->items()->createMany($built['items']);
        $order->update(['total' => $built['total']]);
    }

    /** Renglones del navegador a partir de una orden guardada (para editarla). */
    public static function linesFrom(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'qty' => $item->quantity,
            'without' => collect($item->modifiers ?? [])->where('type', 'without')->pluck('id')->values()->all(),
            'extras' => collect($item->modifiers ?? [])->where('type', 'extra')->pluck('id')->values()->all(),
            'notes' => $item->notes ?? '',
        ])->filter(fn ($l) => $l['product_id'])->values()->all();
    }
}
