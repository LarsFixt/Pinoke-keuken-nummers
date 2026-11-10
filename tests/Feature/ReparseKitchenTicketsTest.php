<?php

declare(strict_types=1);

use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\Product;

/**
 * A ticket stored by an older reader that dropped the line with a misread quantity.
 */
function ticketWithDroppedLine(): KitchenTicket
{
    Product::factory()->create(['name' => 'Br. Hamburger']);

    return KitchenTicket::factory()->create([
        'raw_text' => "PRODUCTIEBON: Keuken\nAxBr. Hamburger\nZ-nov-2026 13:18\nKassa 3 Keuken 0412",
        'items' => [],
        'warnings' => ['no item lines recognised'],
        'printed_at' => null,
    ]);
}

test('reads stored tickets again and replaces their order lines', function (): void {
    $ticket = ticketWithDroppedLine();

    $this->artisan('kitchen-tickets:reparse')->assertSuccessful();

    $ticket->refresh();
    expect($ticket->items)->toBe([['qty' => 1, 'name' => 'Br. Hamburger', 'notes' => [], 'qty_uncertain' => true]])
        ->and($ticket->warnings)->toBe([])
        ->and($ticket->printed_at->toIso8601String())->toBe('2026-11-02T12:18:00+00:00')
        ->and($ticket->orderItems()->sole())
        ->name->toBe('Br. Hamburger')
        ->product_id->toBe(Product::where('name', 'Br. Hamburger')->value('id'))
        ->is_quantity_uncertain->toBeTrue();
});

test('changes nothing on a dry run', function (): void {
    $ticket = ticketWithDroppedLine();

    $this->artisan('kitchen-tickets:reparse', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($ticket->fresh()->items)->toBe([])
        ->and(OrderItem::count())->toBe(0);
});
