<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Ingredient;
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
            ->set('newIngredient', $meat->id)->set('newQuantity', '0.05')->call('addIngredient')
            ->set('newIngredient', $tortilla->id)->set('newQuantity', '2')->call('addIngredient')
            ->assertSet('formCost', 13.0)
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::with('ingredients')->sole();
        $this->assertCount(2, $product->ingredients);
        $this->assertEquals(13.0, $product->unitCost());
    }

    public function test_adding_same_ingredient_twice_replaces_quantity(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);

        Livewire::test('pages::productos')
            ->call('create')
            ->set('newIngredient', $meat->id)->set('newQuantity', '0.05')->call('addIngredient')
            ->set('newIngredient', $meat->id)->set('newQuantity', '0.08')->call('addIngredient')
            ->assertCount('recipe', 1);
    }
}
