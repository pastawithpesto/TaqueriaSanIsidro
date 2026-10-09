<?php

use App\Http\Controllers\BrandingController;
use App\Support\Tours;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

Route::get('/brand/logo-{size}.png', [BrandingController::class, 'logo'])->whereNumber('size')->name('brand.logo');
Route::get('/manifest.webmanifest', [BrandingController::class, 'manifest'])->name('manifest');

Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::vender')->name('vender');
    Route::livewire('/resumen', 'pages::resumen')->name('resumen');
    Route::livewire('/compras', 'pages::compras')->name('compras');
    Route::livewire('/corte', 'pages::corte')->name('corte');
    Route::livewire('/pedidos', 'pages::pedidos')->name('pedidos');
    Route::livewire('/cocina', 'pages::cocina')->name('cocina');
    Route::livewire('/ventas', 'pages::ventas')->name('ventas');
    Route::livewire('/gastos', 'pages::gastos')->name('gastos');
    Route::livewire('/productos', 'pages::productos')->name('productos');
    Route::livewire('/insumos', 'pages::insumos')->name('insumos');
    Route::livewire('/ajustes', 'pages::ajustes')->name('ajustes');

    // Marca un recorrido como visto para no mostrarlo solo otra vez.
    Route::post('/tutorial/visto', function (Request $request) {
        $tour = $request->validate(['tour' => ['required', Rule::in(Tours::KEYS)]])['tour'];
        $user = $request->user();
        $user->update(['seen_tours' => array_values(array_unique([...$user->seen_tours ?? [], $tour]))]);

        return response()->noContent();
    })->name('tutorial.done');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
