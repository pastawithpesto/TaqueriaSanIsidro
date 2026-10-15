<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Día de trabajo. Si el negocio cierra después de medianoche, lo que se vende de madrugada
 * cuenta en el día en que empezó el turno: ventas, corte, resumen y el número de pedido.
 * La hora en que termina el día se elige en Ajustes; 0 es medianoche.
 */
class BusinessDay
{
    public const LATEST_END_HOUR = 6;

    public static function endHour(): int
    {
        return min(self::LATEST_END_HOUR, max(0, (int) Setting::value('day_ends_at', '0')));
    }

    /** Fecha (a las 00:00) del día de trabajo en curso. */
    public static function today(): CarbonImmutable
    {
        return static::of(now());
    }

    /** Fecha del día de trabajo al que pertenece un momento: las 2 am cuentan en el día anterior si se cierra a las 3. */
    public static function of(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->subHours(static::endHour())->startOfDay();
    }

    /** Momento en que empieza el día de trabajo de una fecha. */
    public static function start(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfDay()->addHours(static::endHour());
    }

    /** Último segundo del día de trabajo de una fecha. */
    public static function end(CarbonInterface $date): CarbonImmutable
    {
        return static::start($date)->addDay()->subSecond();
    }

    public static function startOfToday(): CarbonImmutable
    {
        return static::start(static::today());
    }

    /** Si un momento (una venta, un cobro) cae en el día de trabajo en curso. */
    public static function isCurrent(CarbonInterface $moment): bool
    {
        return static::of($moment)->equalTo(static::today());
    }
}
