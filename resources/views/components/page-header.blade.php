@props(['title', 'tour' => null])
{{--
    Cabecera de cada pantalla. En el celular lleva el degradado del logo y se queda fija arriba.
    Con tour="clave" muestra el botón Tutorial de su recorrido (resources/js/tours.js), que además
    marca la pantalla para mostrar el recorrido sola la primera vez. Si la pantalla tiene botones
    arriba, en el celular queda solo el ícono para que quepa.
--}}
<header class="bg-brand sticky top-0 z-30 -mx-4 mb-4 rounded-b-3xl px-4 pt-[max(0.875rem,env(safe-area-inset-top))] pb-3.5 shadow-[0_6px_20px_-12px_rgb(78_44_17/0.45)] md:static md:mx-0 md:mb-6 md:rounded-none md:bg-none md:bg-transparent md:px-0 md:pt-9 md:pb-0 md:shadow-none">
    <div class="flex min-h-11 items-center justify-between gap-3">
        <div class="flex min-w-0 items-center gap-2.5">
            <h1 class="page-title truncate">{{ $title }}</h1>
            @if ($tour)
                <button type="button" data-tour-start="{{ $tour }}" data-tour="ayuda"
                        class="inline-flex h-9 shrink-0 items-center gap-1.5 rounded-full border border-ink/15 bg-surface/70 px-2.5 text-[13px] font-medium text-ink hover:bg-surface"
                        aria-label="Tutorial de esta pantalla" title="Tutorial de esta pantalla">
                    <x-icon name="help" class="size-4" />
                    <span @class(['hidden sm:inline' => isset($actions)])>Tutorial</span>
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
