<?php

use App\Jobs\PruneCompletedOrders;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;

it('deletes completed orders older than 24 hours', function () {
    $old = Order::factory()->create(['status' => OrderStatus::Completed, 'updated_at' => now()->subDay()->subMinute()]);
    $recent = Order::factory()->create(['status' => OrderStatus::Completed, 'updated_at' => now()->subHours(23)]);

    (new PruneCompletedOrders)->handle();

    expect(Order::find($old->id))->toBeNull();
    expect(Order::find($recent->id))->not->toBeNull();
});

it('does not delete ready or pending orders', function () {
    $ready = Order::factory()->create(['status' => OrderStatus::Ready, 'updated_at' => now()->subDays(2)]);
    $pending = Order::factory()->create(['status' => OrderStatus::Pending, 'updated_at' => now()->subDays(2)]);

    (new PruneCompletedOrders)->handle();

    expect(Order::find($ready->id))->not->toBeNull();
    expect(Order::find($pending->id))->not->toBeNull();
});

it('completes ticketed orders the kitchen never called after three hours', function () {
    $stale = Order::factory()->create(['status' => OrderStatus::Pending, 'updated_at' => now()->subHours(4)]);
    KitchenTicket::factory()->for($stale)->create();
    $fresh = Order::factory()->create(['status' => OrderStatus::Pending, 'updated_at' => now()->subHours(2)]);
    KitchenTicket::factory()->for($fresh)->create();

    (new PruneCompletedOrders)->handle();

    expect($stale->fresh()->status)->toBe(OrderStatus::Completed)
        ->and($fresh->fresh()->status)->toBe(OrderStatus::Pending);
});
