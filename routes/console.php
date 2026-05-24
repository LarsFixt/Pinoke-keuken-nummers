<?php

use App\Jobs\PruneCompletedOrders;
use App\Jobs\PruneExpiredOrderPushSubscriptions;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Remove push subscriptions for orders that have been sitting ready for 30 minutes.
Schedule::job(PruneExpiredOrderPushSubscriptions::class)->everyMinute();

// Remove completed orders that are older than 24 hours.
Schedule::job(PruneCompletedOrders::class)->daily();
