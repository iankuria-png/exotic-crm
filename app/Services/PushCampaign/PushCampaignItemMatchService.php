<?php

namespace App\Services\PushCampaign;

use App\Models\Client;
use App\Support\ClientProfileUrl;

class PushCampaignItemMatchService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function candidatesForProfile(
        int $platformId,
        string $profileUrl,
        ?string $search = null,
        int $limit = 25
    ): array {
        $safeLimit = max(1, min(250, $limit));
        $context = $this->buildMatchContext($profileUrl);
        $searchTerm = trim((string) $search);

        $query = Client::query()
            ->where('platform_id', $platformId)
            ->where('client_type', 'escort');

        if ($searchTerm !== '') {
            $digits = preg_replace('/\D+/', '', $searchTerm) ?? '';
            $wpPostIdSearch = ctype_digit($searchTerm) ? (int) $searchTerm : 0;
            $query->where(function ($builder) use ($searchTerm, $digits, $wpPostIdSearch): void {
                $builder->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('phone_normalized', 'like', '%' . $searchTerm . '%')
                    ->orWhere('email', 'like', '%' . $searchTerm . '%');

                if ($digits !== '') {
                    $builder->orWhere('phone_normalized', 'like', '%' . $digits . '%');
                }

                if ($wpPostIdSearch > 0) {
                    $builder->orWhere('wp_post_id', $wpPostIdSearch);
                }
            });
        } else {
            $slugTokens = (array) ($context['slug_tokens'] ?? []);
            $query->where(function ($builder) use ($slugTokens, $context): void {
                foreach ($slugTokens as $token) {
                    if (!is_string($token) || strlen($token) < 3) {
                        continue;
                    }
                    $builder->orWhere('name', 'like', '%' . $token . '%');
                }

                $urlWpPostId = (int) ($context['url_wp_post_id'] ?? 0);
                if ($urlWpPostId > 0) {
                    $builder->orWhere('wp_post_id', $urlWpPostId);
                }
            });
        }

        $rows = $query
            ->with('platform:id,domain')
            ->orderByDesc('id')
            ->limit(max($safeLimit * 5, 60))
            ->get([
                'id',
                'platform_id',
                'name',
                'phone_normalized',
                'email',
                'profile_status',
                'wp_post_id',
                'main_image_url',
            ]);

        $scored = $rows->map(function (Client $client) use ($context, $searchTerm): array {
            return $this->scoreCandidate($client, $context, $searchTerm);
        });

        if ($searchTerm === '') {
            $scored = $scored->filter(fn(array $candidate): bool => (int) ($candidate['score'] ?? 0) > 0);
        }

        return $scored
            ->sortByDesc(fn(array $candidate): int => (int) ($candidate['score'] ?? 0))
            ->take($safeLimit)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     current_page: int,
     *     last_page: int,
     *     per_page: int,
     *     total: int,
     *     from: int|null,
     *     to: int|null
     * }
     */
    public function paginateCandidatesForProfile(
        int $platformId,
        string $profileUrl,
        ?string $search = null,
        int $page = 1,
        int $perPage = 10
    ): array {
        $safePerPage = max(1, min(25, $perPage));
        $safePage = max(1, $page);

        $candidates = collect($this->candidatesForProfile($platformId, $profileUrl, $search, 250));
        $total = $candidates->count();
        $lastPage = max(1, (int) ceil($total / $safePerPage));
        $currentPage = min($safePage, $lastPage);
        $offset = ($currentPage - 1) * $safePerPage;
        $rows = $candidates->slice($offset, $safePerPage)->values()->all();

        return [
            'data' => $rows,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'per_page' => $safePerPage,
            'total' => $total,
            'from' => empty($rows) ? null : ($offset + 1),
            'to' => empty($rows) ? null : ($offset + count($rows)),
        ];
    }

    /**
     * @return array{candidate:array<string,mixed>|null,reason:string,margin:int,candidates:array<int,array<string,mixed>>}
     */
    public function resolveAutoMatch(int $platformId, string $profileUrl): array
    {
        $enabled = (bool) config('services.push_campaigns.auto_match_enabled', true);
        if (!$enabled) {
            return [
                'candidate' => null,
                'reason' => 'disabled',
                'margin' => 0,
                'candidates' => [],
            ];
        }

        $minScore = (int) config('services.push_campaigns.auto_match_min_score', 85);
        $minMargin = (int) config('services.push_campaigns.auto_match_min_margin', 15);
        $candidates = $this->candidatesForProfile($platformId, $profileUrl, null, 5);

        if (empty($candidates)) {
            return [
                'candidate' => null,
                'reason' => 'no_candidates',
                'margin' => 0,
                'candidates' => [],
            ];
        }

        $top = $candidates[0];
        $topScore = (int) ($top['score'] ?? 0);
        if ($topScore < $minScore) {
            return [
                'candidate' => null,
                'reason' => 'low_confidence',
                'margin' => 0,
                'candidates' => $candidates,
            ];
        }

        $second = $candidates[1] ?? null;
        $margin = $second ? ($topScore - (int) ($second['score'] ?? 0)) : $topScore;
        if ($second && $margin < $minMargin) {
            return [
                'candidate' => null,
                'reason' => 'ambiguous',
                'margin' => $margin,
                'candidates' => $candidates,
            ];
        }

        return [
            'candidate' => $top,
            'reason' => 'matched',
            'margin' => $margin,
            'candidates' => $candidates,
        ];
    }

    public function findScopedEscortClient(int $platformId, int $clientId): ?Client
    {
        return Client::query()
            ->where('id', $clientId)
            ->where('platform_id', $platformId)
            ->where('client_type', 'escort')
            ->first();
    }

    /**
     * @return array{slug:string,slug_tokens:array<int,string>,slug_normalized:string,url_wp_post_id:int}
     */
    private function buildMatchContext(string $profileUrl): array
    {
        $slug = $this->extractProfileSlug($profileUrl);

        $slugTokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', $slug) ?: [],
            static fn($token): bool => is_string($token) && trim($token) !== ''
        ));

        return [
            'slug' => $slug,
            'slug_tokens' => $slugTokens,
            'slug_normalized' => $this->normalizeText($slug),
            'url_wp_post_id' => $this->parseWpPostIdFromUrl($profileUrl),
        ];
    }

    private function extractProfileSlug(string $profileUrl): string
    {
        $path = trim((string) parse_url($profileUrl, PHP_URL_PATH));
        if ($path === '') {
            return '';
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn($segment): bool => is_string($segment) && trim($segment) !== ''
        ));

        if (empty($segments)) {
            return '';
        }

        $lastSegment = strtolower(trim((string) end($segments)));
        $previousSegment = count($segments) > 1
            ? strtolower(trim((string) ($segments[count($segments) - 2] ?? '')))
            : '';

        if (in_array($previousSegment, ['escort', 'escorte'], true)) {
            return $lastSegment;
        }

        return $lastSegment;
    }

    /**
     * @param array{slug:string,slug_tokens:array<int,string>,slug_normalized:string,url_wp_post_id:int} $context
     * @return array<string, mixed>
     */
    private function scoreCandidate(Client $client, array $context, string $searchTerm): array
    {
        $score = 0;
        $reasons = [];
        $clientWpPostId = (int) ($client->wp_post_id ?? 0);
        $urlWpPostId = (int) ($context['url_wp_post_id'] ?? 0);

        if ($urlWpPostId > 0 && $clientWpPostId === $urlWpPostId) {
            $score += 220;
            $reasons[] = 'wp_post_id match';
        }

        $name = trim((string) ($client->name ?? ''));
        $nameNormalized = $this->normalizeText($name);
        $nameTokens = array_values(array_filter(
            preg_split('/[^a-z0-9]+/i', strtolower($name)) ?: [],
            static fn($token): bool => is_string($token) && trim($token) !== ''
        ));

        $slugNormalized = (string) ($context['slug_normalized'] ?? '');
        $slugTokens = (array) ($context['slug_tokens'] ?? []);

        if ($slugNormalized !== '' && $nameNormalized !== '') {
            if ($nameNormalized === $slugNormalized) {
                $score += 120;
                $reasons[] = 'exact normalized name';
            } elseif (str_contains($nameNormalized, $slugNormalized) || str_contains($slugNormalized, $nameNormalized)) {
                $score += 88;
                $reasons[] = 'name contains slug';
            }
        }

        if (!empty($slugTokens) && !empty($nameTokens)) {
            $common = array_intersect($slugTokens, $nameTokens);
            if (!empty($common)) {
                $ratio = count($common) / max(1, count($slugTokens));
                $score += (int) round($ratio * 70);
                $reasons[] = 'token overlap';
            }
        }

        if ($searchTerm !== '') {
            $digits = preg_replace('/\D+/', '', $searchTerm) ?? '';
            if ($digits !== '' && str_contains((string) ($client->phone_normalized ?? ''), $digits)) {
                $score += 40;
                $reasons[] = 'phone match';
            }
            if (str_contains(strtolower((string) $name), strtolower($searchTerm))) {
                $score += 35;
                $reasons[] = 'name search match';
            }
            if (str_contains(strtolower((string) ($client->email ?? '')), strtolower($searchTerm))) {
                $score += 25;
                $reasons[] = 'email search match';
            }
        }

        if ((string) $client->profile_status === 'publish') {
            $score += 3;
        }

        return [
            'id' => (int) $client->id,
            'name' => $client->name,
            'phone_normalized' => $client->phone_normalized,
            'email' => $client->email,
            'profile_status' => $client->profile_status,
            'wp_post_id' => $clientWpPostId ?: null,
            'main_image_url' => $client->main_image_url,
            'wp_profile_url' => ClientProfileUrl::resolve($client, $client->platform),
            'score' => $score,
            'score_reason' => empty($reasons) ? 'No strong match signals.' : implode(', ', $reasons),
        ];
    }

    private function parseWpPostIdFromUrl(string $url): int
    {
        if (preg_match('/[?&]p=(\d+)/i', $url, $match)) {
            return (int) ($match[1] ?? 0);
        }

        if (preg_match('/[?&]post_type=escort[&]?p=(\d+)/i', $url, $match)) {
            return (int) ($match[1] ?? 0);
        }

        if (preg_match('#/(\d+)/?$#', $url, $match)) {
            return (int) ($match[1] ?? 0);
        }

        return 0;
    }

    private function normalizeText(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $value));
    }
}
