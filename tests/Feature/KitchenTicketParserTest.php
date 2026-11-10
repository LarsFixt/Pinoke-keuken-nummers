<?php

declare(strict_types=1);

use App\Models\Product;
use App\Services\KitchenTicketParser;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    Product::factory()->create(['name' => 'Br. Hamburger']);
});

/**
 * @param  list<string>  $lines
 * @return array{items: list<array<string, mixed>>, printed_at: ?CarbonImmutable}
 */
function parseTicket(array $lines): array
{
    return app(KitchenTicketParser::class)->parse(implode("\n", $lines));
}

test('reads each item with its notes and the printed time, leaving out header, footer and the remark above the items', function (): void {
    $parsed = parseTicket([
        'PRODUCTIEBON: Keuken',
        'jan 16:00 rond',
        '2xBr. Hamburger',
        'zonder ui',
        '1xTosti kaas',
        '2-nov-2026 14:05',
        'Kassa 3 Keuken 0412',
    ]);

    expect($parsed['items'])->toBe([
        ['qty' => 2, 'name' => 'Br. Hamburger', 'notes' => ['zonder ui']],
        ['qty' => 1, 'name' => 'Tosti kaas', 'notes' => []],
    ])->and($parsed['printed_at']->toIso8601String())->toBe('2026-11-02T14:05:00+01:00');
});

test('keeps a line whose quantity was misread when the name is a known product, marking the quantity uncertain', function (string $line, int $quantity): void {
    expect(parseTicket([$line])['items'])->toBe([
        ['qty' => $quantity, 'name' => 'Br. Hamburger', 'notes' => [], 'qty_uncertain' => true],
    ]);
})->with([
    'letter instead of a digit' => ['AxBr. Hamburger', 1],
    'zero read as a letter' => ['OxBr. Hamburger', 1],
    'five read as S, x as y' => ['SyBr. Hamburger', 5],
    'ten read with an O' => ['1OxBr. Hamburger', 10],
    'stray character before the line' => ['5 1xBr. Hamburger', 1],
]);

test('does not turn a note into an item when its first character only looks like a quantity', function (): void {
    expect(parseTicket(['1xBr. Hamburger', 'AxSomething unknown'])['items'])->toBe([
        ['qty' => 1, 'name' => 'Br. Hamburger', 'notes' => ['AxSomething unknown']],
    ]);
});

test('recognises a printed time whose day or month was misread, instead of keeping it as a note', function (string $dateLine, ?string $printedAt): void {
    $parsed = parseTicket(['1xBr. Hamburger', $dateLine]);

    expect($parsed['items'][0]['notes'])->toBe([])
        ->and($parsed['printed_at']?->toIso8601String())->toBe($printedAt);
})->with([
    'Z read for 2' => ['Z-nov-2026 13:18', '2026-11-02T13:18:00+01:00'],
    'extra letter in the month' => ['29-oakt-2026 15:53', '2026-10-29T15:53:00+01:00'],
    'unreadable day' => ['?-nov-2026 13:18', null],
]);

test('writes the misread "50 pieces" remark the same way every time', function (string $remark): void {
    expect(parseTicket(['1xBr. Hamburger', $remark])['items'][0]['notes'])->toBe(['50st']);
})->with(['5Ûst', '5Ost', 'bOst', '50st']);
