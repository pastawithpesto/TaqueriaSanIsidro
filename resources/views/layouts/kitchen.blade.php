@use('App\Support\Branding')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#1a110a">
    <link rel="icon" href="{{ Branding::logoUrl(64) }}" type="image/png">
    <title>{{ isset($title) ? $title.' | '.Branding::name() : Branding::name() }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased" style="background: #1a110a; color: #fbebd3; color-scheme: dark">
    <div id="busy" aria-hidden="true"></div>
    {{ $slot }}

    <div x-data="{ items: [], push(d) { const id = Math.random(); this.items.push({ id, message: d.message }); setTimeout(() => this.items = this.items.filter(i => i.id !== id), 3000) } }"
         @toast.window="push($event.detail)" class="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2" aria-live="polite">
        <template x-for="t in items" :key="t.id">
            <div class="sheet-in rounded-xl bg-[#fbebd3] px-4 py-3 text-sm font-semibold text-[#4e2c11] shadow-xl" x-text="t.message"></div>
        </template>
    </div>
    @livewireScripts
</body>
</html>
