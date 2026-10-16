@use('App\Support\Branding')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#191411">
    <link rel="icon" href="{{ Branding::logoUrl(64) }}" type="image/png">
    <title>{{ isset($title) ? $title.' | '.Branding::name() : Branding::name() }}</title>
    @fonts
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh antialiased" style="background: #191411; color: #f7efe5; color-scheme: dark" data-business="{{ Branding::name() }}" data-tours-seen="{{ json_encode(auth()->user()->seen_tours ?? []) }}" data-tour-url="{{ route('tutorial.done') }}">
    <div id="busy" aria-hidden="true"></div>
    {{ $slot }}

    <div x-data="{ items: [], push(d) { const id = Math.random(); this.items.push({ id, message: d.message }); setTimeout(() => this.items = this.items.filter(i => i.id !== id), 3000) } }"
         @toast.window="push($event.detail)" class="pointer-events-none fixed inset-x-0 bottom-24 z-50 flex flex-col items-center gap-2" aria-live="polite">
        <template x-for="t in items" :key="t.id">
            <button type="button" @click="items = items.filter(i => i.id !== t.id)" class="sheet-in pointer-events-auto flex items-center gap-2 rounded-xl bg-[#f7efe5] py-3 pr-3 pl-4 text-sm font-semibold text-[#33200f] shadow-xl">
                <span x-text="t.message"></span>
                <span class="opacity-60" aria-hidden="true"><x-icon name="x" class="size-4" /></span>
                <span class="sr-only">Cerrar aviso</span>
            </button>
        </template>
    </div>
    @livewireScripts
</body>
</html>
