<?php

namespace App\Services\Monetization;

use App\Models\ContentMonetizationSetting;
use Illuminate\Support\Facades\Http;

class ReadinessService
{
    public function check(ContentMonetizationSetting $settings): array
    {
        $sync = app(SyncService::class);
        $result = $sync->send($settings, '/premium-content/readiness', []);
        $probe = $result['response'] ?? null;
        $checks = ['protected_storage' => false, 'anonymous_denied' => false, 'direct_denied' => false, 'range' => false, 'no_store' => false, 'video_processing' => false];
        $error = null;
        try {
            if (! $probe || empty($probe['delivery_url'])) {
                throw new \RuntimeException($result['message'] ?? 'WordPress did not return a probe.');
            }
            // Probe URLs must stay on the registered market host (never arbitrary signed input).
            $host = parse_url($settings->platform->wp_api_url, PHP_URL_HOST);
            foreach (['asset_url', 'delivery_url', 'direct_url'] as $key) {
                if (parse_url($probe[$key], PHP_URL_HOST) !== $host) {
                    throw new \RuntimeException('Probe host mismatch.');
                }
            }
            $http = Http::timeout(12)->withoutRedirecting();
            $anonymous = $http->get($probe['asset_url']);
            $direct = $http->get($probe['direct_url']);
            $range = $http->withHeaders(['Range' => 'bytes=0-7'])->get($probe['delivery_url']);
            $checks = [
                'protected_storage' => data_get($probe, 'storage.outside_web_root') === true,
                'anonymous_denied' => in_array($anonymous->status(), [401, 403], true),
                'direct_denied' => in_array($direct->status(), [403, 404], true) && strtoupper((string) $direct->header('CF-Cache-Status')) !== 'HIT',
                'range' => $range->status() === 206 && $range->body() === substr($probe['expected'], 0, 8) && str_starts_with((string) $range->header('Content-Range'), 'bytes 0-7/'),
                'no_store' => str_contains((string) $range->header('Cache-Control'), 'no-store'),
                'video_processing' => data_get($probe, 'storage.ffmpeg') === true,
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        } finally {
            if (! empty($probe['id'])) {
                $sync->send($settings, '/premium-content/readiness', ['cleanup' => $probe['id']]);
            }
        }
        $required = $checks;
        if (! data_get($settings->offer_policy_json, 'videos_enabled')) {
            unset($required['video_processing']);
        }
        $ready = ! in_array(false, $required, true);
        $report = ['ready' => $ready, 'checks' => $checks, 'error' => $error, 'verified_at' => now()->toIso8601String()];
        $settings->update(['readiness_json' => $report, 'heartbeat_at' => now()]);

        return $report;
    }
}
