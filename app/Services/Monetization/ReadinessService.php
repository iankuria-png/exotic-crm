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
        $failureCode = null;
        $details = [];
        try {
            if (! $probe || empty($probe['delivery_url'])) {
                throw new \DomainException($result['message'] ?? 'WordPress did not return a probe. Upload the complete sync plugin, then re-check.');
            }
            // Full origin validation, public DNS pinning and no redirects apply to all probes.
            $guard = app(WordPressDestination::class);
            $origin = $guard->origin((string) $settings->platform->wp_api_url);
            foreach (['asset_url', 'delivery_url', 'direct_url'] as $key) {
                $received = $guard->origin((string) ($probe[$key] ?? ''));
                if ($received !== $origin) {
                    $failureCode = 'canonical_origin_mismatch';
                    throw new \DomainException("CRM is set to $origin, but WordPress reports $received. Use the WordPress address in Guided setup, then run the check again.");
                }
            }
            $http = Http::timeout(12)->withOptions($guard->options($origin));
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
            $reasons = [
                'protected_storage' => 'Private storage is not writable outside the public root. Follow the server step, then re-check.',
                'anonymous_denied' => 'Anonymous access was not refused with HTTP 401/403. Check the sync plugin and CDN rules before enabling.',
                'direct_denied' => 'Direct file access was not refused, or Cloudflare served a cached file. Check private storage and purge/bypass cached private paths before re-checking.',
                'range' => 'Protected delivery did not return the expected partial bytes. Ask the host to permit Range headers on the protected REST route, then re-check.',
                'no_store' => 'Protected delivery did not return Cache-Control: no-store. Bypass caching for the protected REST route, then re-check.',
                'video_processing' => 'FFmpeg cannot run under PHP with libx264 and AAC. Follow the server step, then re-check.',
            ];
            foreach ($checks as $key => $passed) {
                if (! $passed) {
                    $details[$key] = $reasons[$key];
                }
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $failureCode = 'unsafe_probe_destination';
            $error = collect($e->errors())->flatten()->first();
        } catch (\DomainException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $failureCode = 'probe_unreachable';
            $error = 'CRM could not complete the protected-delivery request. Check site availability and protected REST/CDN rules, then re-check.';
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
        if (! $ready && ! $error) {
            $error = collect($details)->except(data_get($settings->offer_policy_json, 'videos_enabled') ? [] : ['video_processing'])->first();
        }
        $report = ['ready' => $ready, 'checks' => $checks, 'details' => $details, 'error' => $error, 'code' => $failureCode, 'config_revision' => $settings->config_revision, 'environment' => $settings->premium_access_environment, 'verified_at' => now()->toIso8601String()];
        $settings->update(['readiness_json' => $report, 'heartbeat_at' => now()]);

        return $report;
    }
}
