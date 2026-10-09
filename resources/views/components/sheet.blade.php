@props(['title', 'close' => 'cancel'])
<div x-data x-trap.noscroll="true"
     @keydown.escape.window="$wire.{{ $close }}()"
     class="fixed inset-0 z-50 flex items-end justify-center md:items-center"
     role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="absolute inset-0 bg-black/55" @click="$wire.{{ $close }}()"></div>
    <div class="sheet-in relative flex max-h-[92dvh] w-full flex-col rounded-t-3xl bg-surface shadow-2xl md:max-w-xl md:rounded-3xl">
        <div class="flex items-center justify-between gap-3 px-5 pb-2 pt-4">
            <h2 class="font-display text-2xl font-bold leading-none">{{ $title }}</h2>
            <button type="button" @click="$wire.{{ $close }}()" class="btn-quiet -mr-2" aria-label="Cerrar">
                <x-icon name="x" />
            </button>
        </div>
        <div class="overflow-y-auto px-5 pb-5 pt-2">{{ $slot }}</div>
        @isset($footer)
            <div class="border-t border-line px-5 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3">{{ $footer }}</div>
        @endisset
    </div>
</div>
