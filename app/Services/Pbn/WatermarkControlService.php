<?php

namespace App\Services\Pbn;

use App\Models\Platform;
use App\Models\User;
use App\Models\WatermarkRemovalAttempt;
use App\Models\WatermarkSetting;
use App\Services\MarketAuthorizationService;
use App\Services\WpWatermarkConfigService;
use App\Support\Watermark\WatermarkRemover;
use App\Support\Watermark\WatermarkTuning;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Everything the watermark control centre reads and writes.
 *
 * Removal declines rather than guessing, so the only way to know whether it is
 * working is to look at what it recorded. This turns those rows into the two
 * questions worth asking — is it removing watermarks, and when it is not, why —
 * and lets the thresholds be moved against that evidence rather than by
 * recompiling constants.
 */
class WatermarkControlService
{
    /** Outcomes that mean the market is misconfigured rather than the image being wrong. */
    private const CONFIGURATION_OUTCOMES = [
        WatermarkRemovalAttempt::OUTCOME_NOT_CONFIGURED,
        WatermarkRemovalAttempt::OUTCOME_ERROR,
    ];

    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly WpWatermarkConfigService $watermarkConfig,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(User $actor, int $days = 30): array
    {
        $since = now()->subDays(max(1, min(180, $days)));
        $settings = WatermarkSetting::current();

        $attempts = WatermarkRemovalAttempt::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source_platform_id, outcome, applied, count(*) as attempts, avg(implausible_ratio) as avg_implausible')
            ->groupBy('source_platform_id', 'outcome', 'applied')
            ->get();

        $platformNames = Platform::query()
            ->whereIn('id', $attempts->pluck('source_platform_id')->filter()->unique())
            ->pluck('name', 'id');

        return [
            'enabled' => (bool) $settings->enabled,
            'tuning' => $settings->tuning()->toArray(),
            'defaults' => (new WatermarkTuning())->toArray(),
            'window_days' => $days,
            'totals' => $this->totals($attempts),
            'outcomes' => $this->outcomes($attempts),
            'markets' => $this->markets($attempts, $platformNames),
            'recent' => $this->recent($since),
            'can_configure' => $this->marketAuthorizationService->isManager($actor),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateSettings(User $actor, array $payload): array
    {
        $this->marketAuthorizationService->ensureManager(
            $actor,
            'Only admin or sub-admin users can change watermark removal settings.'
        );

        $settings = WatermarkSetting::current();
        $settings->forceFill([
            'enabled' => (bool) ($payload['enabled'] ?? $settings->enabled),
            'tuning' => WatermarkTuning::fromArray($payload['tuning'] ?? [])->toArray(),
            'updated_by' => (int) $actor->id,
        ])->save();

        return $this->overview($actor);
    }

    /**
     * Run the removal against one image and report what happened, without
     * touching anything. The same path a seed batch takes, so what it says here
     * is what a batch would do.
     *
     * @return array<string, mixed>
     */
    public function testImage(User $actor, int $platformId, string $url): array
    {
        $this->marketAuthorizationService->ensureUserCanAccessPlatform($actor, $platformId);

        $stamp = $this->watermarkConfig->forPlatform($platformId);
        if ($stamp === null) {
            return [
                'applied' => false,
                'outcome' => WatermarkRemovalAttempt::OUTCOME_NOT_CONFIGURED,
                'reason' => 'This market has no usable watermark settings. Check watermarklogourl and watermark_position in its WordPress options, and that the CRM can reach its database and the logo URL.',
                'stats' => [],
            ];
        }

        $response = Http::timeout(60)->get($url);
        if (!$response->successful()) {
            return [
                'applied' => false,
                'outcome' => WatermarkRemovalAttempt::OUTCOME_UNREADABLE,
                'reason' => 'Image download failed with HTTP ' . $response->status() . '.',
                'stats' => [],
            ];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'wm_test_');
        file_put_contents($temporary, $response->body());

        try {
            $result = (new WatermarkRemover($stamp, WatermarkSetting::current()->tuning()))->attempt($temporary);
            [$logoWidth, $logoHeight] = getimagesize($stamp->pngPath) ?: [0, 0];

            return [
                'applied' => $result->applied,
                'outcome' => $result->outcome,
                'reason' => $result->reason,
                'stats' => $result->stats,
                'watermark' => [
                    'size' => $logoWidth . 'x' . $logoHeight,
                    'position' => $stamp->position,
                    'opacity' => $stamp->opacityPercent,
                ],
                // Both encoded so the result can be seen without a shell on the
                // server, and shared as-is when something looks wrong.
                'before' => $this->dataUri($response->body(), $response->header('Content-Type')),
                'after' => $result->applied ? $this->dataUri((string) file_get_contents($temporary), $response->header('Content-Type')) : null,
            ];
        } finally {
            @unlink($temporary);
        }
    }

    private function dataUri(string $bytes, ?string $mime): string
    {
        return 'data:' . ($mime ?: 'image/jpeg') . ';base64,' . base64_encode($bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function totals($attempts): array
    {
        $total = (int) $attempts->sum('attempts');
        $removed = (int) $attempts->where('applied', true)->sum('attempts');
        $misconfigured = (int) $attempts->whereIn('outcome', self::CONFIGURATION_OUTCOMES)->sum('attempts');

        return [
            'attempted' => $total,
            'removed' => $removed,
            'declined' => $total - $removed,
            'removal_rate' => $total > 0 ? round($removed / $total, 4) : null,
            'misconfigured' => $misconfigured,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function outcomes($attempts): array
    {
        return $attempts->groupBy('outcome')
            ->map(fn ($rows, $outcome) => [
                'outcome' => (string) $outcome,
                'label' => WatermarkRemovalAttempt::OUTCOME_LABELS[$outcome] ?? (string) $outcome,
                'attempts' => (int) $rows->sum('attempts'),
                'avg_implausible' => $rows->avg('avg_implausible') !== null ? round((float) $rows->avg('avg_implausible'), 4) : null,
            ])
            ->sortByDesc('attempts')
            ->values()
            ->all();
    }

    /**
     * Per market, because the answer differs by market: each site has its own
     * logo file, and a market whose mark was redesigned will show a wall of
     * "different watermark" while its neighbours are clean.
     *
     * @return array<int, array<string, mixed>>
     */
    private function markets($attempts, $platformNames): array
    {
        return $attempts->groupBy('source_platform_id')
            ->map(function ($rows, $platformId) use ($platformNames) {
                $total = (int) $rows->sum('attempts');
                $removed = (int) $rows->where('applied', true)->sum('attempts');
                $topDecline = $rows->where('applied', false)->sortByDesc('attempts')->first();

                return [
                    'platform_id' => (int) $platformId,
                    'platform_name' => $platformNames[$platformId] ?? ('Platform ' . $platformId),
                    'attempted' => $total,
                    'removed' => $removed,
                    'declined' => $total - $removed,
                    'removal_rate' => $total > 0 ? round($removed / $total, 4) : null,
                    'top_decline' => $topDecline ? [
                        'outcome' => (string) $topDecline->outcome,
                        'label' => WatermarkRemovalAttempt::OUTCOME_LABELS[$topDecline->outcome] ?? (string) $topDecline->outcome,
                        'attempts' => (int) $topDecline->attempts,
                    ] : null,
                ];
            })
            ->sortByDesc('attempted')
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recent($since): array
    {
        return WatermarkRemovalAttempt::query()
            ->where('created_at', '>=', $since)
            ->with('sourcePlatform:id,name')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (WatermarkRemovalAttempt $attempt) => [
                'id' => (int) $attempt->id,
                'platform_name' => $attempt->sourcePlatform?->name,
                'applied' => (bool) $attempt->applied,
                'outcome' => (string) $attempt->outcome,
                'label' => $attempt->label(),
                'reason' => $attempt->reason,
                'image_url' => $attempt->image_url,
                'image_size' => $attempt->image_size,
                'stamp_size' => $attempt->stamp_size,
                'implausible_ratio' => $attempt->implausible_ratio,
                'landed_px' => $attempt->landed_px,
                'batch_id' => $attempt->batch_id ? (int) $attempt->batch_id : null,
                'created_at' => optional($attempt->created_at)->toDateTimeString(),
            ])
            ->all();
    }
}
