<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('number_key')->default('')->after('number')->index();
        });

        DB::table('orders')->select(['id', 'number'])->lazyById()->each(function (object $order): void {
            // Same rule as Order::numberKey(), inlined so this migration does not depend on the model.
            DB::table('orders')->where('id', $order->id)->update(['number_key' => ltrim(trim((string) $order->number), '0')]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['number_key']);
            $table->dropColumn('number_key');
        });
    }
};
