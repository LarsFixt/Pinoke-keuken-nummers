<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAlias;
use Illuminate\Container\Attributes\Scoped;

/**
 * Finds the product a name read from a ticket belongs to, allowing for small OCR misreadings.
 */
#[Scoped]
class ProductMatcher
{
    /**
     * Product id per normalised product name and alias.
     *
     * @var array<string, int>|null
     */
    private ?array $exactNames = null;

    /**
     * Normalised product name per product id, for close spellings.
     *
     * @var array<int, string>|null
     */
    private ?array $productNames = null;

    /**
     * The id of the product this name means, or null when it is not clear.
     *
     * Names one character off (two for longer names) from exactly one product are matched too,
     * e.g. "losti ham/kaas" is "Tosti ham/kaas". A name close to two products stays unmatched.
     */
    public function match(string $name): ?int
    {
        $this->load();
        $normalised = self::normalise($name);

        if (isset($this->exactNames[$normalised])) {
            return $this->exactNames[$normalised];
        }

        $allowedDistance = mb_strlen($normalised) >= 10 ? 2 : 1;
        $closest = [];

        foreach ($this->productNames as $productId => $productName) {
            $distance = levenshtein($normalised, $productName);

            if ($distance <= $allowedDistance) {
                $closest[$distance][] = $productId;
            }
        }

        if ($closest === []) {
            return null;
        }

        ksort($closest);
        $best = reset($closest);

        return count($best) === 1 ? $best[0] : null;
    }

    /**
     * Link ticket lines that were not recognised before, e.g. after adding a product or alias.
     *
     * @return int The number of lines that are now linked.
     */
    public function linkUnmatchedItems(): int
    {
        $this->forget();
        $linked = 0;

        OrderItem::whereNull('product_id')->get(['id', 'name'])
            ->groupBy('name')
            ->each(function ($items, string $name) use (&$linked): void {
                $productId = $this->match($name);

                if ($productId !== null) {
                    $linked += OrderItem::whereKey($items->modelKeys())->update(['product_id' => $productId]);
                }
            });

        return $linked;
    }

    /**
     * Drop the loaded products, so products or aliases added since are used.
     */
    public function forget(): void
    {
        $this->exactNames = null;
        $this->productNames = null;
    }

    public static function normalise(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($name)));
    }

    private function load(): void
    {
        if ($this->exactNames !== null) {
            return;
        }

        $this->productNames = Product::pluck('name', 'id')->map(self::normalise(...))->all();
        $this->exactNames = array_flip($this->productNames);

        foreach (ProductAlias::pluck('product_id', 'alias') as $alias => $productId) {
            $this->exactNames[self::normalise($alias)] = $productId;
        }
    }
}
