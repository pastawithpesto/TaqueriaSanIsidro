<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OnlineOrderUpdated;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OnlineOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class OnlineStoreTest extends TestCase
{
    use RefreshDatabase;

    private Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taco = Product::create(['name' => 'Taco de bistec', 'price' => 20, 'category' => 'Tacos']);
        // Jueves 8:30 pm: abierto.
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));
    }

    private function form(array $overrides = []): array
    {
        return array_merge(['token' => (string) Str::uuid(), 'name' => 'Juan', 'phone' => '8681234567', 'service' => 'togo', 'address' => '', 'references' => '', 'notes' => ''], $overrides);
    }

    private function lines(): array
    {
        return [['product_id' => $this->taco->id, 'qty' => 2, 'without' => [], 'extras' => [], 'choices' => [], 'notes' => '']];
    }

    private function order(array $attributes = []): Order
    {
        Setting::put('online_enabled', '1');

        return OnlineOrder::place(array_merge(['token' => (string) Str::uuid(), 'name' => 'Juan', 'phone' => '8681234567', 'service' => 'togo', 'lines' => $this->lines()], $attributes), '127.0.0.1');
    }

    public function test_the_root_is_the_public_menu_and_needs_no_login(): void
    {
        Product::create(['name' => 'Torta oculta', 'price' => 39, 'active' => false]);

        $this->get('/')->assertOk()->assertSee('Taco de bistec')->assertSee('Tacos')->assertDontSee('Torta oculta')->assertSee('Jueves a domingo, de 8:00 pm a 12:00 am');
    }

    public function test_the_menu_says_when_ordering_is_off_and_hides_the_order_button(): void
    {
        $this->get('/')->assertSee('Por ahora no estamos recibiendo pedidos en línea')->assertDontSee('Ver mi pedido');

        Setting::put('online_enabled', '1');
        $this->get('/')->assertDontSee('Por ahora no estamos recibiendo pedidos en línea')->assertSee('Abierto hasta las 12:00 am');
    }

    public function test_when_closed_it_shows_the_special_message_and_when_it_opens_again(): void
    {
        Setting::put('online_enabled', '1');
        Setting::put('store_mode', 'closed');
        Setting::put('store_message', 'Cerrado por mal clima');

        $this->get('/')->assertSee('Cerrado')->assertSee('Cerrado por mal clima')->assertSee('No estamos recibiendo pedidos en este momento');
    }

    public function test_a_sold_out_product_is_marked_and_cannot_be_added(): void
    {
        $this->taco->update(['sold_out_on' => today()]);

        $page = Livewire::test('pages::pedir');

        $this->assertTrue(collect($page->get('catalog'))->firstWhere('id', $this->taco->id)['soldOut']);
    }

    public function test_placing_an_order_redirects_to_its_tracking_page(): void
    {
        Setting::put('online_enabled', '1');

        $page = Livewire::test('pages::pedir')->call('place', $this->lines(), $this->form());

        $order = Order::sole();
        $page->assertRedirect(route('pedido', $order->public_token));
        $this->assertSame(OrderStatus::Requested, $order->status);
    }

    public function test_errors_come_back_per_field_without_creating_the_order(): void
    {
        Setting::put('online_enabled', '1');

        $result = Livewire::test('pages::pedir')->call('place', $this->lines(), $this->form(['phone' => '123', 'name' => '']))->effects['returns'][0];

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('phone', $result['errors']);
        $this->assertArrayHasKey('name', $result['errors']);
        $this->assertSame(0, Order::count());
    }

    public function test_ordering_is_blocked_with_the_reason_when_online_orders_are_off(): void
    {
        $result = Livewire::test('pages::pedir')->call('place', $this->lines(), $this->form())->effects['returns'][0];

        $this->assertSame('Por ahora no estamos recibiendo pedidos en línea.', $result['errors']['store']);
        $this->assertSame(0, Order::count());
    }

    public function test_tracking_page_needs_the_secret_link_and_only_shows_online_orders(): void
    {
        $this->get(route('pedido', (string) Str::uuid()))->assertNotFound();
        $this->get('/pedido/no-es-un-uuid')->assertNotFound();

        $pos = Order::create(['status' => OrderStatus::Open, 'public_token' => (string) Str::uuid(), 'daily_number' => 1, 'sold_at' => now(), 'total' => 10]);
        $this->get(route('pedido', $pos->public_token))->assertNotFound();
    }

    public function test_tracking_follows_the_order_from_waiting_to_delivered(): void
    {
        $order = $this->order();
        $url = route('pedido', $order->public_token);

        $this->get($url)->assertOk()->assertSee('Recibimos tu pedido')->assertSee('2')->assertSee('Taco de bistec')->assertSee('$40')->assertSee('Cancelar mi pedido');

        OnlineOrder::accept($order);
        $this->get($url)->assertSee('Tu pedido fue aceptado')->assertSee('Lo estamos preparando')->assertDontSee('Cancelar mi pedido');

        $order->refresh()->update(['ready_at' => now()]);
        $this->get($url)->assertSee('Tu pedido está listo')->assertSee('Pasa a recogerlo');

        $order->refresh()->update(['delivered_at' => now(), 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'paid_at' => now()]);
        $this->get($url)->assertSee('Tu pedido fue entregado');
    }

    public function test_tracking_shows_the_delivery_steps_and_address(): void
    {
        Setting::put('online_delivery', '1');
        $order = $this->order(['service' => 'delivery', 'address' => 'Calle Hidalgo 45', 'references' => 'Casa azul']);
        OnlineOrder::accept($order);
        $order->refresh()->update(['ready_at' => now(), 'dispatched_at' => now()]);

        $this->get(route('pedido', $order->public_token))->assertSee('Tu pedido va en camino')->assertSee('Calle Hidalgo 45')->assertSee('Casa azul')->assertSee('Pagas cuando te lo entregamos');
    }

    public function test_the_customer_sees_the_reason_when_the_taquero_rejects(): void
    {
        $order = $this->order();
        OnlineOrder::reject($order, 'Se acabó algún producto');

        $this->get(route('pedido', $order->public_token))->assertSee('No pudimos aceptar tu pedido')->assertSee('Se acabó algún producto');
    }

    public function test_the_customer_can_cancel_while_waiting_but_not_after_it_is_accepted(): void
    {
        $order = $this->order();
        Livewire::test('pages::pedido', ['token' => $order->public_token])->call('cancel');
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->get(route('pedido', $order->public_token))->assertSee('Cancelaste tu pedido');

        $accepted = $this->order(['phone' => '8689999999']);
        OnlineOrder::accept($accepted);
        Livewire::test('pages::pedido', ['token' => $accepted->public_token])->call('cancel');
        $this->assertSame(OrderStatus::Open, $accepted->fresh()->status);
    }

    public function test_the_tracking_token_cannot_be_changed_from_the_browser(): void
    {
        $order = $this->order();
        $other = $this->order(['phone' => '8689999999']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::pedido', ['token' => $order->public_token])->set('token', $other->public_token);
    }

    public function test_the_taquero_sees_requests_first_and_accepts_one(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->order();

        Livewire::test('pages::pedidos')->assertSee('Pedido en línea por aceptar')->assertSee('Juan')->assertSee('8681234567')
            ->call('accept', $order->id)
            ->assertDontSee('Pedido en línea por aceptar');

        $order->refresh();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertSame(1, $order->daily_number);
        Livewire::test('pages::pedidos')->assertSee('En línea')->assertSee('Llamar');
    }

    public function test_the_taquero_rejects_with_a_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->order();

        Livewire::test('pages::pedidos')->call('reject', $order->id, 'No llegamos a esa zona');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame('No llegamos a esa zona', $order->fresh()->cancel_reason);
    }

    public function test_accepting_twice_or_a_request_the_customer_cancelled_shows_an_error(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        OnlineOrder::cancelByCustomer($order);

        Livewire::test('pages::pedidos')->call('accept', $order->id)->assertDispatched('toast');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_the_pending_counter_includes_requests(): void
    {
        $this->order();

        $this->assertSame(1, Order::pendingCount());
    }

    public function test_requests_do_not_show_in_sales_or_the_kitchen(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->order();

        Livewire::test('pages::ventas')->assertDontSee('Juan');
        Livewire::test('pages::cocina')->assertDontSee('Juan');

        OnlineOrder::accept($order);
        Livewire::test('pages::cocina')->assertSee('Juan');
    }

    public function test_a_filled_honeypot_field_creates_nothing(): void
    {
        Setting::put('online_enabled', '1');

        $result = Livewire::test('pages::pedir')->call('place', $this->lines(), $this->form(['website' => 'http://spam.example']))->effects['returns'][0];

        $this->assertFalse($result['ok']);
        $this->assertSame(0, Order::count());
    }

    public function test_every_change_the_customer_can_see_notifies_their_page(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->order();
        Event::fake([OnlineOrderUpdated::class]);

        OnlineOrder::accept($order);
        Livewire::test('pages::cocina')->call('markReady', $order->id);
        $order->refresh()->update(['service' => 'delivery']);
        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id)->call('deliver', $order->id);

        Event::assertDispatchedTimes(OnlineOrderUpdated::class, 4);
    }

    public function test_a_logged_in_taquero_opening_the_login_goes_to_the_system(): void
    {
        $this->actingAs(User::factory()->create())->get(route('login'))->assertRedirect(route('vender'));
    }
}
