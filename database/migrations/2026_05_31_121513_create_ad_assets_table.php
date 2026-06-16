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
        Schema::create('ad_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsor_id')->constrained()->cascadeOnDelete();
            $table->string('file_path');
            $table->enum('target_screen', ['both', 'wedstrijdschema', 'kitchen']);
            $table->enum('size_format', ['logo_small', 'kitchen_1x1', 'kitchen_2x2']);
            $table->boolean('is_vertical');
            $table->unsignedInteger('duration_seconds')->default(10);
            $table->unsignedTinyInteger('frequency_weight')->default(1);
            $table->timestamps();

            $table->index(['target_screen', 'size_format']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_assets');
    }
};
