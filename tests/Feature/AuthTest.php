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
        foreach (['/', '/vender', '/ventas', '/gastos', '/productos', '/insumos'] as $path) {
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

        foreach (['/', '/vender', '/ventas', '/gastos', '/productos', '/insumos'] as $path) {
            $this->get($path)->assertOk();
        }
    }
}
