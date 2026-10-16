<?php

namespace App\Enums;

enum OrderStatus: string
{
    /** Pedido guardado con nombre, todavía sin cobrar. */
    case Open = 'open';

    /** Pedido que hizo un cliente en línea y el taquero todavía no acepta ni rechaza. */
    case Requested = 'requested';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Por aceptar',
            self::Open => 'Por cobrar',
            self::Paid => 'Pagado',
            self::Cancelled => 'Cancelado',
        };
    }
}
