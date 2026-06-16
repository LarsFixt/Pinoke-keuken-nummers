<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AdAsset;
use App\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdAsset>
 */
class AdAssetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sponsor_id' => Sponsor::factory(),
            'file_path' => 'ads/'.$this->faker->uuid().'.avif',
            'target_screen' => $this->faker->randomElement(['both', 'wedstrijdschema', 'kitchen']),
            'is_vertical' => $this->faker->boolean(),
            'duration_seconds' => $this->faker->numberBetween(5, 30),
            'frequency_weight' => $this->faker->numberBetween(1, 5),
        ];
    }
}
