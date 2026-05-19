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
            name:         $this->stringValue($data['name'] ?? ''),
            age:          $this->intOrNull($data['age'] ?? null),
            city:         $this->stringValue($data['city'] ?? ''),
            neighborhood: $this->nullableString($data['neighborhood'] ?? null),
            gender:       $this->stringValue($data['gender'] ?? 'female'),
            ethnicity:    $this->nullableString($data['ethnicity'] ?? null),
            build:        $this->nullableString($data['build'] ?? null),
            height:       $this->nullableString($data['height'] ?? null),
            hairColor:    $this->nullableString($data['hair_color'] ?? null),
            services:     $this->stringList($data['services'] ?? []),
            languages:    $this->stringList($data['languages'] ?? []),
            rates:        is_array($data['rates'] ?? null) ? $data['rates'] : [],
            availability: $this->normalizeAvailability($data['availability'] ?? null),
            existingBio:  $this->stringValue($data['existing_bio'] ?? ''),
            mediaSummary: is_array($data['media_summary'] ?? null) ? $data['media_summary'] : [
                'image_count'    => 0,
                'video_count'    => 0,
                'has_main_image' => false,
            ],
        );
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = $this->stringValue($value);

        return $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || is_array($value) || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return string[]
     */
    private function stringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_filter(
            array_map(fn (mixed $item): string => $this->stringValue($item), $items),
            fn (string $item): bool => $item !== '',
        )));
    }

    private function normalizeAvailability(mixed $value): ?string
    {
        if (is_array($value)) {
            $map = ['1' => 'Incall', '2' => 'Outcall'];
            $items = array_map(
                fn (mixed $item): string => $map[$this->stringValue($item)] ?? $this->stringValue($item),
                $value,
            );

            $items = array_values(array_unique(array_filter($items)));

            return $items !== [] ? implode(' & ', $items) : null;
        }

        return $this->nullableString($value);
    }
}
