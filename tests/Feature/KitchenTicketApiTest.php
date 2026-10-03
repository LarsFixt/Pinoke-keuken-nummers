<?php

declare(strict_types=1);

use App\Events\OrderReceived;
use App\Http\Middleware\VerifyTicketBridgeSignature;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;
use App\Services\PiStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

const BRIDGE_SECRET = 'test-secret-0123456789abcdef0123456789';

beforeEach(function (): void {
    config([
        'services.ticket_bridge.key_id' => 'bridge-test',
        'services.ticket_bridge.secret' => BRIDGE_SECRET,
        'services.ticket_bridge.max_clock_skew' => 300,
    ]);
    CarbonImmutable::setTestNow('2026-10-02 16:01:10');
    Event::fake([OrderReceived::class]);
});

/**
 * @return array<string, mixed>
 */
function ticketPayload(array $overrides = []): array
{
    return array_merge([
        'id' => '6e247fd44f8f6b55-0',
        'captured_at' => '2026-10-02T16:01:01+00:00',
        'station' => 'Keuken',
        'register' => 3,
        'register_name' => 'Keuken',
        'ticket_number' => '0317',
        'ticket_number_confidence' => 95.5,
        'printed_at' => '2026-10-02T18:01:00+02:00',
        'items' => [['qty' => 1, 'name' => 'Br. Kroket', 'notes' => []]],
        'raw_text' => "PRODUCTIEBON: Keuken\n1xBr. Kroket\n2-okt-2026 18:01\nKassa 3 Keuken 0317",
        'ocr_confidence' => 93.8,
        'warnings' => [],
    ], $overrides);
}

/**
 * Send a request signed the way the bridge signs it. $tamperedBody replaces the body after signing.
 *
 * @param  array<string, string>  $headerOverrides
 */
function sendSigned(string $uri, array $payload, array $headerOverrides = [], ?string $tamperedBody = null): TestResponse
{
    $body = json_encode($payload);
    $timestamp = (string) now()->getTimestamp();
    $nonce = bin2hex(random_bytes(16));

    $headers = array_merge([
        'X-Bridge-Key' => 'bridge-test',
        'X-Bridge-Timestamp' => $timestamp,
        'X-Bridge-Nonce' => $nonce,
        'X-Bridge-Signature' => VerifyTicketBridgeSignature::sign(BRIDGE_SECRET, $timestamp, $nonce, $body),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ], $headerOverrides);

    return test()->call('POST', $uri, [], [], [], test()->transformHeadersToServerVars($headers), $tamperedBody ?? $body);
}

test('the signature matches the vector shared with the bridge', function (): void {
    // Same vector as raspberry-pi/ticket-bridge/tests/test_signing.py.
    expect(VerifyTicketBridgeSignature::sign(BRIDGE_SECRET, '1759420800', '00112233445566778899aabbccddeeff', '{"id":"vector"}'))
        ->toBe('60bf299b647ad781157d59a112ac37f5884eb9ecfdc14ebc133db4e283803369');
});

test('a signed ticket creates an order in preparation', function (): void {
    sendSigned('/api/kitchen-tickets', ticketPayload())
        ->assertCreated()
        ->assertJson(['status' => 'created']);

    $order = Order::sole();
    expect($order->number)->toBe('0317')
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->kitchenTickets()->sole()->items)->toBe([['qty' => 1, 'name' => 'Br. Kroket', 'notes' => []]]);

    Event::assertDispatched(OrderReceived::class, fn (OrderReceived $event): bool => $event->order->is($order));
});

test('a resent ticket is acknowledged without storing it twice', function (): void {
    sendSigned('/api/kitchen-tickets', ticketPayload())->assertCreated();

    sendSigned('/api/kitchen-tickets', ticketPayload())
        ->assertOk()
        ->assertJson(['status' => 'duplicate']);

    expect(KitchenTicket::count())->toBe(1);
});

test('a ticket attaches to the order a customer is already tracking without leading zero', function (): void {
    $tracked = Order::factory()->create(['number' => '317', 'status' => OrderStatus::Pending]);

    sendSigned('/api/kitchen-tickets', ticketPayload())->assertCreated();

    expect(Order::count())->toBe(1)
        ->and($tracked->fresh()->number)->toBe('0317')
        ->and($tracked->fresh()->kitchenTickets()->count())->toBe(1);
});

test('a reused number from a completed order starts in preparation again', function (): void {
    $old = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Completed]);

    sendSigned('/api/kitchen-tickets', ticketPayload())->assertCreated();

    expect($old->fresh()->status)->toBe(OrderStatus::Pending);
});

test('a ticket for an order that is already ready keeps it ready', function (): void {
    $ready = Order::factory()->create(['number' => '0317', 'status' => OrderStatus::Ready]);

    sendSigned('/api/kitchen-tickets', ticketPayload())->assertCreated();

    expect($ready->fresh()->status)->toBe(OrderStatus::Ready);
});

test('a ticket without a readable number is kept for the kitchen without an order', function (): void {
    sendSigned('/api/kitchen-tickets', ticketPayload(['ticket_number' => null, 'warnings' => ['NO TICKET NUMBER']]))
        ->assertCreated();

    expect(Order::count())->toBe(0)
        ->and(KitchenTicket::needsAttention()->count())->toBe(1);
});

test('returns 401 and stores nothing when the signature does not check out', function (array $headers, ?string $tamperedBody): void {
    sendSigned('/api/kitchen-tickets', ticketPayload(), $headers, $tamperedBody)
        ->assertUnauthorized();

    expect(KitchenTicket::count())->toBe(0);
})->with([
    'tampered body' => [[], json_encode(ticketPayload(['ticket_number' => '0999']))],
    'wrong signature' => [['X-Bridge-Signature' => str_repeat('0', 64)], null],
    'unknown key' => [['X-Bridge-Key' => 'someone-else'], null],
    'missing signature' => [['X-Bridge-Signature' => ''], null],
    'malformed nonce' => [['X-Bridge-Nonce' => 'abc'], null],
]);

test('returns 401 for a request signed more than five minutes ago', function (): void {
    $body = json_encode(ticketPayload());
    $timestamp = (string) now()->subMinutes(6)->getTimestamp();
    $nonce = bin2hex(random_bytes(16));

    sendSigned('/api/kitchen-tickets', ticketPayload(), [
        'X-Bridge-Timestamp' => $timestamp,
        'X-Bridge-Nonce' => $nonce,
        'X-Bridge-Signature' => VerifyTicketBridgeSignature::sign(BRIDGE_SECRET, $timestamp, $nonce, $body),
    ])->assertUnauthorized();
});

test('returns 401 when a signed request is sent a second time', function (): void {
    $body = json_encode(ticketPayload());
    $timestamp = (string) now()->getTimestamp();
    $nonce = bin2hex(random_bytes(16));
    $headers = [
        'X-Bridge-Timestamp' => $timestamp,
        'X-Bridge-Nonce' => $nonce,
        'X-Bridge-Signature' => VerifyTicketBridgeSignature::sign(BRIDGE_SECRET, $timestamp, $nonce, $body),
    ];

    sendSigned('/api/kitchen-tickets', ticketPayload(), $headers)->assertCreated();
    sendSigned('/api/kitchen-tickets', ticketPayload(), $headers)->assertUnauthorized();
});

test('returns 401 for every request while no secret is configured', function (): void {
    config(['services.ticket_bridge.secret' => null]);

    sendSigned('/api/kitchen-tickets', ticketPayload())->assertUnauthorized();
});

test('returns 422 when a ticket includes an image', function (): void {
    sendSigned('/api/kitchen-tickets', ticketPayload(['image_png_base64' => 'iVBORw0KGgo=']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['image_png_base64' => 'The image png base64 field is prohibited.']);
});

test('returns 422 when the ticket number is not numeric', function (): void {
    sendSigned('/api/kitchen-tickets', ticketPayload(['ticket_number' => '03l7']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['ticket_number' => 'The ticket number field format is invalid.']);
});

test('the heartbeat records when the bridge was last seen and what it reports', function (): void {
    sendSigned('/api/kitchen-tickets/heartbeat', ['hostname' => 'bridge', 'iface_up' => true, 'capturing' => true, 'spool_pending' => 2])
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'command' => null]);

    expect(Cache::get(PiStatus::BRIDGE_LAST_SEEN_KEY))->toBe(now()->getTimestamp())
        ->and(app(PiStatus::class)->bridge())->toMatchArray(['hostname' => 'bridge', 'capturing' => true, 'spool_pending' => 2]);
});

test('the heartbeat hands a waiting command to the bridge only once', function (): void {
    app(PiStatus::class)->queueBridgeCommand('reboot');

    sendSigned('/api/kitchen-tickets/heartbeat', [])->assertJson(['command' => 'reboot']);
    sendSigned('/api/kitchen-tickets/heartbeat', [])->assertJson(['command' => null]);
});

test('returns 401 for a heartbeat that is not signed, without handing out a command', function (): void {
    app(PiStatus::class)->queueBridgeCommand('reboot');

    sendSigned('/api/kitchen-tickets/heartbeat', [], ['X-Bridge-Signature' => str_repeat('0', 64)])->assertUnauthorized();

    expect(app(PiStatus::class)->pendingBridgeCommand())->toBe('reboot');
});
