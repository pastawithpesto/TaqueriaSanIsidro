<?php

namespace App\Http\Controllers;

use App\Support\Branding;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BrandingController extends Controller
{
    public function logo(int $size): BinaryFileResponse
    {
        abort_unless(in_array($size, Branding::SIZES, true), 404);

        // La URL lleva ?v=<versión>, así que es seguro cachearla por mucho tiempo.
        return response()->file(Branding::path($size), [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => Branding::name(),
            'short_name' => Branding::shortName(),
            'description' => 'Ventas, gastos y ganancias del negocio.',
            'lang' => 'es-MX',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            // Sin orientación fija: la tablet de cocina se usa también en horizontal.
            'background_color' => '#f8f5ef',
            'theme_color' => '#ffd34d',
            'icons' => [
                ['src' => Branding::logoUrl(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => Branding::logoUrl(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }
}
