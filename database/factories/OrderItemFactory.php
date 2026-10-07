<?php

namespace Database\Factories;

use App\Models\KitchenTicket;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kitchen_ticket_id' => KitchenTicket::factory(),
            'order_id' => null,
            'quantity' => $this->faker->numberBetween(1, 3),
            'name' => $this->faker->randomElement(['Br. Kroket', 'Tosti ham/kaas', 'Tosti kaas', 'Patat']),
            'notes' => [],
        ];
    }
}
