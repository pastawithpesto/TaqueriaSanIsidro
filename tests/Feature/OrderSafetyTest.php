<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\OrderBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(today()->setTime(12, 0));
        $this->product = Product::create(['name' => 'Torta', 'price' => 100]);
    }

    protected function form()
    {
        return Livewire::test('pages::vender')->set('label', 'Ana')
            ->set('lines', [['product_id' => $this->product->id, 'qty' => 1]]);
    }

    protected function openOrder(): Order
    {
        $this->form()->call('saveOpen')->assertReturned(['ok' => true]);

        return Order::sole();
    }

    public function test_client_packaging_fee_cannot_reduce_a_new_or_saved_order(): void
    {
        Setting::put('packaging_fee', '7');
        $this->form()->set('service', 'togo')->set('savedFee', -90)->call('saveOpen')->assertReturned(['ok' => true]);
        $order = Order::sole();
        $this->assertSame('107.00', $order->total);

        Setting::put('packaging_fee', '99');
        Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')
            ->set('savedFee', -90)->call('charge')->assertReturned(['ok' => true]);
        $this->assertSame('7.00', $order->fresh()->packaging_fee);
        $this->assertSame('107.00', $order->fresh()->total);
        $this->assertSame('0.00', $order->fresh()->discount);
    }

    public function test_invalid_packaging_settings_never_produce_negative_or_non_finite_fees(): void
    {
        foreach (['-90', '1e999', 'NaN'] as $fee) {
            Setting::put('packaging_fee', $fee);
            $this->form()->set('service', 'togo')->call('charge')->assertReturned(['ok' => true]);
        }
        $this->assertSame(['100.00', '100.00', '100.00'], Order::orderBy('id')->pluck('total')->all());
    }

    public static function writes(): array
    {
        return [['charge'], ['saveOpen']];
    }

    #[DataProvider('writes')]
    public function test_replaying_a_form_returns_the_same_result(string $action): void
    {
        $form = $this->form();
        $token = $form->get('operationToken');
        $form->call($action)->assertReturned(['ok' => true]);
        $order = Order::sole();
        $paymentIds = $order->payments()->pluck('id')->all();

        // Otra petición conserva el token original aunque la respuesta anterior se haya perdido.
        $this->form()->set('operationToken', $token)->call($action)->assertReturned(['ok' => true]);
        $this->assertSame(1, Order::count());
        $this->assertSame($paymentIds, $order->payments()->pluck('id')->all());
        $this->assertSame($token, $order->operation_token);
        $this->assertSame(1, DB::table('order_operations')->where('token', $token)->count());
    }

    public function test_a_charge_of_a_saved_order_is_also_idempotent(): void
    {
        $order = $this->openOrder();
        $form = Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender');
        $token = $form->get('operationToken');
        $form->call('charge')->assertReturned(['ok' => true]);
        $paymentId = $order->payments()->sole()->id;
        $this->form()->set('operationToken', $token)->call('charge')->assertReturned(['ok' => true])->assertSet('lastOrderId', $order->id);
        $this->assertSame(1, Order::count());
        $this->assertSame($paymentId, $order->payments()->sole()->id);
    }

    public function test_correction_uses_the_original_number_and_a_persistent_unique_link(): void
    {
        $form = $this->form()->call('charge')->call('undo');
        $original = Order::sole();
        $form->set('dailyNumber', 900)->call('charge')->assertReturned(['ok' => true]);
        $replacement = Order::where('status', OrderStatus::Paid)->sole();
        $this->assertSame($original->id, $replacement->replaces_order_id);
        $this->assertSame($original->daily_number, $replacement->daily_number);

        // El vínculo sigue protegiendo la original aunque la sustituta se cancele o cambie de número.
        $replacement->update(['daily_number' => 901]);
        $replacement->cancel();
        Livewire::test('pages::ventas')->call('restore', $original->id)->assertDispatched('toast', type: 'error');
        $this->assertSame(OrderStatus::Cancelled, $original->fresh()->status);
    }

    public function test_an_unrelated_cancelled_order_is_not_a_valid_correction(): void
    {
        $order = $this->openOrder();
        $order->cancel();
        $this->form()->set('undoneId', $order->id)->set('dailyNumber', $order->daily_number)
            ->call('charge')->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'ya se corrigió'));
        $this->assertSame(1, Order::count());
    }

    public function test_restoring_the_original_invalidates_its_pending_correction(): void
    {
        $form = $this->form()->call('charge')->call('undo');
        $original = Order::sole();
        Livewire::test('pages::ventas')->call('restore', $original->id);
        $form->call('charge')->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'ya se corrigió'));
        $this->assertSame(1, Order::count());
        $this->assertSame(OrderStatus::Paid, $original->fresh()->status);
    }

    public function test_a_second_correction_cannot_replace_the_same_original(): void
    {
        $form = $this->form()->call('charge')->call('undo');
        $original = Order::sole();
        $form->call('charge')->assertReturned(['ok' => true]);
        $this->form()->set('undoneId', $original->id)->call('saveOpen')
            ->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'ya se corrigió'));
        $this->assertSame(2, Order::count());
    }

    #[DataProvider('writes')]
    public function test_stale_forms_cannot_overwrite_an_open_order_even_in_the_same_second(string $action): void
    {
        $order = $this->openOrder();
        $tablet = Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender');
        Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')
            ->set('lines.0.qty', 2)->set('label', 'Ana actualizado')->call('saveOpen')->assertReturned(['ok' => true]);
        $tablet->call($action)->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'Recarga'));
        $this->assertSame('200.00', $order->fresh()->total);
        $this->assertSame('Ana actualizado', $order->fresh()->label);
        $this->assertSame(2, $order->items()->sole()->quantity);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        $this->assertSame(0, $order->payments()->count());
    }

    public function test_cancel_from_a_stale_queue_cannot_cancel_a_paid_order(): void
    {
        $order = $this->openOrder();
        $queue = Livewire::test('pages::pedidos');
        Livewire::withQueryParams(['orden' => $order->id])->test('pages::vender')->call('charge');
        $queue->call('cancel', $order->id)->assertDispatched('toast', type: 'error');
        $this->assertSame(OrderStatus::Paid, $order->fresh()->status);
        $this->assertSame('100.00', $order->payments()->sole()->amount);
    }

    public function test_a_charge_between_the_cancel_read_and_write_cannot_leave_a_cancelled_paid_order(): void
    {
        $order = $this->openOrder();
        $queue = Livewire::test('pages::pedidos');
        $interleaved = false;
        DB::listen(function ($query) use ($order, &$interleaved) {
            if ($interleaved || ! (str_starts_with($query->sql, 'update "orders"')
                || (str_starts_with($query->sql, 'select * from "orders"') && str_contains($query->sql, '"orders"."id"')))) {
                return;
            }
            $interleaved = true;
            // Reproduce el otro cobro justo después de leer o de actualizar, antes de volver a la acción.
            $charged = DB::table('orders')->where('id', $order->id)->where('status', 'open')
                ->update(['status' => 'paid', 'paid_at' => now(), 'payment_method' => 'cash']);
            if ($charged) {
                DB::table('order_payments')->insert([
                    'order_id' => $order->id, 'method' => 'cash', 'amount' => 100,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        $queue->call('cancel', $order->id);
        $this->assertTrue($interleaved);
        $current = $order->fresh();
        $this->assertSame($current->status === OrderStatus::Paid ? 1 : 0, $current->payments()->count());
        $this->assertSame($current->status === OrderStatus::Paid, $current->paid_at !== null);
    }

    public function test_cancelled_orders_reject_all_kitchen_and_delivery_transitions(): void
    {
        $order = $this->openOrder();
        $order->update(['service' => 'delivery', 'ready_at' => now(), 'dispatched_at' => now(), 'delivered_at' => now()]);
        $order->cancel();
        $before = $order->fresh()->getAttributes();
        Livewire::test('pages::cocina')->call('markReady', $order->id)->call('unready', $order->id);
        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id)->call('deliver', $order->id)->call('undeliver', $order->id);
        $this->assertSame($before, $order->fresh()->getAttributes());

        $order->update(['ready_at' => null, 'dispatched_at' => null, 'delivered_at' => null]);
        Livewire::test('pages::cocina')->call('markReady', $order->id);
        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id)->call('deliver', $order->id);
        $this->assertNull($order->fresh()->ready_at);
        $this->assertNull($order->fresh()->dispatched_at);
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_valid_delivery_transitions_preserve_delivery_without_payment(): void
    {
        $order = $this->openOrder();
        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id)->assertDispatched('toast', type: 'error');
        $this->assertNull($order->fresh()->dispatched_at);
        $order->update(['service' => 'delivery']);
        Livewire::test('pages::pedidos')->call('deliver', $order->id)->assertDispatched('toast', type: 'error');
        $this->assertNull($order->fresh()->delivered_at);
        Livewire::test('pages::pedidos')->call('dispatchOrder', $order->id)->call('deliver', $order->id);
        $this->assertNotNull($order->fresh()->ready_at);
        $this->assertNotNull($order->fresh()->dispatched_at);
        $this->assertNotNull($order->fresh()->delivered_at);
        $this->assertSame(OrderStatus::Open, $order->fresh()->status);
        Livewire::test('pages::pedidos')->call('undeliver', $order->id);
        $this->assertNull($order->fresh()->delivered_at);
    }

    public function test_kitchen_cannot_reopen_dispatched_or_delivered_orders(): void
    {
        $order = $this->openOrder();
        $order->update(['service' => 'delivery', 'ready_at' => now(), 'dispatched_at' => now()]);
        Livewire::test('pages::cocina')->call('unready', $order->id);
        $this->assertNotNull($order->fresh()->ready_at);
        $order->update(['ready_at' => null]);
        Livewire::test('pages::cocina')->call('markReady', $order->id);
        $this->assertNull($order->fresh()->ready_at);
        $order->update(['dispatched_at' => null, 'delivered_at' => now()]);
        Livewire::test('pages::cocina')->call('markReady', $order->id);
        $this->assertNull($order->fresh()->ready_at);
    }

    public static function malformedLines(): array
    {
        return [
            'notas' => [['notes' => ['texto']]],
            'producto' => [['product_id' => ['1']]],
            'cantidad' => [['qty' => ['2']]],
            'extras' => [['extras' => [['id' => 1]]]],
            'sin' => [['without' => '1']],
            'opciones' => [['choices' => [null]]],
            'cortesía' => [['courtesy' => ['sí']]],
        ];
    }

    #[DataProvider('malformedLines')]
    public function test_malformed_nested_lines_return_a_readable_error(array $bad): void
    {
        $lines = [array_replace(['product_id' => $this->product->id, 'qty' => 1], $bad)];
        $this->assertArrayHasKey('error', OrderBuilder::items($lines));
        foreach (['charge', 'saveOpen'] as $action) {
            $this->form()->set('lines', $lines)->call($action)
                ->assertReturned(fn ($r) => $r['ok'] === false && str_contains($r['error'], 'Revisa el pedido'));
        }
        $this->assertSame(0, Order::count());
    }

    public function test_excessive_line_counts_and_totals_are_rejected_before_writing(): void
    {
        $this->form()->set('lines', array_fill(0, 201, ['product_id' => $this->product->id, 'qty' => 1]))
            ->call('charge')->assertReturned(fn ($r) => ! $r['ok'] && isset($r['error']));
        $this->product->update(['price' => 999999.99]);
        foreach (['charge', 'saveOpen'] as $action) {
            $this->form()->set('lines', array_fill(0, 2, ['product_id' => $this->product->id, 'qty' => 60]))
                ->call($action)->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'demasiado alto'));
            $this->form()->set('lines', [['product_id' => $this->product->id, 'qty' => 100]])
                ->set('service', 'delivery')->set('address', 'Calle 1')->set('deliveryFee', '2')
                ->call($action)->assertReturned(fn ($r) => ! $r['ok'] && str_contains($r['error'], 'demasiado alto'));
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, OrderPayment::count());
    }

    public function test_discount_rounds_before_computing_the_total_and_full_discounts_warn(): void
    {
        $this->product->update(['price' => 20]);
        $this->form()->set('discount', '15.555')->call('charge')->assertReturned(['ok' => true]);
        $order = Order::sole();
        $this->assertSame('15.56', $order->discount);
        $this->assertSame('4.44', $order->total);
        $this->form()->set('discount', '999')->assertSee('Máximo aplicado:')
            ->assertSee('El descuento cubre todo el pedido.')
            ->call('charge')->assertReturned(['ok' => true]);
        $this->assertSame('0.00', Order::latest('id')->first()->total);
    }

    public function test_percent_discount_is_rounded_to_two_decimals_before_applying_it(): void
    {
        $this->product->update(['price' => 200]);
        $this->form()->set('discountType', 'percent')->set('discount', '15.555')
            ->call('charge')->assertReturned(['ok' => true]);
        $this->assertSame('31.12', Order::sole()->discount);
        $this->assertSame('168.88', Order::sole()->total);
    }

    public function test_replacing_payments_rolls_back_all_changes_on_failure(): void
    {
        $this->form()->call('charge');
        $order = Order::sole();
        $before = $order->payments()->sole()->getAttributes();
        $event = 'eloquent.creating: '.OrderPayment::class;
        app('events')->listen($event, function () {
            throw new RuntimeException('Fallo de prueba');
        });
        try {
            $order->recordPayments('card');
            $this->fail('El fallo debía interrumpir el reemplazo.');
        } catch (RuntimeException $error) {
            $this->assertSame('Fallo de prueba', $error->getMessage());
        } finally {
            app('events')->forget($event);
        }
        $this->assertSame('cash', $order->fresh()->payment_method->value);
        $this->assertSame($before, $order->payments()->sole()->getAttributes());
    }

    public function test_record_payments_rechecks_the_current_state_instead_of_a_stale_model(): void
    {
        $this->form()->call('charge');
        $stale = Order::sole();
        Order::find($stale->id)->cancel();
        $this->expectException(ValidationException::class);
        $stale->recordPayments('card');
    }

    public function test_payment_form_refuses_a_sale_cancelled_after_opening_the_form(): void
    {
        $this->form()->call('charge');
        $order = Order::sole();
        $form = Livewire::test('pages::ventas')->call('editPayment', $order->id)->set('payMethod', 'card');
        $order->cancel();
        $form->call('savePayment')->assertDispatched('toast', type: 'error');
        $this->assertSame('cash', $order->payments()->sole()->method->value);
    }
}
