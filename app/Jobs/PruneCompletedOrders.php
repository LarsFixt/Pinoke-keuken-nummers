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
        Order::where('status', OrderStatus::Completed)
            ->where('updated_at', '<=', now()->subDay())
            ->delete();
    }
}
