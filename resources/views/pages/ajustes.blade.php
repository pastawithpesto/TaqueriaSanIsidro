<?php

use App\Models\Setting;
use App\Support\Branding;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Ajustes')] class extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $short_name = '';

    public $logo = null;

    public string $current_password = '';

    public string $new_password = '';

    public string $new_password_confirmation = '';

    public ?string $packaging_fee = '0';

    public ?string $delivery_fee = '0';

    public function mount(): void
    {
        $this->name = Branding::name();
        $this->short_name = Branding::shortName();
        $this->packaging_fee = Setting::value('packaging_fee', '0');
        $this->delivery_fee = Setting::value('delivery_fee', '0');
    }

    public function savePackaging(): void
    {
        $this->validate([
            'packaging_fee' => ['required', 'numeric', 'min:0', 'max:1000'],
            'delivery_fee' => ['required', 'numeric', 'min:0', 'max:1000'],
        ], attributes: ['packaging_fee' => 'cobro por empaque', 'delivery_fee' => 'envío a domicilio']);
        Setting::put('packaging_fee', (float) $this->packaging_fee > 0 ? (string) round((float) $this->packaging_fee, 2) : null);
        Setting::put('delivery_fee', (float) $this->delivery_fee > 0 ? (string) round((float) $this->delivery_fee, 2) : null);
        $this->dispatch('toast', message: 'Cobros guardados');
    }

    public function saveBusiness(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'short_name' => ['required', 'string', 'max:14'],
        ], attributes: ['name' => 'nombre', 'short_name' => 'nombre corto']);

        // Si coincide con el valor de fábrica no se guarda nada y se sigue usando el predeterminado.
        Setting::put('business_name', $data['name'] === Branding::DEFAULT_NAME ? null : $data['name']);
        Setting::put('business_short_name', $data['short_name'] === Branding::DEFAULT_SHORT_NAME ? null : $data['short_name']);

        $this->dispatch('toast', message: 'Nombre guardado');
    }

    public function updatedLogo(): void
    {
        $this->validate([
            'logo' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ], attributes: ['logo' => 'logo']);
    }

    public function saveLogo(): void
    {
        $this->validate([
            'logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:10240'],
        ], attributes: ['logo' => 'logo']);

        Branding::store($this->logo);
        $this->reset('logo');
        $this->dispatch('toast', message: 'Logo actualizado');
    }

    public function cancelLogo(): void
    {
        $this->reset('logo');
        $this->resetErrorBag('logo');
    }

    public function resetLogo(): void
    {
        Branding::reset();
        $this->reset('logo');
        $this->dispatch('toast', message: 'Se restauró el logo original');
    }

    public function savePassword(): void
    {
        $this->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'],
        ], attributes: ['current_password' => 'contraseña actual', 'new_password' => 'contraseña nueva']);

        Auth::user()->update(['password' => $this->new_password]);
        $this->reset('current_password', 'new_password', 'new_password_confirmation');
        $this->dispatch('toast', message: 'Contraseña actualizada');
    }
};
?>

<div>
    <x-page-header title="Ajustes" />
    <div class="mx-auto max-w-xl space-y-5">
    {{-- Logo --}}
    <section class="card space-y-4">
        <div>
            <h2 class="section-title">Logo</h2>
            <p class="text-sm text-muted">Aparece en el login, el menú y el ícono de la app en el celular.</p>
        </div>

        <div class="flex items-center gap-4">
            <div class="relative">
                @if ($logo && ! $errors->has('logo'))
                    <img src="{{ $logo->temporaryUrl() }}" alt="Vista previa del logo nuevo" class="size-28 rounded-full object-cover ring-2 ring-chile">
                @else
                    <x-logo class="size-28" :res="192" />
                @endif
            </div>
            <div class="min-w-0 flex-1 space-y-2">
                @if ($logo && ! $errors->has('logo'))
                    <p class="text-sm font-semibold">Vista previa</p>
                    <p class="text-sm text-muted">La imagen se recorta a un cuadrado centrado.</p>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" wire:click="saveLogo" wire:loading.attr="disabled" class="btn-primary">Usar este logo</button>
                        <button type="button" wire:click="cancelLogo" class="btn">Cancelar</button>
                    </div>
                @else
                    <label class="btn cursor-pointer">
                        <x-icon name="image" class="size-4" /> Elegir imagen
                        <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="sr-only">
                    </label>
                    <p wire:loading wire:target="logo" class="text-sm text-muted">Subiendo...</p>
                    <p class="text-sm text-muted">PNG, JPG o WebP, hasta 10 MB. Mejor si es cuadrada.</p>
                @endif
                @error('logo') <p class="error">{{ $message }}</p> @enderror
            </div>
        </div>

        @if (Branding::hasCustomLogo() && ! $logo)
            <button type="button" class="btn-quiet -ml-2.5" @click="if (await $store.confirm.ask({ title: '¿Volver al logo original?', message: 'Se borrará el logo que subiste.', action: 'Restaurar' })) $wire.resetLogo()">Volver al logo original</button>
        @endif
    </section>

    {{-- Nombre --}}
    <form wire:submit="saveBusiness" class="card space-y-4">
        <h2 class="section-title">Negocio</h2>
        <div>
            <label class="label" for="name">Nombre</label>
            <input id="name" type="text" wire:model="name" class="input" maxlength="60">
            @error('name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="label" for="short_name">Nombre corto</label>
            <input id="short_name" type="text" wire:model="short_name" class="input" maxlength="14">
            <p class="hint">Es el que aparece debajo del ícono en la pantalla de inicio del celular.</p>
            @error('short_name') <p class="error">{{ $message }}</p> @enderror
        </div>
        <button class="btn-primary" wire:loading.attr="disabled">Guardar nombre</button>
    </form>

    {{-- Para llevar --}}
    <form wire:submit="savePackaging" class="card space-y-4">
        <div>
            <h2 class="section-title">Para llevar y a domicilio</h2>
            <p class="text-sm text-muted">El empaque se suma una vez a cada pedido para llevar o a domicilio. El envío es el costo normal; se puede cambiar en cada pedido. Deja 0 si no cobras.</p>
        </div>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <label class="label" for="packaging_fee">Empaque</label>
                <x-money-input id="packaging_fee" wire:model="packaging_fee" />
            </div>
            <div>
                <label class="label" for="delivery_fee">Envío a domicilio</label>
                <x-money-input id="delivery_fee" wire:model="delivery_fee" />
            </div>
        </div>
        @error('packaging_fee') <p class="error">{{ $message }}</p> @enderror
        @error('delivery_fee') <p class="error">{{ $message }}</p> @enderror
        <button class="btn-primary" wire:loading.attr="disabled">Guardar</button>
    </form>

    {{-- Contraseña --}}
    <form wire:submit="savePassword" class="card space-y-4">
        <h2 class="section-title">Contraseña</h2>
        <p class="-mt-2 text-sm text-muted">Sesión iniciada como {{ auth()->user()->email }}.</p>
        <input type="text" autocomplete="username" value="{{ auth()->user()->email }}" class="sr-only" tabindex="-1" aria-hidden="true" readonly>
        <div>
            <label class="label" for="current_password">Contraseña actual</label>
            <input id="current_password" type="password" wire:model="current_password" class="input" autocomplete="current-password">
            @error('current_password') <p class="error">{{ $message }}</p> @enderror
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="label" for="new_password">Contraseña nueva</label>
                <input id="new_password" type="password" wire:model="new_password" class="input" autocomplete="new-password">
                @error('new_password') <p class="error">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="label" for="new_password_confirmation">Repítela</label>
                <input id="new_password_confirmation" type="password" wire:model="new_password_confirmation" class="input" autocomplete="new-password">
            </div>
        </div>
        <button class="btn-primary" wire:loading.attr="disabled">Cambiar contraseña</button>
    </form>

    {{-- Tutorial --}}
    <section class="card flex items-center gap-4">
        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-limon text-on-limon"><x-icon name="info" class="size-6" /></span>
        <div class="min-w-0 flex-1">
            <h2 class="section-title">Tutorial</h2>
            <p class="text-sm text-muted">Repasa cómo vender, cobrar y llevar tus gastos.</p>
        </div>
        <button type="button" class="btn" @click="$dispatch('open-tutorial')">Ver tutorial</button>
    </section>

    {{-- Instalación --}}
    <section class="card space-y-1">
        <h2 class="section-title">Instalar en el celular</h2>
        <p class="text-sm text-muted">En Android con Chrome: menú, "Instalar app". En iPhone con Safari: Compartir, "Agregar a pantalla de inicio". La app se abre sin barra del navegador y con el logo del negocio.</p>
    </section>
</div>
</div>
