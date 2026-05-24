<?php

namespace App\Jobs;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneExpiredOrderPushSubscriptions implements ShouldQueue
{
    use Queueable;

    /**
     * Remove stale push subscriptions for orders that have been ready for
     * roughly 30 minutes and have not been completed yet.
     */
    public function handle(): void
    {
        Order::where('status', OrderStatus::Ready)
            ->whereBetween('updated_at', [
                now()->subMinutes(31),
                now()->subMinutes(29),
            ])
            ->each(function (Order $order): void {
                $order->pushSubscriptions()->delete();
            });
    }
}
