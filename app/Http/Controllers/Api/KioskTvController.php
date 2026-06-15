<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Events\TvStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class KioskTvController extends Controller
{
    /**
     * Called by the Raspberry Pi ONCE on boot to get initial state.
     */
    public function getStatus(Request $request)
    {
        $expectedToken = config('services.kiosk.token');

        if (! $expectedToken || $request->bearerToken() !== $expectedToken) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'status' => Cache::get('kiosk_tv_status', 'on'),
        ]);
    }

    /**
     * API Fallback (Optional, since the Volt component handles this natively now).
     */
    public function setStatus(Request $request)
    {
        $request->validate(['status' => 'required|in:on,off']);

        Cache::put('kiosk_tv_status', $request->status);
        broadcast(new TvStatusUpdated($request->status));

        return response()->json(['status' => $request->status]);
    }
}
