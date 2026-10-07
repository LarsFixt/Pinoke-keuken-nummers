<?php

namespace App\Jobs;

use App\Models\Order;
use App\OrderStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PruneCompletedOrders implements ShouldQueue
{
    use Queueable;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Ticketed orders the kitchen never called are long gone by now.
        Order::where('status', OrderStatus::Pending)
            ->whereHas('kitchenTickets')
            ->where('updated_at', '<=', now()->subHours(Order::NUMBER_REUSE_AFTER_HOURS))
            ->update(['status' => OrderStatus::Completed, 'completed_at' => now()]);

        // A customer typed a number on the track page but no ticket ever came: nothing worth keeping.
        Order::where('status', OrderStatus::Pending)
            ->whereDoesntHave('kitchenTickets')
            ->where('created_at', '<=', now()->subHours(Order::NUMBER_REUSE_AFTER_HOURS))
            ->delete();
    }
}
