<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        foreach (['/', '/resumen', '/ventas', '/gastos', '/productos', '/insumos', '/corte', '/pedidos/pendientes'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_user_can_log_in(): void
    {
        User::factory()->create(['email' => 'a@b.test', 'password' => 'secret123']);

        Livewire::test('pages::login')
            ->set('email', 'a@b.test')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('vender'));

        $this->assertAuthenticated();
    }

    public function test_email_is_matched_ignoring_case_and_spaces(): void
    {
        User::factory()->create(['email' => 'a@b.test', 'password' => 'secret123']);

        Livewire::test('pages::login')
            ->set('email', ' A@B.test ')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('vender'));

        $this->assertAuthenticated();
    }

    public function test_wrong_password_is_rejected(): void
    {
        User::factory()->create(['email' => 'a@b.test', 'password' => 'secret123']);

        Livewire::test('pages::login')
            ->set('email', 'a@b.test')
            ->set('password', 'nope')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    public function test_pages_render_for_authenticated_user(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['/', '/resumen', '/ventas', '/gastos', '/productos', '/insumos', '/corte'] as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_login_is_blocked_for_five_minutes_after_five_failed_attempts(): void
    {
        User::factory()->create(['email' => 'a@b.test', 'password' => 'secret123']);

        foreach (range(1, 5) as $attempt) {
            Livewire::test('pages::login')->set('email', 'a@b.test')->set('password', 'nope')->call('login');
        }

        Livewire::test('pages::login')
            ->set('email', 'a@b.test')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors(['email' => 'Demasiados intentos. Intenta de nuevo en 5 min.']);

        $this->assertGuest();
    }
}
