<?php

namespace Tests\Feature;

use App\Models\CashClose;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Support\BusinessDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderDateSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
        $this->travelTo(today()->setTime(12, 0));
    }

    public static function invalidDates(): array
    {
        return array_map(fn ($date) => [$date], ['', 'no es fecha', '0000-01-01', '-9999999999 years', '9999-12-31', '1999-12-31', '2026-02-30', '2026-1-01']);
    }

    #[DataProvider('invalidDates')]
    public function test_invalid_dates_fall_back_only_for_reading_and_never_overwrite_today(string $date): void
    {
        Order::create(['status' => 'paid', 'payment_method' => 'cash', 'label' => 'Venta de hoy', 'sold_at' => now(), 'paid_at' => now(), 'total' => 100])
            ->payments()->create(['method' => 'cash', 'amount' => 100]);
        $close = CashClose::create(['closed_on' => BusinessDay::today(), 'opening' => 0, 'expected' => 100, 'counted' => 100, 'difference' => 0]);
        $movement = CashMovement::create(['type' => 'in', 'amount' => 10, 'moved_at' => now()]);
        $this->assertNull(BusinessDay::parseDate($date));

        Livewire::test('pages::ventas')->set('date', $date)->assertSee('Venta de hoy');
        $form = Livewire::test('pages::corte')->set('date', $date)->assertSet('cashSales', 100.0)->set('counted', '999');
        $form->call('save')->assertHasErrors('date');
        $form->set('moveAmount', '20')->call('addMovement')->assertHasErrors('date');
        $form->call('deleteMovement', $movement->id)->assertHasErrors('date');

        $this->assertSame('100.00', $close->fresh()->counted);
        $this->assertSame(1, CashClose::count());
        $this->assertSame(1, CashMovement::count());
    }

    public function test_future_dates_are_rejected_by_every_cash_write(): void
    {
        $movement = CashMovement::create(['type' => 'in', 'amount' => 10, 'moved_at' => now()]);
        $form = Livewire::test('pages::corte')->set('date', BusinessDay::today()->addDay()->toDateString())
            ->set('counted', '100')->set('moveAmount', '10');
        $form->call('save')->assertHasErrors('date');
        $form->call('addMovement')->assertHasErrors('date');
        $form->call('deleteMovement', $movement->id)->assertHasErrors('date');
        $this->assertSame(0, CashClose::count());
        $this->assertSame(1, CashMovement::count());
    }

    public function test_valid_past_dates_can_be_saved_without_changing_today(): void
    {
        $yesterday = BusinessDay::today()->subDay()->toDateString();
        Livewire::test('pages::corte')->set('date', $yesterday)->set('counted', '50')->call('save')->assertHasNoErrors();
        $this->assertSame($yesterday, CashClose::sole()->closed_on->toDateString());
    }

    public function test_movement_deletion_is_limited_to_the_selected_day(): void
    {
        $movement = CashMovement::create(['type' => 'in', 'amount' => 10, 'moved_at' => now()]);
        Livewire::test('pages::corte')->set('date', BusinessDay::today()->subDay()->toDateString())
            ->call('deleteMovement', $movement->id);
        $this->assertNotNull($movement->fresh());
    }
}
