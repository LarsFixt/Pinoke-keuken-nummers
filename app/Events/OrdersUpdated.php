<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Several orders changed at once from the kitchen (all ready, all removed, all deleted).
 * One event instead of one per order, so screens refresh once and the display rings its bell once.
 */
class OrdersUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public const string BECAME_READY = 'ready';

    public const string REMOVED = 'removed';

    public function __construct(public string $change) {}

    public function broadcastOn(): array
    {
        return [new Channel('orders')];
    }
}
