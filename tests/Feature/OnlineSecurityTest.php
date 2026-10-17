<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OnlineOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OnlineSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taco = Product::create(['name' => 'Taco de bistec', 'price' => 20]);
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(20, 30));
        Setting::put('online_enabled', '1');
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'token' => (string) Str::uuid(),
            'name' => 'Juan',
            'phone' => '8681234567',
            'service' => 'togo',
            'lines' => [['product_id' => $this->taco->id, 'qty' => 1]],
        ], $overrides);
    }

    private function place(array $overrides = [], string $ip = '10.0.0.1'): Order
    {
        return OnlineOrder::place($this->input($overrides), $ip);
    }

    // ---- Cabeceras ----

    public function test_every_response_carries_the_basic_security_headers(): void
    {
        foreach (['/', route('login')] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy');
        }
    }

    public function test_the_tracking_page_is_never_indexed_and_never_sent_in_the_referer(): void
    {
        $order = $this->place();

        $this->get(route('pedido', $order->public_token))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function test_the_internal_system_is_not_indexed_either(): void
    {
        $this->get(route('login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $this->get('/')->assertHeaderMissing('X-Robots-Tag');
    }

    // ---- Acceso ----

    public function test_a_stranger_cannot_listen_to_the_admin_channel(): void
    {
        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-admin-orders', 'socket_id' => '1.2'])->assertStatus(401);
        $this->post('/broadcasting/auth', ['channel_name' => 'private-admin-orders', 'socket_id' => '1.2'])->assertRedirect(route('login'));
    }

    #[DataProvider('badTokens')]
    public function test_malformed_tracking_links_are_not_found(string $token): void
    {
        $this->get('/pedido/'.rawurlencode($token))->assertNotFound();
    }

    public static function badTokens(): array
    {
        return [
            'sql' => ["' OR 1=1 --"],
            'number' => ['1'],
            'path' => ['../../etc/passwd'],
            'almost uuid' => ['3ca3d537-2128-4a4f-9c01-eba3dcedebdz'],
            'long' => [str_repeat('a', 500)],
            'uuid unknown' => ['3ca3d537-2128-4a4f-9c01-eba3dcedebd6'],
        ];
    }

    public function test_the_admin_screens_are_not_reachable_from_the_customer_root(): void
    {
        foreach (['/ventas', '/pedidos', '/cocina', '/corte', '/tienda', '/ajustes', '/login', '/resumen', '/gastos', '/productos', '/insumos'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_the_guest_cannot_see_the_inbox_or_the_store_settings(): void
    {
        $this->place();

        foreach (['pedidos', 'tienda', 'cocina', 'ventas', 'corte'] as $name) {
            $this->get(route($name))->assertRedirect(route('login'));
        }
    }

    // ---- Manipulación de la entrada ----

    #[DataProvider('hostileInputs')]
    public function test_hostile_form_values_are_rejected_without_creating_orders(array $overrides): void
    {
        try {
            $this->place($overrides);
        } catch (ValidationException) {
        }

        $this->assertSame(0, Order::count());
    }

    public static function hostileInputs(): array
    {
        return [
            'name array' => [['name' => ['x']]],
            'name object' => [['name' => ['a' => ['b']]]],
            'name null' => [['name' => null]],
            'name only spaces' => [['name' => "   \t\n"]],
            'name invisible' => [['name' => "\u{200B}\u{200B}"]],
            'name huge' => [['name' => str_repeat('ñ', 5000)]],
            'phone letters' => [['phone' => 'abcdefghij']],
            'phone short' => [['phone' => '86812']],
            'phone long' => [['phone' => '86812345678901234']],
            'phone array' => [['phone' => ['8681234567']]],
            'phone injected' => [['phone' => '8681234567; DROP TABLE orders']],
            'service here' => [['service' => 'here']],
            'service array' => [['service' => ['togo']]],
            'service null' => [['service' => null]],
            'service sql' => [['service' => "togo' OR '1'='1"]],
            'delivery off' => [['service' => 'delivery', 'address' => 'Calle 1']],
            'lines string' => [['lines' => 'texto']],
            'lines empty' => [['lines' => []]],
            'lines nested junk' => [['lines' => [[[[]]], 'x', 5, null]]],
            'product sql' => [['lines' => [['product_id' => '1 OR 1=1', 'qty' => 1]]]],
            'product huge' => [['lines' => [['product_id' => '99999999999999999999999', 'qty' => 1]]]],
            'product missing' => [['lines' => [['qty' => 1]]]],
            'product array' => [['lines' => [['product_id' => [1], 'qty' => 1]]]],
            'notes huge' => [['notes' => str_repeat('a', 500)]],
        ];
    }

    #[DataProvider('weirdQuantities')]
    public function test_weird_quantities_never_produce_negative_or_huge_orders(mixed $qty): void
    {
        $order = $this->place(['lines' => [['product_id' => $this->taco->id, 'qty' => $qty]]]);

        $quantity = $order->items->first()->quantity;
        $this->assertGreaterThanOrEqual(1, $quantity);
        $this->assertLessThanOrEqual(OnlineOrder::MAX_QUANTITY, $quantity);
        $this->assertGreaterThan(0, (float) $order->total);
    }

    public static function weirdQuantities(): array
    {
        return [[-5], [0], ['0'], ['abc'], ['1e308'], [1e308], [NAN], [INF], [[]], [[3]], [true], [null], ['  7 '], [99999999999], ['-1']];
    }

    public function test_extra_keys_cannot_change_prices_status_or_ownership(): void
    {
        $order = $this->place([
            'status' => 'paid', 'source' => 'pos', 'total' => 0, 'public_token' => 'abc', 'daily_number' => 1, 'user_id' => 1,
            'payment_method' => 'cash', 'discount' => 999, 'tip' => 5, 'accepted_at' => now(),
            'lines' => [['product_id' => $this->taco->id, 'qty' => 1, 'unit_price' => 0, 'price' => 0, 'courtesy' => true, 'discount' => 100, 'subtotal' => 0]],
        ]);

        $this->assertSame(OrderStatus::Requested, $order->status);
        $this->assertSame('online', $order->source);
        $this->assertSame('20.00', $order->total);
        $this->assertNull($order->daily_number);
        $this->assertNull($order->user_id);
        $this->assertNull($order->payment_method);
        $this->assertNull($order->accepted_at);
        $this->assertNotSame('abc', $order->public_token);
        $this->assertSame('0.00', $order->discount);
        $this->assertFalse($order->items->first()->isCourtesy());
    }

    public function test_modifiers_from_other_products_are_ignored(): void
    {
        $other = Product::create(['name' => 'Torta', 'price' => 39]);
        $extra = $other->extras()->create(['name' => 'Extra queso', 'price' => 10]);

        $order = $this->place(['lines' => [['product_id' => $this->taco->id, 'qty' => 1, 'extras' => [$extra->id], 'without' => [999], 'choices' => [123]]]]);

        $this->assertSame('20.00', $order->total);
        $this->assertEmpty($order->items->first()->extras());
    }

    public function test_hostile_text_is_stored_as_text_and_shown_escaped_to_everyone(): void
    {
        $payload = '<script>alert("xss")</script><img src=x onerror=alert(1)>';
        $order = $this->place(['name' => $payload, 'notes' => $payload, 'lines' => [['product_id' => $this->taco->id, 'qty' => 1, 'notes' => $payload]]]);
        $this->assertSame(0, Order::where('label', '!=', $payload)->count());

        $this->get(route('pedido', $order->public_token))->assertDontSee('<script>alert', false)->assertDontSee('<img src=x', false)->assertSee('&lt;script&gt;', false);

        $this->actingAs(User::factory()->create());
        Livewire::test('pages::pedidos')->assertDontSee('<script>alert', false)->assertDontSee('<img src=x', false);

        OnlineOrder::accept($order);
        Livewire::test('pages::cocina')->assertDontSee('<script>alert', false);
        Livewire::test('pages::pedidos')->assertDontSee('<script>alert', false)->assertDontSee('<img src=x', false);
    }

    public function test_sql_looking_text_is_just_text(): void
    {
        $order = $this->place(['name' => "Robert'); DROP TABLE orders;--", 'notes' => "' OR 1=1 --"]);

        $this->assertSame("Robert'); DROP TABLE orders;--", $order->fresh()->label);
        $this->assertSame(1, Order::count());
    }

    public function test_invisible_and_control_characters_are_removed_from_names_and_notes(): void
    {
        $order = $this->place(['name' => "Ju\u{200B}an\u{202E} Pé\0rez", 'notes' => "hola\u{200B}\n\n\tmundo"]);

        $this->assertSame('Juan Pérez', $order->label);
        $this->assertSame('hola mundo', $order->notes);
    }

    // ---- Dinero ----

    public function test_a_price_change_while_the_customer_was_filling_the_cart_is_reported(): void
    {
        $this->taco->update(['price' => 25]);

        try {
            $this->place(['expected_total' => 20]);
            $this->fail('Debió avisar el cambio de precio');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('prices', $e->errors());
        }
        $this->assertSame(0, Order::count());

        $order = $this->place(['expected_total' => 25]);
        $this->assertSame('25.00', $order->total);
    }

    public function test_a_wrong_expected_total_never_lowers_what_is_charged(): void
    {
        $order = $this->place(['expected_total' => null]);
        $this->assertSame('20.00', $order->total);
    }

    // ---- Abuso ----

    public function test_the_global_limit_stops_a_flood_from_many_addresses(): void
    {
        foreach (range(1, OnlineOrder::LIMIT_GLOBAL) as $i) {
            $this->place(['phone' => '86812'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)], '10.1.'.intdiv($i, 250).'.'.($i % 250));
            // Se acepta cada uno para no topar con el máximo de pedidos esperando.
            OnlineOrder::accept(Order::latest('id')->first());
        }

        try {
            $this->place(['phone' => '8689999999'], '10.9.9.9');
            $this->fail('El límite global debió bloquear');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('varios pedidos seguidos', $e->errors()['store'][0]);
        }
    }

    public function test_too_many_orders_waiting_at_once_close_the_door_for_new_ones(): void
    {
        foreach (range(1, OnlineOrder::MAX_WAITING) as $i) {
            Order::create(['source' => 'online', 'status' => OrderStatus::Requested, 'public_token' => (string) Str::uuid(), 'label' => 'Cliente '.$i, 'customer_phone' => '8680000000', 'service' => 'togo', 'sold_at' => now(), 'total' => 20]);
        }

        try {
            $this->place();
            $this->fail('Debió bloquearse');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('muchos pedidos esperando', $e->errors()['store'][0]);
        }
    }

    public function test_requests_nobody_answers_expire_by_themselves_and_the_customer_sees_it(): void
    {
        $order = $this->place();

        $this->travel(OnlineOrder::EXPIRE_MINUTES - 1)->minutes();
        $this->assertSame(0, OnlineOrder::expireStale());
        $this->assertSame(OrderStatus::Requested, $order->fresh()->status);

        $this->travel(2)->minutes();
        $this->get(route('pedido', $order->public_token))->assertSee('No pudimos aceptar tu pedido')->assertSee('Nadie respondió a tiempo');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, Order::accepted()->where('id', $order->id)->count());
        $this->assertFalse(OnlineOrder::accept($order));
    }

    public function test_an_expired_request_is_not_in_the_inbox(): void
    {
        $this->actingAs(User::factory()->create());
        $this->place();
        $this->travel(OnlineOrder::EXPIRE_MINUTES + 1)->minutes();

        Livewire::test('pages::pedidos')->assertDontSee('por aceptar');
    }

    public function test_a_token_of_a_cashier_order_does_not_return_that_order(): void
    {
        $token = (string) Str::uuid();
        $pos = Order::create(['status' => OrderStatus::Paid, 'daily_number' => 1, 'sold_at' => now(), 'paid_at' => now(), 'total' => 50, 'operation_token' => $token]);

        $order = $this->place(['token' => $token]);

        $this->assertNotSame($pos->id, $order->id);
        $this->assertSame('online', $order->source);
    }

    public function test_resending_the_same_token_after_acceptance_returns_the_same_order_without_a_new_one(): void
    {
        $input = $this->input();
        $first = OnlineOrder::place($input, '10.0.0.1');
        OnlineOrder::accept($first);

        $again = OnlineOrder::place($input, '10.0.0.2');

        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, Order::count());
    }

    // ---- Reglas de negocio ----

    public function test_it_cannot_be_ordered_the_moment_the_store_closes_even_with_a_stale_page(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 15)->setTime(23, 59, 30));
        $input = $this->input();
        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(0, 0, 1));

        $this->expectException(ValidationException::class);
        OnlineOrder::place($input, '10.0.0.1');
    }

    public function test_a_sold_out_product_is_refused_by_the_server_even_if_the_page_let_it_through(): void
    {
        $this->taco->update(['sold_out_on' => today()]);

        try {
            $this->place();
            $this->fail('Un producto agotado no se debe poder pedir');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Se acabó hoy: Taco de bistec', $e->errors()['lines'][0]);
        }
        $this->assertSame(0, Order::count());

        $this->taco->update(['sold_out_on' => today()->subDay()]);
        $this->assertSame(1, $this->place()->items->count());
    }

    public function test_invalid_attempts_also_count_against_the_limit(): void
    {
        foreach (range(1, OnlineOrder::LIMIT_ATTEMPTS) as $i) {
            try {
                $this->place(['phone' => 'mal'], '10.7.7.7');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('phone', $e->errors());
            }
        }

        try {
            $this->place(['phone' => 'mal'], '10.7.7.7');
            $this->fail('Debió bloquear por demasiados intentos');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('demasiados intentos', $e->errors()['store'][0]);
        }

        // Otro equipo no se ve afectado.
        $this->assertSame(1, $this->place([], '10.8.8.8')->items->count());
    }

    public function test_a_request_cannot_be_accepted_after_it_expired(): void
    {
        $order = $this->place();
        $this->travel(OnlineOrder::EXPIRE_MINUTES + 1)->minutes();

        $this->assertFalse(OnlineOrder::accept($order));

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertSame('Nadie respondió a tiempo', $order->cancel_reason);
        $this->assertNull($order->daily_number);
    }

    public function test_the_tracking_link_stops_working_a_day_after_the_order_ended(): void
    {
        $order = $this->place();
        OnlineOrder::accept($order);
        $this->get(route('pedido', $order->public_token))->assertOk();

        $order->refresh()->update(['delivered_at' => now(), 'status' => OrderStatus::Paid, 'payment_method' => 'cash', 'paid_at' => now()]);
        $this->travel(23)->hours();
        $this->get(route('pedido', $order->public_token))->assertOk()->assertSee('Tu pedido fue entregado');

        $this->travel(2)->hours();
        $this->get(route('pedido', $order->public_token))->assertNotFound();
    }

    public function test_a_rejected_request_link_also_expires(): void
    {
        $order = $this->place();
        OnlineOrder::reject($order, 'Se acabó algún producto');
        $this->get(route('pedido', $order->public_token))->assertOk();

        $this->travel(25)->hours();
        $this->get(route('pedido', $order->public_token))->assertNotFound();
    }

    public function test_contact_data_of_old_online_orders_is_erased_but_the_sale_is_kept(): void
    {
        Setting::put('online_delivery', '1');
        $old = $this->place(['name' => 'Ana', 'service' => 'delivery', 'address' => 'Calle Secreta 12', 'references' => 'Portón negro']);
        OnlineOrder::accept($old);
        $this->travel(OnlineOrder::KEEP_CONTACT_DAYS + 1)->days();
        $recent = Order::create(['source' => 'online', 'status' => OrderStatus::Requested, 'public_token' => (string) Str::uuid(), 'label' => 'Reciente', 'customer_phone' => '8681112222', 'service' => 'togo', 'sold_at' => now(), 'total' => 20]);

        OnlineOrder::expireStale();

        $old->refresh();
        $this->assertNull($old->customer_phone);
        $this->assertNull($old->delivery_phone);
        $this->assertNull($old->delivery_address);
        $this->assertNull($old->delivery_notes);
        $this->assertSame('Ana', $old->label);
        $this->assertSame('20.00', $old->total);
        $this->assertSame('8681112222', $recent->fresh()->customer_phone);
    }

    public function test_sales_restore_cannot_reopen_a_rejected_online_request(): void
    {
        $this->actingAs(User::factory()->create());
        $order = $this->place();
        OnlineOrder::reject($order, 'Se acabó algún producto');

        Livewire::test('pages::ventas')->call('restore', $order->id);

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, Order::pending()->count());
    }

    public function test_accept_and_reject_only_work_on_online_requests(): void
    {
        $this->actingAs(User::factory()->create());
        $paid = Order::create(['status' => OrderStatus::Paid, 'daily_number' => 3, 'sold_at' => now(), 'paid_at' => now(), 'total' => 50]);
        $open = Order::create(['status' => OrderStatus::Open, 'daily_number' => 4, 'sold_at' => now(), 'total' => 50]);

        Livewire::test('pages::pedidos')->call('accept', $paid->id)->call('reject', $paid->id, 'x')->call('accept', $open->id)->call('reject', $open->id, 'x')->call('accept', 999999);

        $this->assertSame(OrderStatus::Paid, $paid->fresh()->status);
        $this->assertSame(OrderStatus::Open, $open->fresh()->status);
    }

    public function test_the_reject_reason_is_trimmed_cleaned_and_limited(): void
    {
        $order = $this->place();

        OnlineOrder::reject($order, str_repeat("a\u{200B}", 500));

        $this->assertSame(120, mb_strlen($order->fresh()->cancel_reason));
        $this->assertStringNotContainsString("\u{200B}", $order->fresh()->cancel_reason);
    }

    public function test_rejected_and_customer_cancelled_requests_leave_no_trace_in_sales_cash_or_kitchen(): void
    {
        $this->actingAs(User::factory()->create());
        $rejected = $this->place(['name' => 'Rechazado Uno']);
        OnlineOrder::reject($rejected, 'Se acabó algún producto');
        $cancelled = $this->place(['name' => 'Cancelado Dos', 'phone' => '8689999999']);
        OnlineOrder::cancelByCustomer($cancelled);

        foreach (['ventas', 'cocina', 'corte'] as $page) {
            Livewire::test('pages::'.$page)->assertDontSee('Rechazado Uno')->assertDontSee('Cancelado Dos');
        }
    }

    public function test_the_phone_is_not_leaked_by_anything_a_stranger_can_open(): void
    {
        $this->place(['phone' => '8685550123']);

        $this->get('/')->assertDontSee('8685550123');
        $this->get(route('login'))->assertDontSee('8685550123');
    }
}
