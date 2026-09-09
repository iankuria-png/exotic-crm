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



    /**
     * A recovered channel this far outside 0-255 means we are not looking at the
     * watermark — a rescaled thumbnail, or an image that never carried one.
     */
    private const GAMUT_TOLERANCE = 24;








    private readonly WatermarkTuning $tuning;

    public function __construct(
        private readonly WatermarkStamp $stamp,
        ?WatermarkTuning $tuning = null,
    ) {
        $this->tuning = $tuning ?? new WatermarkTuning();
    }

    /**
     * Rewrite the file in place with the watermark removed.
     *
     * Returns false, having changed nothing, whenever the removal cannot be
     * trusted: no GD, an unreadable image, a stamp that barely lands, or pixels
     * that do not look like they were blended with this logo. A seeded photo
     * that still carries the mark is a much smaller problem than one with a
     * rectangle of mangled pixels stamped across it.
     */
    public function removeFromFile(string $imagePath): bool
    {
        return $this->attempt($imagePath)->applied;
    }

    /**
     * The same work, reporting what happened and the numbers behind it, so a
     * decline can be diagnosed rather than guessed at.
     */
    public function attempt(string $imagePath): WatermarkRemovalResult
    {
        if (!function_exists('imagecreatetruecolor')) {
            return WatermarkRemovalResult::declined('error', 'GD is not available.');
        }
        if (!$this->stamp->isUsable()) {
            return WatermarkRemovalResult::declined('not_configured', 'The watermark configuration is incomplete.');
        }
        if (!is_file($imagePath)) {
            return WatermarkRemovalResult::declined('unreadable', 'The image file is missing.');
        }

        [$image, $imageType] = $this->loadImage($imagePath);
        if ($image === null) {
            return WatermarkRemovalResult::declined('unreadable', 'The image could not be decoded.');
        }

        $logo = @imagecreatefrompng($this->stamp->pngPath);
        if (!$logo instanceof GdImage) {
            imagedestroy($image);

            return WatermarkRemovalResult::declined('not_configured', 'The watermark PNG could not be decoded.');
        }

        try {
            return $this->apply($image, $logo, $imagePath, $imageType);
        } finally {
            imagedestroy($image);
            imagedestroy($logo);
        }
    }

    private function apply(GdImage $image, GdImage $logo, string $imagePath, int $imageType): WatermarkRemovalResult
    {
        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        $stampWidth = imagesx($logo);
        $stampHeight = imagesy($logo);

        // A stamp wider or taller than the photo is normal, not a failure. The
        // theme composites with imagecopy, which clips rather than scales or
        // refuses, so a 674x160 logo centred on a 474px-wide photo hangs 100px
        // off each side and only its middle lands. The loops below walk the
        // overlap, so the placement maths just needs to allow a negative origin.
        $blend = $this->blendMap($logo, $stampWidth, $stampHeight);
        [$originX, $originY] = $this->stamp->originIn($imageWidth, $imageHeight, $stampWidth, $stampHeight);

        $recovered = [];
        $opaque = [];
        $chromaSuspect = [];
        $blended = 0;
        $landed = 0;
        $outOfGamut = 0;
        $evidence = 0;
        $implausible = 0;
        $maxBlend = 0.0;

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
                $maxBlend = max($maxBlend, $alpha);
                if ($alpha < $this->tuning->minBlend) {
                    continue;
                }

                $landed++;

                // Strongly blended pixels are the best evidence the mark is
                // actually here: at this alpha the logo alone accounts for most
                // of the observed value, so anything much darker than the logo
                // contributes says we are looking at the wrong place. They are
                // filled rather than inverted, but they still get a vote.
                if ($alpha >= $this->tuning->invertBelowBlend) {
                    $logoColour = imagecolorat($logo, $x, $y);
                    $resultColour = imagecolorat($image, $targetX, $targetY);

                    foreach ([16, 8, 0] as $shift) {
                        $floor = $alpha * (($logoColour >> $shift) & 0xFF) - $this->tuning->evidenceTolerance;
                        if ((($resultColour >> $shift) & 0xFF) < $floor) {
                            $implausible++;
                        }
                        $evidence++;
                    }

                    $opaque[] = [$targetX, $targetY];
                    $chromaSuspect[] = [$targetX, $targetY];
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
                $landed++;
                $recovered[] = [$targetX, $targetY, $channels[0], $channels[1], $channels[2]];

                if ($alpha >= $this->tuning->chromaRepairBlend) {
                    $chromaSuspect[] = [$targetX, $targetY];
                }
            }
        }

        $stats = [
            'image' => $imageWidth . 'x' . $imageHeight,
            'stamp' => $stampWidth . 'x' . $stampHeight,
            'origin' => $originX . ',' . $originY,
            'max_blend' => round($maxBlend, 3),
            'landed_px' => $landed,
            'inverted_px' => $blended,
            'filled_px' => count($opaque),
            'out_of_gamut' => $blended > 0 ? round($outOfGamut / ($blended * 3), 4) : 0.0,
            'implausible' => $evidence > 0 ? round($implausible / $evidence, 4) : 0.0,
        ];

        // Enough of the logo's ink has to land on the photo for the recovery to
        // be worth doing and for the checks below to mean anything.
        if ($landed < $this->tuning->minLandedPixels) {
            return WatermarkRemovalResult::declined('barely_lands', 'Too little of the watermark lands on this image.', $stats);
        }

        if ($evidence > 0 && $stats['implausible'] > $this->tuning->maxImplausibleRatio) {
            return WatermarkRemovalResult::declined(
                'not_this_watermark',
                sprintf(
                    'This image does not carry this watermark: %.1f%% of the strongest logo pixels are darker than the logo alone would make them (limit %.0f%%).',
                    $stats['implausible'] * 100,
                    $this->tuning->maxImplausibleRatio * 100
                ),
                $stats
            );
        }

        // Three channels are counted per pixel, so compare against that.
        if ($stats['out_of_gamut'] > $this->tuning->maxOutOfGamutRatio) {
            return WatermarkRemovalResult::declined(
                'not_this_watermark',
                sprintf(
                    'Recovered pixels do not look like this logo: %.1f%% fell outside gamut (limit %.0f%%).',
                    $stats['out_of_gamut'] * 100,
                    $this->tuning->maxOutOfGamutRatio * 100
                ),
                $stats
            );
        }

        foreach ($recovered as [$x, $y, $r, $g, $b]) {
            imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
        }

        $this->fillOpaquePixels($image, $opaque);
        $this->repairChroma($image, $chromaSuspect);

        return $this->writeImage($image, $imagePath, $imageType)
            ? WatermarkRemovalResult::applied($stats)
            : WatermarkRemovalResult::declined('write_failed', 'The cleaned image could not be written.', $stats);
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
     * Take the colour of strongly recovered pixels from the photo around them.
     *
     * Dividing by (1 - a) amplifies whatever compression error the file already
     * carried by 1/(1 - a) — about ten at the strongest part of the mark. JPEG
     * subsamples colour, so that error is far larger in chroma than in luma and
     * shows up as red and cyan speckle along the strokes rather than as grain.
     *
     * Luminance carries the texture and is kept as recovered. Chroma varies
     * slowly across a photograph, so averaging it from neighbours that were
     * never under the mark restores the colour without softening detail.
     *
     * Several passes, because the mark has solid regions — the figure inside
     * the O — that are wider than one neighbourhood. Each pass promotes the
     * pixels it fixed into the pool of trustworthy sources for the next.
     *
     * @param  array<int, array{0: int, 1: int}>  $pixels
     */
    private function repairChroma(GdImage $image, array $pixels): void
    {
        if ($pixels === []) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $suspect = [];
        foreach ($pixels as [$x, $y]) {
            $suspect[$y . ':' . $x] = true;
        }

        // Enough passes to reach the middle of the largest solid region in the
        // mark — the figure inside the O is about 36px across, so a radius-4
        // neighbourhood needs to work inwards several times to reach its core.
        for ($pass = 0; $pass < $this->tuning->chromaRepairPasses && $suspect !== []; $pass++) {
            $updates = [];

            foreach ($pixels as [$x, $y]) {
                if (!isset($suspect[$y . ':' . $x])) {
                    continue;
                }

                $cbSum = 0.0;
                $crSum = 0.0;
                $found = 0;

                for ($dy = -4; $dy <= 4; $dy++) {
                    for ($dx = -4; $dx <= 4; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if (($dx === 0 && $dy === 0) || $nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                            continue;
                        }
                        if (isset($suspect[$ny . ':' . $nx])) {
                            continue;
                        }

                        $colour = imagecolorat($image, $nx, $ny);
                        $r = ($colour >> 16) & 0xFF;
                        $g = ($colour >> 8) & 0xFF;
                        $b = $colour & 0xFF;

                        $cbSum += -0.168736 * $r - 0.331264 * $g + 0.5 * $b;
                        $crSum += 0.5 * $r - 0.418688 * $g - 0.081312 * $b;
                        $found++;
                    }
                }

                if ($found < 4) {
                    continue;
                }

                $colour = imagecolorat($image, $x, $y);
                $luma = 0.299 * (($colour >> 16) & 0xFF)
                    + 0.587 * (($colour >> 8) & 0xFF)
                    + 0.114 * ($colour & 0xFF);

                $cb = $cbSum / $found;
                $cr = $crSum / $found;

                $updates[] = [
                    $x,
                    $y,
                    (int) max(0, min(255, round($luma + 1.402 * $cr))),
                    (int) max(0, min(255, round($luma - 0.344136 * $cb - 0.714136 * $cr))),
                    (int) max(0, min(255, round($luma + 1.772 * $cb))),
                ];
            }

            if ($updates === []) {
                return;
            }

            foreach ($updates as [$x, $y, $r, $g, $b]) {
                imagesetpixel($image, $x, $y, imagecolorallocate($image, $r, $g, $b));
                unset($suspect[$y . ':' . $x]);
            }
        }
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

        // Works inwards: each pass fills from pixels that are already trusted
        // and then promotes them, so a solid region several pixels across —
        // the figure inside the O — is reached from its edges rather than
        // left as a patch.
        for ($pass = 0; $pass < $this->tuning->fillPasses && $opaqueIndex !== []; $pass++) {
            $updates = [];

            foreach ($pixels as [$x, $y]) {
                if (!isset($opaqueIndex[$y . ':' . $x])) {
                    continue;
                }

                $sum = [0, 0, 0];
                $found = 0;

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $nx = $x + $dx;
                        $ny = $y + $dy;

                        if (($dx === 0 && $dy === 0) || $nx < 0 || $ny < 0 || $nx >= $width || $ny >= $height) {
                            continue;
                        }
                        if (isset($opaqueIndex[$ny . ':' . $nx])) {
                            continue;
                        }

                        $colour = imagecolorat($image, $nx, $ny);
                        $sum[0] += ($colour >> 16) & 0xFF;
                        $sum[1] += ($colour >> 8) & 0xFF;
                        $sum[2] += $colour & 0xFF;
                        $found++;
                    }
                }

                if ($found >= 3) {
                    $updates[] = [$x, $y, (int) ($sum[0] / $found), (int) ($sum[1] / $found), (int) ($sum[2] / $found)];
                }
            }

            if ($updates === []) {
                return;
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
