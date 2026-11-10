<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RecordTicketItems;
use App\Models\KitchenTicket;
use App\Services\ProductMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Reads the items of stored tickets again from their text, e.g. after the reading rules improved.
 */
#[Signature('kitchen-tickets:reparse
    {--dry-run : Only show what would change}')]
#[Description('Read the items of all stored kitchen tickets again from their text')]
class ReparseKitchenTickets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecordTicketItems $recordTicketItems, ProductMatcher $productMatcher): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tickets = $changedTickets = $linesBefore = $linesAfter = $uncertainLines = 0;
        $unknownNames = [];

        KitchenTicket::whereNotNull('raw_text')->withCount('orderItems')->lazyById(200)->each(
            function (KitchenTicket $ticket) use ($dryRun, $recordTicketItems, $productMatcher, &$tickets, &$changedTickets, &$linesBefore, &$linesAfter, &$uncertainLines, &$unknownNames): void {
                $itemsBefore = $ticket->items;
                $linesBefore += $ticket->order_items_count;
                $items = $recordTicketItems->read($ticket);

                $tickets++;
                $linesAfter += count($items);
                $changedTickets += $items == $itemsBefore ? 0 : 1;

                foreach ($items as $item) {
                    $uncertainLines += isset($item['qty_uncertain']) ? 1 : 0;

                    if ($productMatcher->match($item['name']) === null) {
                        $unknownNames[$item['name']] = ($unknownNames[$item['name']] ?? 0) + 1;
                    }
                }

                if (! $dryRun) {
                    $recordTicketItems->handle($ticket);
                }
            },
        );

        $this->components->twoColumnDetail('Tickets read', (string) $tickets);
        $this->components->twoColumnDetail('Tickets with changed items', (string) $changedTickets);
        $this->components->twoColumnDetail('Order lines', "{$linesBefore} → {$linesAfter}");
        $this->components->twoColumnDetail('Lines with an uncertain quantity', (string) $uncertainLines);

        if ($unknownNames !== []) {
            arsort($unknownNames);
            $this->newLine();
            $this->components->warn('Names not linked to a product (link them on the orders page):');
            $this->table(['Name', 'Lines'], collect($unknownNames)->map(fn (int $lines, string $name): array => [$name, $lines])->values());
        }

        $dryRun
            ? $this->components->info('Dry run: nothing was changed.')
            : $this->components->info('The order lines were stored again.');

        return self::SUCCESS;
    }
}
