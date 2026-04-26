<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    public function run()
    {
        $products = [
            ['name' => 'Flash Energy', 'category' => 'Drinks', 'price' => 150],
            ['name' => 'Adrenaline Rush', 'category' => 'Drinks', 'price' => 180],
            ['name' => 'Snickers Super', 'category' => 'Food', 'price' => 90],
            ['name' => 'Lays STAX', 'category' => 'Food', 'price' => 210],
        ];

        foreach ($products as $product) {
            DB::table('products')->updateOrInsert(
                ['name' => $product['name']],
                array_merge($product, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
