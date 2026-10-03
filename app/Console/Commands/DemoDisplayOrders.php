<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IngestKitchenTicket;
use App\Events\OrderReady;
use App\Events\OrderReceived;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fills the display with fake ticket orders, so the "being prepared" view can be checked on the TV.
 * The orders go through the same ingest action as real tickets and are removed again with --clear.
 */
#[Signature('display:demo
    {--preparing=20 : Number of orders that are being prepared}
    {--ready=4 : Number of orders that are ready for pick-up}
    {--clear : Remove all demo orders instead}
    {--force : Allow running in production}')]
#[Description('Show fake orders on the display to test the "being prepared" view')]
class DemoDisplayOrders extends Command
{
    public const string EXTERNAL_ID_PREFIX = 'demo-';

    /**
     * @var list<string>
     */
    private const array MENU = ['Br. Kroket', 'Br. Frikadel', 'Br. Kaassoufflé', 'Patat', 'Patat mayo', 'Tosti ham kaas', 'Bitterballen 8st', 'Kipnuggets 6st'];

    /**
     * Execute the console command.
     */
    public function handle(IngestKitchenTicket $ingest): int
    {
        if ($this->laravel->isProduction() && ! $this->option('force')) {
            $this->components->error('This adds fake orders to the live display. Use --force to run it in production.');

            return self::FAILURE;
        }

        if ($this->option('clear')) {
            return $this->clear();
        }

        $preparing = max(0, (int) $this->option('preparing'));
        $ready = max(0, (int) $this->option('ready'));
        $number = random_int(100, 8000);
        $created = 0;

        while ($created < $preparing + $ready) {
            $number++;
            $ticketNumber = str_pad((string) $number, 4, '0', STR_PAD_LEFT);

            if (Order::matchingNumber($ticketNumber)->exists()) {
                continue;
            }

            $ticket = $ingest->handle($this->fakeTicket($ticketNumber))['ticket'];

            if ($created < $ready) {
                $ticket->order->update(['status' => OrderStatus::Ready]);
                broadcast(new OrderReady($ticket->order));
            }

            $created++;
        }

        $this->components->info("Added {$preparing} orders being prepared and {$ready} ready orders. Remove them with: php artisan display:demo --clear");

        return self::SUCCESS;
    }

    private function clear(): int
    {
        $demoTickets = KitchenTicket::where('external_id', 'like', self::EXTERNAL_ID_PREFIX.'%');

        $orders = Order::whereIn('id', (clone $demoTickets)->whereNotNull('order_id')->select('order_id'))->delete();
        $demoTickets->delete();

        broadcast(new OrderReceived(null));

        $this->components->info("Removed {$orders} demo orders.");

        return self::SUCCESS;
    }

    /**
     * @return array{id: string, ticket_number: string, ticket_number_confidence: float, register: int, register_name: string, station: string, printed_at: string, captured_at: string, items: list<array{qty: int, name: string, notes: list<string>}>, raw_text: string, ocr_confidence: float, warnings: list<string>}
     */
    private function fakeTicket(string $ticketNumber): array
    {
        $items = collect(fake()->randomElements(self::MENU, random_int(1, 3)))
            ->map(fn (string $name): array => ['qty' => random_int(1, 3), 'name' => $name, 'notes' => []])
            ->all();

        return [
            'id' => self::EXTERNAL_ID_PREFIX.bin2hex(random_bytes(8)),
            'ticket_number' => $ticketNumber,
            'ticket_number_confidence' => 95.0,
            'register' => 3,
            'register_name' => 'Demo',
            'station' => 'Keuken',
            'printed_at' => now()->toIso8601String(),
            'captured_at' => now()->toIso8601String(),
            'items' => $items,
            'raw_text' => "PRODUCTIEBON: Keuken (demo)\nKassa 3 Demo {$ticketNumber}",
            'ocr_confidence' => 95.0,
            'warnings' => [],
        ];
    }
}
