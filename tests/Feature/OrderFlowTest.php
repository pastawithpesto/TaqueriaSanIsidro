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

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    protected Product $torta;

    protected Ingredient $onion;

    protected Ingredient $bread;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        $this->bread = Ingredient::create(['name' => 'Pan', 'unit' => 'pza', 'cost_per_unit' => 5]);
        $this->onion = Ingredient::create(['name' => 'Cebolla', 'unit' => 'kg', 'cost_per_unit' => 40]);
        $cheese = Ingredient::create(['name' => 'Queso', 'unit' => 'kg', 'cost_per_unit' => 200]);

        $this->torta = Product::create(['name' => 'Torta', 'price' => 39]);
        $this->torta->ingredients()->attach($this->bread, ['quantity' => 1, 'removable' => false]);
        $this->torta->ingredients()->attach($this->onion, ['quantity' => 0.05, 'removable' => true]);
        $this->torta->extras()->create(['name' => 'Extra queso', 'price' => 10, 'ingredient_id' => $cheese->id, 'quantity' => 0.03]);
    }

    public function test_without_removes_ingredient_cost_and_extra_adds_price_and_cost(): void
    {
        $extra = $this->torta->extras()->sole();

        Livewire::test('pages::vender')
            ->set('lines', [
                ['product_id' => $this->torta->id, 'qty' => 2, 'without' => [$this->onion->id], 'extras' => [], 'notes' => ''],
                ['product_id' => $this->torta->id, 'qty' => 1, 'without' => [], 'extras' => [$extra->id], 'notes' => 'bien dorada'],
            ])
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $order = Order::with('items')->sole();
        $this->assertSame('127.00', $order->total); // 2 x 39 + 1 x (39 + 10)

        [$plain, $withExtra] = $order->items->sortBy('id')->values()->all();
        $this->assertSame('5.0000', $plain->unit_cost);           // pan, sin cebolla
        $this->assertSame(['Cebolla'], $plain->withoutNames());
        $this->assertSame('49.00', $withExtra->unit_price);
        $this->assertSame('13.0000', $withExtra->unit_cost);      // pan 5 + cebolla 2 + queso 6
        $this->assertSame('bien dorada', $withExtra->notes);
        $this->assertSame('Extra queso', $withExtra->extras()[0]['name']);
    }

    public function test_only_removable_ingredients_can_be_left_out(): void
    {
        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1, 'without' => [$this->bread->id], 'extras' => [], 'notes' => '']])
            ->call('charge');

        $item = Order::with('items')->sole()->items->sole();
        $this->assertSame([], $item->withoutNames());
        $this->assertSame('7.0000', $item->unit_cost);
    }

    public function test_open_order_requires_a_name(): void
    {
        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->call('saveOpen')
            ->assertReturned(fn ($r) => $r['ok'] === false);

        $this->assertSame(0, Order::count());
    }

    public function test_open_order_is_saved_edited_and_charged_later(): void
    {
        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->set('label', 'Camioneta negra')
            ->call('saveOpen')
            ->assertReturned(['ok' => true]);

        $order = Order::sole();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNull($order->payment_method);
        $this->assertSame('Camioneta negra', $order->label);

        // Se abre desde Pedidos, se agrega otra torta y se cobra.
        Livewire::withQueryParams(['orden' => $order->id, 'cobrar' => 1])
            ->test('pages::vender')
            ->assertSet('openPay', true)
            ->assertSet('label', 'Camioneta negra')
            ->assertSet('lines.0.qty', 1)
            ->set('lines.0.qty', 2)
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $order->refresh();
        $this->assertSame(1, Order::count());
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('78.00', $order->total);
        $this->assertSame(2, $order->items()->sole()->quantity);
    }

    public function test_open_orders_do_not_count_as_sales(): void
    {
        Order::create(['daily_number' => 1, 'status' => OrderStatus::Open, 'label' => 'Juan', 'total' => 80, 'sold_at' => now()]);
        Order::create(['daily_number' => 2, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 40, 'sold_at' => now()]);

        $totals = Livewire::test('pages::resumen')->set('range', 'today')->get('totals');
        $this->assertEquals(40, $totals['sales']);

        Livewire::test('pages::corte')->assertSet('cashSales', 40.0)->assertSee('1 pedido sin cobrar');
    }

    public function test_pending_queue_and_delivery(): void
    {
        $paid = Order::create(['daily_number' => 1, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'label' => 'Carro rojo', 'total' => 39, 'sold_at' => now()]);
        $open = Order::create(['daily_number' => 2, 'status' => OrderStatus::Open, 'label' => 'Juan', 'total' => 39, 'sold_at' => now()]);
        Order::create(['daily_number' => 3, 'status' => OrderStatus::Cancelled, 'label' => 'No va', 'total' => 39, 'sold_at' => now()]);

        Livewire::test('pages::pedidos')
            ->assertSee('Carro rojo')
            ->assertSee('Juan')
            ->assertDontSee('No va')
            ->call('deliver', $paid->id)
            ->call('deliver', $open->id);

        // El pagado sale de la cola; el que falta cobrar se queda aunque ya se entregó.
        $this->assertSame([$open->id], Order::pending()->pluck('id')->all());

        Livewire::test('pages::pedidos')->call('undeliver', $paid->id);
        $this->assertNull($paid->fresh()->delivered_at);
    }

    public function test_open_order_can_be_cancelled_from_the_queue(): void
    {
        $open = Order::create(['daily_number' => 1, 'status' => OrderStatus::Open, 'label' => 'Juan', 'total' => 39, 'sold_at' => now()]);

        Livewire::test('pages::pedidos')->call('cancel', $open->id);

        $this->assertSame(OrderStatus::Cancelled, $open->fresh()->status);
    }

    public function test_product_extras_and_optional_ingredients_are_saved(): void
    {
        $cheese = Ingredient::where('name', 'Queso')->sole();
        $existing = $this->torta->extras()->sole();

        Livewire::test('pages::productos')
            ->call('edit', $this->torta->id)
            ->assertSet('recipe.1.removable', true)
            ->set('extras', [
                ['id' => $existing->id, 'name' => 'Extra queso', 'price' => '12', 'ingredient_id' => $cheese->id, 'quantity' => '0.03'],
                ['id' => null, 'name' => 'Aguacate', 'price' => '15', 'ingredient_id' => null, 'quantity' => null],
                ['id' => null, 'name' => '', 'price' => '', 'ingredient_id' => null, 'quantity' => null],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $extras = $this->torta->extras()->get();
        $this->assertSame(['Extra queso', 'Aguacate'], $extras->pluck('name')->all());
        $this->assertSame($existing->id, $extras[0]->id);
        $this->assertSame('12.00', $extras[0]->price);
    }
}
