<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Transfer = 'transfer';
    case Card = 'card';
    /** Parte en efectivo y parte con otro método (ver order_payments). */
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Efectivo',
            self::Transfer => 'Transferencia',
            self::Card => 'Tarjeta',
            self::Mixed => 'Mixto',
        };
    }

    /** Métodos con los que se registra cada pago (sin "mixto"). */
    public static function simple(): array
    {
        return [self::Cash, self::Transfer, self::Card];
    }
}
