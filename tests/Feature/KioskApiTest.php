<?php

declare(strict_types=1);

use App\Services\PiStatus;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    config(['services.kiosk.token' => 'kiosk-test-token']);
    CarbonImmutable::setTestNow('2026-10-03 18:00:00');
});

test('returns 401 for the kiosk endpoints without the right token', function (string $method, string $uri): void {
    $this->json($method, $uri, [], ['Authorization' => 'Bearer wrong-token'])->assertUnauthorized();
})->with([
    'tv status' => ['GET', '/api/kiosk/tv-status'],
    'heartbeat' => ['POST', '/api/kiosk/heartbeat'],
]);

test('returns the chosen tv state to the kiosk', function (): void {
    cache()->put('kiosk_tv_status', 'off');

    $this->withToken('kiosk-test-token')->getJson('/api/kiosk/tv-status')
        ->assertOk()
        ->assertExactJson(['status' => 'off']);
});

test('stores the status the kiosk reports, including what the tv answers over cec', function (): void {
    $this->withToken('kiosk-test-token')->postJson('/api/kiosk/heartbeat', [
        'hostname' => 'kiosk',
        'ip' => '192.168.1.20',
        'uptime_seconds' => 3600,
        'version' => '2.1.0',
        'tv_power' => 'standby',
        'cec_error' => null,
        'browser_running' => true,
    ])->assertOk();

    expect(app(PiStatus::class)->kiosk())->toMatchArray([
        'hostname' => 'kiosk',
        'tv_power' => 'standby',
        'browser_running' => true,
        'reported_at' => now()->getTimestamp(),
    ]);
});

test('returns 422 when the kiosk reports an unknown tv power state', function (): void {
    $this->withToken('kiosk-test-token')->postJson('/api/kiosk/heartbeat', ['tv_power' => 'exploded'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['tv_power' => 'The selected tv power is invalid.']);
});
