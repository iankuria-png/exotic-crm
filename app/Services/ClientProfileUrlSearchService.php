<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EscortLiveUrl;
use App\Models\Platform;
use App\Models\User;
use App\Models\WordpressPost;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClientProfileUrlSearchService
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService
    ) {
    }

    public function resolveClientSearch(string $search, User $user, ?int $requestedPlatformId = null): ?array
    {
        $normalizedUrl = $this->normalizeSearchUrl($search);
        if ($normalizedUrl === null) {
            return null;
        }

        $candidatePlatforms = $this->resolveCandidatePlatforms($normalizedUrl['host'], $user, $requestedPlatformId);
        if ($candidatePlatforms->isEmpty()) {
            return [
                'client_ids' => [],
                'fallback_terms' => [],
            ];
        }

        if ($normalizedUrl['wp_post_id'] !== null) {
            return [
                'client_ids' => $this->findClientIdsByPostId($candidatePlatforms, $normalizedUrl['wp_post_id']),
                'fallback_terms' => [],
            ];
        }

        $matches = [];

        foreach ($candidatePlatforms as $platform) {
            $postId = $this->resolvePostIdForPlatform(
                $platform,
                $normalizedUrl['url_candidates'],
                $normalizedUrl['slug_candidates']
            );
            if ($postId === null) {
                continue;
            }

            $matches[] = [
                'platform_id' => (int) $platform->id,
                'wp_post_id' => $postId,
            ];
        }

        if ($matches === []) {
            return [
                'client_ids' => [],
                'fallback_terms' => $this->buildFallbackTerms($normalizedUrl['slug_candidates']),
            ];
        }

        return [
            'client_ids' => Client::query()
                ->where(function ($query) use ($matches) {
                    foreach ($matches as $match) {
                        $query->orWhere(function ($nested) use ($match) {
                            $nested->where('platform_id', $match['platform_id'])
                                ->where('wp_post_id', $match['wp_post_id']);
                        });
                    }
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all(),
            'fallback_terms' => [],
        ];
    }

    public function resolveClientIds(string $search, User $user, ?int $requestedPlatformId = null): ?array
    {
        $resolved = $this->resolveClientSearch($search, $user, $requestedPlatformId);

        return $resolved['client_ids'] ?? $resolved;
    }

    public function extractFallbackTerms(string $search): array
    {
        $normalizedUrl = $this->normalizeSearchUrl($search);
        if ($normalizedUrl === null) {
            return [];
        }

        return $this->buildFallbackTerms($normalizedUrl['slug_candidates']);
    }

    private function normalizeSearchUrl(string $search): ?array
    {
        $trimmed = trim($search);
        if ($trimmed === '' || !preg_match('#^https?://#i', $trimmed)) {
            return null;
        }

        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $rawHost = strtolower(trim((string) ($parts['host'] ?? '')));
        $host = $this->normalizeHost($rawHost);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $rawHost === '') {
            return null;
        }

        $path = '/' . ltrim((string) ($parts['path'] ?? '/'), '/');
        $path = preg_replace('#/+#', '/', $path) ?: '/';

        parse_str((string) ($parts['query'] ?? ''), $queryParams);
        $wpPostId = isset($queryParams['p']) && ctype_digit((string) $queryParams['p'])
            ? (int) $queryParams['p']
            : null;

        $urlCandidates = [];
        $basePath = $path === '/' ? '' : rtrim($path, '/');
        $slugCandidates = $this->extractSlugCandidates($path);

        foreach ([$rawHost, $host, 'www.' . $host] as $hostVariant) {
            $hostVariant = strtolower(trim($hostVariant));
            if ($hostVariant === '') {
                continue;
            }

            foreach (['https', 'http'] as $candidateScheme) {
                $baseUrl = $candidateScheme . '://' . $hostVariant . $basePath;
                $urlCandidates[] = $baseUrl === $candidateScheme . '://' . $hostVariant ? $baseUrl . '/' : $baseUrl;

                if ($basePath !== '') {
                    $urlCandidates[] = $baseUrl . '/';
                }
            }
        }

        $urlCandidates = array_values(array_unique(array_filter($urlCandidates)));

        return [
            'host' => $host,
            'wp_post_id' => $wpPostId,
            'url_candidates' => $urlCandidates,
            'slug_candidates' => $slugCandidates,
        ];
    }

    private function resolveCandidatePlatforms(string $host, User $user, ?int $requestedPlatformId): Collection
    {
        $query = Platform::query();
        $accessiblePlatformIds = $this->marketAuthorizationService->resolveAccessiblePlatformIds($user);

        if (is_array($accessiblePlatformIds)) {
            if ($accessiblePlatformIds === []) {
                return collect();
            }

            $query->whereIn('id', $accessiblePlatformIds);
        }

        if ($requestedPlatformId !== null) {
            $query->whereKey($requestedPlatformId);
        }

        return $query->get()->filter(function (Platform $platform) use ($host) {
            return $this->platformMatchesHost($platform, $host);
        })->values();
    }

    private function findClientIdsByPostId(Collection $platforms, int $wpPostId): array
    {
        return Client::query()
            ->whereIn('platform_id', $platforms->pluck('id')->all())
            ->where('wp_post_id', $wpPostId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function resolvePostIdForPlatform(Platform $platform, array $urlCandidates, array $slugCandidates): ?int
    {
        try {
            $connectionName = $this->resolveConnectionName($platform);

            $postId = EscortLiveUrl::on($connectionName)
                ->whereIn('live_url', $urlCandidates)
                ->value('post_id');

            if ($postId !== null) {
                return (int) $postId;
            }

            if ($slugCandidates !== []) {
                $postIdFromLiveUrlSlug = EscortLiveUrl::on($connectionName)
                    ->whereIn('post_name', $slugCandidates)
                    ->value('post_id');

                if ($postIdFromLiveUrlSlug !== null) {
                    return (int) $postIdFromLiveUrlSlug;
                }

                $postIdFromPostSlug = WordpressPost::on($connectionName)
                    ->whereIn('post_name', $slugCandidates)
                    ->value('ID');

                if ($postIdFromPostSlug !== null) {
                    return (int) $postIdFromPostSlug;
                }
            }

            $guidPostId = WordpressPost::on($connectionName)
                ->whereIn('guid', $urlCandidates)
                ->value('ID');

            if ($guidPostId !== null) {
                return (int) $guidPostId;
            }

            return null;
        } catch (Throwable $exception) {
            Log::warning('Failed to resolve client search URL for platform.', [
                'platform_id' => $platform->id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveConnectionName(Platform $platform): string
    {
        $defaultConnection = (string) config('database.default');
        $defaultConfig = (array) config("database.connections.{$defaultConnection}", []);

        if (($defaultConfig['driver'] ?? null) === 'sqlite'
            && blank($platform->db_host)
            && blank($platform->db_name)
            && blank($platform->db_user)
        ) {
            return $defaultConnection;
        }

        $connectionName = 'platform_' . $platform->id;
        DynamicDatabaseService::switchConnection($connectionName, $platform->getConnectionConfig());

        return $connectionName;
    }

    private function platformMatchesHost(Platform $platform, string $host): bool
    {
        $domainHost = $this->normalizeHost((string) ($platform->domain ?? ''));
        if ($domainHost !== '' && $domainHost === $host) {
            return true;
        }

        $apiHost = $this->normalizeHost((string) ($platform->wp_api_url ?? ''));

        return $apiHost !== '' && $apiHost === $host;
    }

    private function normalizeHost(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        $host = parse_url($trimmed, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            $host = preg_replace('#^https?://#i', '', $trimmed) ?: '';
            $host = explode('/', $host)[0] ?? '';
        }

        $host = strtolower(trim($host));

        return preg_replace('#^www\.#', '', $host) ?: '';
    }

    private function extractSlugCandidates(string $path): array
    {
        $trimmedPath = trim($path);
        if ($trimmedPath === '' || $trimmedPath === '/') {
            return [];
        }

        $segments = array_values(array_filter(explode('/', trim($trimmedPath, '/'))));
        if ($segments === []) {
            return [];
        }

        $lastSegment = urldecode((string) end($segments));
        $lastSegment = trim($lastSegment);
        if ($lastSegment === '') {
            return [];
        }

        $slugCandidates = [
            strtolower($lastSegment),
        ];

        $sanitized = preg_replace('/[^a-z0-9_-]+/i', '-', $lastSegment) ?: '';
        $sanitized = strtolower(trim($sanitized, '-'));
        if ($sanitized !== '') {
            $slugCandidates[] = $sanitized;
        }

        return array_values(array_unique(array_filter($slugCandidates)));
    }

    private function buildFallbackTerms(array $slugCandidates): array
    {
        $fallbackTerms = [];

        foreach ($slugCandidates as $slugCandidate) {
            $slug = strtolower(trim((string) $slugCandidate));
            if ($slug === '') {
                continue;
            }

            $label = preg_replace('/[-_]+/', ' ', $slug) ?: '';
            $label = preg_replace('/\s+/', ' ', trim((string) $label)) ?: '';

            if ($label !== '' && preg_match('/[a-z]/i', $label)) {
                $fallbackTerms[] = $label;
            }

            $withoutTrailingNumber = preg_replace('/(?:\s+|-+)\d+$/', '', $label) ?: '';
            $withoutTrailingNumber = preg_replace('/\s+/', ' ', trim((string) $withoutTrailingNumber)) ?: '';

            if ($withoutTrailingNumber !== '' && $withoutTrailingNumber !== $label && preg_match('/[a-z]/i', $withoutTrailingNumber)) {
                $fallbackTerms[] = $withoutTrailingNumber;
            }
        }

        return array_values(array_unique(array_filter($fallbackTerms)));
    }
}
