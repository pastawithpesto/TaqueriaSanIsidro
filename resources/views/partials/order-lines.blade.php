{{-- Renglones del pedido. Se usa dentro del x-data de Vender. --}}
<ul class="divide-y divide-line">
    <template x-for="(line, i) in list()" :key="i + '-' + line.product_id + '-' + line.qty + '-' + (line.choices ?? []).join('.') + '-' + (line.without ?? []).join('.') + '-' + (line.extras ?? []).join('.') + '-' + (line.notes ?? '')">
        <li>
            <button type="button" @click="edit(i)" class="flex w-full items-start gap-3 py-2.5 text-left">
                <span class="num w-7 shrink-0 text-2xl leading-none font-bold text-chile" x-text="line.qty"></span>
                <span class="min-w-0 flex-1">
                    <span class="block text-[15px] font-semibold" x-text="catalog[line.product_id]?.name"></span>
                    <span class="mt-0.5 flex flex-wrap gap-1">
                        <template x-for="c in mods(line).choices" :key="c">
                            <span class="badge bg-limon text-on-limon" x-text="c"></span>
                        </template>
                        <template x-for="w in mods(line).without" :key="w">
                            <span class="badge bg-chile-soft text-chile" x-text="w"></span>
                        </template>
                        <template x-for="x in mods(line).extras" :key="x">
                            <span class="badge bg-salsa-soft text-salsa" x-text="'+ ' + x"></span>
                        </template>
                        <span x-show="mods(line).notes" class="text-sm text-muted italic" x-text="mods(line).notes"></span>
                    </span>
                </span>
                <span class="num shrink-0 text-lg font-semibold" x-text="fmt(unit(line) * line.qty)"></span>
                <x-icon name="pencil" class="mt-1 size-3.5 shrink-0 text-muted" />
            </button>
        </li>
    </template>
</ul>
