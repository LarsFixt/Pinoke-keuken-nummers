<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ad_assets', function (Blueprint $table) {
            $table->dropIndex('ad_assets_target_screen_size_format_index');
            $table->dropColumn('size_format');
            $table->index('target_screen');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ad_assets', function (Blueprint $table) {
            $table->dropIndex('ad_assets_target_screen_index');
            $table->enum('size_format', ['logo_small', 'kitchen_1x1', 'kitchen_2x2'])->default('kitchen_1x1');
            $table->index(['target_screen', 'size_format']);
        });
    }
};
