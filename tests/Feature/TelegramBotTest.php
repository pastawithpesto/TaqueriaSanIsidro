<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OnlineOrder;
use App\Support\Store;
use App\Support\Telegram;
use App\Support\TelegramBot;
use App\Support\TelegramNotifier;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

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
        Store::putSecret('telegram_token', self::TOKEN);
        Store::putSecret('telegram_secret', 'secreto-del-webhook');
        Setting::put('telegram_enabled', '1');
        Setting::put('telegram_username', 'taqueria_bot');
        $this->fakeTelegram();
    }

    // ---- Ayudas ----

    /** Reinicia el Telegram simulado: respuestas nuevas y lista de llamadas vacía. */
    private function fakeTelegram(?array $response = null, int $status = 200, array $extra = []): void
    {
        Http::swap(new Factory);
        Http::fake($extra + ['api.telegram.org/*' => Http::response($response ?? ['ok' => true, 'result' => ['message_id' => 900]], $status)]);
    }

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

    private function message(string $text, int $chat = self::CLIENT, string $type = 'private', string $name = 'Ana'): array
    {
        return ['update_id' => random_int(1, 999999999), 'message' => ['message_id' => 1, 'text' => $text, 'chat' => ['id' => $chat, 'type' => $type, 'title' => 'Grupo'], 'from' => ['id' => $chat, 'first_name' => $name]]];
    }

    private function press(string $data, int $chat, int $messageId = 40): array
    {
        return ['update_id' => random_int(1, 999999999), 'callback_query' => ['id' => 'cb'.random_int(1, 999999), 'data' => $data, 'from' => ['id' => $chat], 'message' => ['message_id' => $messageId, 'chat' => ['id' => $chat, 'type' => 'private']]]];
    }

    private function bot(array $update): void
    {
        TelegramBot::handle($update);
    }

    private function makeStaff(int $chat = self::STAFF): void
    {
        Telegram::addStaff($chat, 'Taquero');
    }

    private function placeOrder(array $overrides = [], ?int $chat = null): Order
    {
        $input = array_merge(['token' => (string) Str::uuid(), 'name' => 'Juan', 'phone' => '8681234567', 'service' => 'togo', 'lines' => [['product_id' => $this->taco->id, 'qty' => 2]]], $overrides);

        if ($chat !== null) {
            $input['telegram_init'] = $this->initData(['id' => $chat, 'first_name' => 'Juan']);
        }

        return OnlineOrder::place($input, '10.0.0.'.random_int(1, 250));
    }

    /** Datos de la Mini App firmados como lo hace Telegram. */
    private function initData(array $user, ?int $authDate = null, string $token = self::TOKEN): string
    {
        $fields = ['auth_date' => (string) ($authDate ?? time()), 'query_id' => 'AAH123', 'user' => json_encode($user)];
        ksort($fields);
        $check = collect($fields)->map(fn ($v, $k) => $k.'='.$v)->join("\n");
        $fields['hash'] = hash_hmac('sha256', $check, hash_hmac('sha256', $token, 'WebAppData', true));

        return http_build_query($fields);
    }

    // ---- Webhook ----

    public function test_the_webhook_does_not_exist_without_a_connected_bot(): void
    {
        Setting::put('telegram_enabled', '0');

        $this->postJson('/telegram/webhook', $this->message('/menu'), ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-del-webhook'])->assertNotFound();
    }

    public function test_the_webhook_only_accepts_telegram_with_the_secret(): void
    {
        $this->postJson('/telegram/webhook', $this->message('/menu'))->assertForbidden();
        $this->postJson('/telegram/webhook', $this->message('/menu'), ['X-Telegram-Bot-Api-Secret-Token' => 'otra-cosa'])->assertForbidden();
        $this->postJson('/telegram/webhook', $this->message('/menu'), ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertForbidden();
        $this->assertSame([], $this->sent());

        $this->postJson('/telegram/webhook', $this->message('/menu'), ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-del-webhook'])->assertOk();
        $this->assertStringContainsString('Taco de bistec', $this->texts());
    }

    public function test_the_webhook_needs_no_session_or_csrf_token_and_sets_no_cookies(): void
    {
        $response = $this->call('POST', '/telegram/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'secreto-del-webhook'], json_encode($this->message('/horario')));

        $response->assertOk();
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_the_same_update_is_processed_only_once(): void
    {
        $update = $this->message('/horario');

        foreach ([1, 2, 3] as $i) {
            $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-del-webhook'])->assertOk();
        }

        $this->assertCount(1, $this->sent('sendMessage'));
    }

    public function test_garbage_updates_never_break_the_webhook(): void
    {
        foreach ([[], ['message' => 'texto'], ['message' => ['text' => ['x'], 'chat' => ['id' => 'abc']]], ['callback_query' => ['id' => 1, 'data' => str_repeat('a', 500)]], ['update_id' => 'x']] as $junk) {
            $this->postJson('/telegram/webhook', $junk, ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-del-webhook'])->assertOk();
        }
        $this->call('POST', '/telegram/webhook', [], [], [], ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => 'secreto-del-webhook'], '{no es json')->assertOk();
    }

    // ---- Clientes ----

    public function test_start_greets_and_offers_the_mini_app(): void
    {
        $this->bot($this->message('/start', name: 'Ana <b>Luz</b>'));

        $sent = $this->sent('sendMessage')[0];
        $this->assertSame(self::CLIENT, $sent['chat_id']);
        $this->assertStringContainsString('Bienvenido', $sent['text']);
        $this->assertStringNotContainsString('<b>Luz', $sent['text']);
        $button = $sent['reply_markup']['inline_keyboard'][0][0];
        $this->assertSame('Hacer pedido', $button['text']);
        $this->assertSame(Telegram::miniAppUrl(), $button['web_app']['url']);
        $this->assertStringEndsWith('?source=telegram', $button['web_app']['url']);
    }

    public function test_the_menu_lists_active_products_with_prices_and_marks_sold_out(): void
    {
        Product::create(['name' => 'Torta oculta', 'price' => 39, 'active' => false]);
        Product::create(['name' => 'Torta', 'price' => 39, 'category' => 'Tortas', 'sold_out_on' => today()]);

        $this->bot($this->message('/menu'));

        $text = $this->texts();
        $this->assertStringContainsString('Taco de bistec  $20', $text);
        $this->assertStringContainsString('Torta  $39 (agotado hoy)', $text);
        $this->assertStringNotContainsString('Torta oculta', $text);
    }

    public function test_a_long_menu_is_split_into_several_messages(): void
    {
        foreach (range(1, 200) as $i) {
            Product::create(['name' => 'Producto número '.$i.' con nombre largo', 'price' => $i, 'category' => 'Cat '.intdiv($i, 20)]);
        }

        $this->bot($this->message('/menu'));

        $this->assertGreaterThan(1, count($this->sent('sendMessage')));
        foreach ($this->sent('sendMessage') as $message) {
            $this->assertLessThanOrEqual(4000, mb_strlen($message['text']));
        }
    }

    public function test_the_schedule_reports_open_closed_message_and_delivery(): void
    {
        $this->bot($this->message('/horario'));
        $this->assertStringContainsString('Estamos abiertos hasta las 12:00 am', $this->texts());
        $this->assertStringContainsString('Pedidos en línea: activados', $this->texts());
        $this->assertStringContainsString('solo para pasar a recoger', $this->texts());

        Setting::put('store_mode', 'closed');
        Setting::put('store_message', 'Cerrado por mal clima');
        $this->fakeTelegram();
        $this->bot($this->message('/horario'));
        $this->assertStringContainsString('Estamos cerrados', $this->sent('sendMessage')[count($this->sent('sendMessage')) - 1]['text']);
        $this->assertStringContainsString('Cerrado por mal clima', $this->texts());
    }

    public function test_a_customer_sees_the_state_of_their_last_order(): void
    {
        $this->bot($this->message('/estado'));
        $this->assertStringContainsString('No encuentro un pedido reciente', $this->texts());

        $order = $this->placeOrder(chat: self::CLIENT);
        OnlineOrder::accept($order);
        $this->fakeTelegram();
        $this->bot($this->message('/estado'));

        $this->assertStringContainsString('Tu pedido fue aceptado', $this->texts());
        $this->assertStringContainsString(route('pedido', $order->public_token), $this->texts());
    }

    public function test_plain_text_gets_help_in_private_and_is_ignored_in_groups(): void
    {
        $this->bot($this->message('hola, quiero tacos'));
        $this->assertCount(1, $this->sent('sendMessage'));
        $this->assertStringContainsString('Esto puedo hacer', $this->texts());

        $this->fakeTelegram();
        $this->bot($this->message('hola a todos', -100123, 'supergroup'));
        $this->bot($this->message('/comandoraro', -100123, 'supergroup'));
        $this->assertSame([], $this->sent());
    }

    public function test_one_chat_cannot_flood_the_bot(): void
    {
        foreach (range(1, TelegramBot::MESSAGES_PER_MINUTE + 15) as $i) {
            $this->bot($this->message('/horario'));
        }

        $this->assertCount(TelegramBot::MESSAGES_PER_MINUTE, $this->sent('sendMessage'));
    }

    public function test_the_bot_does_nothing_when_it_is_turned_off(): void
    {
        Setting::put('telegram_enabled', '0');

        $this->bot($this->message('/menu'));

        $this->assertSame([], $this->sent());
    }

    // ---- Taquero ----

    public function test_the_taquero_links_a_chat_with_a_one_time_code(): void
    {
        $code = Telegram::newLinkCode();

        $this->bot($this->message('/vincular 000000', self::STAFF));
        $this->assertFalse(Telegram::isStaff(self::STAFF));
        $this->assertStringContainsString('Código incorrecto', $this->texts());

        $this->bot($this->message('/vincular '.$code, self::STAFF, name: 'Mi Telegram'));
        $this->assertTrue(Telegram::isStaff(self::STAFF));
        $this->assertSame('Mi Telegram', Telegram::staffChats()[0]['name']);

        $this->bot($this->message('/vincular '.$code, 9999));
        $this->assertFalse(Telegram::isStaff(9999), 'el código sirve una sola vez');
    }

    public function test_a_link_code_expires_after_ten_minutes(): void
    {
        $code = Telegram::newLinkCode();
        $this->travel(11)->minutes();

        $this->bot($this->message('/vincular '.$code, self::STAFF));

        $this->assertFalse(Telegram::isStaff(self::STAFF));
    }

    public function test_the_link_code_cannot_be_guessed_by_trying_many_times(): void
    {
        $code = Telegram::newLinkCode();

        foreach (range(1, Telegram::LINK_ATTEMPTS) as $i) {
            $this->bot($this->message('/vincular '.str_pad((string) $i, 6, '1'), self::STAFF));
        }
        $this->bot($this->message('/vincular '.$code, self::STAFF));

        $this->assertFalse(Telegram::isStaff(self::STAFF), 'pasados los intentos, ni el código correcto sirve');
    }

    public function test_many_chats_together_cannot_brute_force_the_code_either(): void
    {
        $code = Telegram::newLinkCode();

        foreach (range(1, Telegram::LINK_ATTEMPTS) as $i) {
            $this->bot($this->message('/vincular 99999'.$i, 8000 + $i));
        }
        $this->bot($this->message('/vincular '.$code, 8999));

        $this->assertFalse(Telegram::isStaff(8999));
    }

    public function test_the_staff_gets_new_online_orders_with_accept_and_reject_buttons(): void
    {
        $this->makeStaff();
        $this->makeStaff(5552);
        Telegram::removeStaff(5552);

        $order = $this->placeOrder(['notes' => 'Sin <picante>']);

        $messages = $this->sent('sendMessage');
        $this->assertCount(1, $messages);
        $this->assertSame(self::STAFF, $messages[0]['chat_id']);
        $this->assertStringContainsString('Pedido en línea de Juan', $messages[0]['text']);
        $this->assertStringContainsString('2 x Taco de bistec', $messages[0]['text']);
        $this->assertStringContainsString('Sin &lt;picante&gt;', $messages[0]['text']);
        $this->assertStringContainsString('Total: $40', $messages[0]['text']);
        $buttons = $messages[0]['reply_markup']['inline_keyboard'][0];
        $this->assertSame(['acc:'.$order->id, 'rej:'.$order->id], [$buttons[0]['callback_data'], $buttons[1]['callback_data']]);
        $this->assertDatabaseHas('telegram_notices', ['order_id' => $order->id, 'chat_id' => self::STAFF, 'message_id' => 900]);
    }

    public function test_the_staff_accepts_from_telegram_and_the_message_is_updated(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();
        $this->fakeTelegram();

        $this->bot($this->press('acc:'.$order->id, self::STAFF, 900));

        $order->refresh();
        $this->assertSame(OrderStatus::Open, $order->status);
        $this->assertNotNull($order->daily_number);
        $edits = $this->sent('editMessageText');
        $this->assertNotEmpty($edits);
        $this->assertStringContainsString('Aceptado: es el #'.$order->daily_number, end($edits)['text']);
        $buttons = collect(end($edits)['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
        $this->assertSame(['rdy:'.$order->id, 'dlv:'.$order->id, 'pay:'.$order->id, 'cnc:'.$order->id], $buttons);
    }

    public function test_a_chat_that_is_not_staff_cannot_accept_or_reject(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();

        foreach (['acc:'.$order->id, 'rej:'.$order->id, 'rjr:'.$order->id.':0', 'bk:'.$order->id] as $data) {
            $this->bot($this->press($data, 31337));
        }

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertStringContainsString('no puede responder pedidos', json_encode($this->sent('answerCallbackQuery'), JSON_UNESCAPED_UNICODE));
    }

    public function test_the_staff_rejects_choosing_a_reason(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();

        $this->bot($this->press('rej:'.$order->id, self::STAFF));
        $edit = $this->sent('editMessageText');
        $this->assertStringContainsString('Elige el motivo', end($edit)['text']);
        $this->assertCount(count(OnlineOrder::REJECT_REASONS) + 1, end($edit)['reply_markup']['inline_keyboard']);

        $this->bot($this->press('rjr:'.$order->id.':2', self::STAFF));

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame(OnlineOrder::REJECT_REASONS[2], $order->cancel_reason);
    }

    public function test_a_reject_reason_index_that_does_not_exist_rejects_nothing(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();

        $this->bot($this->press('rjr:'.$order->id.':9', self::STAFF));
        $this->bot($this->press('rjr:'.$order->id, self::STAFF));

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
    }

    public function test_buttons_for_orders_that_do_not_exist_or_are_not_online_do_nothing(): void
    {
        $this->makeStaff();
        $pos = Order::create(['status' => OrderStatus::Paid, 'daily_number' => 1, 'sold_at' => now(), 'paid_at' => now(), 'total' => 50]);

        $this->bot($this->press('acc:99999999', self::STAFF));
        $this->bot($this->press('acc:'.$pos->id, self::STAFF));
        $this->bot($this->press('can:'.$pos->id, self::STAFF));

        $this->assertSame(OrderStatus::Paid, $pos->fresh()->status);
    }

    public function test_accepting_twice_or_after_the_customer_cancelled_says_so(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();
        OnlineOrder::cancelByCustomer($order);

        $this->bot($this->press('acc:'.$order->id, self::STAFF));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertStringContainsString('ya no está por aceptar', json_encode($this->sent('answerCallbackQuery'), JSON_UNESCAPED_UNICODE));
    }

    public function test_pending_lists_waiting_orders_for_staff_only(): void
    {
        $this->placeOrder();
        $this->bot($this->message('/pendientes', self::CLIENT));
        $this->assertStringContainsString('solo para el taquero', $this->texts());

        $this->makeStaff();
        $this->fakeTelegram();
        $this->bot($this->message('/pendientes', self::STAFF));
        $this->assertStringContainsString('Pedido en línea de Juan', $this->texts());
    }

    public function test_linking_a_chat_shows_the_orders_already_waiting(): void
    {
        $this->placeOrder();
        $code = Telegram::newLinkCode();

        $this->bot($this->message('/vincular '.$code, self::STAFF));

        $this->assertStringContainsString('Pedido en línea de Juan', $this->texts());
    }

    public function test_unlink_stops_the_notices(): void
    {
        $this->makeStaff();
        $this->bot($this->message('/desvincular', self::STAFF));
        $this->assertFalse(Telegram::isStaff(self::STAFF));

        $this->fakeTelegram();
        $this->placeOrder();
        $this->assertSame([], $this->sent('sendMessage'));
    }

    public function test_an_accept_from_the_web_also_updates_the_telegram_message(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();
        $this->fakeTelegram();

        OnlineOrder::reject($order, 'Se acabó algún producto');

        $edits = $this->sent('editMessageText');
        $this->assertCount(1, $edits);
        $this->assertStringContainsString('Rechazado: Se acabó algún producto', $edits[0]['text']);
    }

    // ---- Cliente en Telegram ----

    public function test_an_order_from_the_mini_app_is_linked_to_the_chat_and_confirmed(): void
    {
        $order = $this->placeOrder(chat: self::CLIENT);

        $this->assertSame(self::CLIENT, $order->fresh()->telegram_chat_id);
        $messages = collect($this->sent('sendMessage'))->where('chat_id', self::CLIENT)->values();
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('Recibimos tu pedido', $messages[0]['text']);
        $this->assertSame('can:'.$order->id, $messages[0]['reply_markup']['inline_keyboard'][0][0]['callback_data']);
    }

    #[DataProvider('badInitData')]
    public function test_forged_or_stale_mini_app_data_does_not_link_a_chat(string $kind): void
    {
        $init = match ($kind) {
            'otro token' => $this->initData(['id' => 42, 'first_name' => 'X'], token: '999:otro-token'),
            'caducado' => $this->initData(['id' => 42, 'first_name' => 'X'], time() - 90000),
            'futuro' => $this->initData(['id' => 42, 'first_name' => 'X'], time() + 4000),
            'alterado' => str_replace('%22id%22%3A42', '%22id%22%3A77', $this->initData(['id' => 42, 'first_name' => 'X'])),
            'sin hash' => 'auth_date='.time().'&user=%7B%22id%22%3A42%7D',
            'vacío' => '',
            'basura' => str_repeat('a', 5000),
        };

        $order = OnlineOrder::place(['token' => (string) Str::uuid(), 'name' => 'Juan', 'phone' => '8681234567', 'service' => 'togo', 'telegram_init' => $init, 'lines' => [['product_id' => $this->taco->id, 'qty' => 1]]], '10.0.0.1');

        $this->assertNull($order->fresh()->telegram_chat_id);
    }

    public static function badInitData(): array
    {
        return [['otro token'], ['caducado'], ['futuro'], ['alterado'], ['sin hash'], ['vacío'], ['basura']];
    }

    public function test_the_mini_app_data_is_checked_with_telegrams_rule(): void
    {
        $user = Telegram::verifyInitData($this->initData(['id' => 42, 'first_name' => 'Ana']));
        $this->assertSame(42, $user['id']);

        $this->assertNull(Telegram::verifyInitData(null));
        $this->assertNull(Telegram::verifyInitData($this->initData(['id' => '42'])), 'el id debe ser un entero');
        $this->assertNull(Telegram::verifyInitData($this->initData(['nombre' => 'sin id'])));
    }

    public function test_the_mini_app_data_is_ignored_when_the_bot_is_off(): void
    {
        Setting::put('telegram_enabled', '0');

        $order = $this->placeOrder(chat: self::CLIENT);

        $this->assertNull($order->fresh()->telegram_chat_id);
    }

    public function test_the_customer_cancels_from_telegram_only_from_their_own_chat_and_only_while_waiting(): void
    {
        $order = $this->placeOrder(chat: self::CLIENT);

        $this->bot($this->press('can:'.$order->id, 31337));
        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);

        $this->bot($this->press('can:'.$order->id, self::CLIENT));
        $this->assertSame(Order::CUSTOMER_CANCELLED, $order->fresh()->cancel_reason);

        $accepted = $this->placeOrder(['phone' => '8689999999'], self::CLIENT);
        OnlineOrder::accept($accepted);
        $this->bot($this->press('can:'.$accepted->id, self::CLIENT));
        $this->assertSame(OrderStatus::Open, $accepted->fresh()->status);
    }

    public function test_the_customer_hears_about_each_step_once(): void
    {
        $order = $this->placeOrder(chat: self::CLIENT);
        $this->fakeTelegram();

        OnlineOrder::accept($order);
        $order->refresh()->update(['ready_at' => now()]);
        $order->refresh()->update(['ready_at' => now()->addSecond()]);
        $order->refresh()->update(['delivered_at' => now(), 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'paid_at' => now()]);

        $titles = collect($this->sent('sendMessage'))->where('chat_id', self::CLIENT)->pluck('text')->join('|');
        $this->assertSame(1, substr_count($titles, 'Tu pedido fue aceptado'));
        $this->assertStringContainsString('Tu número de pedido es el #', $titles);
        $this->assertSame(1, substr_count($titles, 'Tu pedido está listo'));
        $this->assertSame(1, substr_count($titles, 'Tu pedido fue entregado'));
    }

    public function test_a_rejection_reaches_the_customer_with_the_reason(): void
    {
        $order = $this->placeOrder(chat: self::CLIENT);
        $this->fakeTelegram();

        OnlineOrder::reject($order, 'Se acabó algún producto');

        $this->assertStringContainsString('No pudimos aceptar tu pedido', $this->texts());
        $this->assertStringContainsString('Se acabó algún producto', $this->texts());
    }

    public function test_orders_without_telegram_send_nothing_to_customers(): void
    {
        $order = $this->placeOrder();
        $this->fakeTelegram();

        OnlineOrder::accept($order);

        $this->assertSame([], $this->sent('sendMessage'));
    }

    public function test_the_deep_link_from_the_tracking_page_links_the_chat_to_that_order(): void
    {
        $order = $this->placeOrder();
        $this->assertStringContainsString('https://t.me/taqueria_bot?start=pedido_'.$order->public_token, $this->get(route('pedido', $order->public_token))->getContent());

        $this->bot($this->message('/start pedido_'.$order->public_token, self::CLIENT));
        $this->assertSame(self::CLIENT, $order->fresh()->telegram_chat_id);
        $this->assertStringContainsString('te avisamos por aquí', $this->texts());

        $this->get(route('pedido', $order->public_token))->assertSee('Te avisamos de cada paso por Telegram')->assertDontSee('Recibir avisos por Telegram');
    }

    public function test_another_chat_cannot_take_over_the_notices_of_an_order(): void
    {
        $order = $this->placeOrder(chat: self::CLIENT);

        $this->bot($this->message('/start pedido_'.$order->public_token, 31337));

        $this->assertSame(self::CLIENT, $order->fresh()->telegram_chat_id);
        $this->assertStringContainsString('ya recibe avisos en otro chat', $this->texts());
    }

    public function test_bad_deep_links_are_refused(): void
    {
        $this->bot($this->message('/start pedido_'.(string) Str::uuid(), self::CLIENT));
        $this->bot($this->message("/start pedido_' OR 1=1", self::CLIENT));
        $this->bot($this->message('/start pedido_'.str_repeat('a', 36), self::CLIENT));

        $this->assertSame(0, Order::whereNotNull('telegram_chat_id')->count());
    }

    public function test_the_tracking_page_shows_no_telegram_link_when_the_bot_is_off(): void
    {
        $order = $this->placeOrder();
        Setting::put('telegram_enabled', '0');

        $this->get(route('pedido', $order->public_token))->assertDontSee('Recibir avisos por Telegram');
    }

    // ---- Avisos del negocio, fallas y secretos ----

    public function test_the_reminder_and_the_closing_summary_go_to_the_staff(): void
    {
        $this->makeStaff();
        $order = $this->placeOrder();
        $paid = Order::create(['source' => 'online', 'status' => OrderStatus::Paid, 'daily_number' => 4, 'sold_at' => now(), 'paid_at' => now(), 'total' => 130, 'payment_method' => 'cash']);
        Order::create(['status' => OrderStatus::Paid, 'daily_number' => 5, 'sold_at' => now(), 'paid_at' => now(), 'total' => 70, 'payment_method' => 'cash']);
        $this->fakeTelegram();

        $this->travel(7)->minutes();
        TelegramNotifier::remind($order->fresh());
        TelegramNotifier::summary();

        $texts = $this->texts();
        $this->assertStringContainsString('Juan lleva 7 minutos sin respuesta', $texts);
        $this->assertStringContainsString('2 ventas por $200', $texts);
        $this->assertStringContainsString('En línea: 1 por $130', $texts);
        $this->assertNotNull($paid);
    }

    public function test_telegram_notices_still_go_out_when_the_realtime_server_is_down(): void
    {
        $broadcaster = new class extends NullBroadcaster
        {
            public int $attempts = 0;

            public function broadcast(array $channels, $event, array $payload = [])
            {
                $this->attempts++;

                throw new BroadcastException('Reverb no está disponible');
            }
        };
        Broadcast::extend('failing', fn () => $broadcaster);
        config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
        $this->makeStaff();

        $order = $this->placeOrder([], self::CLIENT);
        $this->assertStringContainsString('Pedido en línea de Juan', $this->texts());
        $this->assertStringContainsString('Recibimos tu pedido', $this->texts());

        $this->assertTrue(OnlineOrder::accept($order));
        $this->assertStringContainsString('Tu pedido fue aceptado', $this->texts());

        // Tras la primera falla ya no se intenta en cada aviso: la venta no espera a que venza la conexión.
        $this->assertSame(1, $broadcaster->attempts);
    }

    public function test_a_chat_unlinked_elsewhere_does_not_come_back_when_another_chat_changes(): void
    {
        $this->makeStaff(self::STAFF);
        $this->makeStaff(8881);
        $this->assertTrue(Telegram::isStaff(8881));

        // Otro proceso desvinculó 8881; este todavía tiene la lista vieja en memoria.
        Setting::where('key', 'telegram_staff')->update(['value' => json_encode([['id' => self::STAFF, 'name' => 'Taquero']])]);

        Telegram::setCustomerView(self::STAFF, true);

        $this->assertFalse(Telegram::isStaff(8881));
        $this->assertTrue(Telegram::usesCustomerView(self::STAFF));
    }

    public function test_telegram_being_down_never_breaks_a_customer_order(): void
    {
        $this->makeStaff();
        Http::swap(new Factory);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Connection timed out for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'));
        Log::spy();

        $order = $this->placeOrder();

        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);
        $this->assertTrue(Cache::has('telegram:down'));
        Log::shouldHaveReceived('warning')->atLeast()->once();
        // El mensaje de la excepción lleva el token: no debe llegar al registro.
        Log::shouldNotHaveReceived('warning', fn ($message) => str_contains((string) $message, self::TOKEN));
        Log::shouldNotHaveReceived('error');
    }

    public function test_after_a_failure_the_bot_stops_calling_telegram_for_a_minute(): void
    {
        Cache::put('telegram:down', true, 60);

        $this->assertNull(Telegram::call('getMe'));
        $this->assertSame([], $this->sent());
    }

    public function test_telegram_rejecting_a_call_returns_null_without_throwing(): void
    {
        $this->fakeTelegram(['ok' => false, 'description' => 'Bad Request: chat not found'], 400);

        $this->assertNull(Telegram::send(123, 'hola'));
    }

    public function test_user_text_is_always_escaped_in_what_the_bot_sends(): void
    {
        $this->makeStaff();
        $this->placeOrder(['name' => '<script>alert(1)</script>', 'notes' => '<b>negrita</b>']);

        $text = $this->texts();
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('<b>negrita', $text);
        $this->assertStringContainsString('&lt;script&gt;', $text);
    }

    public function test_the_token_is_stored_encrypted_and_never_shown(): void
    {
        $this->assertNotSame(self::TOKEN, Setting::value('telegram_token'));
        $this->assertNotSame('secreto-del-webhook', Setting::value('telegram_secret'));
        $this->assertSame(self::TOKEN, Telegram::token());

        $this->actingAs(User::factory()->create());
        $page = Livewire::test('pages::tienda');
        $this->assertStringNotContainsString(self::TOKEN, $page->html());
        $this->assertStringNotContainsString('secreto-del-webhook', $page->html());
    }

    // ---- Pantalla Tienda en línea ----

    public function test_connecting_the_bot_checks_the_token_with_telegram_and_saves_it_encrypted(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('telegram_token', null);
        Setting::put('telegram_enabled', '0');
        $this->fakeTelegram(['ok' => true, 'result' => true], 200, ['api.telegram.org/*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 1, 'is_bot' => true, 'username' => 'taqueria_bot']])]);

        $page = Livewire::test('pages::tienda')->set('telegramToken', self::TOKEN)->call('connectTelegram')->assertHasNoErrors()->assertSet('telegramToken', '');

        $this->assertSame(self::TOKEN, Telegram::token());
        $this->assertSame('taqueria_bot', Telegram::username());
        $this->assertTrue(Telegram::enabled());
        $this->assertNotNull(Telegram::webhookSecret());
        $page->assertSee('@taqueria_bot');
    }

    public function test_a_token_with_the_wrong_format_or_that_telegram_refuses_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('telegram_token', null);

        foreach (['', 'hola', '123:abc', '<script>', str_repeat('1', 300)] as $bad) {
            Livewire::test('pages::tienda')->set('telegramToken', $bad)->call('connectTelegram')->assertHasErrors('telegramToken');
        }

        $this->fakeTelegram(['ok' => false, 'description' => 'Unauthorized'], 401);
        Livewire::test('pages::tienda')->set('telegramToken', self::TOKEN)->call('connectTelegram')->assertHasErrors('telegramToken');
        $this->assertNull(Telegram::token());
    }

    public function test_a_token_of_something_that_is_not_a_bot_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        Setting::put('telegram_token', null);
        $this->fakeTelegram(['ok' => true, 'result' => ['id' => 1, 'is_bot' => false]]);

        Livewire::test('pages::tienda')->set('telegramToken', self::TOKEN)->call('connectTelegram')->assertHasErrors('telegramToken');

        $this->assertNull(Telegram::token());
    }

    public function test_the_taquero_manages_the_linked_chats_from_the_settings_screen(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeStaff();

        $page = Livewire::test('pages::tienda')->assertSee('Taquero');
        $page->call('newLinkCode');
        $code = $page->get('linkCode');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $page->assertSee('/vincular '.$code);

        $page->call('testTelegram')->assertDispatched('toast');
        $this->assertStringContainsString('Mensaje de prueba', $this->texts());

        $page->call('removeStaffChat', (string) self::STAFF);
        $this->assertSame([], Telegram::staffChats());
    }

    public function test_disconnecting_forgets_everything(): void
    {
        $this->actingAs(User::factory()->create());
        $this->makeStaff();

        Livewire::test('pages::tienda')->call('disconnectTelegram');

        $this->assertNull(Telegram::token());
        $this->assertNull(Telegram::webhookSecret());
        $this->assertSame([], Telegram::staffChats());
        $this->assertFalse(Telegram::enabled());
        $this->postJson('/telegram/webhook', $this->message('/menu'), ['X-Telegram-Bot-Api-Secret-Token' => 'secreto-del-webhook'])->assertNotFound();
    }

    public function test_the_page_warns_that_the_webhook_needs_https(): void
    {
        $this->actingAs(User::factory()->create());

        $this->assertFalse(Telegram::canUseWebhook());
        Livewire::test('pages::tienda')->assertSee('telegram:poll');
        $this->assertFalse(Telegram::register());
    }

    public function test_register_sets_the_webhook_commands_and_the_mini_app_button_over_https(): void
    {
        config(['app.url' => 'https://taqueria.example.com']);
        URL::forceRootUrl('https://taqueria.example.com');
        URL::forceScheme('https');

        $this->assertTrue(Telegram::register());

        $hook = $this->sent('setWebhook')[0];
        $this->assertSame('https://taqueria.example.com/telegram/webhook', $hook['url']);
        $this->assertSame('secreto-del-webhook', $hook['secret_token']);
        $this->assertSame(['message', 'callback_query'], $hook['allowed_updates']);
        $this->assertNotEmpty($this->sent('setMyCommands'));
        $this->assertSame('https://taqueria.example.com/?source=telegram', $this->sent('setChatMenuButton')[0]['menu_button']['web_app']['url']);
    }

    public function test_the_rate_limiter_state_is_per_chat(): void
    {
        RateLimiter::clear('telegram-chat|'.self::CLIENT);
        $this->bot($this->message('/horario', self::CLIENT));
        $this->bot($this->message('/horario', 4242));

        $this->assertCount(2, $this->sent('sendMessage'));
    }
}
