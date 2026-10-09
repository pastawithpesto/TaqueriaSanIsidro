@php
    $links = [
        'resumen' => 'Resumen',
        'vender' => 'Vender',
        'ventas' => 'Ventas',
        'gastos' => 'Gastos',
        'productos' => 'Productos',
        'insumos' => 'Insumos',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-5xl items-center justify-between gap-4 px-4 py-3">
            <a href="{{ route('resumen') }}" class="font-semibold">{{ config('app.name') }}</a>

            <nav class="fixed inset-x-0 bottom-0 z-20 grid grid-cols-6 border-t border-stone-200 bg-white md:static md:flex md:gap-1 md:border-0 md:bg-transparent">
                @foreach ($links as $route => $label)
                    <a href="{{ route($route) }}" wire:navigate
                       @class([
                           'px-1 py-3 text-center text-xs font-medium md:rounded-lg md:px-3 md:py-1.5 md:text-sm',
                           'text-amber-800 md:bg-amber-100' => request()->routeIs($route),
                           'text-stone-600 hover:text-stone-900 md:hover:bg-stone-100' => ! request()->routeIs($route),
                       ])>{{ $label }}</a>
                @endforeach
            </nav>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button class="text-sm text-stone-500 hover:text-stone-900">Salir</button>
            </form>
        </div>
    </header>

    <main class="mx-auto max-w-5xl px-4 pb-28 pt-5 md:pb-10">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
