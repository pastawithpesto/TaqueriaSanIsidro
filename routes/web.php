<?php

use App\Http\Controllers\BrandingController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/brand/logo-{size}.png', [BrandingController::class, 'logo'])->whereNumber('size')->name('brand.logo');
Route::get('/manifest.webmanifest', [BrandingController::class, 'manifest'])->name('manifest');

Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::vender')->name('vender');
    Route::livewire('/resumen', 'pages::resumen')->name('resumen');
    Route::livewire('/corte', 'pages::corte')->name('corte');
    Route::livewire('/ventas', 'pages::ventas')->name('ventas');
    Route::livewire('/gastos', 'pages::gastos')->name('gastos');
    Route::livewire('/productos', 'pages::productos')->name('productos');
    Route::livewire('/insumos', 'pages::insumos')->name('insumos');
    Route::livewire('/ajustes', 'pages::ajustes')->name('ajustes');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
