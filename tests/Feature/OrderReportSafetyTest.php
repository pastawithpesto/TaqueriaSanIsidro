<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class OrderReportSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(today()->setTime(12, 0));
    }

    protected function order(float $total, float $discount = 0, array $attributes = []): Order
    {
        return Order::create($attributes + ['status' => 'paid', 'payment_method' => 'cash', 'sold_at' => now(), 'paid_at' => now(), 'total' => $total, 'discount' => $discount]);
    }

    protected function item(Order $order, string $name, float $subtotal, float $cost = 0, ?array $modifiers = null): void
    {
        $order->items()->create(['product_name' => $name, 'quantity' => 1, 'unit_price' => $subtotal, 'unit_cost' => $cost, 'subtotal' => $subtotal, 'modifiers' => $modifiers]);
    }

    public function test_product_revenue_and_profit_include_the_order_discount(): void
    {
        $order = $this->order(75, 25);
        $this->item($order, 'Torta', 60, 20);
        $this->item($order, 'Taco', 40, 10);
        $form = Livewire::test('pages::resumen')->set('range', 'today');
        $products = $form->get('topProducts')->keyBy('product_name');
        $this->assertEquals(45, $products['Torta']->revenue);
        $this->assertEquals(30, $products['Taco']->revenue);
        $this->assertEquals($form->get('totals')['sales'], $products->sum('revenue'));
        $this->assertEquals($form->get('totals')['recipeProfit'], $products->sum('revenue') - $products->sum('cost'));
    }

    public function test_allocation_preserves_every_cent_and_includes_packaging_and_delivery(): void
    {
        $order = $this->order(2, 1);
        foreach (['A', 'B', 'C'] as $name) {
            $this->item($order, $name, 1);
        }
        $delivery = $this->order(28.01, 1.99, ['packaging_fee' => 5, 'delivery_fee' => 15]);
        $this->item($delivery, 'D', 10);
        $products = Livewire::test('pages::resumen')->set('range', 'today')->get('topProducts')->keyBy('product_name');
        $this->assertEqualsWithDelta(2, $products->only(['A', 'B', 'C'])->sum('revenue'), 0.00001);
        $this->assertEquals([0.67, 0.66, 0.67], $products->only(['A', 'B', 'C'])->pluck('revenue')->all());
        $this->assertEquals(28.01, $products['D']->revenue);
        $this->assertEqualsWithDelta(30.01, $products->sum('revenue'), 0.00001);
    }

    public function test_courtesies_and_full_discounts_have_no_phantom_product_revenue(): void
    {
        $order = $this->order(5, 5);
        $this->item($order, 'Cortesía', 0, 2, [['type' => 'courtesy', 'value' => 10]]);
        $this->item($order, 'Con descuento', 10, 3);
        $free = $this->order(0, 10);
        $this->item($free, 'Gratis', 10, 1);
        $products = Livewire::test('pages::resumen')->set('range', 'today')->get('topProducts')->keyBy('product_name');
        $this->assertEquals(0, $products['Cortesía']->revenue);
        $this->assertEquals(5, $products['Con descuento']->revenue);
        $this->assertEquals(0, $products['Gratis']->revenue);
        $this->assertEquals(6, $products->sum('cost'));
    }

    public function test_hourly_report_groups_by_payment_time_and_falls_back_to_sale_time(): void
    {
        $this->order(100, attributes: ['sold_at' => now()->subDay()->setTime(8, 0), 'paid_at' => now()->setTime(14, 0)]);
        $this->order(50, attributes: ['sold_at' => now()->setTime(9, 0), 'paid_at' => null]);
        $hours = Livewire::test('pages::resumen')->set('range', 'today')->get('hours')->keyBy('hour');
        $this->assertEquals(100, $hours[14]['sales']);
        $this->assertEquals(50, $hours[9]['sales']);
        $this->assertFalse($hours->has(8));
        $this->assertEquals(150, $hours->sum('sales'));
    }

    public function test_hourly_report_uses_payment_time_across_the_business_day_boundary(): void
    {
        Setting::put('day_ends_at', '3');
        $this->travelTo(today()->setTime(1, 0));
        $this->order(100, attributes: ['sold_at' => now()->subDay()->setTime(20, 0), 'paid_at' => now()]);
        $hours = Livewire::test('pages::resumen')->set('range', 'today')->get('hours');
        $this->assertSame([['hour' => 1, 'sales' => 100.0]], $hours->values()->all());
    }
}
