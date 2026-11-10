<?php

namespace Database\Factories;

use App\KitchenDevice;
use App\Models\Product;
use App\ProductCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            // Staff meals are left out of the statistics by default, so tests opt into them explicitly.
            'category' => $this->faker->randomElement(array_filter(ProductCategory::cases(), fn (ProductCategory $category): bool => $category !== ProductCategory::Staff)),
            'device' => KitchenDevice::None,
        ];
    }
}
