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
     * @return array{items: array<int, array<string, mixed>>, total: float, missing: array<int, string>}
     */
    public static function items(array $lines): array
    {
        $lines = collect($lines)
            ->filter(fn ($l) => is_array($l) && (int) ($l['qty'] ?? 0) > 0 && isset($l['product_id']))
            ->values();

        $products = Product::with(['ingredients', 'extras.ingredient', 'optionGroups.choices'])
            ->where('active', true)
            ->whereIn('id', $lines->pluck('product_id')->map(fn ($id) => (int) $id))
            ->get()
            ->keyBy('id');

        $items = [];
        $total = 0.0;
        $missing = [];

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

            // Una opción por grupo; los grupos obligatorios deben tener una.
            $picked = collect($line['choices'] ?? [])->map(fn ($id) => (int) $id);
            $choices = collect();
            foreach ($product->optionGroups as $group) {
                // Si llegan dos del mismo grupo, se respeta la primera que se eligió.
                $choice = $picked->map(fn ($id) => $group->choices->firstWhere('id', $id))->filter()->first();
                if ($choice) {
                    $choices->push(['group' => $group, 'choice' => $choice]);
                } elseif ($group->required) {
                    $missing[] = $group->name.' en '.$product->name;
                }
            }

            $unitPrice = round((float) $product->price + $extras->sum(fn ($e) => (float) $e->price) + $choices->sum(fn ($c) => (float) $c['choice']->price), 2);
            $unitCost = round($product->unitCost($without->all()) + $extras->sum(fn ($e) => $e->cost()), 4);

            // Cortesía: la pieza no se cobra; se guarda su valor para los reportes.
            $courtesy = ! empty($line['courtesy']);
            $listPrice = $unitPrice;
            if ($courtesy) {
                $unitPrice = 0.0;
            }

            $subtotal = round($unitPrice * $qty, 2);
            $total += $subtotal;

            $modifiers = $choices->map(fn ($c) => ['type' => 'choice', 'id' => $c['choice']->id, 'group' => $c['group']->name, 'name' => $c['choice']->name, 'price' => (float) $c['choice']->price])
                ->concat($without->map(fn ($id) => ['type' => 'without', 'id' => $id, 'name' => $removable[$id]->name]))
                ->concat($extras->map(fn ($e) => ['type' => 'extra', 'id' => $e->id, 'name' => $e->name, 'price' => (float) $e->price]))
                ->when($courtesy, fn ($m) => $m->push(['type' => 'courtesy', 'value' => $listPrice]))
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

        return ['items' => $items, 'total' => round($total, 2), 'missing' => array_values(array_unique($missing))];
    }

    /** Reemplaza las partidas de la orden y actualiza su total (productos más empaque). */
    public static function fill(Order $order, array $built): void
    {
        $order->items()->delete();
        $order->items()->createMany($built['items']);
        // El descuento aplica a la comida y el empaque; el envío se suma aparte.
        $order->update(['total' => max(0, round($built['total'] + (float) $order->packaging_fee - (float) $order->discount, 2)) + (float) $order->delivery_fee]);
    }

    /** Renglones del navegador a partir de una orden guardada (para editarla). */
    public static function linesFrom(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'product_id' => $item->product_id,
            'qty' => $item->quantity,
            'without' => collect($item->modifiers ?? [])->where('type', 'without')->pluck('id')->values()->all(),
            'extras' => collect($item->modifiers ?? [])->where('type', 'extra')->pluck('id')->values()->all(),
            'choices' => collect($item->modifiers ?? [])->where('type', 'choice')->pluck('id')->values()->all(),
            'courtesy' => collect($item->modifiers ?? [])->contains('type', 'courtesy'),
            'notes' => $item->notes ?? '',
        ])->filter(fn ($l) => $l['product_id'])->values()->all();
    }
}
