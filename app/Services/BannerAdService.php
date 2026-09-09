<?php

namespace App\Services;

use App\Exceptions\BannerAdRemoteException;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BannerAdService
{
    private const NAMESPACE = 'exotic-campaigns/v1';

    public function __construct(private readonly MarketAuthorizationService $marketAuthorization) {}

    public function marketsForUser(User $user, ?int $selectedPlatformId = null): array
    {
        $query = Platform::query()
            ->where('is_active', true)
            ->orderBy('name');

        $this->marketAuthorization->applyPlatformScope($query, $user, 'id');

        return $query->get()
            ->sortByDesc(fn (Platform $platform) => $selectedPlatformId !== null && (int) $platform->id === $selectedPlatformId ? 1 : 0)
            ->map(fn (Platform $platform) => $this->marketPayload($platform))
            ->values()
            ->all();
    }

    public function list(Platform $platform, array $filters = []): array
    {
        $campaigns = $this->request($platform, 'get', '/campaigns', [
            'query' => array_filter([
                'status' => $filters['status'] ?? null,
                'page' => $filters['page'] ?? 1,
                'per_page' => $filters['per_page'] ?? 25,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);

        $summary = $this->request($platform, 'get', '/analytics/summary');
        $settings = $this->settings($platform);

        return [
            'items' => $this->normalizeItems((array) ($campaigns['items'] ?? []), $platform),
            'total' => (int) ($campaigns['total'] ?? 0),
            'pages' => (int) ($campaigns['pages'] ?? 1),
            'page' => (int) ($campaigns['page'] ?? ($filters['page'] ?? 1)),
            'summary' => $this->normalizeSummary((array) ($summary['summary'] ?? [])),
            'settings' => $settings,
        ];
    }

    public function get(Platform $platform, int $campaignId): array
    {
        return $this->normalizeItem($this->request($platform, 'get', "/campaigns/{$campaignId}"), $platform);
    }

    public function create(Platform $platform, array $payload): array
    {
        return $this->normalizeItem($this->request($platform, 'post', '/campaigns', ['json' => $payload]), $platform);
    }

    public function update(Platform $platform, int $campaignId, array $payload): array
    {
        return $this->normalizeItem($this->request($platform, 'patch', "/campaigns/{$campaignId}", ['json' => $payload]), $platform);
    }

    public function changeStatus(Platform $platform, int $campaignId, array $payload): array
    {
        return $this->normalizeItem($this->request($platform, 'post', "/campaigns/{$campaignId}/status", ['json' => $payload]), $platform);
    }

    public function delete(Platform $platform, int $campaignId): array
    {
        return $this->request($platform, 'delete', "/campaigns/{$campaignId}");
    }

    public function settings(Platform $platform): array
    {
        $settings = $this->request($platform, 'get', '/settings');

        return [
            'shuffle_mode' => (bool) ($settings['shuffle_mode'] ?? false),
        ];
    }

    public function updateSettings(Platform $platform, array $payload): array
    {
        $settings = $this->request($platform, 'post', '/settings', ['json' => [
            'shuffle_mode' => (bool) ($payload['shuffle_mode'] ?? false),
        ]]);

        return [
            'shuffle_mode' => (bool) ($settings['shuffle_mode'] ?? false),
        ];
    }

    public function media(Platform $platform, array $filters = []): array
    {
        $payload = $this->request($platform, 'get', '/media', [
            'query' => array_filter([
                'search' => $filters['search'] ?? null,
                'page' => $filters['page'] ?? 1,
                'per_page' => $filters['per_page'] ?? 24,
            ], static fn ($value) => $value !== null && $value !== ''),
        ]);

        return [
            'items' => array_map(fn ($item) => $this->normalizeMediaItem((array) $item), (array) ($payload['items'] ?? [])),
            'total' => (int) ($payload['total'] ?? 0),
            'pages' => (int) ($payload['pages'] ?? 1),
            'page' => (int) ($payload['page'] ?? ($filters['page'] ?? 1)),
        ];
    }

    public function uploadMedia(Platform $platform, UploadedFile $file, ?string $altText = null): array
    {
        $this->assertReadyForRequests($platform);

        $url = $this->apiBase($platform).'/media';

        try {
            $response = Http::withHeaders($this->headers($platform))
                ->timeout($this->isRemoteEndpoint($url) ? 120 : 60)
                ->attach('file', fopen($file->getRealPath(), 'r'), $file->getClientOriginalName())
                ->post($url, array_filter([
                    'alt_text' => $altText,
                ], static fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException $exception) {
            throw new BannerAdRemoteException('Could not connect to the WordPress Banner Ads media endpoint.', 502, null, [
                'platform_id' => (int) $platform->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->normalizeMediaItem($this->decode($platform, $response, 'POST', '/media'));
    }

    private function marketPayload(Platform $platform): array
    {
        $readiness = $this->readiness($platform);

        return [
            'id' => (int) $platform->id,
            'name' => (string) $platform->name,
            'country' => (string) $platform->country,
            'timezone' => (string) ($platform->timezone ?: config('app.timezone', 'UTC')),
            'banner_ads_ready' => $readiness['ready'],
            'readiness_message' => $readiness['message'],
        ];
    }

    private function readiness(Platform $platform): array
    {
        if (! $this->hasConnectionMetadata($platform)) {
            return [
                'ready' => false,
                'message' => 'WordPress API credentials are missing for this market.',
            ];
        }

        return [
            'ready' => true,
            'message' => null,
        ];
    }

    private function request(Platform $platform, string $method, string $path, array $options = []): array
    {
        $this->assertReadyForRequests($platform);

        $url = $this->apiBase($platform).$path;
        $method = strtolower($method);

        try {
            $pending = Http::withHeaders($this->headers($platform))
                ->timeout($this->isRemoteEndpoint($url) ? 60 : 30);

            $response = match ($method) {
                'get' => $pending->get($url, $options['query'] ?? []),
                'post' => $pending->post($url, $options['json'] ?? []),
                'patch' => $pending->patch($url, $options['json'] ?? []),
                'delete' => $pending->delete($url, $options['json'] ?? []),
                default => throw new \InvalidArgumentException("Unsupported Banner Ads HTTP method [{$method}]."),
            };
        } catch (ConnectionException $exception) {
            throw new BannerAdRemoteException('Could not connect to the WordPress Banner Ads endpoint.', 502, null, [
                'platform_id' => (int) $platform->id,
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->decode($platform, $response, strtoupper($method), $path);
    }

    private function decode(Platform $platform, Response $response, string $method, string $path): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $remoteStatus = $response->status();
        $message = $this->remoteMessage($response, $remoteStatus);
        $responseStatus = match ($remoteStatus) {
            404 => 404,
            422 => 422,
            default => 502,
        };

        Log::warning('Banner Ads WordPress request failed', [
            'platform_id' => (int) $platform->id,
            'method' => $method,
            'path' => $path,
            'remote_status' => $remoteStatus,
            'body' => $response->body(),
        ]);

        throw new BannerAdRemoteException($message, $responseStatus, $remoteStatus, [
            'platform_id' => (int) $platform->id,
            'path' => $path,
        ]);
    }

    private function remoteMessage(Response $response, int $remoteStatus): string
    {
        if ($remoteStatus === 404) {
            return 'Banner Ads plugin not available or campaign not found.';
        }

        if (in_array($remoteStatus, [401, 403], true)) {
            return 'WordPress API user cannot manage banner ads.';
        }

        $message = trim((string) ($response->json('message') ?? ''));

        return $message !== '' ? $message : 'WordPress Banner Ads request failed.';
    }

    private function assertReadyForRequests(Platform $platform): void
    {
        if (! $this->hasConnectionMetadata($platform)) {
            throw new BannerAdRemoteException('WordPress API credentials are missing for this market.', 422, null, [
                'platform_id' => (int) $platform->id,
            ]);
        }
    }

    private function hasConnectionMetadata(Platform $platform): bool
    {
        return trim((string) $platform->wp_api_url) !== ''
            && trim((string) $platform->wp_api_user) !== ''
            && trim((string) $platform->wp_api_password) !== '';
    }

    private function apiBase(Platform $platform): string
    {
        $root = preg_replace('#/wp-json/.*$#', '', rtrim((string) $platform->wp_api_url, '/'))
            ?: rtrim((string) $platform->wp_api_url, '/');

        return rtrim($root, '/').'/wp-json/'.self::NAMESPACE;
    }

    private function headers(Platform $platform): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode((string) $platform->wp_api_user.':'.(string) $platform->wp_api_password),
            'Accept' => 'application/json',
        ];
    }

    private function normalizeItems(array $items, Platform $platform): array
    {
        return array_map(fn ($item) => $this->normalizeItem((array) $item, $platform), $items);
    }

    private function normalizeItem(array $item, Platform $platform): array
    {
        $impressions = (int) ($item['impressions'] ?? 0);
        $clicks = (int) ($item['clicks'] ?? 0);
        $format = in_array(($item['format'] ?? ''), ['card', 'image'], true) ? (string) $item['format'] : 'card';

        return [
            'platform_id' => (int) $platform->id,
            'id' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'format' => $format,
            'badge_text' => (string) ($item['badge_text'] ?? ''),
            'description' => (string) ($item['description'] ?? ''),
            'icon_class' => (string) ($item['icon_class'] ?? 'fa fa-bullhorn'),
            'color_primary' => (string) ($item['color_primary'] ?? '#AB1C2F'),
            'color_secondary' => (string) ($item['color_secondary'] ?? ''),
            'image_id' => (int) ($item['image_id'] ?? 0),
            'image_url' => $item['image_url'] ?? null,
            'image_alt' => (string) ($item['image_alt'] ?? ''),
            'cta_text' => (string) ($item['cta_text'] ?? ''),
            'cta_url' => (string) ($item['cta_url'] ?? ''),
            'cta_visible' => (bool) ($item['cta_visible'] ?? true),
            'status' => in_array(($item['status'] ?? ''), ['active', 'scheduled', 'paused', 'expired'], true) ? (string) $item['status'] : 'scheduled',
            'priority' => max(1, (int) ($item['priority'] ?? 10)),
            'start_date' => (string) ($item['start_date'] ?? ''),
            'end_date' => (string) ($item['end_date'] ?? ''),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'ctr' => array_key_exists('ctr', $item) ? (float) $item['ctr'] : ($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0),
        ];
    }

    private function normalizeSummary(array $summary): array
    {
        $impressions = (int) ($summary['impressions_total'] ?? 0);
        $clicks = (int) ($summary['clicks_total'] ?? 0);

        return [
            'banner_ads_total' => (int) ($summary['campaigns_total'] ?? 0),
            'banner_ads_active' => (int) ($summary['campaigns_active'] ?? 0),
            'banner_ads_scheduled' => (int) ($summary['campaigns_scheduled'] ?? 0),
            'banner_ads_paused' => (int) ($summary['campaigns_paused'] ?? 0),
            'banner_ads_expired' => (int) ($summary['campaigns_expired'] ?? 0),
            'impressions_total' => $impressions,
            'clicks_total' => $clicks,
            'avg_ctr' => array_key_exists('avg_ctr', $summary) ? (float) $summary['avg_ctr'] : ($impressions > 0 ? round(($clicks / $impressions) * 100, 2) : 0.0),
        ];
    }

    private function normalizeMediaItem(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'title' => (string) ($item['title'] ?? ''),
            'url' => (string) ($item['url'] ?? ''),
            'thumbnail_url' => (string) ($item['thumbnail_url'] ?? ($item['url'] ?? '')),
            'alt_text' => (string) ($item['alt_text'] ?? ''),
            'mime_type' => (string) ($item['mime_type'] ?? ''),
        ];
    }

    private function isRemoteEndpoint(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true)
            && ! str_ends_with(strtolower($host), '.local');
    }
}
