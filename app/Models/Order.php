<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'daily_number', 'status', 'label', 'service', 'payment_method', 'payment_reference', 'total', 'packaging_fee', 'notes', 'sold_at', 'pickup_at', 'paid_at', 'ready_at', 'delivered_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'total' => 'decimal:2',
            'packaging_fee' => 'decimal:2',
            'sold_at' => 'datetime',
            'pickup_at' => 'datetime',
            'ready_at' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pedidos activos: todo lo que está por cobrar (aunque ya se haya entregado)
     * y lo pagado hoy que falta entregar.
     */
    public function scopePending(Builder $query): void
    {
        $query->where(function (Builder $q) {
            $q->where('status', OrderStatus::Open)
                ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Paid)->whereNull('delivered_at')->where('sold_at', '>=', today()));
        });
    }

    /** Hora en que el cliente lo espera: la de recogida o, si no hay, la del pedido. */
    public function dueAt(): CarbonInterface
    {
        return $this->pickup_at ?? $this->sold_at;
    }

    public function isToGo(): bool
    {
        return $this->service === 'togo';
    }

    /** Número consecutivo del día (#1, #2...) para llamar el pedido. */
    public static function nextDailyNumber(): int
    {
        return (int) static::where('sold_at', '>=', today())->max('daily_number') + 1;
    }
}
