<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Events\OrdersUpdated;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
use App\Services\PiStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires authentication to view kitchen screen', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('requires admin role to view kitchen screen', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertForbidden();
});

it('allows admin users to view the kitchen screen', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

it('allows anyone to view the public display screen', function () {
    $this->get(route('home'))->assertStatus(200);
});

it('redirects admin users from public display to dashboard', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertRedirect(route('dashboard'));
});

it('allows non-admin authenticated users to stay on public display', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk();
});

it('includes ad playlist fetch logic on the display screen', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('adsEndpoint:', false)
        ->assertSee('fetch(this.adsEndpoint', false);
});

it('renders order tiles as Blade-rendered elements when orders are ready', function () {
    Order::factory()->create(['number' => '42', 'status' => OrderStatus::Ready]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('wire:key="order-', false)
        ->assertSee('$wire.recentOrdersCount', false)
        ->assertSee('in visibleAds', false)
        ->assertSee('window.open(ad.call_to_action', false);
});

it('shows empty state and sponsor overview when no orders are ready', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('x-show="visibleAds.length > 0"', false)
        ->assertSee('window.open(sponsorAd.call_to_action', false);
});

it('can call an order from the kitchen', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('callOrder', '12');

    $this->assertDatabaseHas('orders', [
        'number' => '12',
        'status' => 'ready',
    ]);

    Event::assertDispatched(OrderReady::class);
});

it('can call a four-digit order from the kitchen', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('callOrder', '1234');

    $this->assertDatabaseHas('orders', [
        'number' => '1234',
        'status' => 'ready',
    ]);

    Event::assertDispatched(OrderReady::class);
});

it('can call an order number with leading zero from the kitchen', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('callOrder', '012');

    $this->assertDatabaseHas('orders', [
        'number' => '012',
        'status' => 'ready',
    ]);

    Event::assertDispatched(OrderReady::class);
});

it('cannot call order number zero from the kitchen', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('callOrder', '0');

    $this->assertDatabaseMissing('orders', [
        'number' => '0',
        'status' => 'ready',
    ]);

    Event::assertNotDispatched(OrderReady::class);
});

it('can complete an order from the kitchen', function () {
    Event::fake([OrderCompleted::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => '42', 'status' => OrderStatus::Ready]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('completeOrder', $order->id);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'completed',
    ]);

    Event::assertDispatched(OrderCompleted::class);
});

it('can reactivate a completed order from the kitchen', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => '55', 'status' => OrderStatus::Completed]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('reactivateOrder', $order->id);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'ready',
    ]);

    Event::assertDispatched(OrderReady::class);
});

it('does not reactivate an order that is still ready', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => '66', 'status' => OrderStatus::Ready]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('reactivateOrder', $order->id);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'ready',
    ]);

    Event::assertNotDispatched(OrderReady::class);
});

it('does not complete an order that is not ready', function () {
    Event::fake([OrderCompleted::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => '77', 'status' => OrderStatus::Pending]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('completeOrder', $order->id);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'pending',
    ]);

    Event::assertNotDispatched(OrderCompleted::class);
});

it('shows push indicator for ready orders with a push subscription', function () {
    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['number' => '8888', 'status' => OrderStatus::Ready]);

    $order->pushSubscriptions()->create([
        'endpoint' => 'https://example.test/push-indicator-1',
        'public_key' => 'public-key',
        'auth_token' => 'auth-token',
        'content_encoding' => 'aesgcm',
    ]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->assertSee(__('Push linked'));
});

it('hides push indicator when ready orders have no push subscriptions', function () {
    $user = User::factory()->create(['is_admin' => true]);
    Order::factory()->create(['number' => '7777', 'status' => OrderStatus::Ready]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->assertDontSee(__('Push linked'));
});

it('renders the display qr code as a themeable svg without a background', function () {
    $this->blade('<x-qr-code class="text-blue-800" />')
        ->assertSee('<path fill-rule="evenodd"', false)
        ->assertSee('class="text-blue-800"', false)
        ->assertSee('fill="currentColor"', false)
        ->assertDontSee('#000000', false)
        ->assertDontSee('#ffffff', false)
        ->assertDontSee('<?xml', false);
});

it('calls a ticketed order from the kitchen when it is ready', function () {
    Event::fake([OrderReady::class]);
    $order = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Pending]);
    KitchenTicket::factory()->for($order)->create();

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->assertSee('Br. Kroket')
        ->call('markReady', $order->id);

    expect($order->fresh()->status)->toBe(OrderStatus::Ready);
    Event::assertDispatched(OrderReady::class);
});

it('calls the ticketed order when the kitchen types the number without leading zero', function () {
    Event::fake([OrderReady::class]);
    $order = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Pending]);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('callOrder', '317');

    expect(Order::count())->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::Ready)
        ->and($order->fresh()->number)->toBe('0317');
});

it('does not call an order number made of only zeros', function () {
    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('callOrder', '00');

    expect(Order::count())->toBe(0);
});

it('lets the kitchen dismiss a ticket whose number could not be read', function () {
    $ticket = KitchenTicket::factory()->withoutNumber()->create();

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->assertSee(__('Needs a look'))
        ->call('dismissTicket', $ticket->id)
        ->assertDontSee(__('Needs a look'));
});

it('warns the kitchen when the ticket reader has gone quiet', function () {
    cache()->forever(PiStatus::BRIDGE_LAST_SEEN_KEY, now()->subMinutes(5)->getTimestamp());

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->assertSee(__('Ticket reader offline'));
});

it('shows ticketed orders as being prepared on the public display', function () {
    $order = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Pending]);
    KitchenTicket::factory()->for($order)->create();
    Order::factory()->create(['number' => '0555', 'status' => OrderStatus::Pending]);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee(__('Being prepared'))
        ->assertSee('0317')
        ->assertDontSee('0555');
});

it('forbids a non-admin from calling kitchen actions with a snapshot of the kitchen page', function () {
    $order = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Pending]);
    KitchenTicket::factory()->for($order)->create();

    $html = $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('dashboard'))
        ->assertOk()
        ->getContent();
    preg_match('/wire:snapshot="([^"]+)"[^>]*wire:name="pages::kitchen"/', $html, $match);
    $snapshot = html_entity_decode($match[1]);

    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->withHeader('X-Livewire', 'true')
        ->postJson(app('livewire')->getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['method' => 'markReady', 'params' => [$order->id], 'metadata' => []]],
        ]]])
        ->assertForbidden();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('calls every order in preparation at once and announces it with one broadcast', function () {
    Event::fake([OrderReady::class, OrdersUpdated::class]);
    $orders = Order::factory()->count(3)->has(KitchenTicket::factory(), 'kitchenTickets')
        ->create(['status' => OrderStatus::Pending]);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('markAllReady');

    expect(Order::whereIn('id', $orders->modelKeys())->pluck('status')->unique()->all())->toBe([OrderStatus::Ready]);
    Event::assertDispatchedTimes(OrdersUpdated::class, 1);
    Event::assertNotDispatched(OrderReady::class);
});

it('deletes every order in preparation with its tickets without touching ready orders', function () {
    Event::fake([OrdersUpdated::class]);
    Order::factory()->count(2)->has(KitchenTicket::factory(), 'kitchenTickets')->create(['status' => OrderStatus::Pending]);
    $ready = Order::factory()->create(['status' => OrderStatus::Ready]);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('deleteAllInPreparation')
        ->assertDontSee(__('Needs a look'));

    expect(Order::pluck('id')->all())->toBe([$ready->id])
        ->and(KitchenTicket::count())->toBe(0);
    Event::assertDispatched(OrdersUpdated::class, fn (OrdersUpdated $event): bool => $event->change === OrdersUpdated::REMOVED);
});

it('marks every ready order as picked up and keeps them available to re-add', function () {
    Event::fake([OrdersUpdated::class]);
    $ready = Order::factory()->count(2)->create(['status' => OrderStatus::Ready]);
    $preparing = Order::factory()->has(KitchenTicket::factory(), 'kitchenTickets')->create(['status' => OrderStatus::Pending]);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('completeAllReady')
        ->assertSee(__('Recently completed'));

    expect(Order::whereIn('id', $ready->modelKeys())->pluck('status')->unique()->all())->toBe([OrderStatus::Completed])
        ->and($preparing->fresh()->status)->toBe(OrderStatus::Pending);
    Event::assertDispatchedTimes(OrdersUpdated::class, 1);
});

it('dismisses all tickets whose number could not be read', function () {
    KitchenTicket::factory()->count(2)->withoutNumber()->create();

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))
        ->test('pages::kitchen')
        ->call('dismissAllTickets')
        ->assertDontSee(__('Needs a look'));

    expect(KitchenTicket::needsAttention()->count())->toBe(0);
});

it('calls a new order when the number was last used more than three hours ago', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $old = Order::factory()->create(['number' => '42', 'status' => OrderStatus::Completed, 'created_at' => now()->subHours(4)]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('callOrder', '42');

    $new = Order::where('status', OrderStatus::Ready)->sole();

    expect($new->is($old))->toBeFalse()
        ->and($old->fresh()->status)->toBe(OrderStatus::Completed);
});

it('records when an order was called and picked up', function () {
    Event::fake([OrderReady::class, OrderCompleted::class]);

    $user = User::factory()->create(['is_admin' => true]);

    $kitchen = Livewire::actingAs($user)->test('pages::kitchen')->call('callOrder', '42');

    $order = Order::sole();
    expect($order->ready_at)->not->toBeNull()
        ->and($order->completed_at)->toBeNull();

    $kitchen->call('completeOrder', $order->id);

    expect($order->fresh()->completed_at)->not->toBeNull()
        ->and($order->fresh()->ready_at->equalTo($order->ready_at))->toBeTrue();
});

it('gives a re-added order a fresh time on the display', function () {
    Event::fake([OrderReady::class]);

    $user = User::factory()->create(['is_admin' => true]);
    $order = Order::factory()->create(['status' => OrderStatus::Completed, 'ready_at' => now()->subHour()]);

    Livewire::actingAs($user)
        ->test('pages::kitchen')
        ->call('reactivateOrder', $order->id);

    expect($order->fresh()->ready_at->isAfter(now()->subMinute()))->toBeTrue();
});
