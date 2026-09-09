<?php

namespace App\Support\Watermark;

use GdImage;

/**
 * Recovers the pixels a semi-transparent watermark was blended over.
 *
 * The WordPress theme stamps the logo destructively at upload — it composites
 * onto the canvas and re-encodes the temp file before storing it — so no clean
 * original survives anywhere. What does survive is every parameter of the
 * blend: the logo PNG, its per-pixel alpha, the 90% opacity pass applied to it,
 * and a placement rule fixed to nine positions with a ten-pixel inset.
 *
 * Knowing all of that turns removal into arithmetic rather than guesswork.
 * Compositing was
 *
 *     result = a * logo + (1 - a) * original
 *
 * so the original comes back as
 *
 *     original = (result - a * logo) / (1 - a)
 *
 * which recovers the true pixels instead of inventing plausible ones. It holds
 * wherever a < 1. Fully opaque logo pixels carry no information about what was
 * beneath them and are filled from their surroundings instead; on a logo drawn
 * mostly in partial alpha that is a small minority of the stamp.
 *
 * Everything here is GD, which is the same extension that applied the mark and
 * the only image extension available on the production host.
 */
class WatermarkRemover
{
    /** GD stores alpha 0-127 where 0 is opaque and 127 is fully transparent. */
    private const GD_ALPHA_MAX = 127;

    /** Below this blend factor the logo left no meaningful trace. */
    private const MIN_BLEND = 0.02;

    /** At or above this, nothing of the original survives and the pixel is filled instead. */
    private const OPAQUE_BLEND = 0.985;

    /**
     * A recovered channel this far outside 0-255 means we are not looking at the
     * watermark — a rescaled thumbnail, or an image that never carried one.
     */
    private const GAMUT_TOLERANCE = 24;

    /** Share of blended pixels allowed to fall outside gamut before we decline. */
    private const MAX_OUT_OF_GAMUT_RATIO = 0.12;

    public function __construct(
        private readonly WatermarkStamp $stamp
    ) {
    }

    /**
     * Rewrite the file in place with the watermark removed.
     *
     * Returns false, having changed nothing, whenever the removal cannot be
     * trusted: no GD, an unreadable image, a stamp that does not fit, or pixels
     * that do not look like they were blended with this logo. A seeded photo
     * that still carries the mark is a much smaller problem than one with a
     * rectangle of mangled pixels stamped across it.
     */
    public function removeFromFile(string $imagePath): bool
    {
        if (!function_exists('imagecreatetruecolor') || !$this->stamp->isUsable() || !is_file($imagePath)) {
            return false;
        }

        [$image, $imageType] = $this->loadImage($imagePath);
        if ($image === null) {
            return false;
        }

        $logo = @imagecreatefrompng($this->stamp->pngPath);
        if (!$logo instanceof GdImage) {
            imagedestroy($image);

            return false;
        }

        try {
            return $this->apply($image, $logo, $imagePath, $imageType);
        } finally {
            imagedestroy($image);
            imagedestroy($logo);
        }
    }

    private function apply(GdImage $image, GdImage $logo, string $imagePath, int $imageType): bool
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $stampWidth = imagesx($logo);
        $stampHeight = imagesy($logo);

        if ($stampWidth > $imageWidth || $stampHeight > $imageHeight) {
            return false;
        }

        $blend = $this->blendMap($logo, $stampWidth, $stampHeight);
        [$originX, $originY] = $this->stamp->originIn($imageWidth, $imageHeight, $stampWidth, $stampHeight);

        $recovered = [];
        $opaque = [];
        $blended = 0;
        $outOfGamut = 0;

        for ($y = 0; $y < $stampHeight; $y++) {
            $targetY = $originY + $y;
            if ($targetY < 0 || $targetY >= $imageHeight) {
                continue;
            }

            for ($x = 0; $x < $stampWidth; $x++) {
                $targetX = $originX + $x;
                if ($targetX < 0 || $targetX >= $imageWidth) {
                    continue;
                }

                $alpha = $blend[$y][$x];
                if ($alpha < self::MIN_BLEND) {
                    continue;
                }

                if ($alpha >= self::OPAQUE_BLEND) {
                    $opaque[] = [$targetX, $targetY];
                    continue;
                }

                $logoColour = imagecolorat($logo, $x, $y);
                $resultColour = imagecolorat($image, $targetX, $targetY);
                $channels = [];

                foreach ([16, 8, 0] as $shift) {
                    $result = ($resultColour >> $shift) & 0xFF;
                    $logoChannel = ($logoColour >> $shift) & 0xFF;
                    $value = ($result - ($alpha * $logoChannel)) / (1 - $alpha);

                    if ($value < -self::GAMUT_TOLERANCE || $value > 255 + self::GAMUT_TOLERANCE) {
                        $outOfGamut++;
                    }

                    $channels[] = (int) round(max(0, min(255, $value)));
                }

                $blended++;
                $recovered[] = [$targetX, $targetY, $channels[0], $channels[1], $channels[2]];
            }
        }

        if ($blended < 1) {
            return false;
        }

        // Three channels are counted per pixel, so compare against that.
        if (($outOfGamut / ($blended * 3)) > self::MAX_OUT_OF_GAMUT_RATIO) {
            return false;
        }

        foreach ($recovered as [$x, $y, $r, $g, $b]) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
        }

        $this->fillOpaquePixels($image, $opaque);

        return $this->writeImage($image, $imagePath, $imageType);
    }

    /**
     * Write back in the format we read.
     *
     * The upload names the file from the source URL, and these markets serve
     * WebP — a file called .webp carrying JPEG bytes would be rejected on
     * arrival. Preserving the format also avoids adding a generation of
     * recompression to an image that has already been through two.
     */
    private function writeImage(GdImage $image, string $path, int $imageType): bool
    {
        return match ($imageType) {
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($image, $path, 92),
            IMAGETYPE_PNG => imagepng($image, $path),
            default => imagejpeg($image, $path, 92),
        };
    }

    /**
     * Per-pixel blend factor, reproducing the theme's opacity pass exactly.
     *
     * The forward operation scales each pixel's alpha towards transparency
     * relative to the most opaque pixel in the logo. Inverting the composite
     * means reproducing that scaling first, or every recovered pixel is wrong
     * by a constant factor.
     *
     * @return array<int, array<int, float>>
     */
    private function blendMap(GdImage $logo, int $width, int $height): array
    {
        $opacity = $this->stamp->opacityPercent / 100;

        $minAlpha = self::GD_ALPHA_MAX;
        for ($x = 0; $x < $width; $x++) {
            for ($y = 0; $y < $height; $y++) {
                $alpha = (imagecolorat($logo, $x, $y) >> 24) & 0xFF;
                if ($alpha < $minAlpha) {
                    $minAlpha = $alpha;
                }
            }
        }

        $map = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $alpha = (imagecolorat($logo, $x, $y) >> 24) & 0xFF;

                $adjusted = $minAlpha !== self::GD_ALPHA_MAX
                    ? self::GD_ALPHA_MAX + self::GD_ALPHA_MAX * $opacity * ($alpha - self::GD_ALPHA_MAX) / (self::GD_ALPHA_MAX - $minAlpha)
                    : $alpha + self::GD_ALPHA_MAX * $opacity;

                $adjusted = max(0, min(self::GD_ALPHA_MAX, $adjusted));
                $map[$y][$x] = (self::GD_ALPHA_MAX - $adjusted) / self::GD_ALPHA_MAX;
            }
        }

        return $map;
    }

    /**
     * Fill the pixels the logo covered completely.
     *
     * Nothing of the original survives under a fully opaque pixel, so it is
     * averaged from the nearest neighbours that were recoverable. The regions
     * are thin — the outline of a letter rather than a block — so a local
     * average reads as texture rather than as a patch.
     *
     * @param  array<int, array{0: int, 1: int}>  $pixels
     */
    private function fillOpaquePixels(GdImage $image, array $pixels): void
    {
        if ($pixels === []) {
            return;
        }

        $opaqueIndex = [];
        foreach ($pixels as [$x, $y]) {
            $opaqueIndex[$y . ':' . $x] = true;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // Two passes: the first fills from recovered neighbours, the second
        // reaches pixels that were surrounded entirely by opaque ones.
        for ($pass = 0; $pass < 2; $pass++) {
            $updates = [];

            foreach ($pixels as [$x, $y]) {
                $sum = [0, 0, 0];
                $found = 0;

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if (($dx === 0 && $dy === 0) || $nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                            continue;
                        }
                        if ($pass === 0 && isset($opaqueIndex[$ny . ':' . $nx])) {
                            continue;
                        }

                        $colour = imagecolorat($image, $nx, $ny);
                        $sum[0] += ($colour >> 16) & 0xFF;
                        $sum[1] += ($colour >> 8) & 0xFF;
                        $sum[2] += $colour & 0xFF;
                        $found++;
                    }
                }

                if ($found > 0) {
                    $updates[] = [$x, $y, (int) ($sum[0] / $found), (int) ($sum[1] / $found), (int) ($sum[2] / $found)];
                }
            }

            foreach ($updates as [$x, $y, $r, $g, $b]) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
                unset($opaqueIndex[$y . ':' . $x]);
            }
        }
    }

    /**
     * @return array{0: ?GdImage, 1: int}
     */
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
}
