<?php

use App\Events\OrdersUpdated;
use App\Jobs\CompleteExpiredReadyOrders;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([OrdersUpdated::class]);
});

it('completes orders that have been ready for 30 minutes and deletes their push subscriptions', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Ready,
        'ready_at' => now()->subMinutes(30),
    ]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/subscription-1',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    (new CompleteExpiredReadyOrders)->handle();

    expect($order->pushSubscriptions()->count())->toBe(0)
        ->and($order->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($order->fresh()->completed_at)->not->toBeNull();

    Event::assertDispatchedTimes(OrdersUpdated::class, 1);
});

it('keeps push subscriptions for orders that are not ready', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Pending,
        'updated_at' => now()->subMinutes(30),
    ]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/subscription-2',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    (new CompleteExpiredReadyOrders)->handle();

    expect($order->pushSubscriptions()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);

    Event::assertNotDispatched(OrdersUpdated::class);
});

it('keeps orders on the display that have been ready for less than 30 minutes', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Ready,
        'ready_at' => now()->subMinutes(29),
    ]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/subscription-3',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    (new CompleteExpiredReadyOrders)->handle();

    expect($order->pushSubscriptions()->count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Ready);

    Event::assertNotDispatched(OrdersUpdated::class);
});
