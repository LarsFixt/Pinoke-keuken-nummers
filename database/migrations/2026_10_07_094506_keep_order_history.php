<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string PRINTER_TIMEZONE = 'Europe/Amsterdam';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('ordered_at')->nullable()->after('status');
            $table->timestamp('ready_at')->nullable()->after('ordered_at');
            $table->timestamp('completed_at')->nullable()->after('ready_at');
            $table->index(['number_key', 'created_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('kitchen_ticket_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->string('name', 100);
            $table->json('notes');
            $table->timestamps();

            $table->index(['name', 'created_at']);
        });

        $this->convertPrintedAt(from: self::PRINTER_TIMEZONE, to: 'UTC');

        DB::table('orders')->select(['id', 'status', 'created_at', 'updated_at'])->lazyById()->each(function (object $order): void {
            $firstTicketAt = DB::table('kitchen_tickets')
                ->where('order_id', $order->id)
                ->min(DB::raw('coalesce(printed_at, created_at)'));

            DB::table('orders')->where('id', $order->id)->update([
                'ordered_at' => $firstTicketAt ?? $order->created_at,
                'ready_at' => $order->status === 'ready' ? $order->updated_at : null,
                'completed_at' => $order->status === 'completed' ? $order->updated_at : null,
            ]);
        });

        DB::table('kitchen_tickets')->select(['id', 'order_id', 'items', 'created_at'])->lazyById()->each(function (object $ticket): void {
            $rows = collect(json_decode($ticket->items, true) ?? [])->map(fn (array $item): array => [
                'order_id' => $ticket->order_id,
                'kitchen_ticket_id' => $ticket->id,
                'quantity' => $item['qty'],
                'name' => $item['name'],
                'notes' => json_encode($item['notes'] ?? []),
                'created_at' => $ticket->created_at,
                'updated_at' => $ticket->created_at,
            ]);

            if ($rows->isNotEmpty()) {
                DB::table('order_items')->insert($rows->all());
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['number_key', 'created_at']);
            $table->dropColumn(['ordered_at', 'ready_at', 'completed_at']);
        });

        $this->convertPrintedAt(from: 'UTC', to: self::PRINTER_TIMEZONE);
    }

    /**
     * Tickets used to be stored with the printer's Amsterdam clock time as if it were UTC.
     */
    private function convertPrintedAt(string $from, string $to): void
    {
        DB::table('kitchen_tickets')->whereNotNull('printed_at')->select(['id', 'printed_at'])->lazyById()->each(function (object $ticket) use ($from, $to): void {
            DB::table('kitchen_tickets')->where('id', $ticket->id)->update([
                'printed_at' => Carbon::parse($ticket->printed_at, $from)->setTimezone($to)->format('Y-m-d H:i:s'),
            ]);
        });
    }
};
