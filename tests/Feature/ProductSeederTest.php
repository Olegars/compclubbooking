<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_bar_catalog_for_shell_store(): void
    {
        $this->seed(ProductSeeder::class);

        $this->assertGreaterThanOrEqual(8, Product::query()->count());
        $this->assertDatabaseHas('products', [
            'name' => 'Энергетик',
            'category' => 'Напитки',
        ]);
        $this->assertDatabaseHas('products', [
            'name' => 'Чипсы',
            'category' => 'Снэки',
        ]);

        $this->getJson('/api/shell/store/products?terminal_id=1')
            ->assertOk()
            ->assertJsonPath('store_enabled', true)
            ->assertJsonCount(Product::query()->where('is_active', true)->count(), 'products');
    }

    public function test_shell_products_omit_inactive(): void
    {
        Product::query()->create([
            'name' => 'Скрытый',
            'category' => 'Снэки',
            'price' => 10,
            'stock' => 5,
            'is_active' => false,
        ]);
        Product::query()->create([
            'name' => 'Витрина',
            'category' => 'Снэки',
            'price' => 20,
            'stock' => 5,
            'is_active' => true,
        ]);

        $this->getJson('/api/shell/store/products?terminal_id=1')
            ->assertOk()
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.name', 'Витрина');
    }

    public function test_shell_products_send_absolute_image_urls(): void
    {
        Product::query()->create([
            'name' => 'Кола',
            'category' => 'Напитки',
            'price' => 100,
            'stock' => 4,
            'is_active' => true,
            'image' => 'images/shop/cola.png',
        ]);
        Product::query()->create([
            'name' => 'Чипсы',
            'category' => 'Снэки',
            'price' => 90,
            'stock' => 4,
            'is_active' => true,
            'image' => '',
        ]);

        $this->getJson('/api/shell/store/products?terminal_id=1')
            ->assertOk()
            ->assertJsonPath('products.0.image', url('/images/shop/cola.png'))
            ->assertJsonPath('products.1.image', url('/'.Product::DEFAULT_IMAGE));
    }
}
