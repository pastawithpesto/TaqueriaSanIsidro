<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_ingredient_purchase_updates_unit_cost(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 100]);

        Livewire::test('pages::gastos')
            ->set('category', 'ingredients')
            ->set('ingredient_id', $meat->id)
            ->set('quantity', '5')
            ->set('total', '950')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('190.0000', $meat->fresh()->cost_per_unit);
        $this->assertSame(1, Expense::count());
    }

    public function test_ingredient_purchase_requires_quantity(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg']);

        Livewire::test('pages::gastos')
            ->set('ingredient_id', $meat->id)
            ->set('total', '950')
            ->call('save')
            ->assertHasErrors('quantity');
    }

    public function test_non_ingredient_expense_does_not_need_quantity(): void
    {
        Livewire::test('pages::gastos')
            ->set('category', 'gas')
            ->set('total', '400')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Expense::count());
    }

    public function test_ingredient_crud(): void
    {
        Livewire::test('pages::insumos')
            ->set('name', 'Cilantro')
            ->set('unit', 'paquete')
            ->set('cost_per_unit', '8')
            ->call('save')
            ->assertHasNoErrors();

        $i = Ingredient::sole();
        $this->assertSame('Cilantro', $i->name);

        Livewire::test('pages::insumos')->call('toggle', $i->id);
        $this->assertFalse($i->fresh()->active);

        Livewire::test('pages::insumos')->call('delete', $i->id);
        $this->assertSame(0, Ingredient::count());
    }

    public function test_ingredient_in_a_recipe_is_not_deleted(): void
    {
        $i = Ingredient::create(['name' => 'Tortilla', 'unit' => 'pza', 'cost_per_unit' => 1]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($i, ['quantity' => 2]);

        Livewire::test('pages::insumos')->call('delete', $i->id);

        $this->assertSame(1, Ingredient::count());
    }

    public function test_product_with_recipe_is_saved_and_costed(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $tortilla = Ingredient::create(['name' => 'Tortilla', 'unit' => 'pza', 'cost_per_unit' => 1.5]);

        Livewire::test('pages::productos')
            ->call('create')
            ->set('name', 'Taco de bistec')
            ->set('category', 'Tacos')
            ->set('price', '20')
            ->set('recipe', [
                ['ingredient_id' => $meat->id, 'quantity' => '0.05'],
                ['ingredient_id' => $tortilla->id, 'quantity' => '2'],
            ])
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::with('ingredients')->sole();
        $this->assertCount(2, $product->ingredients);
        $this->assertEquals(13.0, $product->unitCost());
    }

    public function test_duplicate_copies_product_and_recipe(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $taco = Product::create(['name' => 'Taco de bistec', 'category' => 'Tacos', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);

        Livewire::test('pages::productos')
            ->call('duplicate', $taco->id)
            ->assertSet('showForm', true)
            ->assertSet('name', 'Taco de bistec (copia)');

        $copy = Product::where('name', 'Taco de bistec (copia)')->with('ingredients')->sole();
        $this->assertSame('Tacos', $copy->category);
        $this->assertEquals(10.0, $copy->unitCost());
    }

    public function test_expense_can_be_marked_as_not_from_cash(): void
    {
        Livewire::test('pages::gastos')
            ->set('category', 'equipment')
            ->set('total', '500')
            ->set('from_cash', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(Expense::sole()->from_cash);
    }

    public function test_recipe_quantities_must_be_positive(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);

        Livewire::test('pages::productos')
            ->call('create')
            ->set('name', 'Taco')
            ->set('price', '20')
            ->set('recipe', [['ingredient_id' => $meat->id, 'quantity' => '0']])
            ->call('save')
            ->assertHasErrors('recipe.0.quantity');
    }

    public function test_product_can_be_deleted_and_past_sales_keep_their_name(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $order = Order::create(['status' => 'paid', 'payment_method' => 'cash', 'total' => 20, 'sold_at' => now()]);
        $order->items()->create(['product_id' => $taco->id, 'product_name' => 'Taco', 'quantity' => 1, 'unit_price' => 20, 'subtotal' => 20]);

        Livewire::test('pages::productos')->call('delete', $taco->id);

        $this->assertSame(0, Product::count());
        $this->assertSame('Taco', $order->items()->sole()->product_name);
    }

    public function test_expense_can_be_edited(): void
    {
        $expense = Expense::create(['category' => 'gas', 'total' => 400, 'spent_on' => today()]);

        Livewire::test('pages::gastos')
            ->call('edit', $expense->id)
            ->assertSet('total', '400')
            ->set('total', '450')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, Expense::count());
        $this->assertSame('450.00', $expense->fresh()->total);
    }

    public function test_editing_an_old_purchase_does_not_override_the_latest_cost(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 0]);
        $old = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 900, 'spent_on' => today()->subDays(3)]);
        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()]);
        $meat->update(['cost_per_unit' => 200]);

        Livewire::test('pages::gastos')->call('edit', $old->id)->set('total', '800')->call('save');

        $this->assertSame('200.0000', $meat->fresh()->cost_per_unit);
    }

    public function test_switching_away_from_ingredients_clears_the_ingredient(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg']);

        Livewire::test('pages::gastos')
            ->set('ingredient_id', $meat->id)
            ->set('quantity', '2')
            ->set('category', 'gas')
            ->set('total', '300')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(Expense::sole()->ingredient_id);
    }
}
