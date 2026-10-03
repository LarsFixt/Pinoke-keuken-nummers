<?php

declare(strict_types=1);

use App\Events\OrderReady;
use App\Events\OrderReceived;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Support\Facades\Event;

beforeEach(function (): void {
    Event::fake([OrderReceived::class, OrderReady::class]);
});

test('the demo fills the display with orders being prepared and ready orders', function (): void {
    $this->artisan('display:demo', ['--preparing' => 20, '--ready' => 4])->assertSuccessful();

    expect(Order::inPreparation()->count())->toBe(20)
        ->and(Order::ready()->count())->toBe(4);
    Event::assertDispatchedTimes(OrderReceived::class, 24);
});

test('clearing the demo removes only demo orders', function (): void {
    $realOrder = Order::factory()->has(KitchenTicket::factory(), 'kitchenTickets')
        ->create(['number' => '0042', 'status' => OrderStatus::Pending]);
    $this->artisan('display:demo', ['--preparing' => 5, '--ready' => 1])->assertSuccessful();

    $this->artisan('display:demo', ['--clear' => true])->assertSuccessful();

    expect(Order::pluck('id')->all())->toBe([$realOrder->id])
        ->and(KitchenTicket::count())->toBe(1);
});

test('the demo does not run in production without --force', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $this->artisan('display:demo')->assertFailed();

    expect(Order::count())->toBe(0);
});
