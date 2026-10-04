<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from the ticket bridge Pi.
 *
 * The Pi signs "{timestamp}.{nonce}.{raw body}" with HMAC-SHA256 using a shared secret.
 * A valid signature proves the sender knows the secret and that the body was not changed;
 * the timestamp window and single-use nonce stop a captured request from being replayed.
 */
class VerifyTicketBridgeSignature
{
    public const int MAX_BODY_BYTES = 65536;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $failure = $this->failureReason($request);

        if ($failure !== null) {
            Log::warning('Ticket bridge request rejected', ['reason' => $failure, 'ip' => $request->ip()]);

            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }

    /**
     * Compute the signature the bridge must send for this body.
     */
    public static function sign(string $secret, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$nonce}.{$body}", $secret);
    }

    private function failureReason(Request $request): ?string
    {
        $keyId = (string) config('services.ticket_bridge.key_id');
        $secret = (string) config('services.ticket_bridge.secret');

        if ($keyId === '' || strlen($secret) < 32) {
            return 'bridge not configured';
        }

        $timestamp = (string) $request->header('X-Bridge-Timestamp');
        $nonce = (string) $request->header('X-Bridge-Nonce');
        $signature = (string) $request->header('X-Bridge-Signature');

        if (! hash_equals($keyId, (string) $request->header('X-Bridge-Key'))) {
            return 'unknown key';
        }

        if (! ctype_digit($timestamp) || abs(now()->getTimestamp() - (int) $timestamp) > (int) config('services.ticket_bridge.max_clock_skew')) {
            return 'timestamp outside window';
        }

        if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
            return 'invalid nonce';
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BODY_BYTES) {
            return 'body too large';
        }

        if (! hash_equals(self::sign($secret, $timestamp, $nonce, $body), $signature)) {
            return 'bad signature';
        }

        // Only a correctly signed request may claim a nonce, so nobody can burn nonces ahead of the Pi.
        if (! Cache::add("ticket-bridge-nonce:{$nonce}", true, now()->addSeconds(2 * (int) config('services.ticket_bridge.max_clock_skew')))) {
            return 'nonce reused';
        }

        return null;
    }
}
