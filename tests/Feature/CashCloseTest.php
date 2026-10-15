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

    public function test_next_day_opening_is_the_fund_kept_at_closing(): void
    {
        Livewire::test('pages::corte')
            ->set('opening', '500')
            ->set('counted', '1000')
            ->set('kept', '400')
            ->call('save')
            ->assertHasNoErrors();
        $this->travel(1)->days();

        Livewire::test('pages::corte')->assertSet('opening', '400');
    }

    public function test_leaving_the_fund_empty_keeps_the_same_fund_as_today(): void
    {
        Livewire::test('pages::corte')->set('opening', '500')->set('counted', '1000')->call('save');

        $this->assertSame('500.00', CashClose::sole()->kept);
    }

    public function test_fund_kept_cannot_be_more_than_the_counted_cash(): void
    {
        Livewire::test('pages::corte')
            ->set('counted', '300')
            ->set('kept', '400')
            ->call('save')
            ->assertHasErrors(['kept' => 'No puede quedarse más de lo que contaste.']);

        $this->assertSame(0, CashClose::count());
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

    public function test_saving_counts_sales_made_after_the_page_was_opened(): void
    {
        $component = Livewire::test('pages::corte')->set('opening', '200');
        Order::create(['status' => 'paid', 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now(), 'paid_at' => now()])
            ->payments()->create(['method' => 'cash', 'amount' => 50]);

        $component->set('counted', '650')->call('save')->assertHasNoErrors();

        $close = CashClose::sole();
        $this->assertSame('650.00', $close->expected);
        $this->assertSame('0.00', $close->difference);
    }

    public function test_saved_close_warns_when_cash_changes_afterwards(): void
    {
        Livewire::test('pages::corte')->set('counted', '400')->call('save');
        Order::create(['status' => 'paid', 'payment_method' => 'cash', 'total' => 50, 'sold_at' => now(), 'paid_at' => now()])
            ->payments()->create(['method' => 'cash', 'amount' => 50]);

        Livewire::test('pages::corte')->assertSee('Después de guardar hubo cambios');
    }

    public function test_previous_closes_leave_out_the_day_being_viewed(): void
    {
        $yesterday = CashClose::create(['closed_on' => today()->subDay(), 'opening' => 0, 'expected' => 400, 'counted' => 400, 'difference' => 0]);
        CashClose::create(['closed_on' => today(), 'opening' => 0, 'expected' => 400, 'counted' => 400, 'difference' => 0]);

        Livewire::test('pages::corte')->assertSet('history', fn ($closes) => $closes->pluck('id')->all() === [$yesterday->id]);
    }

    public function test_unpaid_orders_notice_shows_only_for_today(): void
    {
        Order::create(['daily_number' => 9, 'status' => 'open', 'label' => 'Juan', 'total' => 80, 'sold_at' => now()]);

        Livewire::test('pages::corte')->set('date', today()->subDay()->toDateString())->assertDontSee('sin cobrar');
    }

    public function test_close_lists_the_sales_cancelled_that_day(): void
    {
        Order::where('status', 'cancelled')->update(['cancelled_at' => now()]);

        Livewire::test('pages::corte')
            ->assertSet('cancelled', fn ($orders) => $orders->sum('total') == 999)
            ->assertSee('Se canceló 1 venta');
    }
}
