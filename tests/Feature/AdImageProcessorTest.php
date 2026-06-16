<?php

declare(strict_types=1);

use App\Services\AdImageProcessor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use Tests\TestCase;

test('it processes and stores an ad image as avif on the public disk', function (): void {
    skipIfAvifEncodingIsUnsupported($this);

    Storage::fake('public');

    $processor = new AdImageProcessor;
    $file = UploadedFile::fake()->image('banner.jpg', 1200, 700);

    $result = $processor->processAndStore($file);

    expect($result['file_path'])->toStartWith('ads/')
        ->and($result['file_path'])->toEndWith('.avif')
        ->and($result['is_vertical'])->toBeFalse();

    Storage::disk('public')->assertExists($result['file_path']);
});

test('it marks a portrait image as vertical', function (): void {
    skipIfAvifEncodingIsUnsupported($this);

    Storage::fake('public');

    $processor = new AdImageProcessor;
    $file = UploadedFile::fake()->image('portrait.jpg', 600, 1200);

    $result = $processor->processAndStore($file);

    expect($result['is_vertical'])->toBeTrue();
});

test('it keeps original dimensions when processing raster images', function (): void {
    skipIfAvifEncodingIsUnsupported($this);

    Storage::fake('public');

    $processor = new AdImageProcessor;
    $file = UploadedFile::fake()->image('logo.jpg', 1600, 400);

    $result = $processor->processAndStore($file);

    $storedPath = Storage::disk('public')->path($result['file_path']);
    $image = imageManagerForTests()->decodePath($storedPath);

    expect($image->width())->toBe(1600)
        ->and($image->height())->toBe(400);
});

test('it preserves transparency when containing png images', function (): void {
    skipIfAvifEncodingIsUnsupported($this);

    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD image helpers are unavailable in this PHP runtime.');
    }

    Storage::fake('public');

    $sourcePath = tempnam(sys_get_temp_dir(), 'ad-transparent-');

    if ($sourcePath === false) {
        $this->fail('Unable to create temporary file for transparency test.');
    }

    $canvas = imagecreatetruecolor(1600, 400);

    if ($canvas === false) {
        $this->fail('Unable to create GD canvas for transparency test.');
    }

    imagealphablending($canvas, false);
    imagesavealpha($canvas, true);

    $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
    imagefilledrectangle($canvas, 0, 0, 1599, 399, $transparent);

    $solid = imagecolorallocatealpha($canvas, 255, 64, 64, 0);
    imagefilledrectangle($canvas, 300, 100, 1300, 300, $solid);

    imagepng($canvas, $sourcePath);
    imagedestroy($canvas);

    $processor = new AdImageProcessor;
    $file = new UploadedFile($sourcePath, 'transparent.png', 'image/png', null, true);

    $result = $processor->processAndStore($file);

    $storedPath = Storage::disk('public')->path($result['file_path']);
    $image = imageManagerForTests()->decodePath($storedPath);

    $topLeftPixel = $image->colorAt(0, 0);
    $centerPixel = $image->colorAt((int) floor($image->width() / 2), (int) floor($image->height() / 2));

    expect($topLeftPixel->isTransparent())->toBeTrue()
        ->and($centerPixel->isClear())->toBeFalse();

    @unlink($sourcePath);
});

test('it stores svg assets without avif conversion', function (): void {
    Storage::fake('public');

    $sourcePath = tempnam(sys_get_temp_dir(), 'ad-svg-');

    if ($sourcePath === false) {
        $this->fail('Unable to create temporary file for SVG test.');
    }

    $svg = <<<'SVG'
<svg width="320" height="120" viewBox="0 0 320 120" xmlns="http://www.w3.org/2000/svg">
  <rect width="320" height="120" fill="none" />
  <text x="10" y="60">Pinoke</text>
</svg>
SVG;

    file_put_contents($sourcePath, $svg);

    $processor = new AdImageProcessor;
    $file = new UploadedFile($sourcePath, 'banner.svg', 'image/svg+xml', null, true);

    $result = $processor->processAndStore($file);

    expect($result['file_path'])->toEndWith('.svg')
        ->and($result['is_vertical'])->toBeFalse();

    Storage::disk('public')->assertExists($result['file_path']);

    @unlink($sourcePath);
});

function skipIfAvifEncodingIsUnsupported(TestCase $testCase): void
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

function imageManagerForTests(): ImageManagerInterface
{
    if (extension_loaded('imagick')) {
        return ImageManager::usingDriver(ImagickDriver::class);
    }

    return ImageManager::usingDriver(GdDriver::class);
}
