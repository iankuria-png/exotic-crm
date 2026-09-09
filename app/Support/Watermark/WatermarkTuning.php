<?php

namespace App\Support\Watermark;

/**
 * The dials the removal runs on.
 *
 * Every one of these was a hard-coded constant chosen by measuring a handful of
 * real photos. They are worth exposing because the right value depends on
 * things that vary per market and change over time — how heavily a site's logo
 * is drawn, how hard its images are recompressed, how the mark is redesigned —
 * and because the attempts table now produces the evidence to set them from.
 *
 * Kept as a plain object rather than read from config inside the remover, so
 * the remover stays pure and any caller can hand it a different set.
 */
class WatermarkTuning
{
    public function __construct(
        /**
         * Above this blend the inversion is too ill-conditioned to trust and the
         * pixel is filled from its surroundings instead. Recovery divides by
         * (1 - blend), so at 0.9 one level of codec error becomes ten, which
         * over a bright background turns a white mark black.
         */
        public readonly float $invertBelowBlend = 0.7,

        /** Below this a logo pixel left no meaningful trace. */
        public readonly float $minBlend = 0.02,

        /**
         * Share of the strongest logo pixels allowed to be darker than the logo
         * alone would make them before the image is judged not to carry this
         * mark. Correct pairings measure under 5%; a wrong logo reads over 70%.
         */
        public readonly float $maxImplausibleRatio = 0.15,

        /** Codec slack when testing an observed pixel against the logo. */
        public readonly int $evidenceTolerance = 18,

        /** Share of inverted channels allowed outside gamut before declining. */
        public readonly float $maxOutOfGamutRatio = 0.12,

        /** Recovered pixels at or above this blend take their colour from around them. */
        public readonly float $chromaRepairBlend = 0.35,

        /** How far chroma repair reaches into a solid region. */
        public readonly int $chromaRepairPasses = 8,

        /** How far the fill reaches into a solid region. */
        public readonly int $fillPasses = 8,

        /** Below this the stamp barely lands and there is nothing to gain. */
        public readonly int $minLandedPixels = 64,
    ) {
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $defaults = new self();

        return new self(
            invertBelowBlend: self::clampFloat($values['invert_below_blend'] ?? null, 0.1, 0.99, $defaults->invertBelowBlend),
            minBlend: self::clampFloat($values['min_blend'] ?? null, 0.005, 0.3, $defaults->minBlend),
            maxImplausibleRatio: self::clampFloat($values['max_implausible_ratio'] ?? null, 0.01, 0.9, $defaults->maxImplausibleRatio),
            evidenceTolerance: self::clampInt($values['evidence_tolerance'] ?? null, 0, 80, $defaults->evidenceTolerance),
            maxOutOfGamutRatio: self::clampFloat($values['max_out_of_gamut_ratio'] ?? null, 0.01, 0.9, $defaults->maxOutOfGamutRatio),
            chromaRepairBlend: self::clampFloat($values['chroma_repair_blend'] ?? null, 0.05, 0.95, $defaults->chromaRepairBlend),
            chromaRepairPasses: self::clampInt($values['chroma_repair_passes'] ?? null, 1, 20, $defaults->chromaRepairPasses),
            fillPasses: self::clampInt($values['fill_passes'] ?? null, 1, 20, $defaults->fillPasses),
            minLandedPixels: self::clampInt($values['min_landed_px'] ?? null, 8, 5000, $defaults->minLandedPixels),
        );
    }

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'invert_below_blend' => $this->invertBelowBlend,
            'min_blend' => $this->minBlend,
            'max_implausible_ratio' => $this->maxImplausibleRatio,
            'evidence_tolerance' => $this->evidenceTolerance,
            'max_out_of_gamut_ratio' => $this->maxOutOfGamutRatio,
            'chroma_repair_blend' => $this->chromaRepairBlend,
            'chroma_repair_passes' => $this->chromaRepairPasses,
            'fill_passes' => $this->fillPasses,
            'min_landed_px' => $this->minLandedPixels,
        ];
    }

    private static function clampFloat(mixed $value, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return max($min, min($max, (float) $value));
    }

    private static function clampInt(mixed $value, int $min, int $max, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        return (int) max($min, min($max, (int) $value));
    }
}
