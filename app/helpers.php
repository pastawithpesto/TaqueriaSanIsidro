<?php

use Illuminate\Support\Number;

if (! function_exists('money')) {
    /** Pesos sin centavos cuando son exactos: $20, $39.50, $1,342.16. Los negativos con signo menos: −$15. */
    function money(float|int|string|null $amount): string
    {
        $amount = round((float) $amount, 2);

        // Evita "-$0" por redondeos.
        if (abs($amount) < 0.005) {
            $amount = 0.0;
        }

        $formatted = Number::currency(abs($amount), precision: fmod(abs($amount), 1) < 0.005 ? 0 : 2);

        return $amount < 0 ? '−'.$formatted : $formatted;
    }
}
