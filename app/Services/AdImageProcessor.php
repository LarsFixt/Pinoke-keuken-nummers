<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\AvifEncoder;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageManagerInterface;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class AdImageProcessor
{
    /**
     * Process and store an ad image.
     * Raster formats are AVIF-encoded, while SVG files are stored as-is.
     *
     * @return array{file_path: string, is_vertical: bool}
     */
    public function processAndStore(UploadedFile $file): array
    {
        if ($this->isSvgUpload($file)) {
            return $this->storeSvg($file);
        }

        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '') {
            throw new InvalidArgumentException('Uploaded file path could not be resolved.');
        }

        $image = $this->imageManager()->decodePath($sourcePath)->orient();

        $isVertical = $image->height() > $image->width();

        $encodedImage = $image->encode(new AvifEncoder(quality: 80));

        $filePath = sprintf('ads/%s.avif', (string) Str::uuid());

        Storage::disk('public')->put($filePath, $encodedImage->toString(), 'public');

        return [
            'file_path' => $filePath,
            'is_vertical' => $isVertical,
        ];
    }

    private function isSvgUpload(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'svg') {
            return true;
        }

        $mimeType = strtolower((string) $file->getMimeType());

        return $mimeType === 'image/svg+xml';
    }

    /**
     * @return array{file_path: string, is_vertical: bool}
     */
    private function storeSvg(UploadedFile $file): array
    {
        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '') {
            throw new InvalidArgumentException('Uploaded file path could not be resolved.');
        }

        $contents = file_get_contents($sourcePath);
        if (! is_string($contents) || $contents === '') {
            throw new InvalidArgumentException('Uploaded SVG file could not be read.');
        }

        $filePath = sprintf('ads/%s.svg', (string) Str::uuid());

        Storage::disk('public')->put($filePath, $contents, 'public');

        $dimensions = $this->extractSvgDimensions($contents);

        return [
            'file_path' => $filePath,
            'is_vertical' => $dimensions !== null
                ? $dimensions['height'] > $dimensions['width']
                : false,
        ];
    }

    /**
     * @return array{width: float, height: float}|null
     */
    private function extractSvgDimensions(string $svg): ?array
    {
        if (preg_match('/\bviewBox\s*=\s*"\s*[-\d.]+\s+[-\d.]+\s+([\d.]+)\s+([\d.]+)\s*"/i', $svg, $matches) === 1) {
            $width = (float) $matches[1];
            $height = (float) $matches[2];

            if ($width > 0 && $height > 0) {
                return ['width' => $width, 'height' => $height];
            }
        }

        if (
            preg_match('/\bwidth\s*=\s*"\s*([\d.]+)(?:px)?\s*"/i', $svg, $widthMatches) === 1
            && preg_match('/\bheight\s*=\s*"\s*([\d.]+)(?:px)?\s*"/i', $svg, $heightMatches) === 1
        ) {
            $width = (float) $widthMatches[1];
            $height = (float) $heightMatches[1];

            if ($width > 0 && $height > 0) {
                return ['width' => $width, 'height' => $height];
            }
        }

        return null;
    }

    private function imageManager(): ImageManagerInterface
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                if (in_array('AVIF', \Imagick::queryFormats(), true)) {
                    return ImageManager::usingDriver(ImagickDriver::class);
                }
            } catch (Throwable) {
                // Fall through to GD if available.
            }
        }

        if (function_exists('imageavif')) {
            return ImageManager::usingDriver(GdDriver::class);
        }

        if (extension_loaded('imagick')) {
            return ImageManager::usingDriver(ImagickDriver::class);
        }

        throw new RuntimeException('AVIF encoding is not supported by the current PHP image drivers.');
    }
}
