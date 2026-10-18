<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Support\OnlineOrder;
use App\Support\OrderFlow;
use App\Support\Store;
use App\Support\Telegram;
use App\Support\TelegramBot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramAdminTest extends TestCase
{
    use RefreshDatabase;

    private const STAFF = 5551;

    private const CLIENT = 7771;

    private Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->taco = Product::create(['name' => 'Taco de bistec', 'price' => 20, 'category' => 'Tacos']);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));
        Setting::put('online_enabled', '1');
        Store::putSecret('telegram_token', '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw');
        Store::putSecret('telegram_secret', 'secreto-del-webhook');
        Setting::put('telegram_enabled', '1');
        Telegram::addStaff(self::STAFF, 'Taquero');
        $this->fakeTelegram();
    }

    // ---- Ayudas ----

    private function fakeTelegram(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 900]])]);
    }

    /** @return array<int, array<string, mixed>> */
    private function sent(?string $method = null): array
    {
        return Http::recorded()->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $r) => $method === null || str_ends_with($r->url(), '/'.$method))
            ->map(fn (Request $r) => $r->data())->values()->all();
    }

    private function texts(): string
    {
        return collect($this->sent())->pluck('text')->filter()->join("\n---\n");
    }

    /** @return array<int, string> */
    private function buttons(array $call): array
    {
        return collect($call['reply_markup']['inline_keyboard'] ?? [])->flatten(1)->pluck('callback_data')->filter()->values()->all();
    }

    private function say(string $text, int $chat = self::STAFF): void
    {
        TelegramBot::handle(['update_id' => random_int(1, 999999999), 'message' => ['message_id' => 1, 'text' => $text, 'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'first_name' => 'Taquero']]]);
    }

    private function press(string $data, int $chat = self::STAFF): void
    {
        TelegramBot::handle(['update_id' => random_int(1, 999999999), 'callback_query' => ['id' => 'cb'.random_int(1, 999999), 'data' => $data, 'from' => ['id' => $chat, 'first_name' => 'Taquero'], 'message' => ['message_id' => 40, 'chat' => ['id' => $chat, 'type' => 'private']]]]);
    }

    private function answers(): string
    {
        return collect($this->sent('answerCallbackQuery'))->pluck('text')->filter()->join(' | ');
    }

    /** Un pedido en línea ya aceptado. */
    private function acceptedOrder(string $service = 'togo', ?int $customerChat = null): Order
    {
        $input = ['token' => (string) Str::uuid(), 'name' => 'Juan', 'phone' => '8681234567', 'service' => $service, 'lines' => [['product_id' => $this->taco->id, 'qty' => 2]]];

        if ($service === 'delivery') {
            Setting::put('online_delivery', '1');
            $input += ['address' => 'Calle 5 número 10', 'references' => 'Portón negro'];
        }

        $order = OnlineOrder::place($input, '10.0.0.'.random_int(1, 250));

        if ($customerChat) {
            $order->forceFill(['telegram_chat_id' => $customerChat])->saveQuietly();
        }

        OnlineOrder::accept($order);

        return $order->fresh();
    }

    /** Un pedido de la caja guardado sin cobrar. */
    private function counterOrder(array $attributes = []): Order
    {
        return Order::create($attributes + [
            'source' => 'pos',
            'status' => OrderStatus::Open,
            'daily_number' => Order::nextDailyNumber(),
            'label' => 'Mesa 4',
            'service' => 'here',
            'total' => 60,
            'sold_at' => now(),
        ]);
    }

    // ---- Panel y vistas ----

    public function test_the_staff_chat_sees_the_taquero_panel_and_a_customer_sees_the_welcome(): void
    {
        $this->acceptedOrder();
        $this->fakeTelegram();

        $this->say('/start');

        $this->assertStringContainsString('Panel del taquero', $this->texts());
        $this->assertSame(['req', 'act', 'hoy', 'tnd', 'cv'], $this->buttons($this->sent('sendMessage')[0]));
        $this->assertStringContainsString('Activos: 1', $this->texts());

        $this->fakeTelegram();
        $this->say('/start', self::CLIENT);

        $this->assertStringContainsString('Bienvenido', $this->texts());
        $this->assertNotContains('act', $this->buttons($this->sent('sendMessage')[0]));
    }

    public function test_linking_a_chat_gives_it_the_taquero_commands_and_panel(): void
    {
        Telegram::removeStaff(self::STAFF);
        $this->fakeTelegram();
        $code = Telegram::newLinkCode();

        $this->say('/vincular '.$code);

        $commands = $this->sent('setMyCommands');
        $this->assertSame(['type' => 'chat', 'chat_id' => self::STAFF], $commands[0]['scope']);
        $this->assertContains('activos', collect($commands[0]['commands'])->pluck('command')->all());
        $this->assertSame('commands', $this->sent('setChatMenuButton')[0]['menu_button']['type']);
        $this->assertStringContainsString('Panel del taquero', $this->texts());
    }

    public function test_the_taquero_can_see_the_bot_as_a_customer_and_come_back(): void
    {
        $this->press('cv');

        $this->assertTrue(Telegram::usesCustomerView(self::STAFF));
        $this->assertStringContainsString('Bienvenido', $this->texts());
        $this->assertContains('adm', $this->buttons($this->sent('sendMessage')[0]));
        $this->assertSame(['type' => 'chat', 'chat_id' => self::STAFF], $this->sent('deleteMyCommands')[0]['scope']);
        $this->assertSame('web_app', $this->sent('setChatMenuButton')[0]['menu_button']['type']);

        $this->say('/start');
        $this->assertStringContainsString('Bienvenido', collect($this->sent('sendMessage'))->last()['text']);

        $this->fakeTelegram();
        $this->press('adm');

        $this->assertFalse(Telegram::usesCustomerView(self::STAFF));
        $this->assertStringContainsString('Panel del taquero', $this->texts());
        $this->assertSame('commands', $this->sent('setChatMenuButton')[0]['menu_button']['type']);
        $this->assertTrue(Telegram::isStaff(self::STAFF));
    }

    public function test_the_panel_commands_switch_the_view_too(): void
    {
        $this->say('/cliente');
        $this->assertTrue(Telegram::usesCustomerView(self::STAFF));

        $this->say('/panel');
        $this->assertFalse(Telegram::usesCustomerView(self::STAFF));
    }

    public function test_taquero_commands_and_buttons_do_nothing_for_other_chats(): void
    {
        $order = $this->acceptedOrder();

        foreach (['/panel', '/activos', '/hoy', '/tienda', '/cliente', '/pendientes'] as $command) {
            $this->say($command, self::CLIENT);
        }

        $this->assertSame(6, substr_count($this->texts(), 'solo para el taquero'));

        foreach (['cv', 'adm', 'act', 'req', 'hoy', 'tnd', 'tgo', 'tgd', 'tmc', 'rdy:'.$order->id, 'dlv:'.$order->id, 'pay:'.$order->id, 'pym:'.$order->id.':0', 'cnk:'.$order->id] as $data) {
            $this->press($data, self::CLIENT);
        }

        $order->refresh();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNull($order->ready_at);
        $this->assertNull($order->delivered_at);
        $this->assertSame('1', Setting::value('online_enabled'));
        $this->assertFalse(Telegram::usesCustomerView(self::CLIENT));
        $this->assertFalse(Telegram::isStaff(self::CLIENT));
    }

    public function test_unlinking_gives_the_chat_the_normal_bot_back(): void
    {
        $this->say('/desvincular');

        $this->assertFalse(Telegram::isStaff(self::STAFF));
        $this->assertSame(['type' => 'chat', 'chat_id' => self::STAFF], $this->sent('deleteMyCommands')[0]['scope']);
        $this->assertSame('web_app', $this->sent('setChatMenuButton')[0]['menu_button']['type']);
    }

    // ---- Listas ----

    public function test_active_lists_each_order_with_buttons_for_its_state(): void
    {
        $online = $this->acceptedOrder();
        $counter = $this->counterOrder();
        $this->fakeTelegram();

        $this->say('/activos');

        $sent = $this->sent('sendMessage');
        $this->assertStringContainsString('Pedidos activos: 2', $sent[0]['text']);
        $this->assertStringContainsString('Pedido en línea de Juan', $this->texts());
        $this->assertStringContainsString('Pedido #'.$counter->daily_number.' de Mesa 4', $this->texts());
        $this->assertSame(['rdy:'.$online->id, 'dlv:'.$online->id, 'pay:'.$online->id, 'cnc:'.$online->id], $this->buttons($sent[1]));
        $this->assertSame(['rdy:'.$counter->id, 'dlv:'.$counter->id, 'pay:'.$counter->id, 'cnc:'.$counter->id], $this->buttons($sent[2]));
    }

    public function test_active_says_so_when_there_is_nothing_and_pending_lists_the_waiting(): void
    {
        $this->say('/activos');
        $this->assertStringContainsString('No hay pedidos activos', $this->texts());

        $this->fakeTelegram();
        OnlineOrder::place(['token' => (string) Str::uuid(), 'name' => 'Ana', 'phone' => '8681234567', 'service' => 'togo', 'lines' => [['product_id' => $this->taco->id, 'qty' => 1]]], '10.0.0.9');
        $this->fakeTelegram();
        $this->press('req');

        $this->assertStringContainsString('Pedido en línea de Ana', $this->texts());
    }

    public function test_active_shows_only_the_first_ten_and_counts_the_rest(): void
    {
        foreach (range(1, 12) as $i) {
            $this->counterOrder(['label' => 'Mesa '.$i]);
        }

        $this->say('/activos');

        $this->assertStringContainsString('Pedidos activos: 12', $this->texts());
        $this->assertStringContainsString('primeros 10', $this->texts());
        $this->assertCount(11, $this->sent('sendMessage'));
    }

    public function test_today_sends_the_sales_summary_on_demand(): void
    {
        $this->say('/hoy');

        $this->assertStringContainsString('Resumen del día', $this->texts());
    }

    // ---- Pedidos ----

    public function test_a_pickup_order_goes_from_ready_to_delivered_and_the_customer_hears_about_it(): void
    {
        $order = $this->acceptedOrder('togo', self::CLIENT);
        $this->fakeTelegram();

        $this->press('rdy:'.$order->id);

        $this->assertNotNull($order->fresh()->ready_at);
        $this->assertStringContainsString('Tu pedido está listo', $this->texts());
        $edits = $this->sent('editMessageText');
        $this->assertStringContainsString('Listo (#'.$order->daily_number.')', end($edits)['text']);
        $this->assertSame(['dlv:'.$order->id, 'pay:'.$order->id, 'cnc:'.$order->id], $this->buttons(end($edits)));

        $this->press('dlv:'.$order->id);

        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertStringContainsString('Tu pedido fue entregado', $this->texts());

        $this->press('und:'.$order->id);
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_a_delivery_order_has_to_leave_before_it_is_delivered(): void
    {
        $order = $this->acceptedOrder('delivery');
        $this->fakeTelegram();

        $this->press('dlv:'.$order->id);
        $this->assertNull($order->fresh()->delivered_at);
        $this->assertStringContainsString('ya no se puede', $this->answers());

        $this->press('dsp:'.$order->id);
        $order->refresh();
        $this->assertNotNull($order->dispatched_at);
        $this->assertNotNull($order->ready_at);

        $this->press('dlv:'.$order->id);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_charging_asks_how_it_was_paid_and_records_the_payment_once(): void
    {
        $order = $this->counterOrder();

        $this->press('pay:'.$order->id);
        $edit = $this->sent('editMessageText')[0];
        $this->assertStringContainsString('¿Cómo pagó?', $edit['text']);
        $this->assertSame(['pym:'.$order->id.':0', 'pym:'.$order->id.':1', 'pym:'.$order->id.':2', 'bk:'.$order->id], $this->buttons($edit));

        $this->press('pym:'.$order->id.':1');
        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertSame('transfer', $order->payment_method->value);
        $this->assertNotNull($order->paid_at);
        $this->assertSame([['transfer', '60.00']], $order->payments()->get()->map(fn ($p) => [$p->method->value, (string) $p->amount])->all());

        $this->fakeTelegram();
        $this->press('pym:'.$order->id.':0');
        $this->assertSame('transfer', $order->fresh()->payment_method->value);
        $this->assertSame(1, $order->payments()->count());
        $this->assertStringContainsString('ya no se puede', $this->answers());
    }

    public function test_an_unknown_payment_method_charges_nothing(): void
    {
        $order = $this->counterOrder();

        $this->press('pym:'.$order->id.':3');
        $this->press('pym:'.$order->id);

        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
    }

    public function test_cancelling_asks_first_and_only_works_before_paying(): void
    {
        $order = $this->counterOrder();

        $this->press('cnc:'.$order->id);
        $this->assertStringContainsString('¿Cancelar este pedido?', $this->sent('editMessageText')[0]['text']);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);

        $this->press('cnk:'.$order->id);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);

        $paid = $this->counterOrder();
        $this->press('pym:'.$paid->id.':0');
        $this->fakeTelegram();
        $this->press('cnk:'.$paid->id);
        $this->press('cnc:'.$paid->id);

        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
        $this->assertStringContainsString('ya se cobró o se canceló', $this->answers());
    }

    public function test_back_restores_the_order_message_and_its_buttons(): void
    {
        $order = $this->counterOrder();

        $this->press('bk:'.$order->id);

        $edit = $this->sent('editMessageText')[0];
        $this->assertStringContainsString('Mesa 4', $edit['text']);
        $this->assertSame(['rdy:'.$order->id, 'dlv:'.$order->id, 'pay:'.$order->id, 'cnc:'.$order->id], $this->buttons($edit));
    }

    public function test_orders_that_do_not_exist_or_waiting_orders_cannot_be_prepared(): void
    {
        $this->press('rdy:99999');
        $this->assertStringContainsString('ya no existe', $this->answers());

        $waiting = OnlineOrder::place(['token' => (string) Str::uuid(), 'name' => 'Ana', 'phone' => '8681234567', 'service' => 'togo', 'lines' => [['product_id' => $this->taco->id, 'qty' => 1]]], '10.0.0.9');
        $this->press('rdy:'.$waiting->id);
        $this->press('pym:'.$waiting->id.':0');

        $this->assertSame(OrderStatus::Requested, $waiting->fresh()->status);
        $this->assertNull($waiting->fresh()->ready_at);
    }

    public function test_a_change_from_the_web_updates_the_telegram_message_of_a_counter_order(): void
    {
        $order = $this->counterOrder();
        $this->say('/activos');
        $this->fakeTelegram();

        OrderFlow::markReady($order->id);

        $edits = $this->sent('editMessageText');
        $this->assertStringContainsString('Listo (#'.$order->daily_number.')', end($edits)['text']);
    }

    // ---- Tienda ----

    public function test_the_store_panel_turns_online_orders_and_delivery_on_and_off(): void
    {
        $this->say('/tienda');
        $this->assertSame(['tgo', 'tgd', 'tmo', 'tmc', 'adm'], $this->buttons($this->sent('sendMessage')[0]));

        $this->press('tgo');
        $this->assertSame('0', Setting::value('online_enabled'));
        $this->assertStringContainsString('Pedidos en línea apagados', $this->answers());
        $this->assertStringContainsString('Pedidos en línea: apagados', $this->sent('editMessageText')[0]['text']);

        $this->press('tgo');
        $this->assertSame('1', Setting::value('online_enabled'));

        $this->press('tgd');
        $this->assertTrue(Store::deliveryEnabled());
        $this->press('tgd');
        $this->assertFalse(Store::deliveryEnabled());
    }

    public function test_the_store_panel_forces_open_closed_or_follows_the_schedule(): void
    {
        $this->press('tmc');
        $this->assertSame('closed', Store::mode());
        $this->assertFalse(Store::isOpen());

        $this->press('tmo');
        $this->assertSame('open', Store::mode());
        $this->assertTrue(Store::isOpen());

        $this->press('tma');
        $this->assertSame('auto', Store::mode());
    }
}
