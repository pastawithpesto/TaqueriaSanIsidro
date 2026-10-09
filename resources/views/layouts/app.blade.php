@php
    $main = [
        ['vender', 'Vender', 'sale'],
        ['ventas', 'Ventas', 'receipt'],
        ['gastos', 'Gastos', 'wallet'],
        ['resumen', 'Resumen', 'chart'],
    ];
    $more = [
        ['corte', 'Corte de caja', 'banknote'],
        ['productos', 'Productos', 'tag'],
        ['insumos', 'Insumos', 'package'],
    ];
    $moreActive = collect($more)->contains(fn ($l) => request()->routeIs($l[0]));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#c62a1d">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#131010">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="San Isidro">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <title>{{ isset($title) ? $title.' | '.config('app.name') : config('app.name') }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased">
    <div class="md:grid md:grid-cols-[15rem_1fr]">
        {{-- Barra lateral (escritorio) --}}
        <aside class="sticky top-0 hidden h-dvh flex-col border-r border-line bg-surface p-4 md:flex">
            <a href="{{ route('vender') }}" wire:navigate class="mb-6 flex items-center gap-3 px-2">
                <x-logo class="size-10" />
                <span class="font-display text-xl font-bold leading-[1.05]">Taquería<br>San Isidro</span>
            </a>
            <nav class="flex flex-col gap-1">
                @foreach (array_merge($main, $more) as [$route, $label, $icon])
                    <a href="{{ route($route) }}" wire:navigate
                       @class([
                           'flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold',
                           'bg-chile-soft text-chile' => request()->routeIs($route),
                           'text-muted hover:bg-sunken hover:text-ink' => ! request()->routeIs($route),
                       ])
                       @if (request()->routeIs($route)) aria-current="page" @endif>
                        <x-icon :name="$icon" /> {{ $label }}
                    </a>
                @endforeach
            </nav>
            <form method="POST" action="{{ route('logout') }}" class="mt-auto">
                @csrf
                <button class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-[15px] font-semibold text-muted hover:bg-sunken hover:text-ink">
                    <x-icon name="logout" /> Salir
                </button>
            </form>
        </aside>

        <div class="min-w-0">
            <header class="sticky top-0 z-30 border-b border-line bg-bg/90 backdrop-blur md:static md:border-0 md:bg-transparent md:backdrop-blur-none">
                <div class="mx-auto flex max-w-5xl items-center gap-3 px-4 pb-3 pt-[max(0.75rem,env(safe-area-inset-top))] md:pt-8">
                    <x-logo class="size-8 md:hidden" />
                    <h1 class="font-display text-[1.75rem] font-bold leading-none md:text-4xl">{{ $title ?? config('app.name') }}</h1>
                </div>
            </header>

            <main class="mx-auto max-w-5xl px-4 pb-36 pt-3 md:pb-12">
                {{ $slot }}
            </main>
        </div>
    </div>

    {{-- Navegación inferior (celular) --}}
    <div x-data="{ more: false }" class="md:hidden">
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-line bg-surface pb-[env(safe-area-inset-bottom)]" aria-label="Principal">
            @foreach ($main as [$route, $label, $icon])
                <a href="{{ route($route) }}" wire:navigate
                   @class([
                       'relative flex min-h-16 flex-col items-center justify-center gap-0.5 text-[11px] font-semibold',
                       'text-chile' => request()->routeIs($route),
                       'text-muted' => ! request()->routeIs($route),
                   ])
                   @if (request()->routeIs($route)) aria-current="page" @endif>
                    @if (request()->routeIs($route))
                        <span class="absolute inset-x-5 top-0 h-0.5 rounded-b bg-chile"></span>
                    @endif
                    <x-icon :name="$icon" class="size-6" />
                    {{ $label }}
                </a>
            @endforeach
            <button type="button" @click="more = true"
                    @class([
                        'relative flex min-h-16 flex-col items-center justify-center gap-0.5 text-[11px] font-semibold',
                        'text-chile' => $moreActive,
                        'text-muted' => ! $moreActive,
                    ])>
                @if ($moreActive)
                    <span class="absolute inset-x-5 top-0 h-0.5 rounded-b bg-chile"></span>
                @endif
                <x-icon name="more" class="size-6" />
                Más
            </button>
        </nav>

        <div x-show="more" x-cloak x-trap.noscroll="more" @keydown.escape.window="more = false"
             class="fixed inset-0 z-50 flex items-end" role="dialog" aria-modal="true" aria-label="Más opciones">
            <div class="absolute inset-0 bg-black/55" @click="more = false"></div>
            <div class="sheet-in relative w-full rounded-t-3xl bg-surface px-3 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3 shadow-2xl">
                <div class="mx-auto mb-2 h-1 w-10 rounded-full bg-line"></div>
                @foreach ($more as [$route, $label, $icon])
                    <a href="{{ route($route) }}" wire:navigate @click="more = false"
                       class="flex min-h-14 items-center gap-4 rounded-xl px-3 text-base font-semibold hover:bg-sunken">
                        <span class="grid size-10 place-items-center rounded-xl bg-sunken text-muted"><x-icon :name="$icon" /></span>
                        {{ $label }}
                    </a>
                @endforeach
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="flex min-h-14 w-full items-center gap-4 rounded-xl px-3 text-base font-semibold text-muted hover:bg-sunken">
                        <span class="grid size-10 place-items-center rounded-xl bg-sunken"><x-icon name="logout" /></span>
                        Salir
                    </button>
                </form>
            </div>
        </div>
    </div>

    {{-- Avisos --}}
    <div x-data="{
            items: [],
            push(d) {
                const id = Math.random();
                this.items.push({ id, message: d.message });
                setTimeout(() => this.items = this.items.filter(i => i.id !== id), d.ms ?? 3000);
            },
         }"
         @toast.window="push($event.detail)"
         class="pointer-events-none fixed inset-x-0 bottom-24 z-[60] flex flex-col items-center gap-2 px-4 md:bottom-6"
         aria-live="polite">
        <template x-for="t in items" :key="t.id">
            <div x-text="t.message" class="sheet-in pointer-events-auto flex items-center gap-2 rounded-xl bg-ink px-4 py-3 text-sm font-semibold text-bg shadow-xl"></div>
        </template>
    </div>

    @livewireScripts
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
