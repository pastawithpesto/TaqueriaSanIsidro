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

    public function test_ingredient_in_a_recipe_is_removed_from_it_when_deleted(): void
    {
        $i = Ingredient::create(['name' => 'Tortilla', 'unit' => 'pza', 'cost_per_unit' => 1]);
        $cheese = Ingredient::create(['name' => 'Queso', 'unit' => 'kg', 'cost_per_unit' => 100]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($i, ['quantity' => 2]);
        $taco->ingredients()->attach($cheese, ['quantity' => 0.05]);

        Livewire::test('pages::insumos')->call('delete', $i->id, 'borrar');

        $this->assertModelMissing($i);
        $this->assertSame([$cheese->id], $taco->ingredients()->pluck('ingredients.id')->all());
        $this->assertSame(5.0, $taco->fresh()->unitCost());
    }

    public function test_ingredient_search_ignores_accents_and_case(): void
    {
        Ingredient::create(['name' => 'Limón', 'unit' => 'kg', 'cost_per_unit' => 40]);
        Ingredient::create(['name' => 'Cebolla', 'unit' => 'kg', 'cost_per_unit' => 35]);

        Livewire::test('pages::insumos')
            ->set('search', 'LIMON')
            ->assertSet('ingredients', fn ($found) => $found->pluck('name')->all() === ['Limón']);
    }

    public function test_ingredient_used_by_an_extra_is_unlinked_from_it_when_deleted(): void
    {
        $bacon = Ingredient::create(['name' => 'Tocino', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $extra = $burger->extras()->create(['name' => 'Tocino', 'price' => 12, 'ingredient_id' => $bacon->id, 'quantity' => 0.02]);

        Livewire::test('pages::insumos')
            ->assertSee('En 1 extra')
            ->call('delete', $bacon->id, ' Borrar ');

        $this->assertModelMissing($bacon);
        $this->assertModelExists($extra);
        $this->assertNull($extra->fresh()->ingredient_id);
        $this->assertNull($extra->fresh()->quantity);
        $this->assertSame(0.0, $extra->fresh()->cost());
    }

    public function test_deleting_an_ingredient_in_use_needs_the_word_borrar_typed(): void
    {
        $i = Ingredient::create(['name' => 'Tortilla', 'unit' => 'pza', 'cost_per_unit' => 1]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($i, ['quantity' => 2]);

        foreach (['', 'si', 'borar'] as $typed) {
            Livewire::test('pages::insumos')->call('delete', $i->id, $typed)->assertHasErrors('deleteConfirm');
        }

        $this->assertModelExists($i);
        $this->assertSame(1, $i->products()->count());
    }

    public function test_an_unused_ingredient_is_deleted_without_typing_anything(): void
    {
        $salt = Ingredient::create(['name' => 'Sal', 'unit' => 'kg', 'cost_per_unit' => 10]);

        Livewire::test('pages::insumos')->call('startDelete', $salt->id)->assertSet('showDelete', false)->call('delete', $salt->id);

        $this->assertModelMissing($salt);
    }

    public function test_the_delete_detail_lists_each_recipe_and_extra_with_its_cost_before_and_after(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $tortilla = Ingredient::create(['name' => 'Tortilla', 'unit' => 'pza', 'cost_per_unit' => 2]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);
        $taco->ingredients()->attach($tortilla, ['quantity' => 2]);
        $bare = Product::create(['name' => 'Carne sola', 'price' => 30]);
        $bare->ingredients()->attach($meat, ['quantity' => 0.1]);
        $taco->extras()->create(['name' => 'Doble carne', 'price' => 10, 'ingredient_id' => $meat->id, 'quantity' => 0.03]);
        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()]);

        $component = Livewire::test('pages::insumos')->call('startDelete', $meat->id)->assertSet('showDelete', true);
        $detail = $component->get('deletion');

        $this->assertSame(1, $detail['purchases']);
        $this->assertSame(['Carne sola', 'Taco', 'Doble carne'], array_column($detail['rows'], 'name'));
        $this->assertSame([20.0, 14.0, 6.0], array_column($detail['rows'], 'before'));
        $this->assertSame([0.0, 4.0, 0.0], array_column($detail['rows'], 'after'));
        $this->assertSame([true, false, false], array_column($detail['rows'], 'empty'));
        $this->assertSame('Extra de Taco', $detail['rows'][2]['kind']);
        $component->assertSee('Se queda sin receta')->assertSee('Escribe BORRAR para confirmar');
    }

    public function test_the_delete_notes_explain_what_happens_to_the_purchases_in_expenses(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($meat, ['quantity' => 0.05]);
        $salt = Ingredient::create(['name' => 'Sal', 'unit' => 'kg', 'cost_per_unit' => 10]);

        $none = Livewire::test('pages::insumos')->call('startDelete', $salt->id);
        $this->assertSame('No se ha registrado ninguna compra de este insumo en Gastos.', $none->effects['returns'][0]['note']);

        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()]);
        Livewire::test('pages::insumos')->call('startDelete', $meat->id)
            ->assertSee('Hay 1 compra registrada en Gastos con este insumo. Elige abajo a qué insumo pasarla; si no eliges ninguno, se queda en Gastos con el nombre del insumo en su descripción.');

        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 2, 'total' => 400, 'spent_on' => today()]);
        Livewire::test('pages::insumos')->call('startDelete', $meat->id)
            ->assertSee('Hay 2 compras registradas en Gastos con este insumo. Elige abajo a qué insumo pasarlas; si no eliges ninguno, se quedan en Gastos con el nombre del insumo en su descripción.');

        Livewire::test('pages::insumos')->call('delete', $meat->id, 'borrar');
        $this->assertSame(2, Expense::whereNull('ingredient_id')->count());
    }

    public function test_purchases_of_a_deleted_ingredient_keep_its_name_and_can_be_linked_again(): void
    {
        $cheese = Ingredient::create(['name' => 'Queso', 'unit' => 'kg', 'cost_per_unit' => 100]);
        $plain = Expense::create(['category' => 'ingredients', 'ingredient_id' => $cheese->id, 'quantity' => 2, 'total' => 360, 'spent_on' => today()]);
        $noted = Expense::create(['category' => 'ingredients', 'ingredient_id' => $cheese->id, 'quantity' => 1, 'total' => 200, 'description' => 'Del mercado', 'spent_on' => today()]);

        Livewire::test('pages::insumos')->call('delete', $cheese->id);

        $this->assertNull($plain->fresh()->ingredient_id);
        $this->assertSame('Queso', $plain->fresh()->description);
        $this->assertSame('Queso: Del mercado', $noted->fresh()->description);

        $other = Ingredient::create(['name' => 'Queso asadero', 'unit' => 'kg', 'cost_per_unit' => 50]);
        Livewire::test('pages::gastos')
            ->call('edit', $plain->id)
            ->set('category', 'ingredients')
            ->set('ingredient_id', $other->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($other->id, $plain->fresh()->ingredient_id);
        $this->assertSame('180.0000', $other->fresh()->cost_per_unit);
    }

    public function test_deleting_an_ingredient_can_move_its_purchases_to_another_one(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $meat2 = Ingredient::create(['name' => 'Bistec de res', 'unit' => 'g', 'cost_per_unit' => 0.2]);
        $pan = Ingredient::create(['name' => 'Pan', 'unit' => 'pza', 'cost_per_unit' => 6]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($meat, ['quantity' => 0.05]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 2, 'total' => 400, 'spent_on' => today()]);

        $component = Livewire::test('pages::insumos')->call('startDelete', $meat->id);
        $this->assertSame([$meat2->id => 'Bistec de res'], $component->get('deletion')['targets']);

        $component->set('moveTo', (string) $pan->id)->call('delete', $meat->id, 'borrar')->assertHasErrors('moveTo');
        $this->assertModelExists($meat);

        $component->set('moveTo', (string) $meat2->id)->call('delete', $meat->id, 'borrar')->assertHasNoErrors();

        $this->assertModelMissing($meat);
        $this->assertSame($meat2->id, $purchase->fresh()->ingredient_id);
        $this->assertSame('2000.0000', $purchase->fresh()->quantity);
        $this->assertSame('0.2000', $meat2->fresh()->cost_per_unit);
        $this->assertNull($purchase->fresh()->description);
    }

    public function test_an_ingredient_is_replaced_by_another_moving_recipes_extras_and_purchases(): void
    {
        $old = Ingredient::create(['name' => 'Cilantro 2', 'unit' => 'pza', 'cost_per_unit' => 10]);
        $new = Ingredient::create(['name' => 'Cilantro', 'unit' => 'pza', 'cost_per_unit' => 8]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($old, ['quantity' => 0.5, 'removable' => true]);
        $taco->ingredients()->attach($new, ['quantity' => 0.25]);
        $torta = Product::create(['name' => 'Torta', 'price' => 30]);
        $torta->ingredients()->attach($old, ['quantity' => 1]);
        $extra = $torta->extras()->create(['name' => 'Más cilantro', 'price' => 3, 'ingredient_id' => $old->id, 'quantity' => 0.5]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $old->id, 'quantity' => 10, 'total' => 120, 'spent_on' => today()]);

        $component = Livewire::test('pages::insumos')->call('startReplace', $old->id);
        $this->assertSame([$new->id => 'Cilantro'], $component->get('replacement')['targets']);

        $component->set('replaceTo', (string) $new->id);
        $rows = $component->get('replacement')['rows'];
        $this->assertSame(['Taco', 'Torta', 'Más cilantro'], array_column($rows, 'name'));
        $this->assertSame([6.0, 8.0, 4.0], array_column($rows, 'after'));

        $component->call('replace', $old->id, 'si')->assertHasErrors('replaceConfirm');
        $this->assertModelExists($old);

        $component->call('replace', $old->id, 'Reemplazar')->assertHasNoErrors();

        $this->assertModelMissing($old);
        $this->assertSame('0.7500', $taco->ingredients()->where('ingredients.id', $new->id)->sole()->pivot->quantity);
        $this->assertSame(1, $taco->ingredients()->count());
        $this->assertSame('1.0000', $torta->ingredients()->sole()->pivot->quantity);
        $this->assertSame($new->id, $torta->ingredients()->sole()->id);
        $this->assertSame($new->id, $extra->fresh()->ingredient_id);
        $this->assertSame($new->id, $purchase->fresh()->ingredient_id);
        $this->assertSame('12.0000', $new->fresh()->cost_per_unit);
    }

    public function test_an_ingredient_cannot_be_replaced_by_one_with_incompatible_units(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        Ingredient::create(['name' => 'Pan', 'unit' => 'pza', 'cost_per_unit' => 6]);
        $pan2 = Ingredient::create(['name' => 'Pan 2', 'unit' => 'pza', 'cost_per_unit' => 6]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($meat, ['quantity' => 0.05]);

        Livewire::test('pages::insumos')
            ->call('startReplace', $meat->id)
            ->assertSet('showReplace', true)
            ->set('replaceTo', (string) $pan2->id)
            ->call('replace', $meat->id, 'reemplazar')
            ->assertHasErrors('replaceTo');

        $this->assertModelExists($meat);
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

    public function test_changing_to_an_unrelated_unit_asks_the_equivalence_before_saving(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        Product::create(['name' => 'Taco', 'price' => 20])->ingredients()->attach($meat, ['quantity' => 0.05]);

        Livewire::test('pages::insumos')
            ->call('edit', $meat->id)
            ->set('unit', 'pza')
            ->call('save')
            ->assertSet('equivalenceFrom', 'kg')
            ->assertSee('Este insumo ya está en 1 receta, medido por kg');

        $this->assertSame('kg', $meat->fresh()->unit);
    }

    public function test_an_unused_ingredient_changes_to_any_unit_without_asking(): void
    {
        $mayo = Ingredient::create(['name' => 'Mayonesa', 'unit' => 'kg', 'cost_per_unit' => 70]);

        Livewire::test('pages::insumos')
            ->call('edit', $mayo->id)
            ->set('unit', 'bote')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('equivalenceFrom', null);

        $this->assertSame('bote', $mayo->fresh()->unit);
        $this->assertSame('70.0000', $mayo->fresh()->cost_per_unit);
    }

    public function test_renaming_the_purchase_unit_keeps_recipes_and_cost(): void
    {
        $cilantro = Ingredient::create(['name' => 'Cilantro', 'unit' => 'paquete', 'cost_per_unit' => 10]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($cilantro, ['quantity' => 0.03]);

        Livewire::test('pages::insumos')
            ->call('edit', $cilantro->id)
            ->set('unit', 'manojo')
            ->call('save')
            ->set('equivalence', '1')
            ->call('save')
            ->assertHasNoErrors();

        $cilantro->refresh();
        $this->assertSame('manojo', $cilantro->unit);
        $this->assertSame('10.0000', $cilantro->cost_per_unit);
        $this->assertSame('0.0300', $taco->ingredients()->sole()->pivot->quantity);
    }

    public function test_the_equivalence_converts_recipes_purchases_and_cost(): void
    {
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'pza', 'cost_per_unit' => 6]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $burger->ingredients()->attach($bread, ['quantity' => 1]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $bread->id, 'quantity' => 16, 'total' => 96, 'spent_on' => today()]);

        Livewire::test('pages::insumos')
            ->call('edit', $bread->id)
            ->set('unit', 'paquete')
            ->call('save')
            ->set('equivalence', '8')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('48.0000', $bread->fresh()->cost_per_unit);
        $this->assertSame('0.1250', $burger->ingredients()->sole()->pivot->quantity);
        $this->assertSame('2.0000', $purchase->fresh()->quantity);
    }

    public function test_using_an_ingredient_by_portion_turns_recipe_fractions_into_portions(): void
    {
        $cilantro = Ingredient::create(['name' => 'Cilantro', 'unit' => 'pza', 'cost_per_unit' => 10]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($cilantro, ['quantity' => 0.1]);

        Livewire::test('pages::insumos')
            ->call('edit', $cilantro->id)
            ->set('use_unit', 'Porción')
            ->set('use_per_unit', '40')
            ->call('save')
            ->assertHasNoErrors();

        $cilantro->refresh();
        $this->assertSame('porción', $cilantro->use_unit);
        $this->assertSame('40.0000', $cilantro->use_per_unit);
        $this->assertSame(0.25, $cilantro->costPerUse());
        $this->assertSame('4.0000', $taco->ingredients()->sole()->pivot->quantity);
        $this->assertSame(1.0, $taco->fresh()->unitCost());
    }

    public function test_buying_by_the_pack_and_using_by_the_piece_keeps_recipes_and_converts_purchases(): void
    {
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'pza', 'cost_per_unit' => 6]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $burger->ingredients()->attach($bread, ['quantity' => 1]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $bread->id, 'quantity' => 16, 'total' => 96, 'spent_on' => today()]);

        Livewire::test('pages::insumos')
            ->call('edit', $bread->id)
            ->set('unit', 'paquete')
            ->set('use_unit', 'pza')
            ->set('use_per_unit', '8')
            ->call('save')
            ->assertHasNoErrors();

        $bread->refresh();
        $this->assertSame('paquete', $bread->unit);
        $this->assertSame('48.0000', $bread->cost_per_unit);
        $this->assertSame('1.0000', $burger->ingredients()->sole()->pivot->quantity);
        $this->assertSame('2.0000', $purchase->fresh()->quantity);
        $this->assertSame(6.0, $burger->fresh()->unitCost());
    }

    public function test_using_kilos_by_the_gram_needs_no_equivalence(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 180]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.04]);
        $taco->extras()->create(['name' => 'Extra carne', 'price' => 10, 'ingredient_id' => $meat->id, 'quantity' => 0.03]);

        Livewire::test('pages::insumos')
            ->call('edit', $meat->id)
            ->set('use_unit', 'g')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1000.0000', $meat->fresh()->use_per_unit);
        $this->assertSame('40.0000', $taco->ingredients()->sole()->pivot->quantity);
        $this->assertSame('30.0000', $taco->extras()->sole()->quantity);
        $this->assertEqualsWithDelta(5.4, $taco->extras()->sole()->cost(), 0.0001);
    }

    public function test_cost_by_the_gram_is_shown_per_100_g_only_when_the_unit_weighs_that_much(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 180, 'use_unit' => 'g', 'use_per_unit' => 1000]);
        $saffron = Ingredient::create(['name' => 'Azafrán', 'unit' => 'paquete', 'cost_per_unit' => 25, 'use_unit' => 'g', 'use_per_unit' => 5]);

        $this->assertSame('$18 por 100 g', $meat->useCostLabel());
        $this->assertSame('$5 por g', $saffron->useCostLabel());
    }

    public function test_a_typed_use_unit_is_saved_as_the_one_already_listed(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 180]);
        $mayo = Ingredient::create(['name' => 'Mayonesa', 'unit' => 'bote', 'cost_per_unit' => 70]);

        Livewire::test('pages::insumos')->call('edit', $meat->id)->set('use_unit', 'Gramos')->call('save')->assertHasNoErrors();
        Livewire::test('pages::insumos')->call('edit', $mayo->id)->set('use_unit', 'cucharadas')->set('use_per_unit', '60')->call('save')->assertHasNoErrors();

        $this->assertSame('g', $meat->fresh()->use_unit);
        $this->assertSame('1000.0000', $meat->fresh()->use_per_unit);
        $this->assertSame('cucharada', $mayo->fresh()->use_unit);
    }

    public function test_a_new_use_unit_is_saved_in_singular(): void
    {
        $butter = Ingredient::create(['name' => 'Mantequilla', 'unit' => 'bote', 'cost_per_unit' => 60]);

        Livewire::test('pages::insumos')->call('edit', $butter->id)->set('use_unit', 'Untadas')->set('use_per_unit', '50')->call('save')->assertHasNoErrors();

        $this->assertSame('untada', $butter->fresh()->use_unit);
    }

    public function test_a_custom_use_unit_must_be_a_word(): void
    {
        $butter = Ingredient::create(['name' => 'Mantequilla', 'unit' => 'bote', 'cost_per_unit' => 60]);

        Livewire::test('pages::insumos')
            ->call('edit', $butter->id)
            ->set('use_unit', 'que es esto?')
            ->set('use_per_unit', '50')
            ->call('save')
            ->assertHasErrors(['use_unit' => 'Escribe solo la unidad, como untada o taza.']);

        $this->assertNull($butter->fresh()->use_unit);
    }

    public function test_using_an_ingredient_in_another_unit_asks_how_many_it_yields(): void
    {
        $butter = Ingredient::create(['name' => 'Mantequilla', 'unit' => 'bote', 'cost_per_unit' => 60]);

        Livewire::test('pages::insumos')
            ->call('edit', $butter->id)
            ->set('use_unit', 'untada')
            ->call('save')
            ->assertHasErrors(['use_per_unit' => 'Escribe cuántas rinde.']);

        $this->assertNull($butter->fresh()->use_unit);
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

    public function test_two_ingredients_cannot_share_a_name(): void
    {
        Ingredient::create(['name' => 'Cebolla', 'unit' => 'kg', 'cost_per_unit' => 35]);

        Livewire::test('pages::insumos')
            ->call('create')
            ->set('name', 'Cebolla ')
            ->set('cost_per_unit', '30')
            ->call('save')
            ->assertHasErrors(['name' => 'Ya hay un insumo con ese nombre.']);

        $this->assertSame(1, Ingredient::count());
    }

    public function test_two_products_cannot_share_a_name(): void
    {
        Product::create(['name' => 'Taco de bistec', 'price' => 20]);

        Livewire::test('pages::productos')
            ->call('create')
            ->set('name', 'Taco de bistec')
            ->set('price', '22')
            ->call('save')
            ->assertHasErrors(['name' => 'Ya hay un producto con ese nombre.']);

        $this->assertSame(1, Product::count());
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

    public function test_selling_an_ingredient_by_the_kilo_asks_how_much_each_sale_uses(): void
    {
        $avocado = Ingredient::create(['name' => 'Aguacate', 'unit' => 'kg', 'cost_per_unit' => 80]);

        Livewire::test('pages::insumos')
            ->call('startSelling', $avocado->id)
            ->assertSet('sellQuantity', '')
            ->set('sellPrice', '25')
            ->call('sell')
            ->assertHasErrors('sellQuantity');

        $this->assertSame(0, Product::count());
    }

    public function test_selling_an_ingredient_already_sold_opens_that_product_to_change_it(): void
    {
        $cheese = Ingredient::create(['name' => 'Queso entero', 'unit' => 'kg', 'cost_per_unit' => 35]);
        $product = Product::create(['name' => 'Queso entero', 'category' => 'Otros', 'price' => 40]);
        $product->ingredients()->attach($cheese, ['quantity' => 1]);

        Livewire::test('pages::insumos')
            ->call('startSelling', $cheese->id)
            ->assertSee('Ya se vende como')
            ->assertSet('sellProductId', $product->id)
            ->assertSet('sellPrice', '40')
            ->assertSet('sellQuantity', '1')
            ->set('sellPrice', '45')
            ->set('sellQuantity', '0.5')
            ->call('sell')
            ->assertHasNoErrors();

        $product->refresh()->load('ingredients');
        $this->assertSame('45.00', $product->price);
        $this->assertEquals(0.5, $product->ingredients->first()->pivot->quantity);
        $this->assertSame(1, Product::count());
    }

    public function test_selling_an_ingredient_already_sold_can_add_another_product(): void
    {
        $cheese = Ingredient::create(['name' => 'Queso entero', 'unit' => 'kg', 'cost_per_unit' => 35]);
        $product = Product::create(['name' => 'Queso entero', 'category' => 'Otros', 'price' => 40]);
        $product->ingredients()->attach($cheese, ['quantity' => 1]);

        Livewire::test('pages::insumos')
            ->call('startSelling', $cheese->id)
            ->call('pickSellProduct', null)
            ->assertSet('sellName', '')
            ->assertSet('sellPrice', null)
            ->assertSet('sellCategory', 'Otros')
            ->set('sellName', 'Medio queso')
            ->set('sellPrice', '22')
            ->set('sellQuantity', '0.5')
            ->call('sell')
            ->assertHasNoErrors();

        $this->assertSame(2, Product::count());
        $this->assertSame('40.00', $product->fresh()->price);
    }

    public function test_the_equivalence_is_asked_with_the_big_unit_first_going_either_way(): void
    {
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'paquete', 'cost_per_unit' => 48]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $burger->ingredients()->attach($bread, ['quantity' => 0.125]);

        Livewire::test('pages::insumos')
            ->call('edit', $bread->id)
            ->set('unit', 'pza')
            ->call('save')
            ->assertSet('equivalence', '')
            ->call('save')
            ->assertHasErrors(['equivalence' => 'Escribe a cuánto equivale.'])
            ->set('equivalence', '8')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('6.0000', $bread->fresh()->cost_per_unit);
        $this->assertSame('1.0000', $burger->ingredients()->sole()->pivot->quantity);
    }

    public function test_an_absurd_equivalence_is_rejected_and_nothing_changes(): void
    {
        $bread = Ingredient::create(['name' => 'Pan', 'unit' => 'paquete', 'cost_per_unit' => 45]);
        $burger = Product::create(['name' => 'Hamburguesa', 'price' => 50]);
        $burger->ingredients()->attach($bread, ['quantity' => 1]);

        foreach (['0.0000001', '5000000', '0', '-3'] as $value) {
            Livewire::test('pages::insumos')
                ->call('edit', $bread->id)
                ->set('unit', 'pza')
                ->call('save')
                ->set('equivalence', $value)
                ->call('save')
                ->assertHasErrors('equivalence');
        }

        $this->assertSame('paquete', $bread->fresh()->unit);
        $this->assertSame('45.0000', $bread->fresh()->cost_per_unit);
        $this->assertSame('1.0000', $burger->ingredients()->sole()->pivot->quantity);
    }

    public function test_a_conversion_that_would_round_a_recipe_to_zero_is_rejected(): void
    {
        $salt = Ingredient::create(['name' => 'Sal', 'unit' => 'g', 'cost_per_unit' => 0.05]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($salt, ['quantity' => 0.04]);

        Livewire::test('pages::insumos')->call('edit', $salt->id)->set('unit', 'kg')->call('save')->assertHasErrors('unit');

        $this->assertSame('g', $salt->fresh()->unit);
        $this->assertSame('0.0400', $taco->ingredients()->sole()->pivot->quantity);
    }

    public function test_only_correcting_the_unit_converts_nothing(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 0.18]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 50]);
        $purchase = Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 2000, 'total' => 360, 'spent_on' => today()]);

        Livewire::test('pages::insumos')
            ->call('edit', $meat->id)
            ->set('unit', 'g')
            ->set('relabel', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('g', $meat->fresh()->unit);
        $this->assertSame('0.1800', $meat->fresh()->cost_per_unit);
        $this->assertSame('50.0000', $taco->ingredients()->sole()->pivot->quantity);
        $this->assertSame('2000.0000', $purchase->fresh()->quantity);
    }

    public function test_the_edit_form_says_how_many_recipes_extras_and_purchases_depend_on_the_ingredient(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);
        $taco = Product::create(['name' => 'Taco', 'price' => 20]);
        $taco->ingredients()->attach($meat, ['quantity' => 0.05]);
        $taco->extras()->create(['name' => 'Doble carne', 'price' => 10, 'ingredient_id' => $meat->id, 'quantity' => 0.03]);
        Expense::create(['category' => 'ingredients', 'ingredient_id' => $meat->id, 'quantity' => 5, 'total' => 1000, 'spent_on' => today()]);

        Livewire::test('pages::insumos')->call('edit', $meat->id)->assertSee('1 receta, 1 extra y 1 compra');
    }

    public function test_names_cannot_repeat_with_other_case_accents_or_invisible_characters(): void
    {
        Ingredient::create(['name' => 'Limón', 'unit' => 'kg', 'cost_per_unit' => 40]);
        Product::create(['name' => 'Taco de bistec', 'price' => 20]);

        foreach (['limon', 'LIMÓN', "Li\u{200B}món"] as $name) {
            Livewire::test('pages::insumos')->set('name', $name)->set('cost_per_unit', '30')->call('save')
                ->assertHasErrors(['name' => 'Ya hay un insumo con ese nombre.']);
        }
        Livewire::test('pages::productos')->set('name', 'TACO  de BISTEC')->set('price', '22')->call('save')
            ->assertHasErrors(['name' => 'Ya hay un producto con ese nombre.']);

        $this->assertSame(1, Ingredient::count());
        $this->assertSame(1, Product::count());
    }

    public function test_an_ingredient_name_is_saved_without_invisible_characters_or_double_spaces(): void
    {
        Livewire::test('pages::insumos')->set('name', "  Queso\u{200B}   asadero\0 ")->set('cost_per_unit', '30')->call('save')->assertHasNoErrors();

        $this->assertSame('Queso asadero', Ingredient::sole()->name);
    }

    public function test_duplicating_a_product_twice_gives_each_copy_its_own_name(): void
    {
        $taco = Product::create(['name' => 'Pastor', 'price' => 20]);

        Livewire::test('pages::productos')->call('duplicate', $taco->id)->call('cancel')->call('duplicate', $taco->id);

        $this->assertEqualsCanonicalizing(['Pastor', 'Pastor (copia)', 'Pastor (copia 2)'], Product::pluck('name')->all());
    }

    public function test_tiny_quantities_are_rejected_instead_of_saved_as_zero(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);

        Livewire::test('pages::productos')
            ->set('name', 'Taco')->set('price', '20')
            ->set('recipe', [['ingredient_id' => $meat->id, 'quantity' => '0.00001', 'removable' => false]])
            ->call('save')
            ->assertHasErrors('recipe.0.quantity');

        Livewire::test('pages::productos')
            ->set('name', 'Taco')->set('price', '20')
            ->set('extras', [['id' => null, 'name' => 'Extra', 'price' => '5', 'ingredient_id' => $meat->id, 'quantity' => '0.00001']])
            ->call('save')
            ->assertHasErrors('extras.0.quantity');

        $cola = Ingredient::create(['name' => 'Coca', 'unit' => 'pza', 'cost_per_unit' => 15]);
        Livewire::test('pages::insumos')->call('startSelling', $cola->id)->set('sellName', 'Coca fría')->set('sellPrice', '25')->set('sellQuantity', '0.00001')
            ->call('sell')->assertHasErrors('sellQuantity');

        $this->assertSame(0, Product::count());
    }

    public function test_a_product_form_with_manipulated_lists_gives_validation_errors_not_a_crash(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 200]);

        Livewire::test('pages::productos')->set('name', 'Taco')->set('price', '20')
            ->set('recipe', [['ingredient_id' => $meat->id, 'quantity' => ['a']], 'texto'])
            ->call('save')->assertHasErrors('recipe.0.quantity');

        Livewire::test('pages::productos')->set('name', 'Taco')->set('price', '20')
            ->set('extras', [['name' => ['a'], 'price' => '1'], ['name' => 'Extra', 'price' => ['1']]])
            ->call('save')->assertHasErrors('extras.0.price');

        Livewire::test('pages::productos')->set('name', 'Taco')->set('price', '20')
            ->set('options', [['name' => 'Refresco', 'choices' => [['name' => 'Coca', 'price' => ['1']]]], 'texto', ['name' => 'x', 'choices' => 'y']])
            ->call('save')->assertHasErrors('options.0.choices.0.price');

        Livewire::test('pages::productos')->set('name', 'Taco')->set('price', '20')->set('sort_order', 99999999999)->call('save');

        $this->assertSame(1, Product::where('name', 'Taco')->count());
        $this->assertLessThanOrEqual(1000000, Product::where('name', 'Taco')->value('sort_order'));
    }

    public function test_a_product_deleted_in_another_device_is_not_recreated_when_saving_the_old_form(): void
    {
        $taco = Product::create(['name' => 'Pastor', 'price' => 20]);

        $form = Livewire::test('pages::productos')->call('edit', $taco->id);
        $taco->delete();
        try {
            $form->set('price', '25')->call('save');
        } catch (\Throwable) {
        }

        $this->assertSame(0, Product::count());
    }

    public function test_a_purchase_whose_unit_cost_does_not_fit_is_rejected_without_saving_it(): void
    {
        $meat = Ingredient::create(['name' => 'Bistec', 'unit' => 'kg', 'cost_per_unit' => 180]);

        foreach ([['0.0001', '9999999'], ['999999', '0.01']] as [$quantity, $total]) {
            Livewire::test('pages::gastos')
                ->set('category', 'ingredients')->set('ingredient_id', $meat->id)->set('quantity', $quantity)->set('total', $total)
                ->call('save')
                ->assertHasErrors('quantity');
        }

        Livewire::test('pages::gastos')
            ->set('category', 'ingredients')->set('ingredient_id', $meat->id)->set('quantity', '0.00001')->set('total', '10')
            ->call('save')
            ->assertHasErrors('quantity');

        $this->assertSame(0, Expense::count());
        $this->assertSame('180.0000', $meat->fresh()->cost_per_unit);
    }
}
