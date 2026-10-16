<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Events\OnlineOrderPlaced;
use App\Events\OnlineOrderUpdated;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OnlineOrder;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function configureChannelAuth(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);
        Broadcast::purge('reverb');

        // phpunit arranca con el broadcaster null; registra los canales en el driver que firma la autorización.
        require base_path('routes/channels.php');
    }

    private function placeOrder(): Order
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));
        Setting::put('online_enabled', '1');
        $product = Product::create(['name' => 'Taco de bistec', 'price' => 20]);

        return OnlineOrder::place([
            'token' => (string) Str::uuid(),
            'name' => 'Juan Pérez',
            'phone' => '8681234567',
            'service' => 'togo',
            'lines' => [['product_id' => $product->id, 'qty' => 2]],
        ], '127.0.0.1');
    }

    public function test_guests_cannot_authorize_admin_orders_from_the_public_site(): void
    {
        $this->configureChannelAuth();

        $this->withHeader('Origin', config('app.url'))->postJson('/broadcasting/auth', [
            'channel_name' => 'private-admin-orders',
            'socket_id' => '123.456',
        ])->assertUnauthorized();

        $this->post('/broadcasting/auth', [
            'channel_name' => 'private-admin-orders',
            'socket_id' => '123.456',
        ])->assertRedirect(route('login'));
    }

    public function test_a_web_session_can_authorize_admin_orders_at_the_root_endpoint(): void
    {
        $this->configureChannelAuth();
        $user = User::factory()->create();

        $route = app('router')->getRoutes()->match(Request::create('/broadcasting/auth', 'POST'));
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('auth', $route->gatherMiddleware());

        $this->actingAs($user, 'web')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-admin-orders',
            'socket_id' => '123.456',
        ])->assertOk()->assertExactJson([
            'auth' => 'test-key:'.hash_hmac('sha256', '123.456:private-admin-orders', 'test-secret'),
        ]);

        $this->postJson('/'.config('app.admin_path').'/broadcasting/auth', [
            'channel_name' => 'private-admin-orders',
            'socket_id' => '123.456',
        ])->assertNotFound();
    }

    public function test_an_authenticated_user_cannot_authorize_an_unregistered_private_channel(): void
    {
        $this->configureChannelAuth();

        $this->actingAs(User::factory()->create(), 'web')->postJson('/broadcasting/auth', [
            'channel_name' => 'private-unregistered',
            'socket_id' => '123.456',
        ])->assertForbidden();
    }

    public function test_placed_event_has_a_private_channel_and_only_the_admin_payload(): void
    {
        $order = new Order([
            'label' => 'Juan Pérez',
            'total' => 40,
            'service' => 'togo',
            'customer_phone' => '8681234567',
            'public_token' => 'secret-token',
        ]);
        $order->id = 42;
        $event = new OnlineOrderPlaced($order);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertCount(1, $event->broadcastOn());
        $this->assertInstanceOf(PrivateChannel::class, $event->broadcastOn()[0]);
        $this->assertSame('private-admin-orders', $event->broadcastOn()[0]->name);
        $this->assertSame('placed', $event->broadcastAs());
        $this->assertSame(['id' => 42, 'name' => 'Juan Pérez', 'total' => 40.0, 'service' => 'togo'], $event->broadcastWith());
    }

    public function test_updated_event_has_a_public_token_channel_and_only_the_version(): void
    {
        $order = new Order(['public_token' => 'secret-token', 'customer_phone' => '8681234567']);
        $order->version = 7;
        $event = new OnlineOrderUpdated($order);

        $this->assertInstanceOf(ShouldBroadcastNow::class, $event);
        $this->assertCount(1, $event->broadcastOn());
        $this->assertSame(Channel::class, $event->broadcastOn()[0]::class);
        $this->assertSame('order.secret-token', $event->broadcastOn()[0]->name);
        $this->assertSame('updated', $event->broadcastAs());
        $this->assertSame(['version' => 7], $event->broadcastWith());
    }

    public function test_placing_an_order_dispatches_the_placed_event(): void
    {
        Event::fake([OnlineOrderPlaced::class, OnlineOrderUpdated::class]);

        $order = $this->placeOrder();

        Event::assertDispatched(OnlineOrderPlaced::class, fn ($event) => $event->order->is($order));
        Event::assertDispatchedTimes(OnlineOrderPlaced::class, 1);
        Event::assertNotDispatched(OnlineOrderUpdated::class);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Requested->value]);
    }

    public static function orderTransitions(): array
    {
        return [
            'aceptar' => ['accept', OrderStatus::Open],
            'rechazar' => ['reject', OrderStatus::Cancelled],
            'cancelar por cliente' => ['cancelByCustomer', OrderStatus::Cancelled],
        ];
    }

    #[DataProvider('orderTransitions')]
    public function test_changing_an_online_order_dispatches_the_updated_event(string $action, OrderStatus $status): void
    {
        Event::fake([OnlineOrderPlaced::class, OnlineOrderUpdated::class]);
        $order = $this->placeOrder();

        $changed = $action === 'reject'
            ? OnlineOrder::reject($order, 'Se acabó algún producto')
            : OnlineOrder::{$action}($order);

        $this->assertTrue($changed);
        Event::assertDispatched(OnlineOrderUpdated::class, fn ($event) => $event->order->is($order) && $event->order->status === $status);
        Event::assertDispatchedTimes(OnlineOrderUpdated::class, 1);
    }

    public function test_an_order_and_its_items_are_saved_when_the_broadcaster_throws(): void
    {
        $broadcaster = new class extends NullBroadcaster
        {
            public bool $attempted = false;

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->attempted = true;

                throw new BroadcastException('Reverb no está disponible');
            }
        };
        Broadcast::extend('failing', fn () => $broadcaster);
        config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);

        $order = $this->placeOrder();

        $this->assertTrue($broadcaster->attempted);
        $this->assertSame(1, Order::count());
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => OrderStatus::Requested->value, 'total' => 40]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2, 'unit_price' => 20]);
    }
}
