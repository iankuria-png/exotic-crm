<?php

namespace App\Services;

use App\Models\Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class ClientProfileImageService
{
    /**
     * @param array<string, mixed>|array<int, mixed>|null $payload
     * @return array<int, array{id:int,url:string,filename:?string,is_main:bool,mime_type:?string,uploaded_at:?string}>
     */
    public function normalizeMediaItems(?array $payload): array
    {
        if (!$payload) {
            return [];
        }

        $rows = data_get($payload, 'data');
        if (!is_array($rows)) {
            $rows = array_is_list($payload) ? $payload : [];
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
                ];
            })
            ->filter(fn (array $media): bool => (int) ($media['id'] ?? 0) > 0
                && ($media['url'] ?? '') !== ''
                && $this->isImageMedia($media))
            ->values()
            ->all();
    }

    public function isImageMedia(array $media): bool
    {
        $mimeType = strtolower(trim((string) ($media['mime_type'] ?? '')));
        if ($mimeType !== '') {
            return str_starts_with($mimeType, 'image/');
        }

        $url = strtolower(trim((string) ($media['url'] ?? '')));

        return (bool) preg_match('/\.(jpe?g|png|webp|gif|avif)(?:$|[?#])/', $url);
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|null $mediaPayload
     * @return array{url:string,source:string,media:array<string,mixed>}|null
     */
    public function selectDisplayImage(?array $mediaPayload, bool $verifyReachable = false): ?array
    {
        $mediaItems = $this->normalizeMediaItems($mediaPayload);
        if ($mediaItems === []) {
            return null;
        }

        $main = array_values(array_filter(
            $mediaItems,
            static fn (array $item): bool => (bool) ($item['is_main'] ?? false)
        ));
        $fallback = array_values(array_filter(
            $mediaItems,
            static fn (array $item): bool => !(bool) ($item['is_main'] ?? false)
        ));

        foreach ([
            'wp_media_main' => $main,
            'wp_media_first' => $fallback,
        ] as $source => $candidates) {
            foreach ($candidates as $candidate) {
                $url = trim((string) ($candidate['url'] ?? ''));
                if ($url === '') {
                    continue;
                }

                if ($verifyReachable && !$this->isReachableImageUrl($url)) {
                    continue;
                }

                return [
                    'url' => $url,
                    'source' => $source,
                    'media' => $candidate,
                ];
            }
        }

        return null;
    }

    /**
     * Refresh the cached display image for a CRM client without firing Client model events.
     *
     * @return array{url:string,source:string,media:array<string,mixed>}|null
     */
    public function refreshClient(Client $client, ?array $mediaPayload = null, bool $verifyReachable = true): ?array
    {
        $selection = null;

        if ((int) $client->wp_post_id > 0) {
            $payload = $mediaPayload;
            if ($payload === null) {
                $wpSync = WpSyncService::forPlatform((int) $client->platform_id);
                $payload = $wpSync->getClientMedia((int) $client->wp_post_id);
            }

            $selection = $this->selectDisplayImage($payload, $verifyReachable);
        }

        $updates = [
            'display_image_url' => isset($selection['url'])
                ? mb_substr((string) $selection['url'], 0, 500)
                : null,
            'display_image_source' => isset($selection['source'])
                ? mb_substr((string) $selection['source'], 0, 50)
                : null,
            'display_image_checked_at' => now(),
        ];

        DB::table('clients')
            ->where('id', (int) $client->id)
            ->update($updates);

        $client->forceFill($updates);

        return $selection;
    }

    private function isReachableImageUrl(string $url): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'ExoticCRM-ImageCheck/1.0',
                'Accept' => 'image/*,*/*;q=0.1',
            ])
                ->withOptions(['allow_redirects' => true])
                ->connectTimeout(2)
                ->timeout(5)
                ->head($url);
        } catch (Throwable) {
            return false;
        }

        if (!$response->successful()) {
            return false;
        }

        $contentType = strtolower((string) $response->header('Content-Type', ''));

        return $contentType === '' || str_starts_with($contentType, 'image/');
    }
}
