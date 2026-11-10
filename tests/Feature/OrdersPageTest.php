<?php

declare(strict_types=1);

use App\Console\Commands\DemoDisplayOrders;
use App\KitchenDevice;
use App\Models\KitchenTicket;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductAlias;
use App\Models\User;
use App\ProductCategory;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
    $this->actingAs(User::factory()->create(['is_admin' => true]));
});

/**
 * A ticket with one line of the given product, captured at the given time (UTC).
 *
 * @param  array<string, mixed>  $ticketAttributes
 */
function orderedLine(Product|string $product, int $quantity, string $capturedAt, array $ticketAttributes = []): OrderItem
{
    $ticket = KitchenTicket::factory()->create(['captured_at' => $capturedAt, ...$ticketAttributes]);

    return OrderItem::factory()->for($ticket)->create([
        'product_id' => $product instanceof Product ? $product->id : null,
        'name' => $product instanceof Product ? $product->name : $product,
        'quantity' => $quantity,
    ]);
}

test('non-admin users are forbidden from the orders page', function (): void {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get(route('orders'))->assertForbidden();
});

test('counts how often each product was ordered in the period, leaving out staff meals and demo tickets', function (): void {
    $tosti = Product::factory()->create(['name' => 'Tosti special', 'category' => ProductCategory::TostiPanini]);
    $staffMeal = Product::factory()->create(['name' => 'Crew meal', 'category' => ProductCategory::Staff]);
    orderedLine($tosti, 2, '2026-11-08 13:00:00');
    orderedLine($tosti, 1, '2026-11-09 14:00:00');
    orderedLine('Unknown snack', 1, '2026-11-09 14:00:00');
    orderedLine($tosti, 5, '2026-10-01 13:00:00');
    orderedLine($staffMeal, 3, '2026-11-09 11:00:00');
    orderedLine($tosti, 9, '2026-11-09 11:00:00', ['external_id' => DemoDisplayOrders::EXTERNAL_ID_PREFIX.'1']);

    $page = Livewire::test('pages::orders', ['period' => 'week'])->instance();

    expect($page->products->map(fn (array $product): array => [$product['name'], $product['items'], $product['tickets'], $product['known']])->all())->toBe([
        ['Tosti special', 3, 2, true],
        ['Unknown snack', 1, 1, false],
    ])->and($page->summary)
        ->items->toBe(4)
        ->tickets->toBe(3);
});

test('counts staff meals and filters by register on request', function (): void {
    $staffMeal = Product::factory()->create(['name' => 'Crew meal', 'category' => ProductCategory::Staff]);
    orderedLine($staffMeal, 3, '2026-11-09 11:00:00', ['register_name' => 'Keuken']);
    orderedLine($staffMeal, 2, '2026-11-09 11:00:00', ['register_name' => 'Bar']);

    $page = Livewire::test('pages::orders', ['period' => 'week', 'includeStaffMeals' => true, 'register' => 'Bar'])->instance();

    expect($page->summary['items'])->toBe(2);
});

test('compares with the period before only when tickets were already coming in then', function (): void {
    $tosti = Product::factory()->create(['name' => 'Tosti special']);
    orderedLine($tosti, 4, '2026-11-09 12:00:00');

    expect(Livewire::test('pages::orders', ['period' => 'week'])->instance()->summary['itemsChange'])->toBeNull();

    orderedLine($tosti, 2, '2026-10-30 12:00:00');
    orderedLine($tosti, 7, '2026-10-01 12:00:00');

    expect(Livewire::test('pages::orders', ['period' => 'week'])->instance()->summary['itemsChange'])->toBe(100);
});

test('linking an unrecognised name to a product counts its lines for that product', function (): void {
    $product = Product::factory()->create(['name' => 'Tosti special']);
    $line = orderedLine('Tosli specia1', 1, '2026-11-09 12:00:00');

    Livewire::test('pages::orders')
        ->call('startLinking', 'Tosli specia1')
        ->set('linkProductId', (string) $product->id)
        ->call('linkName')
        ->assertHasNoErrors();

    expect($line->fresh()->product_id)->toBe($product->id)
        ->and(ProductAlias::where('alias', 'Tosli specia1')->sole()->product_id)->toBe($product->id);
});

test('an unrecognised name can be added as a new product', function (): void {
    $line = orderedLine('Soup of the day', 1, '2026-11-09 12:00:00');

    Livewire::test('pages::orders')
        ->call('startLinking', 'Soup of the day')
        ->set('linkProductId', 'new')
        ->set('newProductCategory', ProductCategory::Meal->value)
        ->set('newProductDevice', KitchenDevice::Stove->value)
        ->call('linkName')
        ->assertHasNoErrors();

    $product = Product::where('name', 'Soup of the day')->sole();
    expect($product->category)->toBe(ProductCategory::Meal)
        ->and($product->device)->toBe(KitchenDevice::Stove)
        ->and($line->fresh()->product_id)->toBe($product->id);
});

test('linking requires a chosen product', function (): void {
    Livewire::test('pages::orders')
        ->call('startLinking', 'Soup of the day')
        ->call('linkName')
        ->assertHasErrors(['linkProductId' => 'required']);

    expect(ProductAlias::where('alias', 'Soup of the day')->exists())->toBeFalse();
});

test('changes the category of a product and rejects an unknown category', function (): void {
    $product = Product::factory()->create(['category' => ProductCategory::Other]);

    Livewire::test('pages::orders')->call('updateCategory', $product->id, ProductCategory::Bites->value);
    expect($product->fresh()->category)->toBe(ProductCategory::Bites);

    Livewire::test('pages::orders')->call('updateCategory', $product->id, 'nonsense')->assertStatus(422);
    expect($product->fresh()->category)->toBe(ProductCategory::Bites);
});

test('changes the device of a product and rejects an unknown device', function (): void {
    $product = Product::factory()->create(['device' => KitchenDevice::None]);

    Livewire::test('pages::orders')->call('updateDevice', $product->id, KitchenDevice::Grill->value);
    expect($product->fresh()->device)->toBe(KitchenDevice::Grill);

    Livewire::test('pages::orders')->call('updateDevice', $product->id, 'toaster')->assertStatus(422);
    expect($product->fresh()->device)->toBe(KitchenDevice::Grill);
});

test('the last weekend runs from Friday to Sunday and is compared with the weekend before', function (): void {
    $tosti = Product::factory()->create(['name' => 'Tosti special']);
    orderedLine($tosti, 1, '2026-11-05 22:30:00'); // Thursday 23:30 in Amsterdam
    orderedLine($tosti, 2, '2026-11-06 09:00:00'); // Friday
    orderedLine($tosti, 3, '2026-11-08 22:30:00'); // Sunday 23:30 in Amsterdam
    orderedLine($tosti, 4, '2026-11-09 10:00:00'); // Monday
    orderedLine($tosti, 5, '2026-11-01 12:00:00'); // Sunday the weekend before
    orderedLine($tosti, 1, '2026-10-01 12:00:00'); // Earlier history, so there is something to compare with

    expect(Livewire::test('pages::orders', ['period' => 'weekend'])->instance()->summary)
        ->items->toBe(5)
        ->itemsChange->toBe(0);
});

test('shows per weekday when a device is usually needed and when to switch it on', function (): void {
    $tosti = Product::factory()->create(['name' => 'Tosti special', 'device' => KitchenDevice::Grill]);
    $salad = Product::factory()->create(['name' => 'Salad', 'device' => KitchenDevice::None]);
    // Two Saturdays, in UTC; Amsterdam is one hour ahead.
    orderedLine($tosti, 2, '2026-10-31 09:30:00');
    orderedLine($tosti, 1, '2026-10-31 13:00:00');
    orderedLine($tosti, 1, '2026-11-07 09:50:00');
    orderedLine($tosti, 3, '2026-11-07 14:00:00');
    orderedLine($salad, 1, '2026-11-07 08:00:00');

    $usage = Livewire::test('pages::orders', ['period' => 'month'])->instance()->deviceUsage;

    expect($usage['devices'])->toHaveCount(1)
        ->and($usage['devices'][0]['device'])->toBe(KitchenDevice::Grill)
        ->and($usage['devices'][0]['items'])->toBe(7)
        ->and($usage['devices'][0]['weekdays'])->toHaveCount(1)
        ->and($usage['devices'][0]['weekdays'][0])
        ->days->toBe(2)
        ->firstOrderAt->toBe('10:40')
        ->switchOnAt->toBe('10:30')
        ->lastOrderAt->toBe('14:30')
        ->and($usage['devices'][0]['weekdays'][0]['itemsPerHour'][10])->toBe(1.5);
});
