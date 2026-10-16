{{-- Renglones del pedido. Se usa dentro del x-data de Vender. --}}
<ul class="divide-y divide-line">
    <template x-for="(line, i) in list()" :key="i + '-' + line.product_id + '-' + line.qty + '-' + (line.choices ?? []).join('.') + '-' + (line.without ?? []).join('.') + '-' + (line.extras ?? []).join('.') + '-' + (line.notes ?? '')">
        <li class="flex items-start gap-2">
            {{-- La cantidad se escribe directo: 15 tacos no deberían ser 15 toques. Un 0 quita el renglón; vacío regresa a la anterior. --}}
            <input type="number" inputmode="numeric" min="0" max="999" :value="line.qty"
                   @change="setQty(i, $event.target)" @focus="$event.target.select()" @keydown.enter.prevent="$event.target.blur()"
                   class="num mt-1.5 h-10 w-12 shrink-0 rounded-lg border border-line bg-surface text-center text-xl leading-none focus:border-ink focus:outline-none"
                   :aria-label="'Cantidad de ' + (catalog[line.product_id]?.name ?? 'producto')">
            <button type="button" @click="edit(i)" class="flex min-w-0 flex-1 items-start gap-3 py-2.5 text-left">
                <span class="min-w-0 flex-1">
                    <span class="block text-[15px] font-semibold" :class="! catalog[line.product_id] && 'text-chile'" x-text="catalog[line.product_id]?.name ?? 'Ya no está a la venta'"></span>
                    <span class="mt-0.5 flex flex-wrap gap-1">
                        <span x-show="mods(line).courtesy" class="badge bg-ink text-bg">Cortesía</span>
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
                <span class="num shrink-0 text-lg font-semibold" x-text="line.courtesy ? 'Gratis' : fmt(unit(line) * line.qty)"></span>
                <x-icon name="pencil" class="mt-1 size-3.5 shrink-0 text-muted" />
            </button>
        </li>
    </template>
</ul>
