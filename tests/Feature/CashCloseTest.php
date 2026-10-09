<?php

namespace Tests\Feature;

use App\Models\CashClose;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CashCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());

        foreach ([['paid', 'cash', 500], ['paid', 'transfer', 300], ['cancelled', 'cash', 999]] as [$status, $method, $total]) {
            Order::create(['status' => $status, 'payment_method' => $method, 'total' => $total, 'sold_at' => now(), 'paid_at' => now()])
                ->payments()->create(['method' => $method, 'amount' => $total]);
        }
        Expense::create(['category' => 'gas', 'total' => 100, 'spent_on' => today(), 'from_cash' => true]);
        Expense::create(['category' => 'equipment', 'total' => 700, 'spent_on' => today(), 'from_cash' => false]);
    }

    public function test_expected_cash_uses_cash_sales_and_cash_expenses_only(): void
    {
        Livewire::test('pages::corte')
            ->set('opening', '200')
            ->assertSet('cashSales', 500.0)
            ->assertSet('cashExpenses', 100.0)
            ->assertSet('otherSales', 300.0)
            ->assertSet('expected', 600.0);
    }

    public function test_difference_and_save(): void
    {
        Livewire::test('pages::corte')
            ->set('opening', '200')
            ->set('counted', '580')
            ->assertSet('difference', -20.0)
            ->call('save')
            ->assertHasNoErrors();

        $close = CashClose::sole();
        $this->assertSame('600.00', $close->expected);
        $this->assertSame('-20.00', $close->difference);
    }

    public function test_saving_twice_updates_the_same_day(): void
    {
        $component = Livewire::test('pages::corte')->set('counted', '500')->call('save');
        $component->set('counted', '600')->call('save');

        $this->assertSame(1, CashClose::count());
        $this->assertSame('600.00', CashClose::sole()->counted);
    }

    public function test_next_day_inherits_opening_from_last_count(): void
    {
        CashClose::create(['closed_on' => today()->subDay(), 'opening' => 0, 'expected' => 400, 'counted' => 450, 'difference' => 50]);

        Livewire::test('pages::corte')->assertSet('opening', '450');
    }

    public function test_cash_movements_change_the_expected_cash(): void
    {
        Livewire::test('pages::corte')
            ->set('opening', '200')
            ->set('moveType', 'out')->set('moveAmount', '150')->set('moveReason', 'Depósito')->call('addMovement')
            ->set('moveType', 'in')->set('moveAmount', '50')->call('addMovement')
            ->assertSet('movementsNet', -100.0)
            ->assertSet('expected', 500.0); // 200 + 500 - 100 - 100 de gas

        $id = CashMovement::where('type', 'out')->value('id');
        Livewire::test('pages::corte')->call('deleteMovement', $id)->assertSet('movementsNet', 50.0);
    }
}
