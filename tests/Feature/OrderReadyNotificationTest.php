<?php

use App\Models\Order;
use App\Notifications\OrderReadyNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('opens the tracked order with push utm tags when the notification is clicked', function () {
    $order = Order::factory()->create(['number' => '0042']);

    $message = (new OrderReadyNotification($order))->toWebPush($order, new stdClass)->toArray();

    $expectedUrl = route('track', [
        'order' => '0042',
        'utm_source' => 'push',
        'utm_medium' => 'notification',
        'utm_campaign' => 'order-ready',
    ]);

    expect($message['data']['url'])->toBe($expectedUrl)
        ->and($message['actions'][0]['action'])->toBe($expectedUrl);
});
