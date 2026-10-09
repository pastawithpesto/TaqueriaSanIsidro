@props(['title', 'tour' => null])
{{--
    Cabecera de cada pantalla. En el celular lleva el degradado del logo y se queda fija arriba.
    Con tour="clave" muestra el botón "?" de su recorrido (resources/js/tours.js), que además
    marca la pantalla para mostrar el recorrido sola la primera vez.
--}}
<header class="bg-brand sticky top-0 z-30 -mx-4 mb-4 rounded-b-3xl px-4 pt-[max(0.875rem,env(safe-area-inset-top))] pb-3.5 shadow-[0_6px_20px_-12px_rgb(78_44_17/0.45)] md:static md:mx-0 md:mb-6 md:rounded-none md:bg-none md:bg-transparent md:px-0 md:pt-9 md:pb-0 md:shadow-none">
    <div class="flex min-h-11 items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-1.5">
            <h1 class="page-title truncate">{{ $title }}</h1>
            @if ($tour)
                <button type="button" data-tour-start="{{ $tour }}" data-tour="ayuda"
                        class="-my-1 grid size-10 shrink-0 place-items-center rounded-full text-ink/60 hover:bg-ink/10 hover:text-ink"
                        aria-label="Cómo funciona esta pantalla" title="Cómo funciona esta pantalla">
                    <x-icon name="help" class="size-[22px]" />
                </button>
            @endif
        </div>
        @isset($actions)
            <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>
    @if (trim((string) $slot) !== '')
        <div class="mt-3">{{ $slot }}</div>
    @endif
</header>
