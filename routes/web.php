<?php

use App\Http\Controllers\BrandingController;
use App\Http\Controllers\TelegramWebhookController;
use App\Models\Order;
use App\Support\Tours;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/brand/logo-{size}.png', [BrandingController::class, 'logo'])->whereNumber('size')->name('brand.logo');

// La raíz es para los clientes; el sistema del taquero vive bajo ADMIN_PATH (por defecto /admon).
Route::livewire('/', 'pages::pedir')->name('pedir');
Route::livewire('/pedido/{token}', 'pages::pedido')->whereUuid('token')->name('pedido');

// Mensajes del bot de Telegram: sin sesión ni CSRF (los manda Telegram); se valida con la clave secreta del webhook.
Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->middleware('throttle:300,1')
    ->name('telegram.webhook');

Route::prefix(config('app.admin_path'))->group(function () {
    Route::get('/manifest.webmanifest', [BrandingController::class, 'manifest'])->name('manifest');

    Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

    Route::middleware('auth')->group(function () {
        Route::livewire('/', 'pages::vender')->name('vender');
        Route::livewire('/resumen', 'pages::resumen')->name('resumen');
        Route::livewire('/compras', 'pages::compras')->name('compras');
        Route::livewire('/corte', 'pages::corte')->name('corte');
        Route::livewire('/pedidos', 'pages::pedidos')->name('pedidos');
        // El contador de Pedidos del menú lo consulta cada tanto para ver lo que se vende en otro celular.
        Route::get('/pedidos/pendientes', fn () => ['count' => Order::pendingCount()])->name('pedidos.pending');
        Route::livewire('/cocina', 'pages::cocina')->name('cocina');
        Route::livewire('/ventas', 'pages::ventas')->name('ventas');
        Route::livewire('/gastos', 'pages::gastos')->name('gastos');
        Route::livewire('/productos', 'pages::productos')->name('productos');
        Route::livewire('/insumos', 'pages::insumos')->name('insumos');
        Route::livewire('/tienda', 'pages::tienda')->name('tienda');
        Route::livewire('/ajustes', 'pages::ajustes')->name('ajustes');

        // Marca un recorrido como visto para no mostrarlo solo otra vez; "todas" apaga las explicaciones automáticas.
        Route::post('/tutorial/visto', function (Request $request) {
            $tour = $request->validate(['tour' => ['required', Rule::in([...Tours::KEYS, 'todas'])]])['tour'];
            $user = $request->user();
            $seen = $tour === 'todas' ? Tours::KEYS : [...$user->seen_tours ?? [], $tour];
            $user->update(['seen_tours' => array_values(array_unique($seen))]);

            return response()->noContent();
        })->name('tutorial.done');

        Route::post('/logout', function (Request $request) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        })->name('logout');
    });
});
