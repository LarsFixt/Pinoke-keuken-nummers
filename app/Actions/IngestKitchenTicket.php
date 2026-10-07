<?php

declare(strict_types=1);

namespace App\Actions;

use App\Events\OrderReceived;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\OrderStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores a ticket captured by the bridge and puts its order "in preparation".
 */
class IngestKitchenTicket
{
    /**
     * @param  array{id: string, ticket_number?: ?string, ticket_number_confidence?: ?float, register?: ?int, register_name?: ?string, station?: ?string, printed_at?: ?string, captured_at?: ?string, items: list<array{qty: int, name: string, notes: list<string>}>, raw_text?: ?string, ocr_confidence?: ?float, warnings: list<string>}  $payload
     * @return array{ticket: KitchenTicket, duplicate: bool}
     */
    public function handle(array $payload): array
    {
        $existing = KitchenTicket::where('external_id', $payload['id'])->first();

        if ($existing) {
            return ['ticket' => $existing, 'duplicate' => true];
        }

        try {
            $ticket = DB::transaction(fn (): KitchenTicket => $this->store($payload));
        } catch (UniqueConstraintViolationException) {
            // The same ticket arrived twice at the same moment and the other request stored it.
            return ['ticket' => KitchenTicket::where('external_id', $payload['id'])->firstOrFail(), 'duplicate' => true];
        }

        broadcast(new OrderReceived($ticket->order));

        return ['ticket' => $ticket, 'duplicate' => false];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function store(array $payload): KitchenTicket
    {
        $ticketNumber = $payload['ticket_number'] ?? null;
        $printedAt = $this->toAppTime($payload['printed_at'] ?? null);
        $order = null;

        if ($ticketNumber !== null && Order::numberKey($ticketNumber) !== '') {
            $order = Order::currentOrNewForNumber($ticketNumber);

            // The ticket is the source of truth for how the number is written.
            $order->number = $ticketNumber;
            $order->ordered_at ??= $printedAt ?? now();

            // A recent completed order gets an extra ticket: put it back in preparation.
            if (! $order->exists || $order->status === OrderStatus::Completed) {
                $order->status = OrderStatus::Pending;
            }

            $order->save();
        }

        $ticket = KitchenTicket::create([
            'external_id' => $payload['id'],
            'order_id' => $order?->id,
            'register' => $payload['register'] ?? null,
            'register_name' => $payload['register_name'] ?? null,
            'station' => $payload['station'] ?? null,
            'ticket_number' => $ticketNumber,
            'items' => $payload['items'],
            'raw_text' => $payload['raw_text'] ?? null,
            'ocr_confidence' => $payload['ocr_confidence'] ?? null,
            'ticket_number_confidence' => $payload['ticket_number_confidence'] ?? null,
            'warnings' => $payload['warnings'],
            'printed_at' => $printedAt,
            'captured_at' => $this->toAppTime($payload['captured_at'] ?? null),
        ]);

        $ticket->orderItems()->createMany(array_map(fn (array $item): array => [
            'order_id' => $order?->id,
            'quantity' => $item['qty'],
            'name' => $item['name'],
            'notes' => $item['notes'],
        ], $payload['items']));

        return $ticket->setRelation('order', $order);
    }

    /**
     * The bridge sends local times with an offset; convert them so they are not stored as if they were UTC.
     */
    private function toAppTime(?string $time): ?CarbonInterface
    {
        return $time === null ? null : Date::parse($time)->setTimezone(config('app.timezone'));
    }
}
