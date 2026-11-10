<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAlias>
 */
class ProductAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'alias' => $this->faker->unique()->words(2, true),
        ];
    }
}
