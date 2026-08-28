<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Image\Image;
use Illuminate\Support\Facades\Image as ImageFacade;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

        $image = $this->selectDriver(ImageFacade::fromUpload($file))->orient();

        $isVertical = $image->height() > $image->width();

        $filePath = $image->toAvif()->quality(80)
            ->storePubliclyAs('ads', sprintf('%s.avif', Str::uuid()), 'public');

        if ($filePath === false) {
            throw new RuntimeException('Failed to store the processed ad image.');
        }

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

    /**
     * Prefer whichever driver actually supports AVIF encoding: Imagick when it
     * reports AVIF support, GD when it exposes imageavif(), then Imagick as a
     * last resort so decode-only Imagick installs still surface a clear error.
     */
    private function selectDriver(Image $image): Image
    {
        if (extension_loaded('imagick') && class_exists(\Imagick::class)) {
            try {
                if (in_array('AVIF', \Imagick::queryFormats(), true)) {
                    return $image->usingImagick();
                }
            } catch (Throwable) {
                // Fall through to GD if available.
            }
        }

        if (function_exists('imageavif')) {
            return $image->usingGd();
        }

        if (extension_loaded('imagick')) {
            return $image->usingImagick();
        }

        throw new RuntimeException('AVIF encoding is not supported by the current PHP image drivers.');
    }
}
