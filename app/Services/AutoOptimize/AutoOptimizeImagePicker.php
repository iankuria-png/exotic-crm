<?php

namespace App\Services\AutoOptimize;

use App\Services\ClientProfileImageService;

/**
 * Picks the best non-main image from a quality-enriched media list.
 * Pure function — no IO. Image-switch is gated off when dimensions are missing
 * and require_dimensions is true.
 */
class AutoOptimizeImagePicker
{
    public function __construct(
        private readonly ClientProfileImageService $imageService,
    ) {}

    /**
     * @param  array  $mediaPayload  Raw WP media payload (may include width/height/filesize)
     * @param  int|null  $currentMainId  Current main attachment id
     * @param  array  $imageQualityCfg  From actions.image_quality in the plan config
     * @return array|null  The selected media item, or null if no better image found
     */
    public function pickBetterMain(array $mediaPayload, ?int $currentMainId, array $imageQualityCfg): ?array
    {
        $requireDimensions = (bool) ($imageQualityCfg['require_dimensions'] ?? true);
        $minWidth = (int) ($imageQualityCfg['min_width'] ?? 800);
        $minHeight = (int) ($imageQualityCfg['min_height'] ?? 1000);
        $minGain = (float) ($imageQualityCfg['min_megapixel_gain'] ?? 0.15);

        $allItems = $this->normalizeMediaItemsWithQuality($mediaPayload);

        if (empty($allItems)) {
            return null;
        }

        // Find current main's quality for comparison
        $currentMain = null;
        $candidates = [];

        foreach ($allItems as $item) {
            if ((int) ($item['id'] ?? 0) === $currentMainId) {
                $currentMain = $item;
            } else {
                $candidates[] = $item;
            }
        }

        if (empty($candidates)) {
            return null;
        }

        $currentMegapixels = $currentMain ? $this->megapixels($currentMain) : 0.0;
        $currentHasDimensions = $currentMain ? $this->hasDimensions($currentMain) : false;

        $best = null;
        $bestMegapixels = -INF;

        foreach ($candidates as $candidate) {
            $hasDims = $this->hasDimensions($candidate);

            if ($requireDimensions && !$hasDims) {
                continue; // skip — can't verify quality without dimensions
            }

            $mp = $this->megapixels($candidate);

            // Must meet minimum dimensions if configured and available
            if ($hasDims) {
                $w = (int) ($candidate['width'] ?? 0);
                $h = (int) ($candidate['height'] ?? 0);
                if ($minWidth > 0 && $w < $minWidth) {
                    continue;
                }
                if ($minHeight > 0 && $h < $minHeight) {
                    continue;
                }
            }

            // Must be strictly better than current main by min_megapixel_gain
            $gain = $mp - $currentMegapixels;
            if ($gain < $minGain) {
                continue;
            }

            if ($mp > $bestMegapixels) {
                $bestMegapixels = $mp;
                $best = $candidate;
            }
        }

        // If require_dimensions and the best candidate has no dimensions, gate off
        if ($best !== null && $requireDimensions && !$this->hasDimensions($best)) {
            return null;
        }

        return $best;
    }

    /**
     * Normalizes media items, preserving width/height/filesize when the WP payload provides them.
     * Falls back to normalizeMediaItems (which drops dimensions) for backward compat.
     */
    public function normalizeMediaItemsWithQuality(?array $payload): array
    {
        if (!$payload) {
            return [];
        }

        $rows = data_get($payload, 'data');
        if (!is_array($rows)) {
            $rows = is_array($payload) && array_is_list($payload) ? $payload : [];
        }

        return collect($rows)
            ->map(function ($media): array {
                $row = is_array($media) ? $media : [];
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'url' => trim((string) ($row['url'] ?? '')),
                    'filename' => isset($row['filename']) ? trim((string) $row['filename']) : null,
                    'is_main' => (bool) ($row['is_main'] ?? false),
                    'mime_type' => isset($row['mime_type']) ? trim((string) $row['mime_type']) : null,
                    'uploaded_at' => isset($row['uploaded_at']) ? trim((string) $row['uploaded_at']) : null,
                    // Quality dimensions (present when P1 WP plugin change is deployed)
                    'width' => isset($row['width']) ? (int) $row['width'] : null,
                    'height' => isset($row['height']) ? (int) $row['height'] : null,
                    'filesize' => isset($row['filesize']) ? (int) $row['filesize'] : null,
                ];
            })
            ->filter(fn (array $item): bool =>
                (int) ($item['id'] ?? 0) > 0
                && ($item['url'] ?? '') !== ''
                && $this->isImageMedia($item)
            )
            ->values()
            ->all();
    }

    private function megapixels(array $item): float
    {
        $w = (int) ($item['width'] ?? 0);
        $h = (int) ($item['height'] ?? 0);
        if ($w > 0 && $h > 0) {
            return ($w * $h) / 1_000_000.0;
        }
        // Fallback to filesize as a proxy (bytes → approximation)
        $fs = (int) ($item['filesize'] ?? 0);
        return $fs > 0 ? $fs / 300_000.0 : 0.0;
    }

    private function hasDimensions(array $item): bool
    {
        return (int) ($item['width'] ?? 0) > 0 && (int) ($item['height'] ?? 0) > 0;
    }

    private function isImageMedia(array $item): bool
    {
        $mimeType = strtolower(trim((string) ($item['mime_type'] ?? '')));
        if ($mimeType !== '') {
            return str_starts_with($mimeType, 'image/');
        }
        $url = strtolower(trim((string) ($item['url'] ?? '')));
        return (bool) preg_match('/\.(jpe?g|png|webp|gif|avif)(?:$|[?#])/', $url);
    }
}
