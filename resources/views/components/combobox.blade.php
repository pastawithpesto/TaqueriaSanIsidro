@props(['bind', 'options' => [], 'placeholder' => '', 'create' => null, 'none' => null, 'clear' => 'Quitar'])
{{--
    Lista para escoger que filtra mientras se escribe (sin importar acentos ni mayúsculas), con el aspecto de los
    demás campos. El valor solo cambia al escoger una opción: no se cuela texto suelto.

    bind     expresión de Alpine que guarda el valor ($wire.category, newIng, extra.ingredient_id).
    options  textos o ['value' => 7, 'label' => 'Cebolla', 'hint' => 'kg'].
    create   texto para crear una opción nueva con lo escrito ("Crear categoría"); sin él no se crean.
    none     opción para dejarlo vacío ("No usa insumo"); sin ella se vacía con la x.
--}}
@php
    $items = collect($options)->map(fn ($o) => is_array($o)
        ? ['value' => $o['value'], 'label' => (string) $o['label'], 'hint' => $o['hint'] ?? null]
        : ['value' => $o, 'label' => (string) $o, 'hint' => null])->values()->all();
@endphp
<div x-data="{
        value: null,
        options: @js($items),
        query: '',
        open: false,
        active: 0,
        none: @js($none),
        create: @js($create),
        plain(text) { return String(text ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim() },
        empty(v) { return v === null || v === undefined || v === '' },
        get selected() { return this.options.find(o => o.value == this.value) },
        get display() {
            if (this.empty(this.value)) return this.none ?? '';
            return this.selected?.label ?? String(this.value);
        },
        get items() {
            const q = this.plain(this.query);
            const typed = this.query.trim();
            const items = this.options.filter(o => ! q || this.plain(o.label).includes(q)).map(o => ({ ...o, create: false }));
            if (this.none !== null && ! q) items.unshift({ value: '', label: this.none, hint: null, create: false });
            if (this.create && typed && ! this.options.some(o => this.plain(o.label) === q)) {
                items.push({ value: typed, label: this.create + ' «' + typed + '»', hint: null, create: true });
            }
            return items;
        },
        show() {
            if (this.open) return;
            this.query = '';
            this.open = true;
            this.active = Math.max(0, this.items.findIndex(i => i.value == this.value && ! this.empty(i.value)));
        },
        close() { this.open = false; this.query = '' },
        choose(item) {
            if (item.create && ! this.options.some(o => o.value === item.value)) this.options.push({ value: item.value, label: item.value, hint: null });
            this.value = item.value;
            this.close();
            this.$dispatch('pick', item.value);
        },
        move(step) {
            if (! this.open) return this.show();
            const n = this.items.length;
            if (n) this.active = (this.active + step + n) % n;
        },
     }"
     x-modelable="value" x-model="{{ $bind }}"
     @click.outside="close()"
     class="relative">
    <input type="text" role="combobox" autocomplete="off" aria-autocomplete="list"
           {{ $attributes->merge(['class' => 'input pr-10']) }}
           :value="open ? query : display"
           :placeholder="open && ! empty(value) ? display : @js($placeholder)"
           :aria-expanded="open"
           @focus="show()" @click="show()"
           @input="query = $event.target.value; open = true; active = 0"
           @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)"
           @keydown.enter.prevent="items[active] && choose(items[active])"
           @keydown.escape="if (open) { $event.stopPropagation(); close(); $el.blur() }"
           @keydown.tab="close()">
    <button type="button" x-show="! empty(value) && ! open && none === null" x-cloak @click="value = null" class="absolute top-1/2 right-1.5 grid size-9 -translate-y-1/2 place-items-center rounded-lg text-muted hover:bg-sunken" aria-label="{{ $clear }}">
        <x-icon name="x" class="size-4" />
    </button>
    <span x-show="empty(value) || open || none !== null" class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-muted"><x-icon name="down" class="size-4" /></span>

    <ul x-show="open && items.length" x-cloak role="listbox"
        class="absolute inset-x-0 top-full z-20 mt-1 max-h-60 min-w-48 overflow-y-auto rounded-xl border border-line bg-surface p-1 shadow-xl">
        <template x-for="(item, i) in items" :key="(item.create ? '+' : '') + item.value">
            {{-- mousedown en lugar de click: así no se pierde el foco antes de escoger. --}}
            <li role="option" :aria-selected="i === active" @mousedown.prevent="choose(item)" @mouseenter="active = i"
                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-lg px-3 text-[15px]"
                :class="[i === active && 'bg-sunken', item.create && 'font-semibold text-chile', empty(item.value) && 'text-muted']">
                <span x-show="item.create"><x-icon name="plus" class="size-4" /></span>
                <span class="min-w-0 flex-1 truncate" x-text="item.label"></span>
                <span x-show="item.hint" class="text-sm text-muted" x-text="item.hint"></span>
                <span x-show="! item.create && item.value == value && ! empty(item.value)"><x-icon name="check" class="size-4" /></span>
            </li>
        </template>
    </ul>
</div>
