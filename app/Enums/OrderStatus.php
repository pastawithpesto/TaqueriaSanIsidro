<?php

namespace App\Enums;

enum OrderStatus: string
{
    /** Pedido guardado con nombre, todavía sin cobrar. */
    case Open = 'open';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Por cobrar',
            self::Paid => 'Pagado',
            self::Cancelled => 'Cancelado',
        };
    }
}
