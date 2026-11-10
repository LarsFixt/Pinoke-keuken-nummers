<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\Order;
use App\NotificationTiming;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'rating' => $this->faker->numberBetween(1, 5),
            'notification_timing' => $this->faker->randomElement(NotificationTiming::cases()),
            'comment' => null,
            'wait_minutes' => $this->faker->numberBetween(2, 25),
            'notification_mode' => $this->faker->randomElement(['push-ready', 'echo-only', 'echo-only-ios']),
            'platform' => $this->faker->randomElement(['ios', 'android', 'desktop']),
            'display_mode' => 'browser',
            'entry' => 'qr',
        ];
    }

    /**
     * Feedback with a written comment.
     */
    public function withComment(): static
    {
        return $this->state(fn (): array => ['comment' => $this->faker->sentence()]);
    }
}
