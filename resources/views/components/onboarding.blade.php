@use('App\Support\Branding')
@php
    $steps = [
        ['title' => 'Bienvenido', 'text' => 'Aquí llevas las ventas, los gastos y las ganancias de '.Branding::name().'. En un minuto te enseñamos lo básico.'],
        ['title' => 'Vender', 'text' => 'Toca un producto para sumarlo al pedido; con el botón − lo quitas. Cuando esté listo, toca Cobrar.'],
        ['title' => 'Cobrar', 'text' => 'Elige efectivo, transferencia o tarjeta. En efectivo toca el billete que te dieron y la app te dice el cambio.'],
        ['title' => '¿Te equivocaste?', 'text' => 'Justo después de cobrar aparece Deshacer. Tienes 10 minutos para cancelar la venta y corregir el pedido.'],
        ['title' => 'Gastos', 'text' => 'Anota lo que compras: carne, tortilla, gas. Si eliges el insumo y la cantidad, su costo se actualiza solo.'],
        ['title' => 'Recetas', 'text' => 'En Más, Productos, arma la receta de cada producto. Con eso la app calcula cuánto ganas por pieza.'],
        ['title' => 'Corte y resumen', 'text' => 'Al cerrar, haz el Corte de caja para ver si cuadra el efectivo. En Resumen ves cuánto vendiste y ganaste.'],
    ];
    $show = auth()->check() && auth()->user()->onboarded_at === null;
@endphp

<div x-data="{
        open: @js($show),
        step: 0,
        total: {{ count($steps) }},
        dir: 1,
        x0: null,
        go(n) {
            if (n < 0 || n >= this.total) return;
            this.dir = n > this.step ? 1 : -1;
            this.step = n;
        },
        next() { this.step === this.total - 1 ? this.finish() : this.go(this.step + 1) },
        prev() { this.go(this.step - 1) },
        finish() {
            this.open = false;
            fetch(@js(route('tutorial.done')), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content, 'Accept': 'application/json' },
            });
        },
        swipeStart(e) { this.x0 = e.touches[0].clientX },
        swipeEnd(e) {
            if (this.x0 === null) return;
            const dx = e.changedTouches[0].clientX - this.x0;
            this.x0 = null;
            if (Math.abs(dx) > 50) dx < 0 ? this.next() : this.prev();
        },
     }"
     @open-tutorial.window="step = 0; dir = 1; open = true"
     x-show="open" x-cloak
     @keydown.escape.window="open && finish()"
     @keydown.right.window="open && next()"
     @keydown.left.window="open && prev()"
     class="fixed inset-0 z-[85] flex items-end justify-center sm:items-center sm:p-4"
     role="dialog" aria-modal="true" aria-label="Tutorial">
    <div class="absolute inset-0 bg-black/60" x-show="open" x-transition.opacity></div>

    <div class="relative flex max-h-dvh w-full flex-col overflow-hidden bg-surface shadow-2xl sm:max-w-md sm:rounded-3xl max-sm:h-dvh"
         x-show="open" x-trap.noscroll.noautofocus="open"
         x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-8 opacity-0"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-end="opacity-0"
         @touchstart.passive="swipeStart($event)" @touchend.passive="swipeEnd($event)">

        {{-- Ilustración --}}
        <div class="bg-brand relative flex h-[50dvh] max-h-[26rem] min-h-56 shrink-0 items-center justify-center overflow-hidden px-6 pt-[env(safe-area-inset-top)] sm:h-72">
            <button type="button" @click="finish()" class="absolute top-[max(0.75rem,env(safe-area-inset-top))] right-3 z-10 rounded-full bg-white/70 px-3.5 py-1.5 text-sm font-semibold text-ink backdrop-blur hover:bg-white">Saltar</button>

            <div class="pointer-events-none w-full max-w-[18.5rem] select-none" aria-hidden="true">
                {{-- 0: bienvenida --}}
                <div x-show="step === 0" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 scale-90" class="flex flex-col items-center">
                    <x-logo class="size-36 shadow-xl ring-4 ring-white/80" :res="512" />
                </div>

                {{-- 1: vender --}}
                <div x-show="step === 1" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="grid grid-cols-2 gap-2">
                    <div class="relative rounded-2xl border-2 border-chile bg-tint-1 p-3 shadow-lg">
                        <p class="pr-7 font-display text-lg leading-none font-bold text-ink">Taco de bistec</p>
                        <span class="num mt-4 inline-block rounded-md bg-ink px-2 text-base font-bold text-bg">$20</span>
                        <span class="num absolute top-2 right-2 grid size-7 place-items-center rounded-full bg-chile text-base font-bold text-white">3</span>
                    </div>
                    <div class="rounded-2xl bg-tint-2 p-3 shadow-lg">
                        <p class="font-display text-lg leading-none font-bold text-ink">Torta</p>
                        <span class="num mt-4 inline-block rounded-md bg-ink px-2 text-base font-bold text-bg">$39</span>
                    </div>
                    <div class="col-span-2 mt-1 flex items-center gap-2 rounded-xl bg-chile px-3 py-2 text-white shadow-lg">
                        <span class="num grid size-7 place-items-center rounded-lg bg-white/20 font-bold">3</span>
                        <span class="flex-1 text-sm font-semibold">Cobrar</span>
                        <span class="num text-xl font-bold">$60</span>
                    </div>
                </div>

                {{-- 2: cobrar --}}
                <div x-show="step === 2" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="space-y-2 rounded-2xl bg-surface p-3 shadow-xl">
                    <div class="grid grid-cols-3 gap-1 rounded-lg bg-ink/8 p-1 text-center text-[11px] font-semibold">
                        <span class="rounded-md bg-surface py-1 text-ink shadow-sm">Efectivo</span><span class="py-1 text-muted">Transf.</span><span class="py-1 text-muted">Tarjeta</span>
                    </div>
                    <div class="flex gap-1.5 text-xs font-semibold">
                        <span class="rounded-full border border-line px-2.5 py-1 text-ink">Exacto</span>
                        <span class="num rounded-full bg-ink px-2.5 py-1 text-sm text-bg">$100</span>
                        <span class="num rounded-full border border-line px-2.5 py-1 text-sm text-ink">$200</span>
                    </div>
                    <div class="flex items-center justify-between rounded-lg bg-salsa-soft px-3 py-2 text-salsa">
                        <span class="text-xs font-bold">Cambio</span><span class="num text-2xl font-bold">$40</span>
                    </div>
                </div>

                {{-- 3: deshacer --}}
                <div x-show="step === 3" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="flex items-center gap-2.5 rounded-2xl bg-ink px-3 py-3 text-bg shadow-xl">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full bg-salsa text-white"><x-icon name="check" class="size-4" /></span>
                    <span class="flex-1 leading-tight"><span class="block text-xs opacity-75">Venta registrada</span><span class="num block text-xl font-bold">$60</span></span>
                    <span class="inline-flex items-center gap-1 rounded-lg bg-bg/15 px-2.5 py-1.5 text-xs font-semibold ring-2 ring-limon"><x-icon name="undo" class="size-3.5" /> Deshacer</span>
                </div>

                {{-- 4: gastos --}}
                <div x-show="step === 4" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="divide-y divide-line overflow-hidden rounded-2xl bg-surface shadow-xl">
                    @foreach ([['package', 'bg-limon text-on-limon', 'Bistec', '6 kg', '$1,140'], ['flame', 'bg-chile-soft text-chile', 'Gas', '', '$420'], ['cup', 'bg-naranja-soft text-ink', 'Desechables', '', '$180']] as [$icon, $tone, $name, $meta, $amount])
                        <div class="flex items-center gap-2.5 px-3 py-2.5">
                            <span class="grid size-8 place-items-center rounded-lg {{ $tone }}"><x-icon :name="$icon" class="size-4" /></span>
                            <span class="flex-1 text-sm leading-tight font-semibold text-ink">{{ $name }} @if ($meta)<span class="block text-xs font-normal text-muted">{{ $meta }}</span>@endif</span>
                            <span class="num text-lg font-bold text-ink">{{ $amount }}</span>
                        </div>
                    @endforeach
                </div>

                {{-- 5: recetas --}}
                <div x-show="step === 5" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="space-y-2 rounded-2xl bg-surface p-3 shadow-xl">
                    <p class="font-display text-lg leading-none font-bold text-ink">Taco de bistec</p>
                    @foreach ([['Bistec', '0.05 kg'], ['Tortilla', '2 pza'], ['Cilantro', '0.02 paq']] as [$i, $q])
                        <div class="flex justify-between text-xs text-ink"><span>{{ $i }}</span><span class="text-muted">{{ $q }}</span></div>
                    @endforeach
                    <div class="grid grid-cols-3 border-t border-line pt-2 text-center">
                        <span><span class="block text-[10px] text-muted">Costo</span><span class="num text-lg font-bold text-ink">$12</span></span>
                        <span><span class="block text-[10px] text-muted">Ganas</span><span class="num text-lg font-bold text-salsa">$8</span></span>
                        <span><span class="block text-[10px] text-muted">Margen</span><span class="num text-lg font-bold text-ink">40%</span></span>
                    </div>
                </div>

                {{-- 6: corte y resumen --}}
                <div x-show="step === 6" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-x-6" class="space-y-2">
                    <div class="flex items-center justify-between rounded-2xl bg-surface px-3 py-2.5 shadow-xl">
                        <span class="text-xs font-semibold text-muted">Debe haber en caja</span><span class="num text-2xl font-bold text-ink">$1,250</span>
                    </div>
                    <div class="flex items-center gap-2 rounded-2xl bg-salsa-soft px-3 py-2 text-salsa shadow-lg">
                        <x-icon name="check" class="size-4" /><span class="text-sm font-bold">La caja cuadra</span>
                    </div>
                    <div class="flex h-16 items-end gap-1.5 rounded-2xl bg-surface px-3 pt-2 pb-2 shadow-xl">
                        @foreach ([40, 65, 50, 90, 70, 100, 55] as $h)
                            <span class="flex-1 rounded-t {{ $h === 100 ? 'bg-sales' : 'bg-sales/40' }}" style="height: {{ $h }}%"></span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Texto --}}
        <div class="flex flex-1 flex-col px-6 pt-6 pb-[max(1.25rem,env(safe-area-inset-bottom))]">
            <div class="flex-1" aria-live="polite">
                @foreach ($steps as $i => $s)
                    <div x-show="step === {{ $i }}" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="opacity-0 translate-y-2">
                        <p class="text-sm font-semibold text-chile">Paso {{ $i + 1 }} de {{ count($steps) }}</p>
                        <h2 class="mt-1 font-display text-[2rem] leading-none font-bold">{{ $s['title'] }}</h2>
                        <p class="mt-3 text-[17px] leading-relaxed text-muted">{{ $s['text'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="mt-6 flex items-center justify-center gap-1.5" aria-hidden="true">
                @foreach ($steps as $i => $s)
                    <button type="button" tabindex="-1" @click="go({{ $i }})" class="h-2 rounded-full transition-all duration-300"
                            :class="step === {{ $i }} ? 'w-6 bg-chile' : 'w-2 bg-line'"></button>
                @endforeach
            </div>

            <div class="mt-5 flex gap-2">
                <button type="button" @click="prev()" class="btn btn-lg" x-show="step > 0" aria-label="Anterior"><x-icon name="left" /></button>
                <button type="button" @click="next()" class="btn-primary btn-lg w-full" x-text="step === 0 ? 'Empezar' : (step === total - 1 ? 'Listo, a vender' : 'Siguiente')">Empezar</button>
            </div>
        </div>
    </div>
</div>
