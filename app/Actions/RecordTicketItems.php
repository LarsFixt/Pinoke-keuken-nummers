<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\KitchenTicket;
use App\Services\KitchenTicketParser;
use App\Services\ProductMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Reads the items from a ticket's text and stores them as order items linked to their product.
 *
 * Used when a ticket comes in and again when old tickets are read with improved rules.
 */
class RecordTicketItems
{
    /**
     * The bridge's warning that becomes untrue once the items were found here.
     */
    public const string NO_ITEMS_WARNING = 'no item lines recognised';

    public function __construct(private KitchenTicketParser $parser, private ProductMatcher $productMatcher) {}

    /**
     * Fill the ticket's items, warnings and printed time from its text, without saving.
     *
     * @return list<array{qty: int, name: string, notes: list<string>, qty_uncertain?: true}> The items as now read.
     */
    public function read(KitchenTicket $ticket): array
    {
        $parsed = $ticket->raw_text ? $this->parser->parse($ticket->raw_text) : ['items' => [], 'printed_at' => null];

        // Keep what the bridge read when these rules find nothing, so a ticket never loses items.
        if ($parsed['items'] !== []) {
            $ticket->items = $parsed['items'];
            $ticket->warnings = array_values(array_diff($ticket->warnings, [self::NO_ITEMS_WARNING]));
        }

        $ticket->printed_at ??= $parsed['printed_at']?->setTimezone(config('app.timezone'));

        return $ticket->items;
    }

    /**
     * Read the ticket and replace its order items.
     */
    public function handle(KitchenTicket $ticket): void
    {
        $items = $this->read($ticket);

        DB::transaction(function () use ($ticket, $items): void {
            $ticket->save();
            $ticket->orderItems()->delete();
            $ticket->orderItems()->createMany(array_map(fn (array $item): array => [
                'order_id' => $ticket->order_id,
                'product_id' => $this->productMatcher->match($item['name']),
                'quantity' => $item['qty'],
                'is_quantity_uncertain' => $item['qty_uncertain'] ?? false,
                'name' => mb_substr($item['name'], 0, 100),
                'notes' => $item['notes'],
            ], $items));
        });
    }
}
