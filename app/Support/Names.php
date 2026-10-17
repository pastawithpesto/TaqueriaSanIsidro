<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Nombres de insumos y productos: sin caracteres invisibles y sin repetirse aunque cambien mayúsculas o acentos. */
class Names
{
    /** Quita caracteres de control o invisibles y deja un solo espacio entre palabras. */
    public static function clean(?string $name): string
    {
        // Tabuladores y saltos de línea separan palabras: se vuelven espacio antes de quitar el resto de los invisibles.
        $name = preg_replace('/[\t\n\r\x0B\f]+/u', ' ', (string) $name) ?? '';
        $name = preg_replace('/[\p{Cc}\p{Cf}]/u', '', $name) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    }

    /** "Limón", "limon" y "LIMÓN " son el mismo nombre. */
    public static function plain(string $name): string
    {
        $decomposed = \Normalizer::normalize(static::clean($name), \Normalizer::FORM_D) ?: $name;

        return Str::lower(preg_replace('/\p{Mn}/u', '', $decomposed) ?? $name);
    }

    /** Si ya hay otro registro de la tabla con ese nombre; $exceptId deja fuera al que se edita. */
    public static function taken(string $table, string $name, ?int $exceptId = null): bool
    {
        $plain = static::plain($name);

        return DB::table($table)
            ->when($exceptId, fn ($query) => $query->where('id', '!=', $exceptId))
            ->pluck('name')
            ->contains(fn (string $existing) => static::plain($existing) === $plain);
    }

    /** "Pastor (copia)", y si ya existe, "Pastor (copia 2)". */
    public static function copyOf(string $table, string $name): string
    {
        $base = mb_substr($name, 0, 230);

        foreach (array_merge([$base.' (copia)'], array_map(fn ($n) => $base.' (copia '.$n.')', range(2, 99))) as $candidate) {
            if (! static::taken($table, $candidate)) {
                return $candidate;
            }
        }

        return $base.' (copia '.Str::random(4).')';
    }
}
