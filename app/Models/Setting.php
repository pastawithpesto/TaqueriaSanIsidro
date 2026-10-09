<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @var array<string, ?string>|null */
    protected static ?array $cache = null;

    public static function value(string $key, ?string $default = null): ?string
    {
        if (static::$cache === null) {
            // La tabla puede no existir aún (primer arranque, antes de migrar).
            static::$cache = Schema::hasTable('settings')
                ? static::query()->pluck('value', 'key')->all()
                : [];
        }

        $value = static::$cache[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            static::where('key', $key)->delete();
        } else {
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        static::flush();
    }

    public static function flush(): void
    {
        static::$cache = null;
    }
}
