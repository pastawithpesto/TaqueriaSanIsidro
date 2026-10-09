<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['order_id', 'product_id', 'product_name', 'modifiers', 'notes', 'quantity', 'unit_price', 'unit_cost', 'subtotal'])]
class OrderItem extends Model
{
    protected function casts(): array
    {
        return [
            'modifiers' => 'array',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:4',
            'subtotal' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Pieza regalada: no se cobra, pero su costo sí cuenta. */
    public function isCourtesy(): bool
    {
        return collect($this->modifiers ?? [])->contains('type', 'courtesy');
    }

    /** Lo que habría costado la pieza si se cobrara. */
    public function courtesyValue(): float
    {
        return (float) (collect($this->modifiers ?? [])->firstWhere('type', 'courtesy')['value'] ?? 0) * $this->quantity;
    }

    /** "Sin cebolla", "Sin jitomate". */
    public function withoutNames(): array
    {
        return collect($this->modifiers ?? [])->where('type', 'without')->pluck('name')->all();
    }

    /** Opciones elegidas: [['group' => 'Refresco', 'name' => 'Coca-Cola', 'price' => 0.0]]. */
    public function choices(): array
    {
        return collect($this->modifiers ?? [])->where('type', 'choice')->values()->all();
    }

    /** Extras cobrados: [['name' => 'Extra queso', 'price' => 10.0]]. */
    public function extras(): array
    {
        return collect($this->modifiers ?? [])->where('type', 'extra')->values()->all();
    }
}
