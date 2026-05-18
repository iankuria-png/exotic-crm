<?php

namespace App\Services\Seo;

use App\Models\Client;
use App\Services\WpSyncService;

/**
 * Builds a ProfileSnapshot from various input sources.
 *
 * Priority when both persisted data and a submitted overlay exist:
 *  1. Load persisted profile (CRM client + WP payload)
 *  2. Overlay submitted snapshot values on top
 *  3. Return merged snapshot
 */
class ProfileSnapshotBuilder
{
    public function fromRequest(
        ?int   $clientId,
        ?int   $wpPostId,
        int    $platformId,
        ?array $overlay = null,
    ): ProfileSnapshot {
        $base = [];

        if ($clientId !== null) {
            $client = Client::with('platform')->findOrFail($clientId);
            $base   = $this->baseFromClient($client);
            $platformId = (int) $client->platform_id;

            if ($wpPostId === null && $client->wp_post_id) {
                $wpPostId = (int) $client->wp_post_id;
            }
        }

        if ($wpPostId !== null) {
            try {
                $wpData = WpSyncService::forPlatform($platformId)->getClientProfile($wpPostId);
                $base   = array_merge($base, $this->baseFromWpPayload($wpData));
            } catch (\Throwable) {
                // WP unreachable — continue with what we have
            }
        }

        if ($overlay !== null) {
            $base = $this->applyOverlay($base, $overlay);
        }

        return $this->build($base, $clientId, $wpPostId, $platformId);
    }

    public function fromOverlayOnly(array $overlay, int $platformId): ProfileSnapshot
    {
        $base = $this->applyOverlay([], $overlay);
        return $this->build($base, null, null, $platformId);
    }

    // -------------------------------------------------------------------------

    private function baseFromClient(Client $client): array
    {
        return [
            'name'   => (string) $client->name,
            'city'   => (string) $client->city,
            'gender' => '',
        ];
    }

    private function baseFromWpPayload(array $wp): array
    {
        $meta = $wp['meta'] ?? [];

        $birthday = (string) ($meta['birthday'] ?? '');
        $age      = null;
        if ($birthday !== '') {
            try {
                $age = (int) now()->diffInYears(new \DateTime($birthday));
            } catch (\Throwable) {}
        }

        $services = [];
        foreach (['service', 'services', 'service1', 'service2', 'service3'] as $key) {
            $val = $meta[$key] ?? null;
            if (is_array($val)) {
                $services = array_merge($services, $val);
            } elseif (is_string($val) && $val !== '') {
                $services[] = $val;
            }
        }

        $languages = [];
        foreach (['language1', 'language2', 'language3'] as $key) {
            $lang = trim((string) ($meta[$key] ?? ''));
            if ($lang !== '') {
                $languages[] = $lang;
            }
        }

        $rates = [];
        $rateKeys = ['rate', 'rate_1h', 'rate_2h', 'rate_overnight', 'rateincall', 'rateoutcall'];
        foreach ($rateKeys as $key) {
            $val = $meta[$key] ?? null;
            if ($val !== null && $val !== '') {
                $rates[$key] = $val;
            }
        }

        $availability = null;
        $avail = $meta['availability'] ?? [];
        if (is_array($avail)) {
            $map  = ['1' => 'Incall', '2' => 'Outcall'];
            $bits = array_filter(array_map(fn($v) => $map[(string)$v] ?? null, $avail));
            if (!empty($bits)) {
                $availability = implode(' & ', $bits);
            }
        } elseif (is_string($avail) && $avail !== '') {
            $availability = $avail;
        }

        // Main image: prefer main_image_id meta, fall back to _thumbnail_id
        $mainImageId  = (int) ($meta['main_image_id'] ?? 0);
        $thumbnailId  = (int) ($meta['_thumbnail_id'] ?? 0);
        $hasMainImage = $mainImageId > 0 || $thumbnailId > 0;

        $mediaSummary = $wp['media_summary'] ?? [
            'image_count'    => 0,
            'video_count'    => 0,
            'has_main_image' => $hasMainImage,
        ];

        return [
            'name'          => (string) ($wp['name'] ?? ''),
            'city'          => (string) ($wp['city'] ?? ''),
            'neighborhood'  => trim((string) ($meta['neighborhood'] ?? '')),
            'gender'        => strtolower(trim((string) ($meta['gender'] ?? ''))),
            'ethnicity'     => trim((string) ($meta['ethnicity'] ?? '')),
            'build'         => trim((string) ($meta['build'] ?? '')),
            'height'        => trim((string) ($meta['height'] ?? '')),
            'hair_color'    => trim((string) ($meta['haircolor'] ?? '')),
            'age'           => $age,
            'services'      => array_values(array_unique(array_filter($services))),
            'languages'     => array_values(array_unique(array_filter($languages))),
            'rates'         => $rates,
            'availability'  => $availability,
            'existing_bio'  => (string) ($wp['post']['content'] ?? ''),
            'media_summary' => $mediaSummary,
        ];
    }

    private function applyOverlay(array $base, array $overlay): array
    {
        $fields = [
            'name', 'age', 'city', 'neighborhood', 'gender', 'ethnicity',
            'build', 'height', 'hair_color', 'services', 'languages', 'rates',
            'availability', 'existing_bio', 'media_summary',
        ];

        foreach ($fields as $field) {
            $key = $field;
            if (array_key_exists($key, $overlay) && $overlay[$key] !== null && $overlay[$key] !== '') {
                $base[$key] = $overlay[$key];
            }
        }

        return $base;
    }

    private function build(array $data, ?int $clientId, ?int $wpPostId, int $platformId): ProfileSnapshot
    {
        return new ProfileSnapshot(
            clientId:     $clientId,
            wpPostId:     $wpPostId,
            platformId:   $platformId,
            name:         (string) ($data['name'] ?? ''),
            age:          isset($data['age']) && $data['age'] !== '' ? (int) $data['age'] : null,
            city:         (string) ($data['city'] ?? ''),
            neighborhood: ($data['neighborhood'] ?? '') !== '' ? (string) $data['neighborhood'] : null,
            gender:       (string) ($data['gender'] ?? 'female'),
            ethnicity:    ($data['ethnicity'] ?? '') !== '' ? (string) $data['ethnicity'] : null,
            build:        ($data['build'] ?? '') !== '' ? (string) $data['build'] : null,
            height:       ($data['height'] ?? '') !== '' ? (string) $data['height'] : null,
            hairColor:    ($data['hair_color'] ?? '') !== '' ? (string) $data['hair_color'] : null,
            services:     is_array($data['services'] ?? null) ? $data['services'] : [],
            languages:    is_array($data['languages'] ?? null) ? $data['languages'] : [],
            rates:        is_array($data['rates'] ?? null) ? $data['rates'] : [],
            availability: ($data['availability'] ?? '') !== '' ? (string) $data['availability'] : null,
            existingBio:  (string) ($data['existing_bio'] ?? ''),
            mediaSummary: is_array($data['media_summary'] ?? null) ? $data['media_summary'] : [
                'image_count'    => 0,
                'video_count'    => 0,
                'has_main_image' => false,
            ],
        );
    }
}
