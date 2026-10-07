<?php

use App\Jobs\CompleteExpiredReadyOrders;
use App\Jobs\PruneCompletedOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Complete orders that have been sitting ready for 30 minutes and remove their push subscriptions.
Schedule::job(CompleteExpiredReadyOrders::class)->everyMinute();

// Close forgotten orders and remove tracked numbers that never got a ticket. Order history is kept.
Schedule::job(PruneCompletedOrders::class)->dailyAt('03:00')->timezone('Europe/Amsterdam');
