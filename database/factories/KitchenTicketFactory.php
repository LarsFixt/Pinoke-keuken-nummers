<?php

namespace Database\Factories;

use App\Models\KitchenTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenTicket>
 */
class KitchenTicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = $this->faker->numerify('0###');

        return [
            'external_id' => $this->faker->unique()->sha1(),
            'register' => 3,
            'register_name' => 'Keuken',
            'station' => 'Keuken',
            'ticket_number' => $number,
            'items' => [['qty' => 1, 'name' => 'Br. Kroket', 'notes' => []]],
            'raw_text' => "PRODUCTIEBON: Keuken\n1xBr. Kroket\nKassa 3 Keuken {$number}",
            'ocr_confidence' => 94.0,
            'ticket_number_confidence' => 95.0,
            'warnings' => [],
            'printed_at' => now(),
            'captured_at' => now(),
        ];
    }

    /**
     * A ticket whose number could not be read.
     */
    public function withoutNumber(): static
    {
        return $this->state(fn (): array => [
            'ticket_number' => null,
            'ticket_number_confidence' => null,
            'warnings' => ['NO TICKET NUMBER'],
        ]);
    }
}
