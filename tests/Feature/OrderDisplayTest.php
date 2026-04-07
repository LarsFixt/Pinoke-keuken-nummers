<?php

use App\Events\OrderCompleted;
use App\Events\OrderReady;
use App\Livewire\KitchenNumpad;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('requires authentication to view kitchen screen', function () {
    $this->get('/kitchen')->assertRedirect('/login');
});

it('allows authenticated users to view kitchen screen', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/kitchen')
        ->assertStatus(200);
});

it('allows anyone to view the public display screen', function () {
    $this->get('/display')->assertStatus(200);
});

it('can broadcast order ready event from kitchen numpad', function () {
    Event::fake([OrderReady::class]);

    Livewire::test(KitchenNumpad::class)
        ->call('appendNumber', '1')
        ->call('appendNumber', '2')
        ->call('callOrder')
        ->assertSet('currentNumber', '')
        ->assertSee('12');

    $this->assertDatabaseHas('orders', [
        'number' => '12',
        'status' => 'ready',
    ]);

    Event::assertDispatched(OrderReady::class);
});

it('can broadcast order completed event from kitchen numpad', function () {
    Event::fake([OrderCompleted::class]);

    $order = Order::factory()->create(['number' => '42', 'status' => 'ready']);

    Livewire::test(KitchenNumpad::class)
        ->call('completeOrder', $order->id)
        ->assertDontSee($order->number);

    $this->assertDatabaseHas('orders', [
        'id' => $order->id,
        'status' => 'completed',
    ]);

    Event::assertDispatched(OrderCompleted::class);
});
