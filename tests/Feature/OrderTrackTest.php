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

it('marks order as immediately ready when order is already called', function () {
    Order::factory()->create(['number' => '42', 'status' => 'ready']);

    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '4')
        ->call('appendNumber', '2')
        ->call('startWatching')
        ->assertSet('isWatching', true)
        ->assertSet('orderReady', true);
});

it('stays in waiting state when order is not yet called', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '9')
        ->call('appendNumber', '9')
        ->call('startWatching')
        ->assertSet('isWatching', true)
        ->assertSet('orderReady', false);
});

it('sets order ready when the matching OrderReady event fires', function () {
    Order::factory()->create(['number' => '77', 'status' => 'ready']);

    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '7')
        ->call('appendNumber', '7')
        ->call('startWatching')
        ->call('checkOrderReady', ['order' => ['number' => '77']])
        ->assertSet('orderReady', true);
});

it('does not set order ready when a different number is called', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '1')
        ->call('appendNumber', '2')
        ->call('startWatching')
        ->call('checkOrderReady', ['order' => ['number' => '99']])
        ->assertSet('orderReady', false);
});

it('does not set order ready if not watching', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '5')
        ->call('checkOrderReady', ['order' => ['number' => '5']])
        ->assertSet('isWatching', false)
        ->assertSet('orderReady', false);
});

it('resets state back to number entry', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '3')
        ->call('startWatching')
        ->call('stopTracking')
        ->assertSet('currentNumber', '')
        ->assertSet('isWatching', false)
        ->assertSet('orderReady', false);
});

it('does not accept more than 4 digits', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '1')
        ->call('appendNumber', '2')
        ->call('appendNumber', '3')
        ->call('appendNumber', '4')
        ->call('appendNumber', '5')
        ->assertSet('currentNumber', '1234');
});

it('clears the number', function () {
    Livewire\Livewire::test('pages::track')
        ->call('appendNumber', '5')
        ->call('appendNumber', '6')
        ->call('clearNumber')
        ->assertSet('currentNumber', '');
});

it('does not start watching when number is empty', function () {
    Livewire\Livewire::test('pages::track')
        ->call('startWatching')
        ->assertSet('isWatching', false);
});
