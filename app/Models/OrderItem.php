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

    /** "Sin cebolla", "Sin jitomate". */
    public function withoutNames(): array
    {
        return collect($this->modifiers ?? [])->where('type', 'without')->pluck('name')->all();
    }

    /** Extras cobrados: [['name' => 'Extra queso', 'price' => 10.0]]. */
    public function extras(): array
    {
        return collect($this->modifiers ?? [])->where('type', 'extra')->values()->all();
    }
}
