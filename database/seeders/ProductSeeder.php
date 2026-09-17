<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->catalog() as $row) {
            Product::query()->updateOrCreate(
                ['name' => $row['name']],
                [
                    'category' => $row['category'],
                    'price' => $row['price'],
                    'stock' => $row['stock'],
                    'image' => $row['image'] ?? Product::DEFAULT_IMAGE,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return list<array{name:string,category:string,price:float,stock:int,image?:string}>
     */
    private function catalog(): array
    {
        return [
            ['name' => 'Вода 0.5', 'category' => 'Напитки', 'price' => 80, 'stock' => 40],
            ['name' => 'Кола 0.5', 'category' => 'Напитки', 'price' => 120, 'stock' => 30],
            ['name' => 'Спрайт 0.5', 'category' => 'Напитки', 'price' => 120, 'stock' => 24],
            ['name' => 'Энергетик', 'category' => 'Напитки', 'price' => 150, 'stock' => 24],
            ['name' => 'Сок яблоко 0.2', 'category' => 'Напитки', 'price' => 90, 'stock' => 16],
            ['name' => 'Чипсы', 'category' => 'Снэки', 'price' => 130, 'stock' => 20],
            ['name' => 'Сухарики', 'category' => 'Снэки', 'price' => 90, 'stock' => 20],
            ['name' => 'Snickers', 'category' => 'Снэки', 'price' => 90, 'stock' => 30],
            ['name' => 'Twix', 'category' => 'Снэки', 'price' => 90, 'stock' => 24],
            ['name' => 'Орешки', 'category' => 'Снэки', 'price' => 110, 'stock' => 16],
            ['name' => 'Доширак', 'category' => 'Еда', 'price' => 100, 'stock' => 20],
            ['name' => 'Хот-дог', 'category' => 'Еда', 'price' => 180, 'stock' => 10],
        ];
    }
}
