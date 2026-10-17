<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras básicas de seguridad en todas las respuestas. HSTS y TLS los pone el proxy de afuera.
 * La página de seguimiento lleva el enlace secreto del pedido: no se manda en Referer ni se indexa.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Referrer-Policy', $request->routeIs('pedido') ? 'no-referrer' : 'strict-origin-when-cross-origin');

        // Ni el sistema del taquero ni los seguimientos de pedidos deben aparecer en buscadores.
        if ($request->routeIs('pedido') || str_starts_with($request->path(), config('app.admin_path'))) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
