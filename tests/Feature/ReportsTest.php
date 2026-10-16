<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Consumption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected Product $taco;

    protected Ingredient $meat;

    protected Ingredient $onion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $this->onion = Ingredient::create(['name' => 'Cebolla', 'unit' => 'kg', 'cost_per_unit' => 40]);
        $this->taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $this->taco->ingredients()->attach($this->meat, ['quantity' => 0.05, 'removable' => false]);
        $this->taco->ingredients()->attach($this->onion, ['quantity' => 0.01, 'removable' => true]);
        $this->taco->extras()->create(['name' => 'Extra carne', 'price' => 10, 'ingredient_id' => $this->meat->id, 'quantity' => 0.03]);
    }

    protected function sale(CarbonImmutable $at, int $qty, array $modifiers = [], int $total = 0): void
    {
        $order = Order::create(['daily_number' => 1, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => $total ?: $qty * 20, 'sold_at' => $at, 'paid_at' => $at]);
        $order->items()->create(['product_id' => $this->taco->id, 'product_name' => 'Taco', 'modifiers' => $modifiers ?: null, 'quantity' => $qty, 'unit_price' => 20, 'subtotal' => $qty * 20]);
    }

    public function test_consumption_respects_without_and_extras(): void
    {
        $day = CarbonImmutable::today()->setTime(20, 0);
        $extra = $this->taco->extras()->sole();

        $this->sale($day, 10);
        $this->sale($day, 4, [['type' => 'without', 'id' => $this->onion->id, 'name' => 'Cebolla']]);
        $this->sale($day, 2, [['type' => 'extra', 'id' => $extra->id, 'name' => 'Extra carne', 'price' => 10]]);

        $usage = Consumption::byDay($day->startOfDay(), $day->endOfDay())->first();

        $this->assertEqualsWithDelta(0.86, $usage[$this->meat->id], 0.0001);  // 16 x 0.05 + 2 x 0.03
        $this->assertEqualsWithDelta(0.12, $usage[$this->onion->id], 0.0001); // 12 x 0.01 (4 sin cebolla)
    }

    public function test_forecast_uses_the_same_weekday(): void
    {
        $target = CarbonImmutable::parse('next friday');
        $this->travelTo($target->subDay()->setTime(12, 0));

        $this->sale($target->subWeek()->setTime(20, 0), 20);      // viernes: 1 kg
        $this->sale($target->subWeeks(2)->setTime(20, 0), 40);    // viernes: 2 kg
        $this->sale($target->subDays(3)->setTime(20, 0), 100);    // martes: no cuenta

        $forecast = Consumption::forecast($target);

        $this->assertSame(2, $forecast['days']);
        $this->assertEqualsWithDelta(1.5, $forecast['usage'][$this->meat->id], 0.0001);
        $this->assertStringContainsString('viernes', $forecast['basis']);
    }

    public function test_shopping_list_rounds_up_and_adds_buffer(): void
    {
        $target = CarbonImmutable::parse('next friday');
        $this->travelTo($target->subDay()->setTime(12, 0));
        $this->sale($target->subWeek()->setTime(20, 0), 23); // 1.15 kg bistec, 0.23 kg cebolla

        $rows = Livewire::test('pages::compras')
            ->set('date', $target->toDateString())
            ->set('buffer', false)
            ->get('rows')->keyBy('name');

        $this->assertEquals(1.2, $rows['Bistec']['qty']);
        $this->assertEquals(0.3, $rows['Cebolla']['qty']);

        $withBuffer = Livewire::test('pages::compras')->set('date', $target->toDateString())->get('rows')->keyBy('name');
        $this->assertEquals(1.3, $withBuffer['Bistec']['qty']); // 1.265 -> 1.3
    }

    public function test_shopping_list_buys_by_the_pack_what_recipes_use_by_the_piece(): void
    {
        $target = CarbonImmutable::parse('next friday');
        $this->travelTo($target->subDay()->setTime(12, 0));
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'paquete', 'cost_per_unit' => 45, 'use_unit' => 'pza', 'use_per_unit' => 8]);
        $this->taco->ingredients()->attach($bread, ['quantity' => 1, 'removable' => false]);
        $this->sale($target->subWeek()->setTime(20, 0), 20); // 20 pza = 2.5 paquetes

        $rows = Livewire::test('pages::compras')
            ->set('date', $target->toDateString())
            ->set('buffer', false)
            ->get('rows')->keyBy('name');

        $this->assertEquals(3, $rows['Pan']['qty']);
        $this->assertSame('paquetes', $rows['Pan']['unit']);
        $this->assertEquals(135, $rows['Pan']['cost']);
    }

    public function test_shopping_list_without_history_shows_empty_state(): void
    {
        Livewire::test('pages::compras')->assertSee('Todavía no hay suficientes ventas');
    }

    public function test_weekday_report_averages_days_with_sales(): void
    {
        $this->travelTo(CarbonImmutable::parse('next monday')->setTime(12, 0));
        $saturday = CarbonImmutable::today()->subDays(2)->setTime(20, 0);

        $this->sale($saturday, 10);               // $200
        $this->sale($saturday->subWeek(), 20);    // $400

        $week = Livewire::test('pages::resumen')->get('weekdays');

        $this->assertSame('Sábado', $week[6]['label']);
        $this->assertEquals(300, $week[6]['avg']);
        $this->assertSame(2, $week[6]['days']);
        $this->assertSame('Taco', $week[6]['top']);
        $this->assertSame(0, $week[2]['days']);
    }
}
