@props(['label', 'detail' => null, 'max' => null, 'atMax' => false, 'model' => 'date'])
{{-- Día anterior / elegir fecha / día siguiente. Tocar la etiqueta abre el calendario nativo. --}}
<div class="flex items-center gap-2">
    <button type="button" wire:click="shift(-1)" class="btn-icon" aria-label="Día anterior"><x-icon name="left" /></button>
    <label class="relative flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-surface px-3 hover:bg-sunken">
        <x-icon name="calendar" class="size-4 text-muted" />
        <span class="font-semibold">{{ $label }}</span>
        @if ($detail)
            <span class="text-muted">{{ $detail }}</span>
        @endif
        <input type="date" wire:model.live="{{ $model }}" @if ($max) max="{{ $max }}" @endif
               class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Elegir fecha"
               x-data @click="$el.showPicker?.()">
    </label>
    <button type="button" wire:click="shift(1)" class="btn-icon" aria-label="Día siguiente" @disabled($atMax)><x-icon name="right" /></button>
</div>
