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

#[Fillable(['user_id', 'daily_number', 'status', 'label', 'service', 'delivery_address', 'delivery_phone', 'delivery_notes', 'payment_method', 'payment_reference', 'total', 'packaging_fee', 'delivery_fee', 'discount', 'tip', 'notes', 'sold_at', 'pickup_at', 'paid_at', 'ready_at', 'dispatched_at', 'delivered_at'])]
class Order extends Model
{
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_method' => PaymentMethod::class,
            'total' => 'decimal:2',
            'packaging_fee' => 'decimal:2',
            'delivery_fee' => 'decimal:2',
            'dispatched_at' => 'datetime',
            'discount' => 'decimal:2',
            'tip' => 'decimal:2',
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

    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class);
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
                ->orWhere(fn (Builder $q) => $q->where('status', OrderStatus::Paid)->whereNull('delivered_at')->whereRaw('COALESCE(paid_at, sold_at) >= ?', [today()]));
        });
    }

    /** Hora en que el cliente lo espera (la de recogida o la del pedido) dentro de [$from, $to). */
    public function scopeDueBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to = null): void
    {
        $query->when($from, fn (Builder $q) => $q->whereRaw('COALESCE(pickup_at, sold_at) >= ?', [$from]))
            ->when($to, fn (Builder $q) => $q->whereRaw('COALESCE(pickup_at, sold_at) < ?', [$to]));
    }

    /**
     * Órdenes que cuentan en el rango: las cobradas por la hora del cobro y las demás
     * por la hora del pedido. Así un pedido guardado anoche y cobrado hoy cuenta hoy,
     * igual que en el corte de caja.
     */
    public function scopeCountedBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereRaw('COALESCE(paid_at, sold_at) BETWEEN ? AND ?', [$from, $to]);
    }

    public function scopePaidBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('status', OrderStatus::Paid)->countedBetween($from, $to);
    }

    /** Cuándo cuenta la orden: al cobrarse o, si aún no, al tomarse. */
    public function countedAt(): CarbonInterface
    {
        return $this->paid_at ?? $this->sold_at;
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

    public function isDelivery(): bool
    {
        return $this->service === 'delivery';
    }

    public function serviceLabel(): ?string
    {
        return match ($this->service) {
            'togo' => 'Para llevar',
            'here' => 'Aquí',
            'delivery' => 'A domicilio',
            default => null,
        };
    }

    /** Número para WhatsApp: 10 dígitos de México se completan con 52. */
    public function whatsappNumber(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->delivery_phone);

        return match (true) {
            strlen($digits) === 10 => '52'.$digits,
            strlen($digits) > 10 => $digits,
            default => null,
        };
    }

    /** Número consecutivo del día (#1, #2...) para llamar el pedido. */
    public static function nextDailyNumber(): int
    {
        return (int) static::where('sold_at', '>=', today())->max('daily_number') + 1;
    }
}
