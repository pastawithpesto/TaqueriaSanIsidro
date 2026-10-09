<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
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

    public function test_sold_out_lasts_only_for_today(): void
    {
        Livewire::test('pages::vender')->call('toggleSoldOut', $this->torta->id);
        $this->assertTrue($this->torta->fresh()->isSoldOut());

        $this->travel(1)->days();
        $this->assertFalse($this->torta->fresh()->isSoldOut());
    }

    public function test_to_go_orders_add_the_packaging_fee(): void
    {
        Setting::put('packaging_fee', '5');

        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 2]])
            ->set('service', 'togo')
            ->call('charge');

        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->set('service', 'here')
            ->call('charge');

        [$togo, $here] = Order::orderBy('id')->get()->all();
        $this->assertSame('5.00', $togo->packaging_fee);
        $this->assertSame('83.00', $togo->total);
        $this->assertSame('0.00', $here->packaging_fee);
        $this->assertSame('39.00', $here->total);
        $this->assertSame('here', $here->service);
    }

    public function test_pickup_orders_are_sorted_by_pickup_time(): void
    {
        $this->travelTo(today()->setTime(13, 0));

        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->set('label', 'Recoge tarde')
            ->set('pickupTime', '15:30')
            ->call('saveOpen');

        $this->travel(5)->minutes();
        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->set('label', 'Ahorita')
            ->call('saveOpen');

        $this->assertSame('15:30', Order::where('label', 'Recoge tarde')->sole()->pickup_at->format('H:i'));

        $queue = Livewire::test('pages::pedidos')->get('pending');
        $this->assertSame(['Ahorita', 'Recoge tarde'], $queue->pluck('label')->all());
    }

    public function test_kitchen_marks_orders_ready(): void
    {
        $order = Order::create(['daily_number' => 1, 'status' => 'paid', 'payment_method' => 'cash', 'label' => 'Carro rojo', 'total' => 39, 'sold_at' => now()]);

        Livewire::test('pages::cocina')
            ->assertSee('Carro rojo')
            ->call('markReady', $order->id)
            ->assertSet('queue', fn ($q) => $q->isEmpty());

        $this->assertNotNull($order->fresh()->ready_at);
        $this->assertSame([$order->id], Order::pending()->pluck('id')->all());

        Livewire::test('pages::cocina')->call('unready', $order->id);
        $this->assertNull($order->fresh()->ready_at);
    }

    public function test_packaging_fee_setting_is_saved(): void
    {
        Livewire::test('pages::ajustes')->set('packaging_fee', '4.5')->call('savePackaging')->assertHasNoErrors();

        Setting::flush();
        $this->assertSame('4.5', Setting::value('packaging_fee'));
    }

    public function test_pickup_time_that_already_passed_means_tomorrow(): void
    {
        $this->travelTo(today()->setTime(23, 0));

        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->torta->id, 'qty' => 1]])
            ->set('label', 'Trasnochado')
            ->set('pickupTime', '00:30')
            ->call('saveOpen');

        $this->assertTrue(Order::sole()->pickup_at->isTomorrow());
    }

    protected function comboWithDrinks(): array
    {
        $combo = Product::create(['name' => 'Combo', 'price' => 100]);
        $group = $combo->optionGroups()->create(['name' => 'Refresco', 'required' => true]);
        $coca = $group->choices()->create(['name' => 'Coca-Cola', 'price' => 0]);
        $horchata = $group->choices()->create(['name' => 'Horchata', 'price' => 5]);

        return [$combo, $coca, $horchata];
    }

    public function test_required_choice_must_be_picked(): void
    {
        [$combo] = $this->comboWithDrinks();

        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $combo->id, 'qty' => 1, 'choices' => []]])
            ->call('charge')
            ->assertReturned(['ok' => false, 'error' => 'Falta elegir: Refresco en Combo.']);

        $this->assertSame(0, Order::count());
    }

    public function test_choice_is_saved_and_its_price_added(): void
    {
        [$combo, $coca, $horchata] = $this->comboWithDrinks();

        Livewire::test('pages::vender')
            ->set('lines', [
                ['product_id' => $combo->id, 'qty' => 2, 'choices' => [$coca->id]],
                ['product_id' => $combo->id, 'qty' => 1, 'choices' => [$horchata->id, $coca->id]],
            ])
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $items = Order::with('items')->sole()->items->sortBy('id')->values();
        $this->assertSame('Coca-Cola', $items[0]->choices()[0]['name']);
        $this->assertSame('105.00', $items[1]->unit_price); // una opción por grupo: la primera que se eligió
        $this->assertSame('305.00', Order::sole()->total);
    }

    public function test_product_options_are_saved_keeping_ids(): void
    {
        [$combo, $coca] = $this->comboWithDrinks();
        $group = $combo->optionGroups()->sole();

        Livewire::test('pages::productos')
            ->call('edit', $combo->id)
            ->assertSet('options.0.name', 'Refresco')
            ->set('options', [
                ['id' => $group->id, 'name' => 'Refresco', 'required' => true, 'choices' => [
                    ['id' => $coca->id, 'name' => 'Coca-Cola', 'price' => '0'],
                    ['id' => null, 'name' => 'Sprite', 'price' => ''],
                    ['id' => null, 'name' => '', 'price' => ''],
                ]],
                ['id' => null, 'name' => 'Salsa', 'required' => false, 'choices' => [['id' => null, 'name' => 'Verde', 'price' => '0']]],
                ['id' => null, 'name' => 'Vacío', 'required' => true, 'choices' => []],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $groups = $combo->optionGroups()->with('choices')->get();
        $this->assertSame(['Refresco', 'Salsa'], $groups->pluck('name')->all());
        $this->assertSame(['Coca-Cola', 'Sprite'], $groups[0]->choices->pluck('name')->all());
        $this->assertSame($coca->id, $groups[0]->choices[0]->id);
        $this->assertFalse($groups[1]->required);
    }
}
