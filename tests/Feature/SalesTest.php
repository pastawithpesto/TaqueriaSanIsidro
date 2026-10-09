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

    protected function line(Product $p, int $qty, array $extra = []): array
    {
        return array_merge(['product_id' => $p->id, 'qty' => $qty, 'without' => [], 'extras' => [], 'notes' => ''], $extra);
    }

    public function test_charge_creates_order_with_cost_snapshot(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);
        $torta = Product::create(['name' => 'Torta', 'price' => 39]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 2), $this->line($torta, 1)])
            ->set('method', 'transfer')
            ->set('reference', 'ABC123')
            ->call('charge')
            ->assertReturned(['ok' => true])
            ->assertSet('lines', [])
            ->assertSet('method', 'cash');

        $order = Order::with('items')->sole();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('79.00', $order->total);
        $this->assertSame('transfer', $order->payment_method->value);
        $this->assertSame('ABC123', $order->payment_reference);
        $this->assertSame(1, $order->daily_number);
        $this->assertNotNull($order->paid_at);

        $item = $order->items->firstWhere('product_name', 'Taco');
        $this->assertSame('10.0000', $item->unit_cost);

        // Cambiar el costo después no altera la venta ya registrada.
        $meat->update(['cost_per_unit' => 400]);
        $this->assertSame('10.0000', $item->fresh()->unit_cost);
    }

    public function test_server_uses_database_prices(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 3)])->call('charge');

        $this->assertSame('60.00', Order::sole()->total);
    }

    public function test_charge_with_empty_or_invalid_lines_does_nothing(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $hidden = Product::create(['name' => 'Viejo', 'price' => 10, 'active' => false]);

        Livewire::test('pages::vender')->call('charge')->assertReturned(['ok' => false, 'error' => 'El pedido está vacío.']);
        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 0), ['product_id' => 9999, 'qty' => 2], $this->line($hidden, 1)])
            ->call('charge');

        $this->assertSame(0, Order::count());
    }

    public function test_cash_shortfall_blocks_the_charge(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 1)])
            ->set('received', '10')
            ->call('charge')
            ->assertReturned(['ok' => false, 'error' => 'El monto recibido no alcanza para el total.']);

        $this->assertSame(0, Order::count());
    }

    public function test_undo_cancels_last_sale_and_restores_the_order(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 2, ['notes' => 'bien dorado'])])
            ->set('label', 'Camioneta negra')
            ->call('charge')
            ->assertSet('lines', [])
            ->call('undo')
            ->assertSet('label', 'Camioneta negra')
            ->assertSet('lines.0.qty', 2)
            ->assertSet('lines.0.notes', 'bien dorado')
            ->assertSet('lastOrderId', null);

        $this->assertSame(OrderStatus::Cancelled, Order::sole()->status);
    }

    public function test_undo_does_nothing_for_old_orders(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        $component = Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)])->call('charge');
        $this->travel(15)->minutes();
        $component->call('undo');

        $this->assertSame(OrderStatus::Paid, Order::sole()->status);
    }

    public function test_daily_number_restarts_each_day(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)])->call('charge');
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)])->call('charge');
        $this->travel(1)->days();
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)])->call('charge');

        $this->assertSame([1, 2, 1], Order::orderBy('id')->pluck('daily_number')->all());
    }

    public function test_cancelled_orders_are_excluded_from_daily_total(): void
    {
        $order = Order::create(['daily_number' => 1, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now()]);

        Livewire::test('pages::ventas')
            ->assertSee('$50')
            ->call('cancel', $order->id)
            ->assertSee('Cancelada');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }
}
