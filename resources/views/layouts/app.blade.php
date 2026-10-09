@use('App\Support\Branding')
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
        ['ajustes', 'Ajustes', 'settings'],
    ];
    $moreActive = collect($more)->contains(fn ($l) => request()->routeIs($l[0]));
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffd657">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#3d2e0c">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ Branding::shortName() }}">
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="{{ Branding::logoUrl(64) }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ Branding::logoUrl(180) }}">
    <title>{{ isset($title) ? $title.' | '.Branding::name() : Branding::name() }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased">
    <div id="busy" aria-hidden="true"></div>

    <div class="md:grid md:min-h-dvh md:grid-cols-[15.5rem_1fr]">
        {{-- Barra lateral (escritorio) --}}
        <div class="hidden border-r border-line bg-surface md:block">
            <aside class="sticky top-0 flex h-dvh flex-col p-4">
                <a href="{{ route('vender') }}" wire:navigate class="bg-brand mb-6 flex items-center gap-3 rounded-2xl p-3">
                    <x-logo class="size-12 ring-2 ring-white/70" :res="192" />
                    <span class="font-display text-xl leading-[1.05] font-bold text-balance text-ink">{{ Branding::name() }}</span>
                </a>
                <nav class="flex flex-col gap-0.5" aria-label="Principal">
                    @foreach ($main as [$route, $label, $icon])
                        <x-nav-link :route="$route" :icon="$icon">{{ $label }}</x-nav-link>
                    @endforeach
                    <p class="mt-5 mb-1.5 px-3 text-xs font-semibold text-muted">Administración</p>
                    @foreach ($more as [$route, $label, $icon])
                        <x-nav-link :route="$route" :icon="$icon">{{ $label }}</x-nav-link>
                    @endforeach
                </nav>
                <div class="mt-auto border-t border-line pt-3">
                    <p class="truncate px-3 pb-1 text-sm text-muted">{{ auth()->user()->name }}</p>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="flex min-h-11 w-full items-center gap-3 rounded-xl px-3 text-[15px] font-semibold text-muted hover:bg-sunken hover:text-ink">
                            <x-icon name="logout" /> Cerrar sesión
                        </button>
                    </form>
                </div>
            </aside>
        </div>

        <main class="mx-auto w-full max-w-5xl min-w-0 px-4 pb-36 md:px-8 md:pb-12">
            {{ $slot }}
        </main>
    </div>

    {{-- Navegación inferior (celular) --}}
    <div x-data="{ more: false }" class="md:hidden">
        <nav class="fixed inset-x-0 bottom-0 z-40 grid grid-cols-5 border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md" aria-label="Principal">
            @foreach ($main as [$route, $label, $icon])
                @php $active = request()->routeIs($route); @endphp
                <a href="{{ route($route) }}" wire:navigate
                   class="flex min-h-[3.75rem] flex-col items-center justify-center gap-1 text-[11px] font-semibold {{ $active ? 'text-ink' : 'text-muted' }}"
                   @if ($active) aria-current="page" @endif>
                    <span class="flex h-7 w-14 items-center justify-center rounded-full {{ $active ? 'bg-chile text-white' : '' }}">
                        <x-icon :name="$icon" class="size-[22px]" />
                    </span>
                    {{ $label }}
                </a>
            @endforeach
            <button type="button" @click="more = true"
                    class="flex min-h-[3.75rem] flex-col items-center justify-center gap-1 text-[11px] font-semibold {{ $moreActive ? 'text-ink' : 'text-muted' }}">
                <span class="flex h-7 w-14 items-center justify-center rounded-full {{ $moreActive ? 'bg-chile text-white' : '' }}">
                    <x-icon name="more" class="size-[22px]" />
                </span>
                Más
            </button>
        </nav>

        <div x-show="more" x-cloak x-trap.noscroll="more" @keydown.escape.window="more = false"
             class="fixed inset-0 z-50 flex items-end" role="dialog" aria-modal="true" aria-label="Más opciones">
            <div class="absolute inset-0 bg-black/55" @click="more = false"
                 x-show="more" x-transition:enter="transition-opacity duration-200" x-transition:enter-start="opacity-0" x-transition:leave="transition-opacity duration-150" x-transition:leave-end="opacity-0"></div>
            <div class="relative w-full rounded-t-3xl bg-surface px-3 pt-2 pb-[max(1rem,env(safe-area-inset-bottom))] shadow-2xl"
                 x-show="more" x-transition:enter="transition duration-250 ease-out" x-transition:enter-start="translate-y-full" x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-y-full">
                <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-line"></div>
                <div class="mb-2 flex items-center gap-3 px-3 pb-3">
                    <x-logo class="size-11" :res="192" />
                    <div class="min-w-0">
                        <p class="truncate font-display text-lg leading-tight font-bold">{{ Branding::name() }}</p>
                        <p class="truncate text-sm text-muted">{{ auth()->user()->email }}</p>
                    </div>
                </div>
                @foreach ($more as [$route, $label, $icon])
                    <a href="{{ route($route) }}" wire:navigate @click="more = false"
                       class="flex min-h-14 items-center gap-4 rounded-xl px-3 text-base font-semibold hover:bg-sunken {{ request()->routeIs($route) ? 'bg-sunken' : '' }}">
                        <x-icon :name="$icon" class="size-[22px] text-muted" />
                        {{ $label }}
                    </a>
                @endforeach
                <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-line pt-1">
                    @csrf
                    <button class="flex min-h-14 w-full items-center gap-4 rounded-xl px-3 text-base font-semibold text-muted hover:bg-sunken">
                        <x-icon name="logout" class="size-[22px]" /> Cerrar sesión
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
                this.items.push({ id, message: d.message, type: d.type ?? 'success' });
                setTimeout(() => this.items = this.items.filter(i => i.id !== id), d.ms ?? (d.type === 'error' ? 5000 : 3000));
            },
         }"
         @toast.window="push($event.detail)"
         class="pointer-events-none fixed inset-x-0 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-[90] flex flex-col items-center gap-2 px-4 md:bottom-6"
         aria-live="polite">
        <template x-for="t in items" :key="t.id">
            <div class="sheet-in pointer-events-auto flex max-w-sm items-center gap-2.5 rounded-xl py-3 pr-4 pl-3 text-sm font-semibold shadow-xl"
                 :class="t.type === 'error' ? 'bg-chile text-white' : 'bg-ink text-bg'">
                <span x-show="t.type !== 'error'"><x-icon name="check" class="size-4 text-salsa" /></span>
                <span x-show="t.type === 'error'"><x-icon name="alert" class="size-4" /></span>
                <span x-text="t.message"></span>
            </div>
        </template>
    </div>

    {{-- Confirmación de acciones importantes --}}
    <div x-data x-show="$store.confirm.open" x-cloak
         @keydown.escape.window="$store.confirm.open && $store.confirm.answer(false)"
         class="fixed inset-0 z-[80] flex items-end justify-center p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:items-center"
         role="alertdialog" aria-modal="true" :aria-label="$store.confirm.title">
        <div class="absolute inset-0 bg-black/55" @click="$store.confirm.answer(false)"
             x-show="$store.confirm.open" x-transition.opacity.duration.150ms></div>
        <div class="relative w-full max-w-sm rounded-3xl bg-surface p-5 shadow-2xl"
             x-show="$store.confirm.open" x-trap.noscroll="$store.confirm.open"
             x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="translate-y-4 scale-[0.98] opacity-0"
             x-transition:leave="transition duration-100 ease-in" x-transition:leave-end="opacity-0">
            <h2 class="section-title !text-2xl" x-text="$store.confirm.title"></h2>
            <p class="mt-1.5 text-[15px] text-muted" x-show="$store.confirm.message" x-text="$store.confirm.message"></p>
            <div class="mt-5 grid grid-cols-2 gap-2">
                <button type="button" class="btn btn-lg" @click="$store.confirm.answer(false)" x-text="$store.confirm.cancel"></button>
                <button type="button" class="btn-lg inline-flex items-center justify-center rounded-2xl px-4 font-semibold text-white"
                        :class="$store.confirm.danger ? 'bg-chile hover:bg-chile-strong' : 'bg-ink !text-bg'"
                        @click="$store.confirm.answer(true)" x-text="$store.confirm.action"></button>
            </div>
        </div>
    </div>

    @livewireScripts
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>
</body>
</html>
