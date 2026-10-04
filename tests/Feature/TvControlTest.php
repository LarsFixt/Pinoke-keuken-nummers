<?php

declare(strict_types=1);

use App\Events\TvStatusUpdated;
use App\Models\User;
use App\Services\PiStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-10-03 18:00:00');
});

function tvControlAdmin(bool $superAdmin = false): User
{
    return User::factory()->create(['is_admin' => true, 'is_super_admin' => $superAdmin]);
}

/**
 * @param  array<string, mixed>  $status
 */
function kioskReported(array $status, int $secondsAgo = 10): void
{
    cache()->forever(PiStatus::KIOSK_STATUS_KEY, [...$status, 'reported_at' => now()->subSeconds($secondsAgo)->getTimestamp()]);
}

test('shows both pis as offline before they ever reported', function (): void {
    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSeeInOrder([__('TV Control'), __('Offline'), __('Ticket reader'), __('Offline')]);
});

test('shows the kiosk as online with the power state the tv reports over cec', function (): void {
    kioskReported(['hostname' => 'kiosk-pi', 'tv_power' => 'on', 'browser_running' => true]);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSee('kiosk-pi')
        ->assertSeeInOrder([__('Online'), __('TV (via CEC)'), __('On'), __('Browser'), __('Running')]);
});

test('shows a kiosk that stopped reporting three minutes ago as offline', function (): void {
    kioskReported(['tv_power' => 'on'], secondsAgo: 181);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSeeInOrder([__('TV Control'), __('Offline'), __('Ticket reader')]);
});

test('explains the cec error the kiosk reports', function (): void {
    kioskReported(['tv_power' => null, 'cec_error' => 'cec-client did not answer within 30 seconds']);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSee(__('The TV cannot be reached over HDMI-CEC'))
        ->assertSee('cec-client did not answer within 30 seconds');
});

test('warns when the tv stays in standby although the screen is switched on', function (): void {
    cache()->put('kiosk_tv_status', 'on');
    kioskReported(['tv_power' => 'standby']);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSee(__('The TV does not follow the chosen setting'));
});

test('does not warn when the tv follows the chosen setting', function (): void {
    cache()->put('kiosk_tv_status', 'off');
    kioskReported(['tv_power' => 'standby']);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertDontSee(__('The TV does not follow the chosen setting'));
});

test('switches the screen off and tells the kiosk', function (): void {
    Event::fake([TvStatusUpdated::class]);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')->call('toggle', 'off');

    expect(cache('kiosk_tv_status'))->toBe('off');
    Event::assertDispatched(TvStatusUpdated::class, fn (TvStatusUpdated $event): bool => $event->status === 'off');
});

test('ignores a screen state other than on or off', function (): void {
    Event::fake([TvStatusUpdated::class]);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')->call('toggle', 'reboot');

    Event::assertNotDispatched(TvStatusUpdated::class);
});

test('queues a restart for the ticket reader and shows it is waiting', function (): void {
    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->call('restartBridge')
        ->assertSee(__('Restart requested, waiting for the Pi…'));

    expect(app(PiStatus::class)->pendingBridgeCommand())->toBe('restart');
});

test('lets only a super admin reboot the ticket reader pi', function (bool $superAdmin, ?string $expected): void {
    Livewire::actingAs(tvControlAdmin($superAdmin))->test('pages::screen')->call('rebootBridge');

    expect(app(PiStatus::class)->pendingBridgeCommand())->toBe($expected);
})->with([
    'admin' => [false, null],
    'super admin' => [true, 'reboot'],
]);

test('cancels a command the ticket reader has not picked up yet', function (): void {
    app(PiStatus::class)->queueBridgeCommand('restart');

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')->call('cancelBridgeCommand');

    expect(app(PiStatus::class)->pendingBridgeCommand())->toBeNull();
});

test('tells an unplugged mirror cable apart from a reader that is not listening', function (array $status, string $label): void {
    cache()->forever(PiStatus::BRIDGE_STATUS_KEY, [...$status, 'reported_at' => now()->getTimestamp()]);

    Livewire::actingAs(tvControlAdmin())->test('pages::screen')
        ->assertSeeInOrder([__('Printer network'), $label]);
})->with([
    'listening' => [['iface_up' => true, 'capturing' => true], 'Listening'],
    'cable unplugged' => [['iface_up' => false, 'capturing' => false], 'Cable unplugged'],
    'not listening' => [['iface_up' => true, 'capturing' => false], 'Not listening'],
]);
