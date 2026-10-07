<?php

namespace App\Jobs;

use App\Events\OrdersUpdated;
use App\Models\Order;
use App\Models\PushSubscription;
use App\OrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class CompleteExpiredReadyOrders implements ShouldQueue
{
    use Queueable;

    /**
     * How long a called order stays on the display before it counts as picked up.
     */
    public const int READY_TIMEOUT_MINUTES = 30;

    /**
     * Complete orders that have been ready for 30 minutes, remove their push subscriptions
     * and refresh the screens once.
     */
    public function handle(): void
    {
        $orderIds = Order::ready()
            ->where('ready_at', '<=', now()->subMinutes(self::READY_TIMEOUT_MINUTES))
            ->pluck('id');

        if ($orderIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($orderIds): void {
            Order::whereIn('id', $orderIds)->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);
            PushSubscription::whereIn('order_id', $orderIds)->delete();
        });

        broadcast(new OrdersUpdated(OrdersUpdated::REMOVED));
    }
}
