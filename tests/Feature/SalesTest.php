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

    public function test_sale_costs_ingredients_by_their_use_unit_and_follows_new_purchases(): void
    {
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'paquete', 'cost_per_unit' => 48, 'use_unit' => 'pza', 'use_per_unit' => 8]);
        $meat = Ingredient::create(['name' => 'Carne', 'unit' => 'kg', 'cost_per_unit' => 150, 'use_unit' => 'g', 'use_per_unit' => 1000]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $burger->ingredients()->attach($bread, ['quantity' => 1]);
        $burger->ingredients()->attach($meat, ['quantity' => 120]);
        $double = $burger->extras()->create(['name' => 'Doble carne', 'price' => 20, 'ingredient_id' => $meat->id, 'quantity' => 120]);

        Livewire::test('pages::gastos')
            ->set('category', 'ingredients')
            ->set('ingredient_id', $bread->id)
            ->set('quantity', '2')
            ->set('total', '80')
            ->call('save')
            ->assertHasNoErrors();

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($burger, 1, ['extras' => [$double->id]])])
            ->call('charge')
            ->assertReturned(['ok' => true]);

        // Pan: $80 / 2 paquetes / 8 pza = $5. Carne: 240 g a $0.15 = $36.
        $this->assertSame('40.0000', $bread->fresh()->cost_per_unit);
        $this->assertSame('41.0000', Order::with('items')->sole()->items->sole()->unit_cost);
    }

    public function test_a_misentered_recipe_does_not_block_the_sale(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 220]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 99999]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 1)])
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $this->assertSame('999999.9999', Order::with('items')->sole()->items->sole()->unit_cost);
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
            ->assertReturned(['ok' => false, 'error' => 'El monto recibido no alcanza para el total.', 'field' => 'received']);

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

    public function test_undo_after_two_minutes_points_to_correcting_from_pedidos(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $component = Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)])->call('charge');
        $this->travel(3)->minutes();

        $component->call('undo')->assertDispatched('toast', message: 'Ya pasaron más de 2 minutos. Corrígela desde Pedidos.', type: 'error');

        $this->assertSame(OrderStatus::Paid, Order::sole()->status);
    }

    public function test_sale_charged_again_unchanged_after_undo_keeps_its_place_in_the_kitchen(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $component = Livewire::test('pages::vender')->set('lines', [$this->line($taco, 2)])->call('charge');
        $first = Order::sole();
        Livewire::test('pages::cocina')->call('markReady', $first->id);
        $this->travel(1)->minutes();

        $component->call('undo')->set('method', 'transfer')->call('charge');

        $again = Order::where('status', OrderStatus::Paid)->sole();
        $this->assertSame($first->sold_at->toDateTimeString(), $again->sold_at->toDateTimeString());
        $this->assertNotNull($again->ready_at);
        Livewire::test('pages::cocina')->assertSet('queue', fn ($queue) => $queue->isEmpty());
    }

    public function test_charged_order_is_corrected_from_ventas_and_charged_again(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 2)])->set('label', 'Juan')->call('charge');
        $first = Order::sole();
        $first->update(['delivered_at' => now()]);

        Livewire::test('pages::ventas')->call('correct', $first->id)->assertRedirect(route('vender'));
        Livewire::test('pages::vender')
            ->assertSet('label', 'Juan')
            ->assertSet('correcting', true)
            ->assertSet('openPay', true)
            ->set('lines.0.qty', 3)
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $this->assertSame(OrderStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(Order::CORRECTION, $first->fresh()->cancel_reason);
        $again = Order::where('status', OrderStatus::Paid)->sole();
        $this->assertSame(1, $again->daily_number);
        $this->assertSame('60.00', $again->total);
    }

    public function test_payment_method_is_changed_without_cancelling_the_sale(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 5)])->call('charge');
        $order = Order::sole();

        Livewire::test('pages::ventas')
            ->call('editPayment', $order->id)
            ->assertSet('payMethod', 'cash')
            ->set('payMethod', 'transfer')
            ->set('payReference', 'F-9')
            ->call('savePayment')
            ->assertHasNoErrors();

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('transfer', $order->payment_method->value);
        $this->assertSame(['transfer' => '100.00'], $order->payments->pluck('amount', 'method.value')->all());
        Livewire::test('pages::corte')->assertSet('cashSales', 0.0)->assertSet('otherSales', 100.0);
    }

    public function test_mixed_payment_change_needs_a_cash_part_below_the_total(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 5)])->call('charge');
        $order = Order::sole();

        Livewire::test('pages::ventas')
            ->call('editPayment', $order->id)
            ->set('payMethod', 'mixed')
            ->set('payCashPart', '100')
            ->call('savePayment')
            ->assertHasErrors(['payCashPart' => 'En pago mixto el efectivo tiene que ser menos que el total.']);

        $this->assertSame('cash', $order->fresh()->payment_method->value);
    }

    public function test_sale_from_another_day_cannot_be_changed(): void
    {
        $this->travelTo(today()->setTime(12, 0));
        $order = Order::create(['daily_number' => 1, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now()->subDay(), 'paid_at' => now()->subDay()]);

        Livewire::test('pages::ventas')
            ->set('date', today()->subDay()->toDateString())
            ->assertSee('Es de otro día')
            ->assertDontSee('Cancelar venta')
            ->call('cancel', $order->id)
            ->call('correct', $order->id)
            ->assertNoRedirect();

        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
    }

    public function test_cancelled_sale_records_when_it_was_cancelled(): void
    {
        $this->freezeTime();
        $order = Order::create(['daily_number' => 1, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now(), 'paid_at' => now()]);

        Livewire::test('pages::ventas')->call('cancel', $order->id);

        $this->assertSame(now()->toDateTimeString(), $order->fresh()->cancelled_at->toDateTimeString());
        $this->assertNull($order->fresh()->cancel_reason);
    }

    public function test_sale_changed_after_undo_goes_back_to_the_kitchen(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $component = Livewire::test('pages::vender')->set('lines', [$this->line($taco, 2)])->call('charge');
        Livewire::test('pages::cocina')->call('markReady', Order::sole()->id);

        $component->call('undo')->set('lines.0.qty', 3)->call('charge');

        $this->assertNull(Order::where('status', OrderStatus::Paid)->sole()->ready_at);
    }

    public function test_cash_sale_keeps_the_change_to_give(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 3)])
            ->set('received', '100')
            ->call('charge')
            ->assertSet('lastChange', 40.0)
            ->assertSee('Cambio');
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

    public function test_undo_brings_back_delivery_discount_payment_and_number(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 3)])
            ->set('service', 'delivery')
            ->set('address', 'Calle 5 #12')
            ->set('phone', '8681234567')
            ->set('deliveryFee', '25')
            ->set('discount', '10')
            ->set('tip', '5')
            ->set('method', 'transfer')
            ->set('reference', 'F-1')
            ->call('charge')
            ->call('undo')
            ->assertSet('service', 'delivery')
            ->assertSet('address', 'Calle 5 #12')
            ->assertSet('phone', '8681234567')
            ->assertSet('deliveryFee', '25')
            ->assertSet('discount', '10')
            ->assertSet('tip', '5')
            ->assertSet('method', 'transfer')
            ->assertSet('reference', 'F-1')
            ->assertDispatched('order-restored', later: false, discount: true)
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $this->assertSame([1, 1], Order::orderBy('id')->pluck('daily_number')->all());
        $this->assertSame([OrderStatus::Cancelled, OrderStatus::Paid], Order::orderBy('id')->pluck('status')->all());
    }

    public function test_sale_corrected_with_undo_cannot_be_restored(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 2)])->call('charge')->call('undo')->call('charge');
        $undone = Order::where('status', OrderStatus::Cancelled)->sole();

        Livewire::test('pages::ventas')
            ->assertSee('Corregida')
            ->assertDontSee('Restaurar venta')
            ->call('restore', $undone->id)
            ->assertDispatched('toast', type: 'error');

        $this->assertSame(OrderStatus::Cancelled, $undone->fresh()->status);
        $this->assertSame(1, Order::where('status', OrderStatus::Paid)->count());
    }

    public function test_sale_undone_and_not_charged_again_can_be_restored(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Livewire::test('pages::vender')->set('lines', [$this->line($taco, 2)])->call('charge')->call('undo');
        $undone = Order::sole();

        Livewire::test('pages::ventas')->assertSee('Restaurar venta')->call('restore', $undone->id);

        $this->assertSame(OrderStatus::Paid, $undone->fresh()->status);
    }

    public function test_product_hidden_while_in_the_cart_blocks_the_charge(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $component = Livewire::test('pages::vender')->set('lines', [$this->line($taco, 1)]);
        $taco->update(['active' => false]);

        $component->call('charge')->assertReturned(['ok' => false, 'error' => 'Ya no se vende: Taco. Quítalo del pedido.']);

        $this->assertSame(0, Order::count());
    }

    public function test_amount_above_the_limit_is_rejected(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 1)])
            ->set('tip', '500000')
            ->call('charge')
            ->assertReturned(['ok' => false, 'error' => 'La propina es demasiado alta (máximo $100,000).', 'field' => 'tip']);

        $this->assertSame(0, Order::count());
    }

    public function test_long_texts_are_cut_to_fit(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::vender')
            ->set('lines', [$this->line($taco, 1)])
            ->set('label', str_repeat('a', 80))
            ->set('method', 'transfer')
            ->set('reference', str_repeat('9', 300))
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $order = Order::sole();
        $this->assertSame(60, mb_strlen($order->label));
        $this->assertSame(255, mb_strlen($order->payment_reference));
    }

    public function test_order_saved_yesterday_and_charged_today_counts_today(): void
    {
        $this->travelTo(today()->setTime(12, 0));
        Order::create(['daily_number' => 7, 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'total' => 80, 'sold_at' => now()->subDay(), 'paid_at' => now()]);

        Livewire::test('pages::ventas')->assertSee('$80');
        Livewire::test('pages::ventas')->set('date', today()->subDay()->toDateString())->assertDontSee('$80');
        $this->assertEquals(80, Livewire::test('pages::resumen')->set('range', 'today')->get('totals')['sales']);
    }

    public function test_fresh_catalog_reflects_price_changes(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $component = Livewire::test('pages::vender');
        $taco->update(['price' => 22]);

        $component->call('freshCatalog')->assertReturned(fn ($fresh) => (float) $fresh['catalog'][$taco->id]['price'] === 22.0);
    }
}
