<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_charge_creates_order_with_cost_snapshot(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);
        $torta = Product::create(['name' => 'Torta', 'price' => 39]);

        Livewire::test('pages::vender')
            ->call('add', $taco->id)
            ->call('add', $taco->id)
            ->call('add', $torta->id)
            ->assertSet('cart', [$taco->id => 2, $torta->id => 1])
            ->set('method', 'transfer')
            ->set('reference', 'ABC123')
            ->call('charge')
            ->assertSet('cart', []);

        $order = Order::with('items')->sole();
        $this->assertSame('79.00', $order->total);
        $this->assertSame('transfer', $order->payment_method->value);
        $this->assertSame('ABC123', $order->payment_reference);
        $this->assertCount(2, $order->items);

        $item = $order->items->firstWhere('product_name', 'Taco');
        $this->assertSame(2, $item->quantity);
        $this->assertSame('10.0000', $item->unit_cost);

        // Cambiar el costo después no altera la venta ya registrada.
        $meat->update(['cost_per_unit' => 400]);
        $this->assertSame('10.0000', $item->fresh()->unit_cost);
    }

    public function test_remove_decrements_and_drops_empty_lines(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->call('add', $taco->id)
            ->call('add', $taco->id)
            ->call('remove', $taco->id)
            ->assertSet('cart', [$taco->id => 1])
            ->call('remove', $taco->id)
            ->assertSet('cart', []);
    }

    public function test_charge_with_empty_cart_does_nothing(): void
    {
        Livewire::test('pages::vender')->call('charge');

        $this->assertSame(0, Order::count());
    }

    public function test_inactive_products_cannot_be_added(): void
    {
        $p = Product::create(['name' => 'Viejo', 'price' => 10, 'active' => false]);

        Livewire::test('pages::vender')->call('add', $p->id)->assertSet('cart', []);
    }

    public function test_cash_change_is_computed(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->call('add', $taco->id)
            ->call('add', $taco->id)
            ->call('openPay')
            ->set('received', '100')
            ->assertSee('Cambio')
            ->assertSet('change', 60.0);
    }

    public function test_cancelled_orders_are_excluded_from_daily_total(): void
    {
        $order = Order::create(['status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now()]);

        Livewire::test('pages::ventas')
            ->assertSee('$50.00')
            ->call('cancel', $order->id)
            ->assertSee('Cancelada');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_cash_shortfall_blocks_the_charge(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->call('add', $taco->id)
            ->call('openPay')
            ->set('received', '10')
            ->call('charge')
            ->assertHasErrors('received');

        $this->assertSame(0, Order::count());
    }

    public function test_undo_cancels_last_sale_and_restores_cart(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->call('add', $taco->id)
            ->call('add', $taco->id)
            ->call('charge')
            ->assertSet('cart', [])
            ->call('undo')
            ->assertSet('cart', [$taco->id => 2])
            ->assertSet('lastOrderId', null);

        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
    }

    public function test_undo_does_nothing_for_old_orders(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        $component = Livewire::test('pages::vender')->call('add', $taco->id)->call('charge');
        $this->travel(15)->minutes();
        $component->call('undo');

        $this->assertSame(OrderStatus::Paid, Order::sole()->status);
    }
}
