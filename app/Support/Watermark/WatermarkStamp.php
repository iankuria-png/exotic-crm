<?php

namespace App\Support\Watermark;

/**
 * The watermark a market stamps onto every uploaded photo.
 *
 * Position codes and the ten-pixel inset mirror the WordPress theme's own
 * upload handler exactly, including its middle-right case, which adds the
 * inset where every other right-hand case subtracts it. That is a quirk of the
 * theme rather than a decision, but the stamp really does land there, so the
 * removal has to look there too.
 */
class WatermarkStamp
{
    public const POSITIONS = ['tl', 'tc', 'tr', 'cl', 'cc', 'cr', 'bl', 'bc', 'br'];

    private const INSET = 10;

    public function __construct(
        public readonly string $pngPath,
        public readonly string $position = 'cc',
        public readonly int $opacityPercent = 90,
    ) {
    }

    public function isUsable(): bool
    {
        return is_file($this->pngPath)
            && in_array($this->position, self::POSITIONS, true)
            && $this->opacityPercent > 0
            && $this->opacityPercent <= 100;
    }

    /**
     * Top-left corner of the stamp within an image of the given size.
     *
     * @return array{0: int, 1: int}
     */
    public function originIn(int $imageWidth, int $imageHeight, int $stampWidth, int $stampHeight): array
    {
        $x = match ($this->position) {
            'tl', 'cl', 'bl' => self::INSET,
            'tc', 'cc', 'bc' => (int) round(($imageWidth / 2) - ($stampWidth / 2)),
            'cr' => $imageWidth - $stampWidth + self::INSET,
            default => $imageWidth - $stampWidth - self::INSET,
        };

        $y = match ($this->position) {
            'tl', 'tc', 'tr' => self::INSET,
            'cl', 'cc', 'cr' => (int) round(($imageHeight / 2) - ($stampHeight / 2)),
            default => $imageHeight - $stampHeight - self::INSET,
        };

        return [$x, $y];
    }
}
