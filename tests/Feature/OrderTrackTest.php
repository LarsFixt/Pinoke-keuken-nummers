<?php

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows anyone to view the track page', function () {
    $this->get('/track')->assertStatus(200);
});

it('shows the order number entry form by default', function () {
    $this->get('/track')->assertSee(__('Track your order'));
});

it('supports four-digit order numbers in the track flow', function () {
    Order::factory()->create(['number' => '1234', 'status' => 'ready']);

    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '1234')
        ->assertSet('currentNumber', '1234')
        ->assertSet('orderReady', true);
});

it('marks order as immediately ready when order is already called', function () {
    Order::factory()->create(['number' => '42', 'status' => 'ready']);

    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '42')
        ->assertSet('currentNumber', '42')
        ->assertSet('orderReady', true);
});

it('stays in waiting state when order is not yet called', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '99')
        ->assertSet('currentNumber', '99')
        ->assertSet('orderReady', false);
});

it('sets order ready when the matching OrderReady event fires', function () {
    Order::factory()->create(['number' => '77', 'status' => 'ready']);

    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '77')
        ->call('checkOrderReady', ['order' => ['number' => '77']])
        ->assertSet('orderReady', true);
});

it('does not set order ready when a different number is called', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '12')
        ->call('checkOrderReady', ['order' => ['number' => '99']])
        ->assertSet('orderReady', false);
});

it('does not set order ready if not watching', function () {
    Livewire\Livewire::test('pages::track')
        ->call('checkOrderReady', ['order' => ['number' => '5']])
        ->assertSet('currentNumber', '')
        ->assertSet('orderReady', false);
});

it('refreshes tracking status from the database for fallback delivery', function () {
    $order = Order::factory()->create(['number' => '81', 'status' => 'pending']);

    $component = Livewire\Livewire::test('pages::track')
        ->call('startWatching', '81')
        ->assertSet('orderReady', false);

    $order->update(['status' => 'ready']);

    $component
        ->call('refreshTrackingStatus')
        ->assertSet('orderReady', true);
});

it('ignores fallback refresh when no number is being tracked', function () {
    Livewire\Livewire::test('pages::track')
        ->call('refreshTrackingStatus')
        ->assertSet('currentNumber', '')
        ->assertSet('orderReady', false);
});

it('resets state when stopping tracking', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '3')
        ->call('stopTracking')
        ->assertSet('currentNumber', '')
        ->assertSet('orderReady', false);
});

it('ignores invalid characters in number', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching', 'abc')
        ->assertSet('currentNumber', '');
});

it('ignores numbers longer than 4 digits', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching', '12345')
        ->assertSet('currentNumber', '');
});
