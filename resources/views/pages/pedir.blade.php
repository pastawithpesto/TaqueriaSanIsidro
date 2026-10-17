<?php

use App\Models\Product;
use App\Models\Setting;
use App\Support\Branding;
use App\Support\OnlineOrder;
use App\Support\Store;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::public')] #[Title('Haz tu pedido')] class extends Component
{
    /** Menú para el navegador: precios, "sin" posibles, extras y opciones obligatorias. Lo agotado hoy sale marcado. */
    #[Computed]
    public function catalog(): array
    {
        return Product::with(['ingredients', 'extras', 'optionGroups.choices'])->where('active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'category' => $p->category ?: 'Menú',
                'price' => (float) $p->price,
                'soldOut' => $p->isSoldOut(),
                'without' => $p->ingredients->filter(fn ($i) => $i->pivot->removable)->map(fn ($i) => ['id' => $i->id, 'name' => $i->name])->values()->all(),
                'extras' => $p->extras->map(fn ($e) => ['id' => $e->id, 'name' => $e->name, 'price' => (float) $e->price])->values()->all(),
                'options' => $p->optionGroups->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name, 'required' => $g->required,
                    'choices' => $g->choices->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'price' => (float) $c->price])->values()->all(),
                ])->filter(fn ($g) => $g['choices'])->values()->all(),
            ])->all();
    }

    /** Cómo está la taquería para los clientes: abierta o cerrada, mensaje, horario y si se puede pedir. */
    #[Computed]
    public function store(): array
    {
        $status = Store::status();
        $can = Store::canOrderOnline();

        return [
            'open' => $status['open'],
            'message' => $status['message'],
            'opensAt' => $status['opens_at']?->translatedFormat('l').' a las '.($status['opens_at'] ? Store::label($status['opens_at']->format('H:i')) : ''),
            'closesAt' => $status['closes_at'] ? Store::label($status['closes_at']->format('H:i')) : null,
            'schedule' => Store::summary(),
            'canOrder' => $can['ok'],
            'reason' => $can['reason'],
            'delivery' => Store::deliveryEnabled(),
            'deliveryOffMessage' => Store::deliveryOffMessage(),
            'phone' => Store::phone(),
            'packagingFee' => (float) Setting::value('packaging_fee', '0'),
            'deliveryFee' => (float) Setting::value('delivery_fee', '0'),
        ];
    }

    /**
     * Manda el pedido. Los errores regresan como texto por campo para mostrarlos junto al formulario.
     *
     * @param  array<int, mixed>  $lines
     * @param  array<string, mixed>  $form
     * @return array{ok: bool, errors?: array<string, string>}
     */
    #[Renderless]
    public function place(array $lines, array $form): array
    {
        // Campo trampa: las personas no lo ven, así que si trae algo es un programa.
        if (filled($form['website'] ?? null)) {
            return ['ok' => false, 'errors' => ['store' => 'No pudimos mandar tu pedido. Inténtalo otra vez.']];
        }

        try {
            $order = OnlineOrder::place($form + ['lines' => $lines], request()->ip() ?? 'desconocido');
        } catch (ValidationException $e) {
            return ['ok' => false, 'errors' => collect($e->errors())->map(fn ($messages) => $messages[0])->all()];
        }

        $this->redirect(route('pedido', $order->public_token));

        return ['ok' => true];
    }
};
?>

@php
    $store = $this->store;
    $ordering = $store['canOrder'];
@endphp

<div x-data="orderPage(@js($this->catalog), @js($store))" class="min-h-dvh pb-32">
    {{-- Encabezado --}}
    <header class="bg-brand flex flex-col items-center rounded-b-3xl px-5 pt-[max(1.5rem,env(safe-area-inset-top))] pb-6 text-center shadow-[0_6px_20px_-12px_rgb(78_44_17/0.45)]">
        <x-logo class="size-40 shadow-xl ring-4 ring-white/80 sm:size-44" :res="512" />
        <h1 class="mt-4 font-display text-[2.25rem] leading-[1] text-balance">{{ Branding::name() }}</h1>
        <p class="mt-2 inline-flex items-center gap-2 rounded-full bg-white/70 px-3.5 py-1.5 text-sm font-semibold">
            <span @class(['size-2.5 rounded-full', 'bg-salsa' => $store['open'], 'bg-chile' => ! $store['open']])></span>
            @if ($store['open'])
                Abierto{{ $store['closesAt'] ? ' hasta las '.$store['closesAt'] : '' }}
            @else
                Cerrado{{ $store['opensAt'] && $store['opensAt'] !== ' a las ' ? '. Abrimos el '.mb_strtolower($store['opensAt']) : '' }}
            @endif
        </p>
        <p class="mt-3 text-sm">{{ $store['schedule'] }}</p>
        @if ($store['phone'])
            <div class="mt-3 flex flex-wrap justify-center gap-2">
                <a href="tel:{{ $store['phone'] }}" class="btn !min-h-9 !px-3"><x-icon name="phone" class="size-4" /> Llamar</a>
                <a href="https://wa.me/52{{ $store['phone'] }}" target="_blank" rel="noopener" class="btn !min-h-9 !px-3"><x-icon name="chat" class="size-4" /> WhatsApp</a>
            </div>
        @endif
    </header>

    <div class="mx-auto max-w-2xl">

    @if ($store['message'])
        <div role="status" class="mx-4 mt-4 flex gap-3 rounded-2xl bg-limon-soft p-4 text-[15px] font-semibold">
            <x-icon name="info" class="mt-0.5 size-5 shrink-0" />
            <p>{{ $store['message'] }}</p>
        </div>
    @endif

    @if (! $ordering)
        <div role="status" class="mx-4 mt-4 flex gap-3 rounded-2xl border border-line bg-surface p-4 text-[15px]">
            <x-icon name="clock" class="mt-0.5 size-5 shrink-0 text-muted" />
            <p>
                @if ($store['reason'] === 'online_off')
                    Por ahora no estamos recibiendo pedidos en línea. Puedes ver el menú, y te esperamos en el puesto.
                @else
                    No estamos recibiendo pedidos en este momento{{ $store['opensAt'] && $store['opensAt'] !== ' a las ' ? '. Podrás pedir desde el '.mb_strtolower($store['opensAt']) : '' }}. Mientras, mira el menú.
                @endif
            </p>
        </div>
    @endif

    {{-- Menú --}}
    <main class="space-y-6 px-4 pt-5">
        @if ($this->catalog === [])
            <x-empty icon="tag" title="Aún no hay menú">Pronto vamos a publicar lo que vendemos.</x-empty>
        @endif
        <template x-for="group in groups" :key="group.name">
            <section class="space-y-2">
                <h2 class="section-title px-1" x-text="group.name"></h2>
                <ul class="list">
                    <template x-for="p in group.products" :key="p.id">
                        <li class="flex items-center gap-3 py-3 pr-3 pl-4" :class="p.soldOut && 'opacity-55'">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold" x-text="p.name"></p>
                                <p class="text-sm text-muted" x-show="p.soldOut" x-cloak>Agotado por hoy</p>
                                <p class="text-sm text-muted" x-show="! p.soldOut && customizable(p)" x-cloak>Personalízalo a tu gusto</p>
                            </div>
                            <span class="num text-xl font-bold" x-text="money(p.price)"></span>
                            @if ($ordering)
                                <div class="flex items-center gap-1">
                                    <button type="button" x-show="plainCount(p) > 0 && ! customizable(p)" x-cloak @click="changeQty(p, -1)" class="btn-icon size-10" :aria-label="'Quitar uno de ' + p.name"><x-icon name="minus" class="size-4" /></button>
                                    <span class="num w-6 text-center text-lg font-bold" x-show="countOf(p) > 0" x-cloak x-text="countOf(p)"></span>
                                    <button type="button" @click="tap(p)" :disabled="p.soldOut" class="btn-primary size-10 justify-center !px-0 disabled:opacity-40" :aria-label="'Agregar ' + p.name"><x-icon name="plus" class="size-5" /></button>
                                </div>
                            @endif
                        </li>
                    </template>
                </ul>
            </section>
        </template>
    </main>
    </div>

    {{-- Barra del pedido --}}
    @if ($ordering)
        <div x-show="count() > 0" x-cloak class="fixed inset-x-0 bottom-0 z-30 px-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div class="mx-auto max-w-2xl">
                <button type="button" @click="openCart()" class="btn-primary btn-lg w-full justify-between">
                    <span class="num grid size-9 place-items-center rounded-lg bg-white/20 text-lg" x-text="count()"></span>
                    <span>Ver mi pedido</span>
                    <span class="num text-xl" x-text="money(subtotal())"></span>
                </button>
            </div>
        </div>
    @endif

    {{-- Personalizar un producto --}}
    <x-sheet title="Personalizar" open="editor !== null" dismiss="editor = null" x-text="editor ? editor.product.name : ''">
        <template x-if="editor">
            <div class="space-y-5">
                <div x-show="editor.product.options.length" class="space-y-4">
                    <template x-for="g in editor.product.options" :key="g.id">
                        <div>
                            <p class="label"><span x-text="g.name"></span> <span class="font-normal text-muted" x-text="g.required ? '(elige una)' : '(opcional)'"></span></p>
                            <div class="flex flex-wrap gap-2">
                                <template x-for="c in g.choices" :key="c.id">
                                    <button type="button" class="chip" :aria-pressed="editor.choices[g.id] === c.id" @click="editor.choices[g.id] = (editor.choices[g.id] === c.id && ! g.required) ? null : c.id">
                                        <span x-text="c.name"></span> <span class="num ml-1 font-normal" x-show="c.price > 0" x-text="'+' + money(c.price)"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div x-show="editor.product.without.length">
                    <p class="label">Sin</p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="w in editor.product.without" :key="w.id">
                            <button type="button" class="chip" :aria-pressed="editor.without.includes(w.id)" @click="toggle(editor.without, w.id)" x-text="'Sin ' + w.name.toLowerCase()"></button>
                        </template>
                    </div>
                </div>
                <div x-show="editor.product.extras.length">
                    <p class="label">Extras</p>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="e in editor.product.extras" :key="e.id">
                            <button type="button" class="chip" :aria-pressed="editor.extras.includes(e.id)" @click="toggle(editor.extras, e.id)">
                                <span x-text="e.name"></span> <span class="num ml-1 font-normal" x-text="'+' + money(e.price)"></span>
                            </button>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="label" for="line-notes">Nota (opcional)</label>
                    <input id="line-notes" type="text" x-model="editor.notes" maxlength="120" class="input" placeholder="Ej. bien dorada, salsa aparte">
                </div>
                <div class="flex items-center justify-between gap-3">
                    <span class="font-semibold">Cantidad</span>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn-icon size-11" @click="editor.qty = Math.max(1, editor.qty - 1)" aria-label="Menos"><x-icon name="minus" class="size-4" /></button>
                        <span class="num w-8 text-center text-xl font-bold" x-text="editor.qty"></span>
                        <button type="button" class="btn-icon size-11" @click="editor.qty = Math.min(20, editor.qty + 1)" aria-label="Más"><x-icon name="plus" class="size-4" /></button>
                    </div>
                </div>
                <p class="error" x-show="editorError" x-text="editorError" x-cloak></p>
            </div>
        </template>
        <x-slot:footer>
            <button type="button" class="btn-primary btn-lg w-full" @click="saveEditor()">
                <span x-text="'Agregar ' + money(editorTotal())"></span>
            </button>
        </x-slot:footer>
    </x-sheet>

    {{-- Mi pedido --}}
    @if ($ordering)
        <x-sheet title="Mi pedido" open="cart" dismiss="cart = false">
            <div class="space-y-5">
                <ul class="divide-y divide-line rounded-2xl border border-line">
                    <template x-for="(l, i) in lines" :key="l.key">
                        <li class="flex items-start gap-3 p-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold" x-text="l.product.name"></p>
                                <p class="text-sm text-muted" x-show="describe(l)" x-text="describe(l)"></p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" class="btn-icon size-9" @click="bump(i, -1)" :aria-label="'Menos ' + l.product.name"><x-icon name="minus" class="size-4" /></button>
                                <span class="num w-6 text-center font-bold" x-text="l.qty"></span>
                                <button type="button" class="btn-icon size-9" @click="bump(i, 1)" :aria-label="'Más ' + l.product.name"><x-icon name="plus" class="size-4" /></button>
                            </div>
                            <span class="num w-16 text-right font-bold" x-text="money(lineTotal(l))"></span>
                        </li>
                    </template>
                </ul>
                <p class="error" x-show="errors.lines" x-text="errors.lines" x-cloak></p>

                <div>
                    <label class="label" for="c-name">Tu nombre</label>
                    <input id="c-name" type="text" x-model="form.name" maxlength="60" autocomplete="name" class="input" placeholder="Para llamarte cuando esté listo">
                    <p class="error" x-show="errors.name" x-text="errors.name" x-cloak></p>
                </div>
                <div>
                    <label class="label" for="c-phone">Tu teléfono</label>
                    <input id="c-phone" type="tel" inputmode="tel" x-model="form.phone" maxlength="20" autocomplete="tel" class="input" placeholder="10 dígitos">
                    <p class="error" x-show="errors.phone" x-text="errors.phone" x-cloak></p>
                </div>

                <div>
                    <p class="label">¿Cómo lo quieres?</p>
                    <div class="segmented" role="group" aria-label="Servicio">
                        <button type="button" @click="form.service = 'togo'" :aria-pressed="form.service === 'togo'">Paso a recoger</button>
                        <button type="button" @click="if (store.delivery) form.service = 'delivery'" :aria-pressed="form.service === 'delivery'" :aria-disabled="! store.delivery" :class="! store.delivery && 'opacity-45'">A domicilio</button>
                    </div>
                    <p class="hint mt-2" x-show="! store.delivery" x-text="store.deliveryOffMessage" x-cloak></p>
                    <p class="error" x-show="errors.service" x-text="errors.service" x-cloak></p>
                </div>

                <div x-show="form.service === 'delivery'" x-cloak class="space-y-4">
                    <div>
                        <label class="label" for="c-address">Dirección</label>
                        <input id="c-address" type="text" x-model="form.address" maxlength="255" autocomplete="street-address" class="input" placeholder="Calle, número y colonia">
                        <p class="error" x-show="errors.address" x-text="errors.address" x-cloak></p>
                    </div>
                    <div>
                        <label class="label" for="c-refs">Referencias (opcional)</label>
                        <input id="c-refs" type="text" x-model="form.references" maxlength="160" class="input" placeholder="Ej. casa azul, portón negro">
                    </div>
                </div>

                <div>
                    <label class="label" for="c-notes">Algo más que debamos saber (opcional)</label>
                    <input id="c-notes" type="text" x-model="form.notes" maxlength="160" class="input" placeholder="Ej. paso en una camioneta roja">
                    <p class="error" x-show="errors.notes" x-text="errors.notes" x-cloak></p>
                </div>

                {{-- Honeypot: las personas no lo ven; los programas que llenan todo, sí. --}}
                <input type="text" x-model="form.website" tabindex="-1" autocomplete="off" aria-hidden="true" class="absolute -left-[9999px] size-0 opacity-0">

                <dl class="space-y-1.5 rounded-2xl bg-sunken p-4 text-[15px]">
                    <div class="flex justify-between"><dt>Productos</dt><dd class="num" x-text="money(subtotal())"></dd></div>
                    <div class="flex justify-between" x-show="fees().packaging > 0" x-cloak><dt>Empaque</dt><dd class="num" x-text="money(fees().packaging)"></dd></div>
                    <div class="flex justify-between" x-show="fees().delivery > 0" x-cloak><dt>Envío</dt><dd class="num" x-text="money(fees().delivery)"></dd></div>
                    <div class="flex justify-between border-t border-line pt-2 text-xl font-bold"><dt>Total</dt><dd class="num" x-text="money(total())"></dd></div>
                </dl>
                <p class="hint">Pagas cuando {{ '' }}<span x-text="form.service === 'delivery' ? 'te lo entregamos' : 'recoges tu pedido'"></span>. La taquería primero confirma tu pedido y aquí mismo verás si lo aceptó.</p>
                <p class="error" x-show="errors.store" x-text="errors.store" x-cloak></p>
                <div x-show="errors.prices" x-cloak class="space-y-2">
                    <p class="error" x-text="errors.prices"></p>
                    {{-- El menú se carga una vez: para ver los precios nuevos hay que volver a leerlo. El borrador del pedido se conserva. --}}
                    <button type="button" class="btn w-full" @click="location.reload()">Actualizar precios</button>
                </div>
            </div>
            <x-slot:footer>
                <button type="button" class="btn-primary btn-lg w-full disabled:opacity-60" :disabled="sending" @click="submit()">
                    <span x-show="! sending" x-text="'Enviar pedido ' + money(total())"></span>
                    <span x-show="sending" x-cloak>Enviando...</span>
                </button>
            </x-slot:footer>
        </x-sheet>
    @endif
</div>

