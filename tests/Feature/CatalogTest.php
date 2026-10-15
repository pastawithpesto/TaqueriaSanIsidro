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

    public function test_ingredient_search_ignores_accents_and_case(): void
    {
        Ingredient::create(['name' => 'Limón', 'unit' => 'kg', 'cost_per_unit' => 40]);
        Ingredient::create(['name' => 'Cebolla', 'unit' => 'kg', 'cost_per_unit' => 35]);

        Livewire::test('pages::insumos')
            ->set('search', 'LIMON')
            ->assertSet('ingredients', fn ($found) => $found->pluck('name')->all() === ['Limón']);
    }

    public function test_ingredient_used_by_an_extra_is_not_deleted(): void
    {
        $bacon = Ingredient::create(['name' => 'Tocino', 'unit' => 'kg', 'cost_per_unit' => 200]);
        Product::create(['name' => 'Hamburguesa', 'price' => 50])->extras()->create(['name' => 'Tocino', 'price' => 12, 'ingredient_id' => $bacon->id, 'quantity' => 0.02]);

        Livewire::test('pages::insumos')
            ->assertSee('En 1 extra')
            ->call('delete', $bacon->id);

        $this->assertModelExists($bacon);
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

    public function test_product_with_sales_is_hidden_instead_of_deleted(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $order = Order::create(['status' => 'paid', 'payment_method' => 'cash', 'total' => 20, 'sold_at' => now()]);
        $order->items()->create(['product_id' => $taco->id, 'product_name' => 'Taco', 'quantity' => 1, 'unit_price' => 20, 'subtotal' => 20]);

        Livewire::test('pages::productos')->call('delete', $taco->id);

        $this->assertFalse($taco->fresh()->active);
        $this->assertSame($taco->id, $order->items()->sole()->product_id);
    }

    public function test_product_without_sales_is_deleted(): void
    {
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);

        Livewire::test('pages::productos')->call('delete', $taco->id);

        $this->assertModelMissing($taco);
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

    public function test_deleting_the_latest_purchase_restores_the_previous_cost(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 220]);
        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()->subDays(3)]);
        $latest = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1100, 'spent_on' => today()]);

        Livewire::test('pages::gastos')->call('delete', $latest->id);

        $this->assertSame('200.0000', $meat->fresh()->cost_per_unit);
    }

    public function test_moving_a_purchase_to_another_ingredient_recalculates_both_costs(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 0]);
        $pork = Ingredient::create(['name' => 'Pastor', 'unit' => 'kg', 'cost_per_unit' => 0]);
        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()->subDay()]);
        $wrong = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 4, 'total' => 600, 'spent_on' => today()]);

        Livewire::test('pages::gastos')->call('edit', $wrong->id)->set('ingredient_id', $pork->id)->call('save')->assertHasNoErrors();

        $this->assertSame('200.0000', $meat->fresh()->cost_per_unit);
        $this->assertSame('150.0000', $pork->fresh()->cost_per_unit);
    }

    public function test_future_expense_date_is_rejected(): void
    {
        Livewire::test('pages::gastos')
            ->set('category', 'gas')
            ->set('total', '300')
            ->set('spent_on', today()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['spent_on' => 'La fecha no puede ser futura.']);

        $this->assertSame(0, Expense::count());
    }

    public function test_changing_kilos_to_grams_converts_recipes_purchases_and_cost(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);
        $taco->extras()->create(['name' => 'Doble carne', 'price' => 10, 'ingredient_id' => $meat->id, 'quantity' => 0.03]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()]);

        Livewire::test('pages::insumos')->call('edit', $meat->id)->set('unit', 'g')->call('save')->assertHasNoErrors();

        $this->assertSame('g', $meat->fresh()->unit);
        $this->assertSame('0.2000', $meat->fresh()->cost_per_unit);
        $this->assertSame('50.0000', $taco->ingredients()->sole()->pivot->quantity);
        $this->assertSame('30.0000', $taco->extras()->sole()->quantity);
        $this->assertSame('5000.0000', $purchase->fresh()->quantity);
        $this->assertSame(10.0, $taco->fresh()->unitCost());
    }

    public function test_unit_of_an_ingredient_in_use_cannot_change_to_an_unrelated_unit(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($meat, ['quantity' => 0.05]);

        Livewire::test('pages::insumos')
            ->call('edit', $meat->id)
            ->set('unit', 'pza')
            ->call('save')
            ->assertHasErrors(['unit' => 'Ya se usa en recetas o compras en kg. Para medirlo en pza, crea un insumo nuevo.']);

        $this->assertSame('kg', $meat->fresh()->unit);
    }

    public function test_dragging_a_product_reorders_only_its_category(): void
    {
        $bistec = Product::create(['name' => 'Bistec', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 0]);
        $torta = Product::create(['name' => 'Torta', 'category' => 'Tortas', 'price' => 39, 'sort_order' => 1]);
        $pastor = Product::create(['name' => 'Pastor', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 2]);

        Livewire::test('pages::productos')->call('sortProduct', $pastor->id, 0);

        $this->assertSame(['Pastor', 'Torta', 'Bistec'], Product::orderBy('sort_order')->pluck('name')->all());
    }

    public function test_moving_a_product_down_swaps_it_with_the_next_in_its_category(): void
    {
        $bistec = Product::create(['name' => 'Bistec', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 0]);
        Product::create(['name' => 'Pastor', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 1]);

        Livewire::test('pages::productos')->call('move', $bistec->id, 1);

        $this->assertSame(['Pastor', 'Bistec'], Product::orderBy('sort_order')->pluck('name')->all());
    }

    public function test_sell_screen_keeps_each_category_together(): void
    {
        Product::create(['name' => 'Bistec', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 0]);
        Product::create(['name' => 'Torta', 'category' => 'Tortas', 'price' => 39, 'sort_order' => 1]);
        Product::create(['name' => 'Pastor', 'category' => 'Tacos', 'price' => 20, 'sort_order' => 2]);

        $names = Livewire::test('pages::vender')->get('products')->pluck('name')->all();

        $this->assertSame(['Bistec', 'Pastor', 'Torta'], $names);
    }

    public function test_category_typed_in_other_case_reuses_the_existing_one(): void
    {
        Product::create(['name' => 'Bistec', 'category' => 'Tacos', 'price' => 20]);

        Livewire::test('pages::productos')
            ->call('create')
            ->set('name', 'Pastor')
            ->set('price', '22')
            ->set('category', ' tacos ')
            ->call('save');

        $this->assertSame('Tacos', Product::firstWhere('name', 'Pastor')->category);
    }

    public function test_only_product_in_a_category_can_fix_its_capitalization(): void
    {
        $pastor = Product::create(['name' => 'Pastor', 'category' => 'tacos', 'price' => 22]);

        Livewire::test('pages::productos')
            ->call('edit', $pastor->id)
            ->set('category', 'Tacos')
            ->call('save');

        $this->assertSame('Tacos', $pastor->fresh()->category);
    }

    public function test_ingredient_sold_as_is_becomes_a_product_with_it_in_the_recipe(): void
    {
        $soda = Ingredient::create(['name' => 'Refresco 600 ml', 'unit' => 'pza', 'cost_per_unit' => 16]);
        Product::create(['name' => 'Agua', 'category' => 'Bebidas', 'price' => 20]);

        Livewire::test('pages::insumos')
            ->call('startSelling', $soda->id)
            ->assertSet('sellName', 'Refresco 600 ml')
            ->set('sellName', 'Refresco')
            ->set('sellPrice', '25')
            ->set('sellCategory', 'bebidas')
            ->call('sell')
            ->assertHasNoErrors();

        $product = Product::with('ingredients')->firstWhere('name', 'Refresco');
        $this->assertSame('25.00', $product->price);
        $this->assertSame('Bebidas', $product->category);
        $this->assertSame([$soda->id], $product->ingredients->pluck('id')->all());
        $this->assertEquals(16, $product->unitCost());
    }

    public function test_selling_an_ingredient_needs_a_price_and_a_new_name(): void
    {
        $soda = Ingredient::create(['name' => 'Refresco 600 ml', 'unit' => 'pza', 'cost_per_unit' => 16]);
        Product::create(['name' => 'Refresco', 'price' => 25]);

        Livewire::test('pages::insumos')
            ->call('startSelling', $soda->id)
            ->set('sellName', 'Refresco')
            ->call('sell')
            ->assertHasErrors(['sellName' => 'Ya hay un producto con ese nombre.', 'sellPrice']);

        $this->assertSame(1, Product::count());
    }
}
