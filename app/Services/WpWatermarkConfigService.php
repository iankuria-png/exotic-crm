<?php

namespace App\Services;

use App\Models\Platform;
use App\Support\Watermark\WatermarkStamp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the watermark a market stamps onto its uploads.
 *
 * The settings live in that site's own WordPress options — `watermarklogourl`
 * for the PNG and `watermark_position` for one of nine placements — so this
 * reads them from the market database the CRM already holds credentials for.
 * No plugin change is needed on the site.
 *
 * The logo is fetched once and kept on local disk. It changes about never, and
 * removal runs per image, so re-downloading it for every photo would be the
 * slowest part of the job by a wide margin.
 */
class WpWatermarkConfigService
{
    /** The theme hard-codes this when it applies the mark. */
    private const OPACITY_PERCENT = 90;

    private const CACHE_TTL_MINUTES = 720;

    public function forPlatform(int $platformId): ?WatermarkStamp
    {
        $settings = $this->settingsFor($platformId);
        if ($settings === null) {
            return null;
        }

        $pngPath = $this->localLogoPath($platformId, $settings['url']);
        if ($pngPath === null) {
            return null;
        }

        $stamp = new WatermarkStamp($pngPath, $settings['position'], self::OPACITY_PERCENT);

        return $stamp->isUsable() ? $stamp : null;
    }

    /**
     * @return array{url: string, position: string}|null
     */
    private function settingsFor(int $platformId): ?array
    {
        return Cache::remember(
            'wp.watermark.settings.' . $platformId,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($platformId): ?array {
                $platform = Platform::query()->find($platformId);
                if (!$platform) {
                    return null;
                }

                try {
                    $connectionName = 'wp_watermark_' . $platformId;
                    DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

                    if (!Schema::connection($connectionName)->hasTable('options')) {
                        return null;
                    }

                    $options = DB::connection($connectionName)->table('options')
                        ->whereIn('option_name', ['watermarklogourl', 'watermark_position'])
                        ->pluck('option_value', 'option_name');
                } catch (\Throwable $exception) {
                    Log::warning('wp.watermark_settings_unavailable', [
                        'platform_id' => $platformId,
                        'error' => $exception->getMessage(),
                    ]);

                    return null;
                }

                $url = trim((string) ($options['watermarklogourl'] ?? ''));
                if ($url === '') {
                    return null;
                }

                $position = strtolower(substr(trim((string) ($options['watermark_position'] ?? 'cc')), 0, 2));

                return [
                    'url' => $url,
                    'position' => in_array($position, WatermarkStamp::POSITIONS, true) ? $position : 'cc',
                ];
            }
        );
    }

    private function localLogoPath(int $platformId, string $url): ?string
    {
        $directory = storage_path('app/watermarks');
        $path = $directory . '/platform-' . $platformId . '.png';

        if (is_file($path) && filemtime($path) > now()->subDays(30)->getTimestamp()) {
            return $path;
        }

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($url);
            if (!$response->successful()) {
                throw new \RuntimeException('HTTP ' . $response->status());
            }

            file_put_contents($path, $response->body());
        } catch (\Throwable $exception) {
            Log::warning('wp.watermark_logo_unavailable', [
                'platform_id' => $platformId,
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return is_file($path) ? $path : null;
        }

        // A logo that is not a readable image would make every removal decline
        // anyway; failing here keeps the reason in one place.
        return @getimagesize($path) !== false ? $path : null;
    }
}
