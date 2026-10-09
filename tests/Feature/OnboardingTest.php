<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_sees_the_tutorial_open(): void
    {
        $this->actingAs(User::factory()->create(['onboarded_at' => null]));

        $this->get('/')->assertOk()->assertSee('aria-label="Tutorial"', false)->assertSee('open: true', false);
    }

    public function test_tutorial_starts_closed_once_seen(): void
    {
        $this->actingAs(User::factory()->create(['onboarded_at' => now()]));

        $this->get('/')->assertOk()->assertSee('open: false', false);
    }

    public function test_finishing_the_tutorial_marks_the_user(): void
    {
        $user = User::factory()->create(['onboarded_at' => null]);

        $this->actingAs($user)->post('/tutorial/visto')->assertNoContent();

        $this->assertNotNull($user->fresh()->onboarded_at);
    }

    public function test_settings_offers_to_replay_the_tutorial(): void
    {
        $this->actingAs(User::factory()->create(['onboarded_at' => now()]));

        $this->get('/ajustes')->assertOk()->assertSee('Ver tutorial')->assertSee('open-tutorial', false);
    }

    public function test_guests_cannot_mark_the_tutorial(): void
    {
        $this->post('/tutorial/visto')->assertRedirect('/login');
    }
}
