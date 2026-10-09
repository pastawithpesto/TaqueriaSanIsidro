@props(['max' => null, 'model' => 'date'])
{{--
    Día anterior / elegir fecha / día siguiente. La etiqueta cambia al instante en el
    navegador y el servidor carga los datos del día en paralelo.
--}}
<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}
     x-data="{
        date: $wire.$entangle('{{ $model }}', true),
        max: @js($max),
        parse(s) { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d); },
        iso(d) { return [d.getFullYear(), String(d.getMonth() + 1).padStart(2, '0'), String(d.getDate()).padStart(2, '0')].join('-'); },
        get label() {
            const d = this.parse(this.date), today = new Date(); today.setHours(0, 0, 0, 0);
            const diff = Math.round((today - d) / 86400000);
            if (diff === 0) return 'Hoy';
            if (diff === 1) return 'Ayer';
            const w = d.toLocaleDateString('es-MX', { weekday: 'long' });
            return w.charAt(0).toUpperCase() + w.slice(1);
        },
        get detail() { return this.parse(this.date).toLocaleDateString('es-MX', { day: 'numeric', month: 'short' }) },
        get atMax() { return this.max && this.date >= this.max },
        shift(n) {
            const d = this.parse(this.date); d.setDate(d.getDate() + n);
            const next = this.iso(d);
            if (! (this.max && next > this.max)) this.date = next;
        },
     }">
    <button type="button" @click="shift(-1)" class="btn-icon" aria-label="Día anterior"><x-icon name="left" /></button>
    <label class="relative flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-surface px-3 hover:bg-sunken">
        <x-icon name="calendar" class="size-4 text-muted" />
        <span class="font-semibold" x-text="label"></span>
        <span class="text-muted" x-text="detail"></span>
        <input type="date" x-model="date" @if ($max) max="{{ $max }}" @endif
               class="absolute inset-0 h-full w-full cursor-pointer opacity-0" aria-label="Elegir fecha"
               @click="$el.showPicker?.()">
    </label>
    <button type="button" @click="shift(1)" class="btn-icon" aria-label="Día siguiente" :disabled="atMax"><x-icon name="right" /></button>
</div>
