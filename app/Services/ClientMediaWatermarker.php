<?php

namespace App\Services;

use App\Support\Watermark\WatermarkStamp;
use GdImage;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Applies the approved Exotic Escorts logo to a client image before it is
 * handed to WordPress. The source upload is never changed in place: callers
 * receive a short-lived, stamped copy and remain free to retry the original.
 */
class ClientMediaWatermarker
{
    /** @var array<string, float> */
    private const SIZE_WIDTH_RATIOS = [
        'small' => 0.18,
        'medium' => 0.24,
        'large' => 0.30,
    ];

    private const MIN_LOGO_WIDTH = 64;

    private const MAX_LOGO_WIDTH = 480;

    /**
     * @return array{path: string, mime_type: string}
     */
    public function stamp(UploadedFile $file, ?string $position = null, ?string $size = null): array
    {
        if (! function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Image watermarking is unavailable on this server.');
        }

        $sourcePath = (string) $file->getRealPath();
        if ($sourcePath === '' || ! is_file($sourcePath)) {
            throw new RuntimeException('The uploaded image is no longer available for watermarking.');
        }

        [$image, $imageType] = $this->loadImage($sourcePath);
        if ($image === null) {
            throw new RuntimeException('The uploaded image could not be decoded for watermarking.');
        }

        $logo = @imagecreatefrompng($this->logoPath());
        if (! $logo instanceof GdImage) {
            imagedestroy($image);

            throw new RuntimeException('The configured Exotic Escorts watermark logo is unavailable.');
        }

        try {
            $this->overlay($image, $logo, $this->position($position), $this->size($size));
            $temporaryPath = $this->temporaryPath($imageType);

            if (! $this->writeImage($image, $temporaryPath, $imageType)) {
                @unlink($temporaryPath);

                throw new RuntimeException('The watermarked image could not be written.');
            }

            return [
                'path' => $temporaryPath,
                'mime_type' => $this->mimeTypeFor($imageType),
            ];
        } finally {
            imagedestroy($image);
            imagedestroy($logo);
        }
    }

    public function enabledByDefault(): bool
    {
        return (bool) config('client_media_watermark.enabled', true);
    }

    /** @return array<int, string> */
    public function positions(): array
    {
        return WatermarkStamp::POSITIONS;
    }

    /** @return array<int, string> */
    public function sizes(): array
    {
        return array_keys(self::SIZE_WIDTH_RATIOS);
    }

    private function overlay(GdImage $image, GdImage $logo, string $position, string $size): void
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        if ($imageWidth < 1 || $imageHeight < 1 || $logoWidth < 1 || $logoHeight < 1) {
            throw new RuntimeException('The image or watermark logo has invalid dimensions.');
        }

        $targetWidth = min(
            self::MAX_LOGO_WIDTH,
            max(self::MIN_LOGO_WIDTH, (int) round($imageWidth * self::SIZE_WIDTH_RATIOS[$size]))
        );
        $targetWidth = min($targetWidth, $imageWidth);
        $targetHeight = max(1, (int) round($targetWidth * ($logoHeight / $logoWidth)));
        if ($targetHeight > $imageHeight) {
            $targetHeight = $imageHeight;
            $targetWidth = max(1, (int) round($targetHeight * ($logoWidth / $logoHeight)));
        }

        $scaledLogo = imagecreatetruecolor($targetWidth, $targetHeight);
        if (! $scaledLogo instanceof GdImage) {
            throw new RuntimeException('The watermark image could not be prepared.');
        }

        try {
            imagealphablending($scaledLogo, false);
            imagesavealpha($scaledLogo, true);
            imagefill($scaledLogo, 0, 0, imagecolorallocatealpha($scaledLogo, 255, 255, 255, 127));
            imagecopyresampled($scaledLogo, $logo, 0, 0, 0, 0, $targetWidth, $targetHeight, $logoWidth, $logoHeight);
            $this->applyOpacity($scaledLogo, $this->opacity());

            [$originX, $originY] = (new WatermarkStamp('', $position))->originIn(
                $imageWidth,
                $imageHeight,
                $targetWidth,
                $targetHeight
            );
            imagealphablending($image, true);
            imagecopy($image, $scaledLogo, $originX, $originY, 0, 0, $targetWidth, $targetHeight);
        } finally {
            imagedestroy($scaledLogo);
        }
    }

    private function applyOpacity(GdImage $logo, int $opacity): void
    {
        $factor = $opacity / 100;
        imagealphablending($logo, false);

        for ($y = 0; $y < imagesy($logo); $y++) {
            for ($x = 0; $x < imagesx($logo); $x++) {
                $colour = imagecolorat($logo, $x, $y);
                $alpha = ($colour >> 24) & 0x7F;
                $adjustedAlpha = (int) round(127 - ((127 - $alpha) * $factor));
                imagesetpixel(
                    $logo,
                    $x,
                    $y,
                    imagecolorallocatealpha(
                        $logo,
                        ($colour >> 16) & 0xFF,
                        ($colour >> 8) & 0xFF,
                        $colour & 0xFF,
                        $adjustedAlpha
                    )
                );
            }
        }
    }

    /** @return array{0: ?GdImage, 1: int} */
    private function loadImage(string $path): array
    {
        $info = @getimagesize($path);
        $type = (int) ($info[2] ?? 0);
        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return [$image instanceof GdImage ? $image : null, $type];
    }

    private function writeImage(GdImage $image, string $path, int $imageType): bool
    {
        return match ($imageType) {
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($image, $path, 92),
            IMAGETYPE_PNG => imagepng($image, $path),
            IMAGETYPE_JPEG => imagejpeg($image, $path, 92),
            default => false,
        };
    }

    private function temporaryPath(int $imageType): string
    {
        $extension = match ($imageType) {
            IMAGETYPE_WEBP => 'webp',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_JPEG => 'jpg',
            default => throw new RuntimeException('This image format cannot be watermarked.'),
        };
        $path = tempnam(sys_get_temp_dir(), 'crm-watermark-');
        if ($path === false) {
            throw new RuntimeException('The watermark workspace could not be prepared.');
        }

        $withExtension = $path.'.'.$extension;
        if (! @rename($path, $withExtension)) {
            @unlink($path);

            throw new RuntimeException('The watermark workspace could not be prepared.');
        }

        return $withExtension;
    }

    private function logoPath(): string
    {
        return (string) config('client_media_watermark.logo_path');
    }

    private function position(?string $position): string
    {
        $candidate = strtolower(trim((string) $position));
        if (in_array($candidate, $this->positions(), true)) {
            return $candidate;
        }

        $default = strtolower(trim((string) config('client_media_watermark.default_position', 'br')));

        return in_array($default, $this->positions(), true) ? $default : 'br';
    }

    private function size(?string $size): string
    {
        $candidate = strtolower(trim((string) $size));
        if (array_key_exists($candidate, self::SIZE_WIDTH_RATIOS)) {
            return $candidate;
        }

        $default = strtolower(trim((string) config('client_media_watermark.default_size', 'medium')));

        return array_key_exists($default, self::SIZE_WIDTH_RATIOS) ? $default : 'medium';
    }

    private function opacity(): int
    {
        return max(1, min(100, (int) config('client_media_watermark.opacity', 82)));
    }

    private function mimeTypeFor(int $imageType): string
    {
        return match ($imageType) {
            IMAGETYPE_WEBP => 'image/webp',
            IMAGETYPE_PNG => 'image/png',
            default => 'image/jpeg',
        };
    }
}
