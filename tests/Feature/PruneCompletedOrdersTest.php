<?php

use App\Jobs\PruneCompletedOrders;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;

it('keeps completed orders and their tickets as history', function () {
    $old = Order::factory()->create(['status' => OrderStatus::Completed, 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);
    $ticket = KitchenTicket::factory()->for($old)->create(['created_at' => now()->subMonth()]);

    (new PruneCompletedOrders)->handle();

    expect(Order::find($old->id))->not->toBeNull()
        ->and(KitchenTicket::find($ticket->id))->not->toBeNull();
});

it('deletes tracked numbers that never got a ticket after three hours', function () {
    $abandoned = Order::factory()->create(['status' => OrderStatus::Pending, 'created_at' => now()->subHours(4)]);
    $waiting = Order::factory()->create(['status' => OrderStatus::Pending, 'created_at' => now()->subHours(2)]);

    (new PruneCompletedOrders)->handle();

    expect(Order::find($abandoned->id))->toBeNull()
        ->and(Order::find($waiting->id))->not->toBeNull();
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
        ->and($stale->fresh()->completed_at)->not->toBeNull()
        ->and($fresh->fresh()->status)->toBe(OrderStatus::Pending);
});

it('completes ticketed orders that came in while the display was off after three hours', function () {
    $hidden = Order::factory()->create(['status' => OrderStatus::Pending, 'is_shown_in_preparation' => false, 'updated_at' => now()->subHours(4)]);
    KitchenTicket::factory()->for($hidden)->create();

    (new PruneCompletedOrders)->handle();

    expect($hidden->fresh()->status)->toBe(OrderStatus::Completed);
});
