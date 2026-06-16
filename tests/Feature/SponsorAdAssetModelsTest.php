<?php

declare(strict_types=1);

use App\Models\AdAsset;
use App\Models\Sponsor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('sponsors table contains required columns', function (): void {
    expect(Schema::hasColumns('sponsors', [
        'id',
        'name',
        'title',
        'description',
        'call_to_action',
        'start_date',
        'end_date',
        'is_active',
    ]))->toBeTrue();
});

test('ad assets table contains required columns', function (): void {
    expect(Schema::hasColumns('ad_assets', [
        'id',
        'sponsor_id',
        'file_path',
        'target_screen',
        'is_vertical',
        'duration_seconds',
        'frequency_weight',
    ]))->toBeTrue();
});

test('sponsor has many ad assets and ad asset belongs to sponsor', function (): void {
    $sponsor = Sponsor::factory()->create();

    $assets = AdAsset::factory()->count(2)->for($sponsor)->create();

    expect($sponsor->adAssets)->toHaveCount(2)
        ->and($assets->first()?->sponsor->is($sponsor))->toBeTrue();
});

test('deleting sponsor cascades ad assets', function (): void {
    $sponsor = Sponsor::factory()->create();
    AdAsset::factory()->for($sponsor)->count(2)->create();

    $sponsor->delete();

    $this->assertDatabaseCount('ad_assets', 0);
});

test('ad asset duration and frequency default values are applied by database', function (): void {
    $sponsor = Sponsor::factory()->create();

    $assetId = DB::table('ad_assets')->insertGetId([
        'sponsor_id' => $sponsor->id,
        'file_path' => 'ads/defaults-test.avif',
        'target_screen' => 'kitchen',
        'is_vertical' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $asset = AdAsset::query()->findOrFail($assetId);

    expect($asset->duration_seconds)->toBe(10)
        ->and($asset->frequency_weight)->toBe(1);
});
