<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::guest')] #[Title('Entrar')] class extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        $key = Str::lower($this->email).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectIntended(route('vender'));
    }
};
?>

<form wire:submit="login" class="card space-y-4 !p-5">
    <div>
        <label class="label" for="email">Correo</label>
        <input id="email" type="email" wire:model="email" class="input" autocomplete="username" autofocus>
        @error('email') <p class="error">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label" for="password">Contraseña</label>
        <input id="password" type="password" wire:model="password" class="input" autocomplete="current-password">
        @error('password') <p class="error">{{ $message }}</p> @enderror
    </div>
    <label class="flex items-center gap-3 text-sm font-medium text-muted">
        <input type="checkbox" wire:model="remember" class="switch"> Mantener sesión en este equipo
    </label>
    <button wire:loading.attr="disabled" class="btn-primary btn-lg w-full">Entrar</button>
</form>
