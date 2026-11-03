<?php

namespace App\Services\Tours;

use App\Models\Platform;
use App\Models\TourMarketSetting;
use App\Services\TourSettingsService;
use App\Services\WpSyncService;
use Illuminate\Support\Facades\Cache;

/** Catalogue reads never fan out to markets; purchases revalidate receivers directly. */
class CatalogueService
{
    private function key(Platform $home, Platform $destination, string $environment): string
    {
        return 'tours:destination:'.hash('sha256', json_encode([
            $home->id, app(TourSettingsService::class)->originForPlatform($home),
            $destination->id, $destination->domain, $destination->wp_api_url, $environment,
        ], JSON_THROW_ON_ERROR));
    }

    public function read(Platform $home, Platform $destination, string $environment): ?array
    {
        if (! $destination->is_active) {
            return null;
        }

        $value = Cache::get($this->key($home, $destination, $environment));

        return is_array($value) && ($value['expires_at'] ?? 0) > now()->timestamp ? $value : null;
    }

    public function refresh(Platform $home, TourMarketSetting $config, ?float $deadline = null): array
    {
        $deadline ??= microtime(true) + 900;
        $environment = $config->settings['environment'];
        $ids = collect($config->settings['passes'])->where('enabled', true)->pluck('destination_platform_ids')->flatten()->unique()->values();
        $result = ['verified' => [], 'unavailable' => []];
        foreach ($ids as $id) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $destination = Platform::find($id);
            if (! $destination) {
                $result['unavailable'][] = (int) $id;

                continue;
            }
            $key = $this->key($home, $destination, $environment);
            try {
                // Only background/operator refresh calls perform remote discovery.
                app(CapabilityService::class)->requireDestination($home, $destination, $environment, [], 8);
                $needsCities = collect($config->settings['passes'])->contains(fn ($pass) => $pass['enabled'] && $pass['region'] === 'africa' && in_array((int) $id, $pass['destination_platform_ids'], true));
                $locations = $needsCities ? (new WpSyncService($destination))->getTourLocations(8) : [];
                $entry = ['cities' => $locations['cities'] ?? $locations['locations'] ?? [], 'expires_at' => now()->addMinutes(30)->timestamp];
                Cache::put($key, $entry, now()->addMinutes(30));
                $result['verified'][] = (int) $id;
            } catch (\Throwable) {
                Cache::forget($key);
                $result['unavailable'][] = (int) $id;
            }
        }

        return $result;
    }
}
