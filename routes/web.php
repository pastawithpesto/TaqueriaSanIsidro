<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', 'pages::login')->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::livewire('/', 'pages::resumen')->name('resumen');
    Route::livewire('/vender', 'pages::vender')->name('vender');
    Route::livewire('/ventas', 'pages::ventas')->name('ventas');
    Route::livewire('/gastos', 'pages::gastos')->name('gastos');
    Route::livewire('/productos', 'pages::productos')->name('productos');
    Route::livewire('/insumos', 'pages::insumos')->name('insumos');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
