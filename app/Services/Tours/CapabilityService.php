<?php

namespace App\Services\Tours;

use App\Models\Platform;
use App\Services\TourSettingsService;
use App\Services\WpSyncService;

class CapabilityService
{
    public function requireDestination(Platform $home, Platform $destination, string $environment, array $media = [], ?int $timeout = null): array
    {
        abort_unless($destination->is_active && $destination->wp_api_url && $destination->id !== $home->id, 409, 'This destination is not available.');
        $cap = (new WpSyncService($destination))->getTourCapability($timeout);
        $origin = app(TourSettingsService::class)->originForPlatform($destination);
        $homeOrigin = app(TourSettingsService::class)->originForPlatform($home);
        abort_unless(($cap['contract_version'] ?? 0) === 1 && ! empty($cap['available']) && ! empty($cap['receiver_enabled']) && ! empty($cap['theme_window_guard']) && ($cap['environment'] ?? '') === $environment && rtrim((string) ($cap['site_url'] ?? ''), '/') === $origin, 409, 'This destination has not passed its Tours release checks.');
        $source = collect($cap['receiver_sources'] ?? [])->first(fn ($s) => (int) $s['platform_id'] === (int) $home->id && $s['origin'] === $homeOrigin);
        abort_unless($source, 409, 'This destination has not approved the home market.');
        foreach ($media as $item) {
            $parts = parse_url($item['url'] ?? '');
            $mediaOrigin = ($parts['scheme'] ?? '').'://'.($parts['host'] ?? '');
            abort_unless(in_array($mediaOrigin, $source['media_origins'] ?? [], true), 409, 'A selected media website is not approved by this destination.');
        }
        foreach (['independent_pass_expiry', 'window_gating', 'home_canonical', 'noindex', 'separate_visiting_strip', 'idempotent_revision'] as $feature) {
            abort_unless(in_array($feature, $cap['features'] ?? [], true), 409, 'A destination is missing a required Tours safeguard.');
        }

        return $cap;
    }
}
