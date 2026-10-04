<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates the kiosk Pi (TV) by the bearer token in KIOSK_API_TOKEN.
 */
class VerifyKioskToken
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = (string) config('services.kiosk.token');

        if ($expectedToken === '' || ! hash_equals($expectedToken, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
