@props(['title', 'close' => 'cancel', 'open' => null, 'dismiss' => null])
{{--
    Con :open="expr" el cuadro se abre y cierra solo en el navegador (sin pasar por el servidor).
    Al abrirse empieza desde arriba y el botón atrás del celular lo cierra (window.backButton en app.js).
--}}
@php $closeJs = $dismiss ?? ($open ? "$open = false" : "\$wire.$close()"); @endphp
<div {{ $attributes->except(['x-text', 'class']) }}
     @if ($open)
         x-data="{ shown: false }"
         x-effect="
            const isOpen = !! ({{ $open }});
            if (isOpen === shown) return;
            shown = isOpen;
            if (isOpen) {
                backButton.opened($el, () => { {{ $closeJs }} });
                $nextTick(() => $refs.body.scrollTop = 0);
            } else {
                backButton.closed($el);
            }
         "
         x-show="{{ $open }}" x-cloak x-trap.noscroll="{{ $open }}"
     @else
         x-data x-trap.noscroll="true"
     @endif
     @keydown.escape.window="{{ $open ? "if ($open) { $closeJs }" : $closeJs }}"
     class="fixed inset-0 z-50 flex items-end justify-center md:items-center"
     role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="absolute inset-0 bg-black/55" @click="{{ $closeJs }}"></div>
    <div class="sheet-in relative flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-surface shadow-2xl md:max-w-xl md:rounded-3xl">
        <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-line md:hidden"></div>
        <div class="flex items-center justify-between gap-3 px-5 pt-3 pb-2 md:pt-5">
            <h2 class="section-title !text-2xl" {{ $attributes->only('x-text') }}>{{ $title }}</h2>
            <button type="button" @click="{{ $closeJs }}" class="btn-quiet -mr-2" aria-label="Cerrar">
                <x-icon name="x" />
            </button>
        </div>
        <div x-ref="body" class="overflow-y-auto px-5 pb-5 pt-2">{{ $slot }}</div>
        @isset($footer)
            <div class="border-t border-line px-5 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
