<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    use RefreshDatabase;

    protected Product $taco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->taco = Product::create(['name' => 'Taco', 'price' => 20]);
    }

    protected function sell(array $set, ?array $lines = null): array
    {
        $component = Livewire::test('pages::vender')->set('lines', $lines ?? [['product_id' => $this->taco->id, 'qty' => 5]]);
        foreach ($set as $key => $value) {
            $component->set($key, $value);
        }

        return $component->call('charge')->get('lastOrderId') ? ['ok' => true] : ['ok' => false];
    }

    public function test_tip_is_kept_apart_from_sales(): void
    {
        $this->sell(['tip' => '10']);

        $order = Order::with('payments')->sole();
        $this->assertSame('100.00', $order->total);
        $this->assertSame('10.00', $order->tip);
        $this->assertSame('110.00', $order->payments->sole()->amount);

        $this->assertEquals(100, Livewire::test('pages::resumen')->set('range', 'today')->get('totals')['sales']);
        Livewire::test('pages::corte')->assertSet('cashSales', 110.0)->assertSet('tips', 10.0);
    }

    public function test_discount_by_amount_and_by_percent(): void
    {
        $this->sell(['discount' => '15']);
        $this->sell(['discount' => '10', 'discountType' => 'percent']);
        $this->sell(['discount' => '999']);

        $this->assertSame(['85.00', '90.00', '0.00'], Order::orderBy('id')->pluck('total')->all());
        $this->assertSame(['15.00', '10.00', '100.00'], Order::orderBy('id')->pluck('discount')->all());
    }

    public function test_courtesy_is_free_but_its_cost_counts(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $this->taco->ingredients()->attach($meat, ['quantity' => 0.05]);

        $this->sell([], [
            ['product_id' => $this->taco->id, 'qty' => 3],
            ['product_id' => $this->taco->id, 'qty' => 1, 'courtesy' => true],
        ]);

        $order = Order::with('items')->sole();
        $this->assertSame('60.00', $order->total);
        $gift = $order->items->first(fn ($i) => $i->isCourtesy());
        $this->assertSame('0.00', $gift->subtotal);
        $this->assertSame('10.0000', $gift->unit_cost);
        $this->assertEquals(20.0, $gift->courtesyValue());

        $totals = Livewire::test('pages::resumen')->set('range', 'today')->get('totals');
        $this->assertEquals(40, $totals['recipeCost']);
        $this->assertEquals(20, $totals['discounts']);
    }

    public function test_mixed_payment_is_split(): void
    {
        $this->sell(['method' => 'mixed', 'cashPart' => '30', 'otherMethod' => 'transfer', 'tip' => '10']);

        $order = Order::with('payments')->sole();
        $this->assertSame('mixed', $order->payment_method->value);
        $this->assertSame(['cash' => '30.00', 'transfer' => '80.00'], $order->payments->pluck('amount', 'method.value')->all());

        Livewire::test('pages::corte')->assertSet('cashSales', 30.0)->assertSet('otherSales', 80.0);
    }

    public function test_mixed_payment_needs_a_valid_cash_part(): void
    {
        foreach (['0', '100', ''] as $cash) {
            Livewire::test('pages::vender')
                ->set('lines', [['product_id' => $this->taco->id, 'qty' => 5]])
                ->set('method', 'mixed')->set('cashPart', $cash)
                ->call('charge')
                ->assertReturned(fn ($r) => $r['ok'] === false);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_received_cash_must_cover_total_plus_tip(): void
    {
        Livewire::test('pages::vender')
            ->set('lines', [['product_id' => $this->taco->id, 'qty' => 5]])
            ->set('tip', '10')->set('received', '105')
            ->call('charge')
            ->assertReturned(['ok' => false, 'error' => 'El monto recibido no alcanza para el total.']);
    }
}
