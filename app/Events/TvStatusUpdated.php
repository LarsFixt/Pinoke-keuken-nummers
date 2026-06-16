<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// We use ShouldBroadcastNow so it fires instantly without waiting for a queue worker
class TvStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $status;

    public function __construct(string $status)
    {
        $this->status = $status;
    }

    public function broadcastOn(): array
    {
        return [new Channel('kiosk-control')];
    }
}
