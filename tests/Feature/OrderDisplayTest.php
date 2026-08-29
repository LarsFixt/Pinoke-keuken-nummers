<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Models\Order;
use App\Models\User;
use App\OrderStatus;
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
