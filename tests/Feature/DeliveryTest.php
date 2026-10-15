<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->taco = Product::create(['name' => 'Taco', 'price' => 20]);
        Setting::put('delivery_fee', '25');
        Setting::put('packaging_fee', '5');
    }

    protected function delivery(): Testable
    {
        return Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->taco->id, 'qty' => 5]])
            ->set('label', 'Doña Mary')
            ->set('service', 'delivery');
    }

    public function test_delivery_requires_an_address(): void
    {
        $this->delivery()->call('saveOpen')->assertReturned(['ok' => false, 'error' => 'Escribe la dirección de entrega.', 'field' => 'address']);
        $this->delivery()->call('charge')->assertReturned(['ok' => false, 'error' => 'Escribe la dirección de entrega.', 'field' => 'address']);

        $this->assertSame(0, Order::count());
    }

    public function test_delivery_fee_is_added_after_the_discount(): void
    {
        $this->delivery()
            ->set('address', 'Calle 5 #123, Col. Centro')
            ->set('phone', '868 123 4567')
            ->set('discount', '10')->set('discountType', 'percent')
            ->call('charge')
            ->assertReturned(['ok' => true]);

        $order = Order::sole();
        // (100 + 5 empaque) - 10% = 94.50, + 25 de envío
        $this->assertSame('119.50', $order->total);
        $this->assertSame('25.00', $order->delivery_fee);
        $this->assertSame('5.00', $order->packaging_fee);
        $this->assertSame('Calle 5 #123, Col. Centro', $order->delivery_address);
        $this->assertSame('528681234567', $order->whatsappNumber());
    }

    public function test_delivery_fee_can_be_changed_per_order(): void
    {
        $this->delivery()->set('address', 'Lejos')->set('deliveryFee', '40')->call('saveOpen');

        $this->assertSame('40.00', Order::sole()->delivery_fee);
        $this->assertSame('145.00', Order::sole()->total);
    }

    public function test_delivery_flow_dispatch_then_deliver(): void
    {
        $this->delivery()->set('address', 'Calle 5')->call('charge');
        $order = Order::sole();

        Livewire::test('pages::pedidos')->assertSee('Calle 5')->assertSee('Enviar')->call('dispatchOrder', $order->id);
        $this->assertNotNull($order->fresh()->dispatched_at);
        $this->assertSame([$order->id], Order::pending()->pluck('id')->all());

        Livewire::test('pages::pedidos')->assertSee('En camino')->call('deliver', $order->id);
        $this->assertSame([], Order::pending()->pluck('id')->all());
    }

    public function test_dispatched_order_leaves_the_kitchen(): void
    {
        $this->delivery()->set('address', 'Calle 5')->set('label', 'Doña Mary')->call('charge');
        $order = Order::sole();

        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id);

        $this->assertNotNull($order->fresh()->ready_at);
        Livewire::test('pages::cocina')->assertSet('queue', fn ($queue) => $queue->isEmpty());
    }

    public function test_editing_an_open_delivery_order_keeps_its_address(): void
    {
        $this->delivery()->set('address', 'Calle 5')->set('addressNotes', 'Portón negro')->call('saveOpen');
        $order = Order::sole();

        Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')
            ->assertSet('service', 'delivery')
            ->assertSet('address', 'Calle 5')
            ->assertSet('addressNotes', 'Portón negro');
    }

    public function test_settings_save_the_delivery_fee(): void
    {
        Livewire::test('pages::ajustes')->set('delivery_fee', '30')->call('savePackaging')->assertHasNoErrors();

        Setting::flush();
        $this->assertSame('30', Setting::value('delivery_fee'));
    }
}
