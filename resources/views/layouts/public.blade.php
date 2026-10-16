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
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
    @livewireScripts
</body>
</html>
