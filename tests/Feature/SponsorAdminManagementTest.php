<?php

declare(strict_types=1);

use App\Livewire\Admin\Sponsors\AssetManager;
use App\Livewire\Admin\Sponsors\Form;
use App\Livewire\Admin\Sponsors\Index;
use App\Models\Sponsor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

test('guests are redirected from sponsors admin page', function (): void {
    $response = $this->get(route('sponsors.index'));

    $response->assertRedirect(route('login'));
});

test('non-super-admin users cannot access sponsors admin page', function (): void {
    $user = User::factory()->create(['is_super_admin' => false, 'is_admin' => true]);
    $this->actingAs($user);

    $response = $this->get(route('sponsors.index'));

    $response->assertForbidden();
});

test('super-admin users can access sponsors admin page', function (): void {
    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    $response = $this->get(route('sponsors.index'));

    $response->assertOk();
});

test('super-admin can update concurrent sponsor display setting', function (): void {
    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    Cache::forget('display.concurrent_sponsors');

    Livewire::test(Index::class)
        ->set('concurrentSponsors', 3)
        ->call('saveDisplaySettings')
        ->assertHasNoErrors();

    expect((int) cache('display.concurrent_sponsors'))->toBe(3);
});

test('super-admin can create and update sponsor campaigns via livewire form', function (): void {
    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    Livewire::test(Form::class)
        ->set('name', 'Pinoke Partner')
        ->set('title', 'Summer Promo')
        ->set('description', 'Campaign details')
        ->set('callToAction', 'https://example.com/visit')
        ->set('startDate', '2026-06-01')
        ->set('endDate', '2026-06-30')
        ->set('isActive', true)
        ->call('save')
        ->assertHasNoErrors();

    $sponsor = Sponsor::query()->firstOrFail();

    expect($sponsor->name)->toBe('Pinoke Partner')
        ->and($sponsor->call_to_action)->toBe('https://example.com/visit')
        ->and($sponsor->is_active)->toBeTrue();

    Livewire::test(Form::class, ['sponsorId' => $sponsor->id])
        ->set('name', 'Pinoke Partner Updated')
        ->set('endDate', '2026-07-15')
        ->set('isActive', false)
        ->call('save')
        ->assertHasNoErrors();

    $sponsor->refresh();

    expect($sponsor->name)->toBe('Pinoke Partner Updated')
        ->and($sponsor->is_active)->toBeFalse();
});

test('vertical image is rejected for wedstrijdschema and both screens', function (): void {
    skipIfAvifEncodingUnsupportedForSponsorAdminTests($this);

    Storage::fake('public');

    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    $sponsor = Sponsor::factory()->create();

    Livewire::test(AssetManager::class, ['sponsor' => $sponsor])
        ->set('file', UploadedFile::fake()->image('portrait.jpg', 700, 1400))
        ->set('targetScreen', 'wedstrijdschema')
        ->set('durationSeconds', 12)
        ->set('frequencyWeight', 3)
        ->call('save')
        ->assertHasErrors(['file']);

    expect($sponsor->adAssets()->count())->toBe(0);
});

test('kitchen target accepts vertical image and stores asset', function (): void {
    skipIfAvifEncodingUnsupportedForSponsorAdminTests($this);

    Storage::fake('public');

    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    $sponsor = Sponsor::factory()->create();

    Livewire::test(AssetManager::class, ['sponsor' => $sponsor])
        ->set('file', UploadedFile::fake()->image('portrait.jpg', 700, 1400))
        ->set('targetScreen', 'kitchen')
        ->set('durationSeconds', 15)
        ->set('frequencyWeight', 2)
        ->call('save')
        ->assertHasNoErrors();

    $asset = $sponsor->adAssets()->first();

    expect($asset)->not->toBeNull()
        ->and($asset?->is_vertical)->toBeTrue()
        ->and($asset?->target_screen)->toBe('kitchen')
        ->and($asset?->duration_seconds)->toBe(15);
});

test('asset manager accepts svg uploads', function (): void {
    Storage::fake('public');

    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    $sponsor = Sponsor::factory()->create();

    $svg = <<<'SVG'
<svg width="320" height="120" viewBox="0 0 320 120" xmlns="http://www.w3.org/2000/svg">
  <rect width="320" height="120" fill="none" />
  <text x="10" y="60">Pinoke</text>
</svg>
SVG;

    $file = UploadedFile::fake()->createWithContent('banner.svg', $svg);

    Livewire::test(AssetManager::class, ['sponsor' => $sponsor])
        ->set('file', $file)
        ->set('targetScreen', 'both')
        ->set('durationSeconds', 20)
        ->set('frequencyWeight', 4)
        ->call('save')
        ->assertHasNoErrors();

    $asset = $sponsor->adAssets()->first();

    expect($asset)->not->toBeNull()
        ->and($asset?->file_path)->toEndWith('.svg')
        ->and($asset?->duration_seconds)->toBe(20)
        ->and($asset?->frequency_weight)->toBe(4);
});

test('sponsor form validates call to action as a url', function (): void {
    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    Livewire::test(Form::class)
        ->set('name', 'Pinoke Partner')
        ->set('callToAction', 'not-a-url')
        ->set('startDate', '2026-06-01')
        ->set('endDate', '2026-06-30')
        ->set('isActive', true)
        ->call('save')
        ->assertHasErrors(['callToAction']);
});

test('asset settings can be updated', function (): void {
    $user = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($user);

    $sponsor = Sponsor::factory()->create();

    $asset = $sponsor->adAssets()->create([
        'file_path' => 'ads/existing.avif',
        'target_screen' => 'kitchen',
        'is_vertical' => false,
        'duration_seconds' => 10,
        'frequency_weight' => 1,
    ]);

    Livewire::test(AssetManager::class, ['sponsor' => $sponsor])
        ->call('prepareAssetSettings', $asset->id, $asset->target_screen, $asset->duration_seconds, $asset->frequency_weight)
        ->set("assetSettings.{$asset->id}.target_screen", 'both')
        ->set("assetSettings.{$asset->id}.duration_seconds", 22)
        ->set("assetSettings.{$asset->id}.frequency_weight", 5)
        ->call('updateAssetSettings', $asset->id)
        ->assertHasNoErrors();

    $asset->refresh();

    expect($asset->target_screen)->toBe('both')
        ->and($asset->duration_seconds)->toBe(22)
        ->and($asset->frequency_weight)->toBe(5);
});

function skipIfAvifEncodingUnsupportedForSponsorAdminTests(TestCase $testCase): void
{
    if (extension_loaded('imagick') && class_exists(Imagick::class)) {
        try {
            if (in_array('AVIF', Imagick::queryFormats(), true)) {
                return;
            }
        } catch (Throwable) {
            // Fall through to GD support checks.
        }
    }

    if (function_exists('imageavif')) {
        return;
    }

    $testCase->markTestSkipped('AVIF encoding is not available in this PHP runtime.');
}
