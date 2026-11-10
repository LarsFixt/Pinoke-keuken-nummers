<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Reads the items and printed time from the OCR text of a "PRODUCTIEBON" kitchen ticket.
 *
 * The ticket bridge sends the text it read, so improving these rules needs no update on the Pi
 * and old tickets can be read again. The ticket number is still taken from the bridge.
 */
class KitchenTicketParser
{
    private const array MONTHS = [
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * Letters the OCR reads instead of digits on these tickets, e.g. "Z-nov-2026" for "2-nov-2026".
     */
    private const array DIGIT_FIXES = [
        'O' => '0', 'o' => '0', 'D' => '0', 'I' => '1', 'l' => '1', '|' => '1', 'i' => '1',
        'Z' => '2', 'z' => '2', 'S' => '5', 's' => '5', 'G' => '6', 'T' => '7', 'B' => '8', 'g' => '9',
    ];

    /**
     * "1xBr. Kroket": the quantity was read as digits. "1OxFriet" is 10, read with an O.
     */
    private const string ITEM_PATTERN = '/^([0-9Il|][0-9OoIl|]{0,2})\s*[xX×]\s*(\S.*)$/u';

    /**
     * "5 1xKaassouflé los": a stray character before an item line, only trusted for known products.
     */
    private const string STRAY_PREFIX_PATTERN = '/^\S{1,2}\s+(\S.*)$/u';

    /**
     * "AxBr. Kroket" or "SyPersoneelseten": one misread character before the "x", only trusted for known products.
     */
    private const string MISREAD_ITEM_PATTERN = '/^(\S)\s*[xXyY×]\s*(\S.*)$/u';

    private const string DATE_PATTERN = '/^(\S{1,2})\s*-\s*([A-Za-z]{3,4})\s*-\s*(\d{4})\s+(\S{1,2})[:.](\d{2})$/u';

    private const string HEADER_PATTERN = '/PRODUCTIE\s*BON/iu';

    private const string FOOTER_PATTERN = '/(^K\s*a\s*s\s*s\s*a\b)|(\bKassa\s+\S{1,2}\s)/iu';

    /**
     * The "50 pieces" remark under a team bar snack platter, read as "5Ûst", "5Ost" or "bOst".
     */
    private const string FIFTY_PIECES_PATTERN = '/^[5Sb][0OoÔÛÖ]\s*st\.?$/u';

    public function __construct(private ProductMatcher $productMatcher) {}

    /**
     * @return array{items: list<array{qty: int, name: string, notes: list<string>, qty_uncertain?: true}>, printed_at: ?CarbonImmutable}
     */
    public function parse(string $rawText): array
    {
        $items = [];
        $printedAt = null;

        foreach (preg_split('/\R/u', $rawText) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match(self::HEADER_PATTERN, $line) || preg_match(self::FOOTER_PATTERN, $line)) {
                continue;
            }

            if (preg_match(self::DATE_PATTERN, $line, $date)) {
                $printedAt ??= $this->toDate($date);

                continue;
            }

            if ($item = $this->toItem($line)) {
                $items[] = $item;

                continue;
            }

            // Text above the first item is a remark for the whole order (often a name), which is not kept.
            if ($items !== []) {
                $items[array_key_last($items)]['notes'][] = preg_match(self::FIFTY_PIECES_PATTERN, $line) ? '50st' : $line;
            }
        }

        return ['items' => $items, 'printed_at' => $printedAt];
    }

    /**
     * @return array{qty: int, name: string, notes: list<string>, qty_uncertain?: true}|null
     */
    private function toItem(string $line): ?array
    {
        if (preg_match(self::ITEM_PATTERN, $line, $match)) {
            $item = ['qty' => max(1, (int) strtr($match[1], self::DIGIT_FIXES)), 'name' => trim($match[2]), 'notes' => []];

            return preg_match('/[Oo]/', $match[1]) ? [...$item, 'qty_uncertain' => true] : $item;
        }

        if (preg_match(self::MISREAD_ITEM_PATTERN, $line, $match) && $this->productMatcher->match($match[2]) !== null) {
            $quantity = (int) $this->toDigits($match[1]);

            return ['qty' => max(1, $quantity), 'name' => trim($match[2]), 'notes' => [], 'qty_uncertain' => true];
        }

        if (preg_match(self::STRAY_PREFIX_PATTERN, $line, $match)
            && preg_match(self::ITEM_PATTERN, $match[1], $item)
            && $this->productMatcher->match($item[2]) !== null) {
            return ['qty' => max(1, (int) strtr($item[1], self::DIGIT_FIXES)), 'name' => trim($item[2]), 'notes' => [], 'qty_uncertain' => true];
        }

        return null;
    }

    /**
     * @param  array<int, string>  $date
     */
    private function toDate(array $date): ?CarbonImmutable
    {
        [, $day, $month, $year, $hour, $minute] = $date;
        $day = $this->toDigits($day);
        $hour = $this->toDigits($hour);
        $month = $this->toMonth($month);

        if ($day === null || $hour === null || $month === null || ! checkdate($month, (int) $day, (int) $year) || (int) $hour > 23) {
            return null;
        }

        return CarbonImmutable::create((int) $year, $month, (int) $day, (int) $hour, (int) $minute, 0, config('app.display_timezone'));
    }

    /**
     * The month number for "nov", also when the OCR added a letter, e.g. "oakt".
     */
    private function toMonth(string $text): ?int
    {
        $text = strtolower($text);

        foreach (self::MONTHS as $name => $number) {
            if (levenshtein($text, $name) <= (strlen($text) === 3 ? 0 : 1)) {
                return $number;
            }
        }

        return null;
    }

    private function toDigits(string $text): ?string
    {
        $digits = strtr($text, self::DIGIT_FIXES);

        return ctype_digit($digits) ? $digits : null;
    }
}
