<?php

namespace App\Http\Controllers\Api;

use App\Events\TvStatusUpdated;
use App\Http\Controllers\Controller;
use App\Services\PiStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class KioskTvController extends Controller
{
    /**
     * Called by the Raspberry Pi ONCE on boot to get initial state.
     */
    public function getStatus(Request $request)
    {
        return response()->json([
            'status' => Cache::get(PiStatus::KIOSK_TV_STATUS_KEY, 'on'),
        ]);
    }

    /**
     * Status report from the kiosk Pi, every minute and after every TV command.
     */
    public function heartbeat(Request $request, PiStatus $piStatus): JsonResponse
    {
        $status = $request->validate([
            'hostname' => ['nullable', 'string', 'max:100'],
            'ip' => ['nullable', 'ip'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'version' => ['nullable', 'string', 'max:20'],
            'tv_power' => ['nullable', 'string', 'in:on,standby,in transition from standby to on,in transition from on to standby,unknown'],
            'cec_error' => ['nullable', 'string', 'max:300'],
            'browser_running' => ['nullable', 'boolean'],
        ]);

        $piStatus->recordKiosk($status);

        return response()->json(['status' => 'ok']);
    }

    /**
     * API Fallback (Optional, since the Volt component handles this natively now).
     */
    public function setStatus(Request $request)
    {
        $request->validate(['status' => 'required|in:on,off']);

        Cache::put(PiStatus::KIOSK_TV_STATUS_KEY, $request->status);
        broadcast(new TvStatusUpdated($request->status));

        return response()->json(['status' => $request->status]);
    }
}
