<?php

namespace App\Jobs;

use App\Models\KitchenTicket;
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
        Order::inPreparation()
            ->where('updated_at', '<=', now()->subHours(3))
            ->update(['status' => OrderStatus::Completed]);

        Order::where('status', OrderStatus::Completed)
            ->where('updated_at', '<=', now()->subDay())
            ->delete();

        KitchenTicket::where('created_at', '<=', now()->subWeek())->delete();
    }
}
