<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Insumos, recetas y extras de ejemplo para los productos de la taquería.
 * Costos aproximados de Matamoros (2026); ajústalos con las compras reales.
 *
 * php artisan db:seed --class=DemoRecipesSeeder
 */
class DemoRecipesSeeder extends Seeder
{
    public function run(): void
    {
        $i = collect([
            ['Bistec de res', 'kg', 220],
            ['Carne para hamburguesa', 'kg', 160],
            ['Tortilla de maíz', 'pza', 1.2],
            ['Tortilla de harina', 'pza', 3],
            ['Pan de torta', 'pza', 6],
            ['Pan de hamburguesa', 'pza', 6],
            ['Cebolla', 'kg', 35],
            ['Cilantro', 'paquete', 10],
            ['Salsa roja', 'lt', 60],
            ['Limón', 'kg', 40],
            ['Aguacate', 'kg', 80],
            ['Jitomate', 'kg', 30],
            ['Frijoles refritos', 'kg', 40],
            ['Queso asadero', 'kg', 180],
            ['Lechuga', 'kg', 30],
            ['Mayonesa', 'kg', 70],
            ['Tocino', 'kg', 200],
            ['Papas fritas', 'kg', 60],
            ['Refresco 600 ml', 'pza', 16],
        ])->mapWithKeys(fn ($row) => [$row[0] => Ingredient::updateOrCreate(
            ['name' => $row[0]],
            ['unit' => $row[1], 'cost_per_unit' => $row[2], 'active' => true],
        )]);

        // [insumo, cantidad por pieza, se puede pedir sin]
        $recipes = [
            'Taco de bistec' => [
                ['Bistec de res', 0.04, false], ['Tortilla de maíz', 2, false], ['Cebolla', 0.01, true],
                ['Cilantro', 0.03, true], ['Salsa roja', 0.015, true], ['Limón', 0.01, true],
            ],
            'Torta' => [
                ['Pan de torta', 1, false], ['Bistec de res', 0.08, false], ['Frijoles refritos', 0.03, true],
                ['Aguacate', 0.03, true], ['Jitomate', 0.03, true], ['Cebolla', 0.01, true], ['Mayonesa', 0.01, true],
            ],
            'Pirata' => [
                ['Tortilla de harina', 1, false], ['Bistec de res', 0.08, false], ['Queso asadero', 0.04, true],
                ['Aguacate', 0.03, true], ['Cebolla', 0.01, true], ['Cilantro', 0.03, true],
            ],
            'Hamburguesa' => [
                ['Pan de hamburguesa', 1, false], ['Carne para hamburguesa', 0.12, false], ['Queso asadero', 0.02, true],
                ['Lechuga', 0.02, true], ['Jitomate', 0.03, true], ['Cebolla', 0.01, true], ['Mayonesa', 0.015, true],
            ],
            'Hamburguesa combo' => [
                ['Pan de hamburguesa', 1, false], ['Carne para hamburguesa', 0.12, false], ['Queso asadero', 0.03, true],
                ['Lechuga', 0.02, true], ['Jitomate', 0.03, true], ['Cebolla', 0.01, true], ['Mayonesa', 0.015, true],
                ['Papas fritas', 0.15, false], ['Refresco 600 ml', 1, false],
            ],
        ];

        // [nombre, precio, insumo, cantidad]
        $extras = [
            'Taco de bistec' => [['Extra carne', 10, 'Bistec de res', 0.03], ['Con queso', 8, 'Queso asadero', 0.02]],
            'Torta' => [['Extra queso', 10, 'Queso asadero', 0.03], ['Extra carne', 15, 'Bistec de res', 0.05]],
            'Pirata' => [['Extra queso', 10, 'Queso asadero', 0.03], ['Extra carne', 15, 'Bistec de res', 0.05]],
            'Hamburguesa' => [['Extra carne', 20, 'Carne para hamburguesa', 0.12], ['Tocino', 12, 'Tocino', 0.02]],
            'Hamburguesa combo' => [['Extra carne', 20, 'Carne para hamburguesa', 0.12], ['Tocino', 12, 'Tocino', 0.02]],
        ];

        // [grupo, obligatorio, [[opción, precio extra]]]
        $options = [
            'Hamburguesa combo' => [
                ['Refresco', true, [['Coca-Cola', 0], ['Sprite', 0], ['Fanta', 0], ['Coca-Cola sin azúcar', 0], ['Agua de horchata', 5]]],
            ],
        ];

        foreach ($recipes as $productName => $rows) {
            $product = Product::where('name', $productName)->first();

            if (! $product) {
                continue;
            }

            $product->ingredients()->sync(collect($rows)->mapWithKeys(fn ($r) => [
                $i[$r[0]]->id => ['quantity' => $r[1], 'removable' => $r[2]],
            ])->all());

            $product->optionGroups()->delete();
            foreach ($options[$productName] ?? [] as $g => [$groupName, $required, $choices]) {
                $group = $product->optionGroups()->create(['name' => $groupName, 'required' => $required, 'sort_order' => $g]);
                foreach ($choices as $c => [$choiceName, $price]) {
                    $group->choices()->create(['name' => $choiceName, 'price' => $price, 'sort_order' => $c]);
                }
            }

            $product->extras()->delete();
            foreach ($extras[$productName] ?? [] as $n => [$name, $price, $ingredient, $qty]) {
                $product->extras()->create([
                    'name' => $name, 'price' => $price, 'ingredient_id' => $i[$ingredient]->id, 'quantity' => $qty, 'sort_order' => $n,
                ]);
            }
        }
    }
}
