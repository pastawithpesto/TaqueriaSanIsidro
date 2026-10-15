<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BusinessDayTest extends TestCase
{
    use RefreshDatabase;

    protected Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Setting::put('day_ends_at', '3');
    }

    protected function sellAt(string $moment): void
    {
        $this->travelTo($moment);
        Livewire::test('pages::vender')->set('lines', [['product_id' => $this->taco->id, 'qty' => 1]])->call('charge');
    }

    public function test_sales_after_midnight_keep_the_numbering_of_the_night(): void
    {
        $this->sellAt('2026-10-10 23:00');
        $this->sellAt('2026-10-11 01:30');
        $this->sellAt('2026-10-11 03:30');

        $this->assertSame([1, 2, 1], Order::orderBy('id')->pluck('daily_number')->all());
    }

    public function test_sales_after_midnight_count_in_the_day_the_shift_started(): void
    {
        $this->sellAt('2026-10-10 23:00');
        $this->sellAt('2026-10-11 01:30');

        Livewire::test('pages::ventas')
            ->assertSet('date', '2026-10-10')
            ->assertSet('dayLabel', 'Hoy')
            ->assertSet('paid', fn ($orders) => $orders->count() === 2);
        Livewire::test('pages::corte')->assertSet('cashSales', 40.0);
        $this->assertEquals(40, Livewire::test('pages::resumen')->set('range', 'today')->get('totals')['sales']);
    }

    public function test_peak_hours_run_across_midnight(): void
    {
        $this->sellAt('2026-10-10 23:00');
        $this->sellAt('2026-10-11 01:30');

        $hours = Livewire::test('pages::resumen')->set('range', 'today')->get('hours');

        $this->assertSame([23, 0, 1], $hours->pluck('hour')->all());
    }

    public function test_sold_out_lasts_until_the_day_ends(): void
    {
        $this->travelTo('2026-10-10 22:00');
        Livewire::test('pages::vender')->call('toggleSoldOut', $this->taco->id);

        $this->travelTo('2026-10-11 02:00');
        $this->assertTrue($this->taco->fresh()->isSoldOut());

        $this->travelTo('2026-10-11 04:00');
        $this->assertFalse($this->taco->fresh()->isSoldOut());
    }

    public function test_settings_save_when_the_day_ends(): void
    {
        Livewire::test('pages::ajustes')->set('day_ends_at', 4)->call('saveDayEnd')->assertHasNoErrors();
        $this->assertSame('4', Setting::value('day_ends_at'));

        Livewire::test('pages::ajustes')->set('day_ends_at', 9)->call('saveDayEnd')->assertHasErrors('day_ends_at');
        $this->assertSame('4', Setting::value('day_ends_at'));
    }
}
