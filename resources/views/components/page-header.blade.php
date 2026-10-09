@props(['title'])
{{-- Cabecera de cada pantalla. En el celular lleva el degradado del logo y se queda fija arriba. --}}
<header class="bg-brand sticky top-0 z-30 -mx-4 mb-4 rounded-b-3xl px-4 pt-[max(0.875rem,env(safe-area-inset-top))] pb-3.5 shadow-[0_6px_20px_-12px_rgb(78_44_17/0.45)] md:static md:mx-0 md:mb-6 md:rounded-none md:bg-none md:px-0 md:pt-9 md:pb-0 md:shadow-none">
    <div class="flex min-h-11 items-center justify-between gap-3">
        <h1 class="page-title truncate">{{ $title }}</h1>
        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @if (trim((string) $slot) !== '')
        <div class="mt-3">{{ $slot }}</div>
    @endif
</header>
