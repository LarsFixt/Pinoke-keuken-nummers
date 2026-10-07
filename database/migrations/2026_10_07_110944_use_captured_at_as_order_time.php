<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The order time came from the OCR'd print time; the bridge's capture time is exact.
        DB::table('orders')->select(['id'])->lazyById()->each(function (object $order): void {
            $firstCapturedAt = DB::table('kitchen_tickets')->where('order_id', $order->id)->min('captured_at');

            if ($firstCapturedAt !== null) {
                DB::table('orders')->where('id', $order->id)->update(['ordered_at' => $firstCapturedAt]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The previous order times are not kept; the capture times are the more accurate ones.
    }
};
