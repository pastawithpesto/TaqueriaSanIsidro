@props(['title'])
{{-- Cabecera de cada pantalla. En el celular se queda fija arriba junto con sus controles. --}}
<header class="sticky top-0 z-30 -mx-4 mb-4 bg-bg/92 px-4 pt-[max(0.875rem,env(safe-area-inset-top))] pb-3 backdrop-blur-md md:static md:mx-0 md:mb-6 md:bg-transparent md:px-0 md:pt-9 md:pb-0 md:backdrop-blur-none">
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
