<?php

use App\Models\Setting;
use App\Support\Store;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Tienda en línea')] class extends Component
{
    /** 'auto' sigue el horario; 'open' y 'closed' lo ignoran. */
    public string $mode = 'auto';

    public string $notice = '';

    public string $phone = '';

    public bool $online = false;

    public bool $delivery = false;

    public string $deliveryMessage = '';

    /** @var array<string, array{open: bool, from: string, to: string}> */
    public array $schedule = [];

    public bool $mpEnabled = false;

    public string $mpMode = 'test';

    public string $mpPublicKey = '';

    public string $mpAccessToken = '';

    public string $mpWebhookSecret = '';

    public bool $mpRequired = false;

    public function mount(): void
    {
        $this->mode = Store::mode();
        $this->notice = (string) Store::message();
        $this->phone = (string) Store::phone();
        $this->online = Store::onlineEnabled();
        $this->delivery = Store::deliveryEnabled();
        $this->deliveryMessage = (string) Setting::value('online_delivery_message');
        $this->schedule = Store::schedule();

        $mp = Store::mercadoPago();
        $this->mpEnabled = $mp['enabled'];
        $this->mpMode = $mp['mode'];
        $this->mpPublicKey = (string) $mp['public_key'];
        $this->mpRequired = $mp['required'];
    }

    /** Lo que ven los clientes ahora mismo. */
    #[Computed]
    public function status(): array
    {
        return Store::status();
    }

    #[Computed]
    public function hasToken(): bool
    {
        return Store::secret('mp_access_token') !== null;
    }

    #[Computed]
    public function hasWebhookSecret(): bool
    {
        return Store::secret('mp_webhook_secret') !== null;
    }

    public function setMode(string $mode): void
    {
        if (! in_array($mode, Store::MODES, true)) {
            return;
        }

        $this->mode = $mode;
        Setting::put('store_mode', $mode === 'auto' ? null : $mode);
        unset($this->status);
        $this->dispatch('toast', message: match ($mode) {
            'open' => 'La taquería quedó abierta, sin importar el horario.',
            'closed' => 'La taquería quedó cerrada, sin importar el horario.',
            default => 'La taquería sigue su horario.',
        });
    }

    public function saveMessage(): void
    {
        $this->notice = trim($this->notice);
        $this->validate(['notice' => ['nullable', 'string', 'max:'.Store::MESSAGE_MAX]], [], ['notice' => 'mensaje']);

        Setting::put('store_message', $this->notice);
        unset($this->status);
        $this->dispatch('toast', message: $this->notice === '' ? 'Se quitó el mensaje.' : 'Mensaje guardado.');
    }

    public function savePhone(): void
    {
        $digits = preg_replace('/\D+/', '', $this->phone);
        $digits = strlen($digits) === 12 && str_starts_with($digits, '52') ? substr($digits, 2) : $digits;

        $this->phone = $digits;
        $this->validate(['phone' => ['nullable', 'regex:/^$|^\d{10}$/']], ['phone.regex' => 'Escribe el teléfono a 10 dígitos.'], ['phone' => 'teléfono']);

        Setting::put('store_phone', $digits);
        $this->dispatch('toast', message: $digits === '' ? 'Se quitó el teléfono.' : 'Teléfono guardado.');
    }

    public function toggleOnline(): void
    {
        $this->online = ! $this->online;
        Setting::put('online_enabled', $this->online ? '1' : '0');
        $this->dispatch('toast', message: $this->online ? 'Pedidos en línea activados.' : 'Pedidos en línea desactivados.');
    }

    public function toggleDelivery(): void
    {
        $this->delivery = ! $this->delivery;
        Setting::put('online_delivery', $this->delivery ? '1' : '0');
        $this->dispatch('toast', message: $this->delivery ? 'Entregas a domicilio disponibles.' : 'Sin entregas a domicilio, solo para recoger.');
    }

    public function saveDeliveryMessage(): void
    {
        $this->deliveryMessage = trim($this->deliveryMessage);
        $this->validate(['deliveryMessage' => ['nullable', 'string', 'max:'.Store::MESSAGE_MAX]], [], ['deliveryMessage' => 'mensaje']);

        Setting::put('online_delivery_message', $this->deliveryMessage);
        $this->dispatch('toast', message: 'Mensaje guardado.');
    }

    public function saveSchedule(): void
    {
        $this->validate([
            'schedule' => ['array'],
            'schedule.*.open' => ['boolean'],
            'schedule.*.from' => ['required', 'date_format:H:i'],
            'schedule.*.to' => ['required', 'date_format:H:i'],
        ], ['schedule.*.from.required' => 'Escribe la hora de apertura.', 'schedule.*.to.required' => 'Escribe la hora de cierre.', 'schedule.*.from.date_format' => 'La hora de apertura no es válida.', 'schedule.*.to.date_format' => 'La hora de cierre no es válida.']);

        Store::saveSchedule($this->schedule);
        $this->schedule = Store::schedule();
        unset($this->status);
        $this->dispatch('toast', message: 'Horario guardado.');
    }

    public function saveMercadoPago(): void
    {
        $this->mpPublicKey = trim($this->mpPublicKey);
        $this->mpAccessToken = trim($this->mpAccessToken);
        $this->mpWebhookSecret = trim($this->mpWebhookSecret);

        $this->validate([
            'mpMode' => ['required', 'in:test,live'],
            'mpPublicKey' => ['nullable', 'string', 'max:255'],
            'mpAccessToken' => ['nullable', 'string', 'max:255'],
            'mpWebhookSecret' => ['nullable', 'string', 'max:255'],
        ], [], ['mpPublicKey' => 'llave pública', 'mpAccessToken' => 'token de acceso', 'mpWebhookSecret' => 'clave secreta del webhook']);

        // Lo que no se escribe se conserva: el token no se vuelve a mostrar.
        if ($this->mpAccessToken !== '') {
            Store::putSecret('mp_access_token', $this->mpAccessToken);
        }
        if ($this->mpWebhookSecret !== '') {
            Store::putSecret('mp_webhook_secret', $this->mpWebhookSecret);
        }

        $ready = $this->mpPublicKey !== '' && Store::secret('mp_access_token') !== null;

        if (($this->mpEnabled || $this->mpRequired) && ! $ready) {
            $this->addError('mpEnabled', 'Para activarlo hacen falta la llave pública y el token de acceso.');

            return;
        }

        Setting::put('mp_enabled', $this->mpEnabled ? '1' : '0');
        Setting::put('mp_required', $this->mpRequired && $this->mpEnabled ? '1' : '0');
        Setting::put('mp_mode', $this->mpMode);
        Setting::put('mp_public_key', $this->mpPublicKey);

        $this->mpAccessToken = '';
        $this->mpWebhookSecret = '';
        unset($this->hasToken, $this->hasWebhookSecret);
        $this->dispatch('toast', message: 'Mercado Pago guardado.');
    }

    public function removeMercadoPagoKeys(): void
    {
        foreach (['mp_access_token', 'mp_webhook_secret', 'mp_public_key', 'mp_enabled', 'mp_required'] as $key) {
            Setting::put($key, null);
        }

        $this->mpEnabled = false;
        $this->mpRequired = false;
        $this->mpPublicKey = '';
        unset($this->hasToken, $this->hasWebhookSecret);
        $this->dispatch('toast', message: 'Se quitaron las credenciales de Mercado Pago.');
    }
};
?>

<div>
    <x-page-header title="Tienda en línea" />

    <div class="mx-auto max-w-xl space-y-5">
        {{-- Cómo ven la taquería los clientes ahora --}}
        @php $status = $this->status; @endphp
        <section class="card space-y-4" data-tour="tienda-estado">
            <div class="flex items-center gap-3">
                <span @class(['size-3.5 shrink-0 rounded-full', 'bg-salsa' => $status['open'], 'bg-chile' => ! $status['open']])></span>
                <div class="min-w-0">
                    <p class="text-xl font-bold">{{ $status['open'] ? 'Abierta' : 'Cerrada' }}</p>
                    <p class="text-sm text-muted">
                        @if ($status['manual'])
                            Así la dejaste a mano, sin importar el horario.
                        @elseif ($status['open'])
                            Por horario{{ $status['closes_at'] ? ': cierra a las '.\App\Support\Store::label($status['closes_at']->format('H:i')) : '' }}.
                        @else
                            Por horario{{ $status['opens_at'] ? ': abre el '.mb_strtolower($status['opens_at']->translatedFormat('l')).' a las '.\App\Support\Store::label($status['opens_at']->format('H:i')) : '' }}.
                        @endif
                    </p>
                </div>
            </div>

            <div>
                <p class="label">La taquería está</p>
                <div class="segmented" role="group" aria-label="Estado de la taquería">
                    <button type="button" wire:click="setMode('auto')" aria-pressed="{{ $mode === 'auto' ? 'true' : 'false' }}">Según horario</button>
                    <button type="button" wire:click="setMode('open')" aria-pressed="{{ $mode === 'open' ? 'true' : 'false' }}">Abierta</button>
                    <button type="button" wire:click="setMode('closed')" aria-pressed="{{ $mode === 'closed' ? 'true' : 'false' }}">Cerrada</button>
                </div>
                <p class="hint mt-2">"Abierta" y "Cerrada" ignoran el horario hasta que vuelvas a "Según horario".</p>
            </div>

            <form wire:submit="saveMessage" class="space-y-2">
                <label class="label" for="store-message">Mensaje para los clientes (opcional)</label>
                <input id="store-message" type="text" wire:model="notice" maxlength="{{ Store::MESSAGE_MAX }}" class="input" placeholder="Ej. Cerrado por mal clima, volvemos el jueves">
                @error('notice') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Lo ven los clientes arriba del menú. Úsalo para avisos como mal clima o un evento especial.</p>
                <button type="submit" class="btn">Guardar mensaje</button>
            </form>
        </section>

        {{-- La página de los clientes --}}
        <section class="card space-y-3">
            <div>
                <h2 class="section-title">Tu página para clientes</h2>
                <p class="text-sm text-muted">Comparte este enlace en tus redes y en tu puesto.</p>
            </div>
            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <input type="text" readonly value="{{ url('/') }}" class="input flex-1" aria-label="Enlace de la página para clientes" @focus="$el.select()">
                <button type="button" class="btn" @click="navigator.clipboard?.writeText(@js(url('/'))).then(() => { copied = true; setTimeout(() => copied = false, 2000) })">
                    <x-icon name="copy" class="size-4" /> <span x-text="copied ? 'Copiado' : 'Copiar'">Copiar</span>
                </button>
            </div>
            <a href="{{ route('pedir') }}" target="_blank" rel="noopener" class="btn w-full">Ver como cliente</a>
        </section>

        {{-- Pedidos en línea --}}
        <section class="card space-y-4" data-tour="tienda-pedidos">
            <div>
                <h2 class="section-title">Pedidos en línea</h2>
                <p class="text-sm text-muted">Es independiente de abrir o cerrar: puedes estar abierto y atender solo en el puesto.</p>
            </div>

            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                <span>
                    <span class="block font-semibold">Recibir pedidos en línea</span>
                    <span class="block text-sm text-muted">{{ $online ? 'Los clientes pueden pedir mientras la taquería esté abierta.' : 'Los clientes ven el menú, pero no pueden pedir.' }}</span>
                </span>
                <input type="checkbox" class="switch" @checked($online) wire:click="toggleOnline" aria-label="Recibir pedidos en línea">
            </label>

            <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                <span>
                    <span class="block font-semibold">Entregas a domicilio</span>
                    <span class="block text-sm text-muted">{{ $delivery ? 'Los clientes pueden elegir a domicilio.' : 'Solo para recoger. A domicilio aparece bloqueado.' }}</span>
                </span>
                <input type="checkbox" class="switch" @checked($delivery) wire:click="toggleDelivery" aria-label="Entregas a domicilio">
            </label>

            <form wire:submit="saveDeliveryMessage" class="space-y-2">
                <label class="label" for="delivery-message">Mensaje cuando no hay domicilio</label>
                <input id="delivery-message" type="text" wire:model="deliveryMessage" maxlength="{{ Store::MESSAGE_MAX }}" class="input" placeholder="{{ Store::DELIVERY_OFF_MESSAGE }}">
                @error('deliveryMessage') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Si lo dejas vacío, se usa el mensaje de arriba.</p>
                <button type="submit" class="btn">Guardar mensaje</button>
            </form>

            <form wire:submit="savePhone" class="space-y-2">
                <label class="label" for="store-phone">Teléfono de la taquería (opcional)</label>
                <input id="store-phone" type="tel" inputmode="tel" wire:model="phone" maxlength="20" class="input" placeholder="10 dígitos">
                @error('phone') <p class="error">{{ $message }}</p> @enderror
                <p class="hint">Los clientes lo ven en la página para llamar o escribirte por WhatsApp.</p>
                <button type="submit" class="btn">Guardar teléfono</button>
            </form>

            <p class="hint">Todos los pedidos en línea llegan como "Por aceptar": tú decides si los aceptas o los rechazas. El empaque y el envío que cobras salen de Ajustes.</p>
        </section>

        {{-- Horario --}}
        <section class="card space-y-4" data-tour="tienda-horario">
            <div>
                <h2 class="section-title">Horario</h2>
                <p class="text-sm text-muted">Horario actual: {{ \App\Support\Store::summary() }}. Si cierras después de medianoche, escribe 12:00 am o una hora de la madrugada: cuenta como el día que abriste.</p>
            </div>

            <form wire:submit="saveSchedule" class="space-y-3">
                @foreach (\App\Support\Store::DAYS as $day => $label)
                    <div wire:key="day-{{ $day }}" class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-line px-3 py-2.5">
                        <label class="flex min-w-32 flex-1 cursor-pointer items-center gap-3 font-semibold">
                            <input type="checkbox" class="switch" wire:model.live="schedule.{{ $day }}.open" aria-label="Abre el {{ mb_strtolower($label) }}">
                            {{ $label }}
                        </label>
                        @if ($schedule[$day]['open'] ?? false)
                            <div class="flex items-center gap-2">
                                <input type="time" wire:model="schedule.{{ $day }}.from" class="input !min-h-11 w-36" aria-label="Abre el {{ mb_strtolower($label) }} a las">
                                <span class="text-muted">a</span>
                                <input type="time" wire:model="schedule.{{ $day }}.to" class="input !min-h-11 w-36" aria-label="Cierra el {{ mb_strtolower($label) }} a las">
                            </div>
                        @else
                            <span class="text-sm text-muted">Cerrado</span>
                        @endif
                    </div>
                    @error("schedule.$day.from") <p class="error">{{ $label }}: {{ $message }}</p> @enderror
                    @error("schedule.$day.to") <p class="error">{{ $label }}: {{ $message }}</p> @enderror
                @endforeach
                <button type="submit" class="btn-primary">Guardar horario</button>
            </form>
        </section>

        {{-- Mercado Pago --}}
        <section class="card space-y-4" data-tour="tienda-mercadopago">
            <div>
                <h2 class="section-title">Mercado Pago</h2>
                <p class="text-sm text-muted">Para cobrar los pedidos en línea con tarjeta y evitar pedidos falsos. Aquí se guardan las credenciales; la conexión se activa en una fase siguiente, así que por ahora no cobra nada.</p>
            </div>

            <form wire:submit="saveMercadoPago" class="space-y-4">
                <div>
                    <p class="label">Modo</p>
                    <div class="segmented" role="group" aria-label="Modo de Mercado Pago">
                        <button type="button" wire:click="$set('mpMode', 'test')" aria-pressed="{{ $mpMode === 'test' ? 'true' : 'false' }}">Pruebas</button>
                        <button type="button" wire:click="$set('mpMode', 'live')" aria-pressed="{{ $mpMode === 'live' ? 'true' : 'false' }}">Real</button>
                    </div>
                    <p class="hint mt-2">Usa Pruebas con las credenciales de prueba hasta confirmar que todo funciona.</p>
                </div>

                <div>
                    <label class="label" for="mp-public">Llave pública</label>
                    <input id="mp-public" type="text" wire:model="mpPublicKey" autocomplete="off" class="input" placeholder="APP_USR-... o TEST-...">
                    @error('mpPublicKey') <p class="error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="label" for="mp-token">Token de acceso</label>
                    <input id="mp-token" type="password" wire:model="mpAccessToken" autocomplete="new-password" class="input" placeholder="{{ $this->hasToken ? 'Guardado. Escribe uno nuevo para cambiarlo' : 'APP_USR-...' }}">
                    @error('mpAccessToken') <p class="error">{{ $message }}</p> @enderror
                    <p class="hint">Se guarda cifrado y no se vuelve a mostrar.</p>
                </div>

                <div>
                    <label class="label" for="mp-secret">Clave secreta del webhook</label>
                    <input id="mp-secret" type="password" wire:model="mpWebhookSecret" autocomplete="new-password" class="input" placeholder="{{ $this->hasWebhookSecret ? 'Guardada. Escribe una nueva para cambiarla' : 'Opcional por ahora' }}">
                    @error('mpWebhookSecret') <p class="error">{{ $message }}</p> @enderror
                    <p class="hint">En Mercado Pago, la dirección de notificaciones es <span class="font-semibold text-ink break-all">{{ url('/mercadopago/webhook') }}</span></p>
                </div>

                <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                    <span>
                        <span class="block font-semibold">Activar Mercado Pago</span>
                        <span class="block text-sm text-muted">Permite que los clientes paguen al hacer su pedido.</span>
                    </span>
                    <input type="checkbox" class="switch" wire:model.live="mpEnabled" aria-label="Activar Mercado Pago">
                </label>
                @error('mpEnabled') <p class="error">{{ $message }}</p> @enderror

                @if ($mpEnabled)
                    <label class="flex cursor-pointer items-center justify-between gap-4 rounded-xl border border-line px-4 py-3">
                        <span>
                            <span class="block font-semibold">Exigir pago para pedir</span>
                            <span class="block text-sm text-muted">Sin pagar no se manda el pedido. Si lo apagas, el cliente puede pedir sin pagar y tú lo aceptas a mano.</span>
                        </span>
                        <input type="checkbox" class="switch" wire:model="mpRequired" aria-label="Exigir pago para pedir">
                    </label>
                @endif

                <div class="flex flex-wrap gap-2">
                    <button type="submit" class="btn-primary">Guardar Mercado Pago</button>
                    @if ($this->hasToken || $mpPublicKey !== '')
                        <button type="button" class="btn-quiet text-chile"
                                @click="if (await $store.confirm.ask({ title: '¿Quitar las credenciales?', message: 'Se borran la llave, el token y la clave secreta, y Mercado Pago queda desactivado.', action: 'Quitar' })) $wire.removeMercadoPagoKeys()">Quitar credenciales</button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</div>
