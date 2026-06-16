<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Sponsor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsor>
 */
class SponsorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = CarbonImmutable::now()->startOfDay();

        return [
            'name' => $this->faker->company(),
            'title' => $this->faker->optional()->sentence(4),
            'description' => $this->faker->optional()->paragraph(),
            'call_to_action' => $this->faker->optional()->url(),
            'start_date' => $startDate,
            'end_date' => $startDate->addDays(14),
            'is_active' => true,
        ];
    }
}
