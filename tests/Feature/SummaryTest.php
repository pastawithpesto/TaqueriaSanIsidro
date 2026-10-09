<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_totals_exclude_cancelled_orders_and_include_expenses(): void
    {
        $this->actingAs(User::factory()->create());

        $paid = Order::create(['status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 100, 'sold_at' => now()]);
        $paid->items()->create(['product_name' => 'Taco', 'quantity' => 5, 'unit_price' => 20, 'unit_cost' => 8, 'subtotal' => 100]);

        $cancelled = Order::create(['status' => OrderStatus::Cancelled, 'payment_method' => 'cash', 'total' => 999, 'sold_at' => now()]);
        $cancelled->items()->create(['product_name' => 'Taco', 'quantity' => 1, 'unit_price' => 999, 'unit_cost' => 0, 'subtotal' => 999]);

        Expense::create(['category' => 'gas', 'total' => 30, 'spent_on' => today()]);

        $component = Livewire::test('pages::resumen')->set('range', 'today');
        $totals = $component->get('totals');

        $this->assertEquals(100, $totals['sales']);
        $this->assertEquals(1, $totals['orders']);
        $this->assertEquals(40, $totals['recipeCost']);
        $this->assertEquals(30, $totals['expenses']);
        $this->assertEquals(60, $totals['recipeProfit']);
        $this->assertEquals(70, $totals['cash']);

        $this->assertSame(5, (int) $component->get('topProducts')->first()->qty);
    }

    public function test_delivery_fees_count_as_sales_and_are_shown_apart(): void
    {
        $this->actingAs(User::factory()->create());
        Order::create(['status' => OrderStatus::Paid, 'payment_method' => 'cash', 'service' => 'delivery', 'total' => 125, 'delivery_fee' => 25, 'sold_at' => now(), 'paid_at' => now()]);

        $component = Livewire::test('pages::resumen')->set('range', 'today');

        $this->assertEquals(125, $component->get('totals')['sales']);
        $this->assertEquals(25, $component->get('totals')['delivery']);
        $component->assertSee('incluye $25 de envíos a domicilio');
    }
}
