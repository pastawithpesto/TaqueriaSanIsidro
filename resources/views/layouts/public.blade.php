@use('App\Support\Branding')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffd34d">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#231c17">
    <link rel="icon" href="{{ Branding::logoUrl(64) }}" type="image/png">
    <link rel="apple-touch-icon" href="{{ Branding::logoUrl(180) }}">
    <title>{{ isset($title) ? $title.' | '.Branding::name() : Branding::name() }}</title>
    <x-realtime />
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/order-page.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased">
    {{ $slot }}

    <div x-data="{ items: [], push(d) { const id = Math.random(); this.items.push({ id, message: d.message, error: d.type === 'error' }); setTimeout(() => this.items = this.items.filter(i => i.id !== id), 3500) } }"
         @toast.window="push($event.detail)" class="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2" aria-live="polite">
        <template x-for="t in items" :key="t.id">
            <button type="button" @click="items = items.filter(i => i.id !== t.id)" class="sheet-in pointer-events-auto flex items-center gap-2 rounded-xl py-3 pr-3 pl-4 text-sm font-semibold shadow-xl" :class="t.error ? 'bg-chile text-white' : 'bg-ink text-bg'">
                <span x-text="t.message"></span>
                <span class="sr-only">Cerrar aviso</span>
            </button>
        </template>
    </div>

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
</body>
</html>
