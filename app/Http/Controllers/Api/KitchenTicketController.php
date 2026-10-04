<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\IngestKitchenTicket;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreKitchenTicketRequest;
use App\Services\PiStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives tickets from the ticket bridge Pi. Requests are signed, see VerifyTicketBridgeSignature.
 */
class KitchenTicketController extends Controller
{
    /**
     * Store a captured ticket. Re-sending the same ticket is safe and returns "duplicate".
     */
    public function store(StoreKitchenTicketRequest $request, IngestKitchenTicket $ingest, PiStatus $piStatus): JsonResponse
    {
        $piStatus->recordBridgeSeen();

        $result = $ingest->handle($request->validated());

        return response()->json([
            'status' => $result['duplicate'] ? 'duplicate' : 'created',
            'order_id' => $result['ticket']->order_id,
        ], $result['duplicate'] ? 200 : 201);
    }

    /**
     * Record that the bridge is alive and what it is doing, and hand it a waiting command (restart, reboot).
     */
    public function heartbeat(Request $request, PiStatus $piStatus): JsonResponse
    {
        $status = $request->validate([
            'hostname' => ['nullable', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:20'],
            'uptime_seconds' => ['nullable', 'integer', 'min:0'],
            'iface' => ['nullable', 'string', 'max:20'],
            'iface_up' => ['nullable', 'boolean'],
            'capturing' => ['nullable', 'boolean'],
            'tickets_seen' => ['nullable', 'integer', 'min:0'],
            'last_ticket_at' => ['nullable', 'date'],
            'spool_pending' => ['nullable', 'integer', 'min:0'],
            'spool_failed' => ['nullable', 'integer', 'min:0'],
        ]);

        $piStatus->recordBridge($status);

        return response()->json(['status' => 'ok', 'command' => $piStatus->takeBridgeCommand()]);
    }
}
