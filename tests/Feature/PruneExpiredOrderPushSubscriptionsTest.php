<?php

use App\Jobs\PruneExpiredOrderPushSubscriptions;
use App\Models\Order;
use App\OrderStatus;

it('deletes push subscriptions for ready orders around 30 minutes old', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Ready,
        'updated_at' => now()->subMinutes(30),
    ]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/subscription-1',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    (new PruneExpiredOrderPushSubscriptions)->handle();

    expect($order->pushSubscriptions()->count())->toBe(0);
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

    (new PruneExpiredOrderPushSubscriptions)->handle();

    expect($order->pushSubscriptions()->count())->toBe(1);
});

it('keeps push subscriptions for orders outside the expiration window', function () {
    $order = Order::factory()->create([
        'status' => OrderStatus::Ready,
        'updated_at' => now()->subMinutes(10),
    ]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/subscription-3',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    (new PruneExpiredOrderPushSubscriptions)->handle();

    expect($order->pushSubscriptions()->count())->toBe(1);
});
