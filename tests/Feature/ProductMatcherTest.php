<?php

declare(strict_types=1);

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Services\ProductMatcher;

test('matches a name to its product by exact name, alias or a close spelling', function (string $name): void {
    $product = Product::factory()->create(['name' => 'Tosti hamburger']);
    ProductAlias::factory()->for($product)->create(['alias' => 'Toast burger']);

    expect(app(ProductMatcher::class)->match($name))->toBe($product->id);
})->with([
    'exact name, other case and spacing' => ['tosti  Hamburger '],
    'alias' => ['Toast burger'],
    'one letter misread' => ['losti hamburger'],
]);

test('leaves a name unmatched when it is close to two products or too far from any', function (string $name): void {
    Product::factory()->create(['name' => 'Menu 3']);
    Product::factory()->create(['name' => 'Menu 5']);

    expect(app(ProductMatcher::class)->match($name))->toBeNull();
})->with(['Menu 4', 'Dinner 3']);

test('links the order lines that were not matched before', function (): void {
    $unmatched = OrderItem::factory()->create(['name' => 'Toast burger', 'product_id' => null]);
    $otherName = OrderItem::factory()->create(['name' => 'Something else', 'product_id' => null]);
    $product = Product::factory()->create(['name' => 'Tosti hamburger']);
    ProductAlias::factory()->for($product)->create(['alias' => 'Toast burger']);

    expect(app(ProductMatcher::class)->linkUnmatchedItems())->toBe(1)
        ->and($unmatched->fresh()->product_id)->toBe($product->id)
        ->and($otherName->fresh()->product_id)->toBeNull();
});
