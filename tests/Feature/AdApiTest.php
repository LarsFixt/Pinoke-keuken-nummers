<?php

declare(strict_types=1);

use App\Models\AdAsset;
use App\Models\Sponsor;
use Carbon\CarbonImmutable;

test('api ads endpoint requires the screen query parameter', function (): void {
    $response = $this->getJson('/api/ads');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['screen']);
});

test('api ads endpoint returns only currently active sponsor assets for the requested screen', function (): void {
    CarbonImmutable::setTestNow('2026-05-31 09:00:00');

    $activeSponsor = Sponsor::factory()->create([
        'name' => 'Active Sponsor',
        'is_active' => true,
        'start_date' => '2026-05-01',
        'end_date' => '2026-06-15',
    ]);

    $inactiveSponsor = Sponsor::factory()->create([
        'name' => 'Inactive Sponsor',
        'is_active' => false,
        'start_date' => '2026-05-01',
        'end_date' => '2026-06-15',
    ]);

    AdAsset::factory()->for($activeSponsor)->create([
        'file_path' => 'ads/active-both.avif',
        'target_screen' => 'both',
        'duration_seconds' => 8,
        'frequency_weight' => 1,
    ]);

    AdAsset::factory()->for($activeSponsor)->create([
        'file_path' => 'ads/active-kitchen.avif',
        'target_screen' => 'kitchen',
        'duration_seconds' => 10,
        'frequency_weight' => 1,
    ]);

    AdAsset::factory()->for($activeSponsor)->create([
        'file_path' => 'ads/active-wedstrijd.avif',
        'target_screen' => 'wedstrijdschema',
        'duration_seconds' => 6,
        'frequency_weight' => 1,
    ]);

    AdAsset::factory()->for($inactiveSponsor)->create([
        'file_path' => 'ads/inactive.avif',
        'target_screen' => 'both',
    ]);

    $response = $this->getJson('/api/ads?screen=kitchen');

    $response->assertOk();

    $payload = $response->json();

    expect($payload)->toBeArray()->toHaveCount(2)
        ->and(collect($payload)->pluck('image_url')->every(static fn (string $url): bool => str_contains($url, '/storage/ads/active-')))->toBeTrue();
});

test('api ads endpoint applies frequency weighting in the playlist output', function (): void {
    CarbonImmutable::setTestNow('2026-05-31 09:00:00');

    $sponsor = Sponsor::factory()->create([
        'name' => 'Weighted Sponsor',
        'is_active' => true,
        'start_date' => '2026-05-01',
        'end_date' => '2026-06-30',
    ]);

    AdAsset::factory()->for($sponsor)->create([
        'file_path' => 'ads/weight-3.avif',
        'target_screen' => 'both',
        'duration_seconds' => 11,
        'frequency_weight' => 3,
    ]);

    AdAsset::factory()->for($sponsor)->create([
        'file_path' => 'ads/weight-1.avif',
        'target_screen' => 'both',
        'duration_seconds' => 7,
        'frequency_weight' => 1,
    ]);

    $response = $this->getJson('/api/ads?screen=kitchen');
    $response->assertOk();

    $payload = collect($response->json());

    expect($payload)->toHaveCount(4)
        ->and($payload->where('image_url', asset('storage/ads/weight-3.avif'))->count())->toBe(3)
        ->and($payload->where('image_url', asset('storage/ads/weight-1.avif'))->count())->toBe(1);
});

test('api ads endpoint returns the expected response shape', function (): void {
    CarbonImmutable::setTestNow('2026-05-31 09:00:00');

    $sponsor = Sponsor::factory()->create([
        'name' => 'Shape Sponsor',
        'title' => 'Title',
        'call_to_action' => 'https://example.com/tap-now',
        'is_active' => true,
        'start_date' => '2026-05-01',
        'end_date' => '2026-06-30',
    ]);

    AdAsset::factory()->for($sponsor)->create([
        'file_path' => 'ads/shape.avif',
        'target_screen' => 'both',
        'duration_seconds' => 9,
        'frequency_weight' => 1,
    ]);

    $response = $this->getJson('/api/ads?screen=wedstrijdschema');

    $response->assertOk()
        ->assertJsonStructure([
            '*' => [
                'sponsor_name',
                'title',
                'call_to_action',
                'image_url',
                'duration_seconds',
            ],
        ]);

    $item = $response->json()[0];

    expect($item['sponsor_name'])->toBe('Shape Sponsor')
        ->and($item['call_to_action'])->toBe('https://example.com/tap-now')
        ->and($item['image_url'])->toBe(asset('storage/ads/shape.avif'))
        ->and($item['duration_seconds'])->toBe(9);
});

test('cors headers are present for api ads requests', function (): void {
    $response = $this
        ->withHeaders(['Origin' => 'https://match.example.com'])
        ->get('/api/ads?screen=kitchen');

    $response->assertHeader('Access-Control-Allow-Origin', '*');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});
