<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Tours;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_page_offers_its_tour_and_tells_which_were_seen(): void
    {
        $this->actingAs(User::factory()->create(['seen_tours' => ['vender']]));

        $this->get(route('ventas'))
            ->assertOk()
            ->assertSee('data-tour-start="ventas"', false)
            ->assertSee('data-tours-seen="[&quot;vender&quot;]"', false);
    }

    public function test_kitchen_screen_offers_its_tour(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('cocina'))->assertOk()->assertSee('data-tour-start="cocina"', false);
    }

    public function test_seen_tour_is_saved_once(): void
    {
        $user = User::factory()->create(['seen_tours' => ['vender']]);

        $this->actingAs($user)->postJson(route('tutorial.done'), ['tour' => 'gastos'])->assertNoContent();
        $this->actingAs($user)->postJson(route('tutorial.done'), ['tour' => 'gastos'])->assertNoContent();

        $this->assertSame(['vender', 'gastos'], $user->fresh()->seen_tours);
    }

    public function test_skipping_all_tours_marks_every_screen_as_seen(): void
    {
        $user = User::factory()->create(['seen_tours' => ['vender']]);

        $this->actingAs($user)->postJson(route('tutorial.done'), ['tour' => 'todas'])->assertNoContent();

        $this->assertSame(Tours::KEYS, $user->fresh()->seen_tours);
    }

    public function test_unknown_tour_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('tutorial.done'), ['tour' => 'otra'])->assertUnprocessable();

        $this->assertNull($user->fresh()->seen_tours);
    }

    public function test_guests_cannot_mark_a_tour(): void
    {
        $this->post(route('tutorial.done'), ['tour' => 'vender'])->assertRedirect(route('login'));
    }

    public function test_settings_can_show_every_tour_again(): void
    {
        $user = User::factory()->create(['seen_tours' => ['vender', 'gastos']]);
        $this->actingAs($user);

        Livewire::test('pages::ajustes')->call('resetTours')->assertDispatched('tours-reset');

        $this->assertSame([], $user->fresh()->seen_tours);
    }
}
