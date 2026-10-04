<?php

declare(strict_types=1);

use App\Events\OrdersUpdated;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;
use Livewire\Livewire;

/**
 * The tiles on the board in display order, as number => whether it is highlighted as new.
 *
 * @return array<string, bool>
 */
function preparingTilesOn(string $html): array
{
    preg_match_all('/wire:key="preparing-\d+"\s+x-data="\{ isNew: (true|false) \}".*?>\s*(\d+)\s*</s', $html, $matches, PREG_SET_ORDER);

    return collect($matches)->mapWithKeys(fn (array $match): array => [$match[2] => $match[1] === 'true'])->all();
}

function preparingCountOn(string $html): ?int
{
    return preg_match('/data-test="preparing-count">\s*(\d+)\s*</', $html, $match) ? (int) $match[1] : null;
}

function orderInPreparation(string $number, int $receivedMinutesAgo = 5): Order
{
    return Order::factory()
        ->has(KitchenTicket::factory(), 'kitchenTickets')
        ->create(['number' => $number, 'status' => OrderStatus::Pending, 'updated_at' => now()->subMinutes($receivedMinutesAgo)]);
}

test('the board lists orders being prepared oldest first with their count', function (): void {
    orderInPreparation('0320', receivedMinutesAgo: 1);
    orderInPreparation('0318', receivedMinutesAgo: 9);
    orderInPreparation('0319', receivedMinutesAgo: 4);

    $html = Livewire::test('pages::display')->html();

    expect(array_keys(preparingTilesOn($html)))->toBe(['0318', '0319', '0320'])
        ->and(preparingCountOn($html))->toBe(3);
});

test('the board shows twenty orders and summarises anything beyond twenty-four', function (int $orders, ?string $summary): void {
    foreach (range(1, $orders) as $i) {
        orderInPreparation(sprintf('%04d', 100 + $i), receivedMinutesAgo: 60 - $i);
    }

    $component = Livewire::test('pages::display');

    expect(preparingTilesOn($component->html()))->toHaveCount(min($orders, 24))
        ->and(preparingCountOn($component->html()))->toBe($orders);

    $summary === null
        ? $component->assertDontSee('more')
        : $component->assertSee($summary);
})->with([
    'a busy moment' => [20, null],
    'more than fits' => [27, '+ 3 more'],
]);

test('orders that are ready or have no kitchen ticket are not on the board', function (): void {
    orderInPreparation('0318');
    Order::factory()->has(KitchenTicket::factory(), 'kitchenTickets')->create(['number' => '0400', 'status' => OrderStatus::Ready]);
    Order::factory()->create(['number' => '0555', 'status' => OrderStatus::Pending]);

    expect(preparingTilesOn(Livewire::test('pages::display')->html()))->toBe(['0318' => false]);
});

test('a just received order is highlighted and an older one is not', function (): void {
    $this->freezeTime();
    orderInPreparation('0318', receivedMinutesAgo: 0);
    orderInPreparation('0319', receivedMinutesAgo: 5);

    expect(preparingTilesOn(Livewire::test('pages::display')->html()))->toBe(['0319' => false, '0318' => true]);
});

test('the board stays in place with a zero count when nothing is being prepared', function (): void {
    $component = Livewire::test('pages::display')->assertSeeHtml('data-test="preparing-board"');

    expect(preparingCountOn($component->html()))->toBe(0)
        ->and(preparingTilesOn($component->html()))->toBe([]);
});

test('sponsors get the large view when there are no orders at all', function (): void {
    Livewire::test('pages::display')
        ->assertSeeHtml('(sponsorAd, adIndex) in visibleAds')
        ->assertDontSeeHtml('(ad, adIndex) in visibleAds');
});

test('sponsors stay in the ready grid while orders are only being prepared', function (): void {
    orderInPreparation('0318');

    Livewire::test('pages::display')
        ->assertSeeHtml('(ad, adIndex) in visibleAds')
        ->assertDontSeeHtml('(sponsorAd, adIndex) in visibleAds');
});

test('the display rings the bell once when an order or a batch of orders becomes ready', function (): void {
    Livewire::test('pages::display')
        ->call('orderBecameReady', ['order' => ['number' => '0318']])
        ->assertDispatched('ring-bell');

    Livewire::test('pages::display')
        ->call('ordersUpdated', ['change' => OrdersUpdated::BECAME_READY])
        ->assertDispatched('ring-bell');
});

test('the display stays silent when a batch of orders is removed', function (): void {
    Livewire::test('pages::display')
        ->call('ordersUpdated', ['change' => OrdersUpdated::REMOVED])
        ->assertNotDispatched('ring-bell');
});
