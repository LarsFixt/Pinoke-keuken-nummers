<?php

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
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 64)->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('register')->nullable();
            $table->string('register_name', 100)->nullable();
            $table->string('station', 100)->nullable();
            $table->string('ticket_number', 6)->nullable();
            $table->json('items');
            $table->text('raw_text')->nullable();
            $table->decimal('ocr_confidence', 5, 1)->nullable();
            $table->decimal('ticket_number_confidence', 5, 1)->nullable();
            $table->json('warnings');
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
    }
};
