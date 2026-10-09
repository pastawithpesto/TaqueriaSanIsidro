<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Nombre y logo del negocio, editables desde Ajustes. */
class Branding
{
    public const SIZES = [64, 180, 192, 512];

    public const DEFAULT_NAME = 'Taquería San Isidro';

    public const DEFAULT_SHORT_NAME = 'San Isidro';

    /** 4000 × 4000: más que eso no cabe en memoria al procesarla. */
    public const MAX_PIXELS = 16_000_000;

    /** Fondo del icono de iOS, que no admite transparencia. */
    protected const IOS_BACKGROUND = [244, 243, 240];

    public static function name(): string
    {
        return Setting::value('business_name', self::DEFAULT_NAME);
    }

    public static function shortName(): string
    {
        return Setting::value('business_short_name', self::DEFAULT_SHORT_NAME);
    }

    public static function hasCustomLogo(): bool
    {
        return Setting::value('logo_version') !== null;
    }

    public static function logoUrl(int $size = 192): string
    {
        return route('brand.logo', ['size' => $size, 'v' => Setting::value('logo_version', 'default')]);
    }

    /** Ruta del archivo a servir: el subido o, si no hay, el que viene con la app. */
    public static function path(int $size): string
    {
        if (self::hasCustomLogo() && Storage::disk('local')->exists("branding/logo-$size.png")) {
            return Storage::disk('local')->path("branding/logo-$size.png");
        }

        return public_path("images/brand/default-$size.png");
    }

    /** Recorta la imagen a un cuadrado centrado y genera los tamaños que usa la app. */
    public static function store(UploadedFile $file): void
    {
        // Una imagen enorme (aunque pese poco) agota la memoria al descomprimirla.
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [0, 0];

        if ($width * $height > self::MAX_PIXELS) {
            throw new RuntimeException('La imagen es demasiado grande.');
        }

        $binary = file_get_contents($file->getRealPath());
        $source = @imagecreatefromstring($binary);

        if (! $source) {
            throw new RuntimeException('No se pudo leer la imagen.');
        }

        $source = self::orient($source, $file);
        $side = min(imagesx($source), imagesy($source));
        $square = imagecrop($source, [
            'x' => intdiv(imagesx($source) - $side, 2),
            'y' => intdiv(imagesy($source) - $side, 2),
            'width' => $side,
            'height' => $side,
        ]);

        $disk = Storage::disk('local');
        $disk->makeDirectory('branding');

        foreach (self::SIZES as $size) {
            $disk->put("branding/logo-$size.png", self::render($square, $size, flatten: $size === 180));
        }

        Setting::put('logo_version', (string) time());
    }

    public static function reset(): void
    {
        Storage::disk('local')->deleteDirectory('branding');
        Setting::put('logo_version', null);
    }

    protected static function render(\GdImage $square, int $size, bool $flatten): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);

        if ($flatten) {
            [$r, $g, $b] = self::IOS_BACKGROUND;
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, $r, $g, $b));
            imagealphablending($canvas, true);
            $inset = (int) round($size * 0.07);
            imagecopyresampled($canvas, $square, $inset, $inset, 0, 0, $size - 2 * $inset, $size - 2 * $inset, imagesx($square), imagesy($square));
        } else {
            imagealphablending($canvas, false);
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresampled($canvas, $square, 0, 0, 0, 0, $size, $size, imagesx($square), imagesy($square));
        }

        ob_start();
        imagepng($canvas, null, 9);

        return (string) ob_get_clean();
    }

    /** Las fotos del celular traen la rotación en EXIF. */
    protected static function orient(\GdImage $image, UploadedFile $file): \GdImage
    {
        if (! function_exists('exif_read_data') || ! in_array($file->getMimeType(), ['image/jpeg', 'image/jpg'], true)) {
            return $image;
        }

        $orientation = @exif_read_data($file->getRealPath())['Orientation'] ?? 1;
        $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;

        return $angle ? (imagerotate($image, $angle, 0) ?: $image) : $image;
    }
}
