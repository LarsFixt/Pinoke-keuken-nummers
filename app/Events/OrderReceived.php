<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A kitchen ticket arrived from the bridge. The order is null when its number could not be read.
 */
class OrderReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ?Order $order) {}

    public function broadcastOn(): array
    {
        return [new Channel('orders')];
    }

    /**
     * Only the number and status are public; ticket contents stay in the kitchen.
     *
     * @return array{order: array{id: int, number: string, status: string}|null}
     */
    public function broadcastWith(): array
    {
        return [
            'order' => $this->order ? [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status->value,
            ] : null,
        ];
    }
}
