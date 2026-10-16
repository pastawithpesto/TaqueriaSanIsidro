<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Models\Order;
use App\Support\BusinessDay;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Ventas')] class extends Component
{
    public string $date = '';

    /** Cambio de forma de pago de una venta ya cobrada. */
    public bool $showPayment = false;

    public ?int $payOrderId = null;

    public string $payMethod = 'cash';

    public ?string $payCashPart = null;

    public string $payOtherMethod = 'transfer';

    public ?string $payReference = null;

    public function mount(): void
    {
        $this->date = BusinessDay::today()->toDateString();
    }

    protected function day(): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($this->date)->startOfDay();
        } catch (Throwable) {
            return BusinessDay::today();
        }
    }

    #[Computed]
    public function dayLabel(): string
    {
        $day = $this->day();

        return match (true) {
            $day->equalTo(BusinessDay::today()) => 'Hoy',
            $day->equalTo(BusinessDay::today()->subDay()) => 'Ayer',
            default => ucfirst($day->translatedFormat('l')),
        };
    }

    #[Computed]
    public function orders()
    {
        $day = $this->day();

        return Order::with(['items', 'payments'])
            ->countedBetween(BusinessDay::start($day), BusinessDay::end($day))
            ->orderByRaw('COALESCE(paid_at, sold_at)')
            ->get()
            ->each(fn ($o, $i) => $o->number = $i + 1)
            ->reverse()
            ->values();
    }

    #[Computed]
    public function paid()
    {
        return $this->orders->where('status', OrderStatus::Paid);
    }

    /**
     * Solo se corrige lo del día de trabajo en curso: lo de otros días ya está en su corte.
     * Si se devolvió dinero de una venta vieja, va como retiro en el corte de hoy.
     */
    protected function editable(Order $order): bool
    {
        return BusinessDay::isCurrent($order->countedAt());
    }

    protected function refuse(): void
    {
        $this->dispatch('toast', message: 'Es de otro día. Si devolviste dinero, anótalo como retiro en el corte de hoy.', type: 'error');
    }

    protected function changed(string $message): void
    {
        unset($this->orders, $this->paid);
        $this->dispatch('toast', message: $message);
        $this->dispatch('pending-count', count: Order::pendingCount());
    }

    public function cancel(int $id): void
    {
        $order = Order::where('status', OrderStatus::Paid)->findOrFail($id);

        if (! $this->editable($order)) {
            $this->refuse();

            return;
        }

        $order->cancel();
        $this->changed('Venta cancelada');
    }

    public function restore(int $id): void
    {
        $order = Order::where('status', OrderStatus::Cancelled)->findOrFail($id);

        if (! $this->editable($order)) {
            $this->refuse();

            return;
        }

        if ($order->wasCorrected()) {
            $this->dispatch('toast', message: 'Esta venta se corrigió y ya se volvió a cobrar.', type: 'error');

            return;
        }

        $order->update(['status' => $order->payment_method ? OrderStatus::Paid : OrderStatus::Open, 'cancelled_at' => null, 'cancel_reason' => null]);
        $this->changed('Venta restaurada');
    }

    /** Cobrada mal (faltó o sobró algo): Vender la cancela y la abre con todo para cobrarla de nuevo. */
    public function correct(int $id): void
    {
        $order = Order::where('status', OrderStatus::Paid)->findOrFail($id);

        if (! $this->editable($order)) {
            $this->refuse();

            return;
        }

        session()->flash('vender.corregir', $order->id);
        $this->redirectRoute('vender', navigate: true);
    }

    #[Computed]
    public function payOrder(): ?Order
    {
        return $this->payOrderId ? Order::find($this->payOrderId) : null;
    }

    public function editPayment(int $id): void
    {
        $order = Order::with('payments')->where('status', OrderStatus::Paid)->findOrFail($id);
        $cash = $order->payments->firstWhere('method', PaymentMethod::Cash);
        $other = $order->payments->first(fn ($p) => $p->method !== PaymentMethod::Cash);

        $this->resetErrorBag();
        $this->payOrderId = $order->id;
        $this->payMethod = $order->payment_method?->value ?? 'cash';
        $this->payCashPart = $order->payment_method === PaymentMethod::Mixed && $cash ? rtrim(rtrim((string) $cash->amount, '0'), '.') : null;
        $this->payOtherMethod = $other && $other->method !== PaymentMethod::Mixed ? $other->method->value : 'transfer';
        $this->payReference = $order->payment_reference;
        $this->showPayment = true;
    }

    /** El error más común al cobrar: se cambia el pago sin cancelar la venta ni tocar la cocina. */
    public function savePayment(): void
    {
        $order = Order::where('status', OrderStatus::Paid)->findOrFail($this->payOrderId);

        if (! $this->editable($order)) {
            $this->refuse();

            return;
        }

        $due = round((float) $order->total + (float) $order->tip, 2);

        $this->validate([
            'payMethod' => ['required', 'in:cash,transfer,card,mixed'],
            'payCashPart' => ['exclude_unless:payMethod,mixed', 'required', 'numeric', 'gt:0', 'lt:'.$due],
            'payOtherMethod' => ['exclude_unless:payMethod,mixed', 'required', 'in:transfer,card'],
            'payReference' => ['nullable', 'string', 'max:255'],
        ], [
            'payCashPart.lt' => 'En pago mixto el efectivo tiene que ser menos que el total.',
        ], ['payCashPart' => 'efectivo']);

        $order->recordPayments($this->payMethod, (float) $this->payCashPart, $this->payOtherMethod, trim((string) $this->payReference) ?: null);

        $this->showPayment = false;
        $this->changed('Forma de pago cambiada');
    }
};
?>

@php
    $paid = $this->paid;
    $total = (float) $paid->sum('total');
    // Por forma de pago (un pago mixto cuenta en las dos), sin propinas para que sume lo vendido.
    // La propina se cobra junto con lo demás; en un pago mixto va en la parte que no es efectivo.
    $sold = collect(PaymentMethod::simple())->mapWithKeys(fn ($m) => [$m->value => 0.0])->all();
    foreach ($paid as $order) {
        $tipLeft = (float) $order->tip;
        foreach ($order->payments->sortBy(fn ($p) => $p->method === PaymentMethod::Cash ? 1 : 0) as $payment) {
            $fromTip = min($tipLeft, (float) $payment->amount);
            $tipLeft -= $fromTip;
            $sold[$payment->method->value] += (float) $payment->amount - $fromTip;
        }
    }
    $byMethod = collect(PaymentMethod::simple())
        ->map(fn ($m) => ['method' => $m, 'total' => round($sold[$m->value], 2)])
        ->filter(fn ($m) => $m['total'] > 0);
    $collected = (float) $byMethod->sum('total');
    $tips = (float) $paid->sum('tip');
    $discounts = (float) $paid->sum('discount') + $paid->flatMap->items->sum(fn ($i) => $i->courtesyValue());
    // Canceladas con Deshacer que se volvieron a cobrar con el mismo número: son correcciones, no ventas perdidas.
    $isCorrected = fn ($o) => $o->status === OrderStatus::Cancelled && $this->orders->contains(
        fn ($other) => $other->status !== OrderStatus::Cancelled && $other->daily_number === $o->daily_number && BusinessDay::of($other->sold_at)->equalTo(BusinessDay::of($o->sold_at))
    );
    $cancelled = $this->orders->where('status', OrderStatus::Cancelled)->reject($isCorrected)->count();
    $openCount = $this->orders->where('status', OrderStatus::Open)->count();
    $isToday = $this->dayLabel === 'Hoy';
    $color = fn ($m) => $m === PaymentMethod::Cash ? 'bg-ink' : ($m === PaymentMethod::Transfer ? 'bg-naranja' : 'bg-limon');
@endphp

<div x-data="{ paySheet: $wire.$entangle('showPayment') }">
    <x-page-header title="Ventas" tour="ventas">
        <x-slot:actions>
            <a href="{{ route('corte') }}" wire:navigate class="btn" data-tour="corte"><x-icon name="banknote" class="size-4" /> Corte</a>
        </x-slot:actions>
        <x-date-nav :max="BusinessDay::today()->toDateString()" data-tour="fecha" />
    </x-page-header>

    {{-- Mientras llega el nuevo día --}}
    <div wire:loading.delay.short wire:target="date" class="w-full space-y-4">
        <div class="card h-[8.5rem] animate-pulse !bg-sunken/60"></div>
        <x-skeleton-list :rows="5" />
    </div>

    <div wire:loading.remove.delay.short wire:target="date" class="space-y-4">
        @if ($this->orders->isNotEmpty())
            <section class="card !p-5" data-tour="ventas-dia">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <p class="text-sm font-semibold text-muted">Vendido</p>
                        <p class="num text-[3rem] leading-none font-bold">{{ money($total) }}</p>
                    </div>
                    <p class="pb-1 text-right text-sm text-muted">
                        <span class="num block text-2xl leading-none font-bold text-ink">{{ $paid->count() }}</span>
                        {{ $paid->count() === 1 ? 'orden' : 'órdenes' }}
                    </p>
                </div>
                @if ($total > 0)
                    <div class="mt-4 flex h-2 gap-0.5 overflow-hidden rounded-full" aria-hidden="true">
                        @foreach ($byMethod as $row)
                            <span class="h-full {{ $color($row['method']) }}" style="width: {{ $collected > 0 ? $row['total'] / $collected * 100 : 0 }}%"></span>
                        @endforeach
                    </div>
                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                        @foreach ($byMethod as $row)
                            <div class="flex items-center gap-2">
                                <span class="size-2.5 rounded-sm {{ $color($row['method']) }}"></span>
                                <dt class="text-muted">{{ $row['method']->label() }}</dt>
                                <dd class="num text-base font-bold">{{ money($row['total']) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                @endif
                @if ($tips > 0 || $discounts > 0)
                    <p class="mt-3 flex flex-wrap gap-x-5 gap-y-1 border-t border-line pt-3 text-sm text-muted">
                        @if ($tips > 0) <span>Propinas <span class="num text-base font-bold text-ink">{{ money($tips) }}</span></span> @endif
                        @if ($discounts > 0) <span>Descuentos y cortesías <span class="num text-base font-bold text-ink">{{ money($discounts) }}</span></span> @endif
                    </p>
                @endif
            </section>

            <div class="flex items-baseline justify-between px-1">
                <h2 class="section-title">Órdenes</h2>
                <span class="text-sm text-muted">
                    @if ($openCount > 0)<a href="{{ route('pedidos') }}" wire:navigate class="font-semibold text-chile underline">{{ $openCount }} por cobrar</a>@endif{{ $openCount > 0 && $cancelled > 0 ? ', ' : '' }}@if ($cancelled > 0){{ $cancelled }} {{ $cancelled === 1 ? 'cancelada' : 'canceladas' }}@endif
                </span>
            </div>
            <ul class="list">
                @foreach ($this->orders as $order)
                    @php
                        $isCancelled = $order->status === OrderStatus::Cancelled;
                        $corrected = $isCorrected($order);
                        $editable = BusinessDay::isCurrent($order->countedAt());
                        $cost = $order->items->sum(fn ($i) => $i->quantity * (float) $i->unit_cost);
                    @endphp
                    <li wire:key="o-{{ $order->id }}" x-data="{ open: false }" @if ($loop->first) data-tour="orden" @endif>
                        @php
                            $isOpenOrder = $order->status === OrderStatus::Open;
                            $summary = $order->itemsSummary();
                        @endphp
                        <button type="button" @click="open = !open" class="flex w-full items-center gap-3 px-4 py-3.5 text-left hover:bg-sunken/50" :aria-expanded="open">
                            <span class="num w-8 shrink-0 text-center text-lg font-bold text-muted">{{ $order->daily_number ?? $order->number }}</span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-[15px] font-semibold', 'text-muted line-through' => $isCancelled])>
                                    {{ $order->label ?: $summary }}
                                </span>
                                <span class="mt-0.5 flex items-center gap-2 text-sm text-muted">
                                    <span class="shrink-0">{{ $order->countedAt()->format('g:i a') }}</span>
                                    @if ($isOpenOrder)
                                        <span class="badge bg-limon-soft text-ink">Por cobrar</span>
                                    @elseif ($order->payment_method && $order->payment_method !== PaymentMethod::Cash)
                                        <span class="badge bg-naranja-soft text-ink">{{ $order->payment_method->label() }}</span>
                                    @endif
                                    @if ($corrected)
                                        <span class="badge bg-sunken text-muted">Corregida</span>
                                    @elseif ($isCancelled)
                                        <span class="badge bg-chile-soft text-chile">Cancelada</span>
                                    @endif
                                    @if ($order->label) <span class="truncate">{{ $summary }}</span> @endif
                                </span>
                            </span>
                            <span @class(['num text-[1.375rem] font-bold', 'text-muted line-through' => $isCancelled, 'text-muted' => $isOpenOrder])>{{ money($order->total) }}</span>
                            <x-icon name="down" class="size-4 shrink-0 text-muted transition-transform duration-200" ::class="open && 'rotate-180'" />
                        </button>

                        <div x-show="open" x-collapse x-cloak>
                            <div class="space-y-3 border-t border-line bg-sunken/40 px-4 py-3.5 pl-15 text-sm">
                                <ul class="space-y-1.5">
                                    @foreach ($order->items as $item)
                                        <li class="flex justify-between gap-3">
                                            <span>
                                                <span class="num font-bold">{{ $item->quantity }}</span> × {{ $item->product_name }} <span class="text-muted">a {{ money($item->unit_price) }}</span>
                                                @if ($item->modifiers || $item->notes)
                                                    <span class="mt-0.5 flex flex-wrap gap-1">
                                                        @if ($item->isCourtesy()) <span class="badge bg-ink text-bg">Cortesía</span> @endif
                                                        @foreach ($item->choices() as $choice) <span class="badge bg-limon-soft text-ink">{{ $choice['name'] }}</span> @endforeach
                                                        @foreach ($item->withoutNames() as $name) <span class="badge bg-chile-soft text-chile">Sin {{ mb_strtolower($name) }}</span> @endforeach
                                                        @foreach ($item->extras() as $extra) <span class="badge bg-salsa-soft text-salsa">+ {{ $extra['name'] }}</span> @endforeach
                                                        @if ($item->notes) <span class="text-muted italic">{{ $item->notes }}</span> @endif
                                                    </span>
                                                @endif
                                            </span>
                                            <span class="num text-base font-semibold">{{ money($item->subtotal) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($order->isDelivery())
                                    <p class="text-muted">A domicilio: <span class="font-semibold text-ink">{{ $order->delivery_address }}</span>@if ($order->delivery_phone), {{ $order->delivery_phone }}@endif</p>
                                @endif
                                @if ((float) $order->packaging_fee > 0 || (float) $order->delivery_fee > 0 || (float) $order->discount > 0 || (float) $order->tip > 0 || $order->payments->count() > 1)
                                    <div class="space-y-1 border-t border-line pt-2 text-muted">
                                        @if ((float) $order->packaging_fee > 0) <p class="flex justify-between">Empaque <span class="num text-base font-semibold text-ink">{{ money($order->packaging_fee) }}</span></p> @endif
                                        @if ((float) $order->delivery_fee > 0) <p class="flex justify-between">Envío <span class="num text-base font-semibold text-ink">{{ money($order->delivery_fee) }}</span></p> @endif
                                        @if ((float) $order->discount > 0) <p class="flex justify-between">Descuento <span class="num text-base font-semibold text-salsa">−{{ money($order->discount) }}</span></p> @endif
                                        @if ((float) $order->tip > 0) <p class="flex justify-between">Propina <span class="num text-base font-semibold text-ink">{{ money($order->tip) }}</span></p> @endif
                                        @if ($order->payments->count() > 1)
                                            @foreach ($order->payments as $pay)
                                                <p class="flex justify-between">Pagó con {{ mb_strtolower($pay->method->label()) }} <span class="num text-base font-semibold text-ink">{{ money($pay->amount) }}</span></p>
                                            @endforeach
                                        @endif
                                    </div>
                                @endif
                                @if ($cost > 0 && ! $isCancelled)
                                    <p class="flex justify-between border-t border-line pt-2 text-muted">Ganancia por receta <span @class(['num text-base font-bold', (float) $order->total >= $cost ? 'text-salsa' : 'text-chile'])>{{ money((float) $order->total - $cost) }}</span></p>
                                @endif
                                @if ($order->payment_reference)
                                    <p class="text-muted">Referencia: <span class="font-semibold text-ink">{{ $order->payment_reference }}</span></p>
                                @endif
                                @if ($isCancelled && $order->cancelled_at)
                                    <p class="text-muted">{{ $corrected || $order->cancel_reason === Order::CORRECTION ? 'Se canceló para corregirla' : 'Se canceló' }} a las {{ $order->cancelled_at->format('g:i a') }}.</p>
                                @endif
                                @if ($isOpenOrder)
                                    <div class="flex justify-end">
                                        <a href="{{ route('vender', ['orden' => $order->id, 'cobrar' => 1]) }}" wire:navigate class="btn">Cobrar</a>
                                    </div>
                                @elseif ($corrected)
                                    <p class="text-muted">Se volvió a cobrar con el mismo número.</p>
                                @elseif (! $editable)
                                    {{-- Lo de otros días ya está en su corte: no se cambia. --}}
                                    <p class="flex gap-2 text-muted"><x-icon name="lock" class="mt-0.5 size-4 shrink-0" /> Es de otro día. Si devolviste dinero, anótalo como retiro en el corte de hoy.</p>
                                @elseif ($isCancelled)
                                    <div class="flex justify-end">
                                        <button type="button" wire:click="restore({{ $order->id }})" class="btn">Restaurar venta</button>
                                    </div>
                                @else
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button type="button" wire:click="editPayment({{ $order->id }})" class="btn">Cambiar forma de pago</button>
                                        <button type="button" class="btn"
                                                @click="if (await $store.confirm.ask({ title: @js('¿Corregir la venta #'.$order->daily_number.'?'), message: 'Se cancela y el pedido se abre en Vender con todo, para agregar o quitar productos y cobrarlo de nuevo. Conserva su número y su lugar en la cocina.', action: 'Corregir', cancel: 'No', danger: false })) $wire.correct({{ $order->id }})">
                                            <x-icon name="pencil" class="size-4" /> Corregir pedido
                                        </button>
                                        <button type="button" class="btn-quiet-danger"
                                                @click="if (await $store.confirm.ask({ title: @js('¿Cancelar la venta #'.$order->daily_number.'?'), message: 'Dejará de contar en ventas y en el corte. Si ya te pagaron, devuelve el dinero.', action: 'Cancelar venta', cancel: 'No' })) $wire.cancel({{ $order->id }})">
                                            <x-icon name="x" class="size-4" /> Cancelar venta
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @else
            <x-empty icon="receipt" :title="$isToday ? 'Todavía no hay ventas hoy' : 'Sin ventas este día'" data-tour="ventas-vacio">
                @if ($isToday)
                    Cada pedido que cobres aparecerá aquí, con su hora y forma de pago.
                    <x-slot:action><a href="{{ route('vender') }}" wire:navigate class="btn-primary">Ir a vender</a></x-slot:action>
                @else
                    No se registraron órdenes en esta fecha.
                @endif
            </x-empty>
        @endif
    </div>
    {{-- Cambiar forma de pago: el error más común al cobrar; no cancela la venta ni toca la cocina. --}}
    <x-sheet title="Forma de pago" open="paySheet">
        <form wire:submit="savePayment" id="payment-form" class="space-y-4">
            @if ($payOrder = $this->payOrder)
                <p class="text-sm text-muted">Venta #{{ $payOrder->daily_number }}{{ $payOrder->label ? ' '.$payOrder->label : '' }} por <span class="num text-base font-semibold text-ink">{{ money((float) $payOrder->total + (float) $payOrder->tip) }}</span>{{ (float) $payOrder->tip > 0 ? ', con propina' : '' }}.</p>
            @endif
            <div class="segmented" role="group" aria-label="Forma de pago">
                <button type="button" @click="$wire.payMethod = 'cash'" :aria-pressed="$wire.payMethod === 'cash'">Efectivo</button>
                <button type="button" @click="$wire.payMethod = 'transfer'" :aria-pressed="$wire.payMethod === 'transfer'">Transf.</button>
                <button type="button" @click="$wire.payMethod = 'card'" :aria-pressed="$wire.payMethod === 'card'">Tarjeta</button>
                <button type="button" @click="$wire.payMethod = 'mixed'" :aria-pressed="$wire.payMethod === 'mixed'">Mixto</button>
            </div>
            <div x-show="$wire.payMethod === 'mixed'" x-cloak class="grid grid-cols-2 gap-3">
                <div>
                    <label class="label" for="pay-cash-part">En efectivo</label>
                    <x-money-input id="pay-cash-part" wire:model="payCashPart" placeholder="0" />
                    @error('payCashPart') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <p class="label">El resto con</p>
                    <div class="segmented" role="group" aria-label="Resto con">
                        <button type="button" @click="$wire.payOtherMethod = 'transfer'" :aria-pressed="$wire.payOtherMethod === 'transfer'">Transf.</button>
                        <button type="button" @click="$wire.payOtherMethod = 'card'" :aria-pressed="$wire.payOtherMethod === 'card'">Tarjeta</button>
                    </div>
                </div>
            </div>
            <div x-show="$wire.payMethod !== 'cash'" x-cloak>
                <label class="label" for="pay-reference">Referencia <span class="font-normal text-muted">(opcional)</span></label>
                <input id="pay-reference" type="text" wire:model="payReference" maxlength="255" class="input" placeholder="Folio o últimos dígitos">
            </div>
            <p class="hint">El corte de hoy se ajusta solo: si cambia lo que entró en efectivo, revisa la caja.</p>
        </form>
        <x-slot:footer>
            <button type="submit" form="payment-form" wire:loading.attr="disabled" wire:target="savePayment" class="btn-primary btn-lg w-full">Guardar forma de pago</button>
        </x-slot:footer>
    </x-sheet>
</div>
