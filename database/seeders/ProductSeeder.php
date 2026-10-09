<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $products = [
            ['name' => 'Taco de bistec', 'category' => 'Tacos', 'price' => 20],
            ['name' => 'Torta', 'category' => 'Tortas', 'price' => 39],
            ['name' => 'Pirata', 'category' => 'Piratas', 'price' => 39],
            ['name' => 'Hamburguesa', 'category' => 'Hamburguesas', 'price' => 50],
            ['name' => 'Hamburguesa combo', 'category' => 'Hamburguesas', 'price' => 100],
        ];

        foreach ($products as $i => $product) {
            Product::updateOrCreate(['name' => $product['name']], $product + ['sort_order' => $i]);
        }
    }
}
