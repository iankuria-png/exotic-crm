<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\TimelineEvent;
use App\Models\User;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Symfony\Component\CssSelector\CssSelectorConverter;

class ScraperSourceService
{
    public const PARSER_PROFILES = ['contact_cards', 'profile_links'];
    public const FETCH_SCHEDULES = ['manual_only', 'daily', 'weekly'];
    public const DEDUPE_MODES = ['phone_or_email', 'phone_only', 'email_only', 'source_url'];
    private const SCRAPER_USER_AGENT = 'ExoticCRMLeadBot/1.0 (+https://exoticcrm.local)';

    private ?CssSelectorConverter $cssSelectorConverter = null;

    public function __construct(
        private readonly LeadAssignmentService $leadAssignmentService
    ) {
    }

    public function runSource(ScraperSource $source, User $actor, bool $dryRun = true, int $maxCandidates = 50): array
    {
        $source->loadMissing('platform');
        $startedAt = now();

        $run = ScraperRun::query()->create([
            'scraper_source_id' => (int) $source->id,
            'platform_id' => (int) $source->platform_id,
            'initiated_by' => (int) $actor->id,
            'mode' => $dryRun ? 'dry_run' : 'import',
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        if (!$source->is_active) {
            return $this->finalizeRun($run, $source, [
                'status' => 'blocked',
                'dry_run' => $dryRun,
                'message' => 'Scraper source is inactive. Activate it before running.',
                'errors' => ['Scraper source is inactive.'],
                'robots' => null,
                'discovered' => 0,
                'created' => 0,
                'duplicates' => 0,
                'skipped' => 0,
                'preview' => [],
            ]);
        }

        if (!$source->compliance_ack_robots || !$source->compliance_ack_tos) {
            return $this->finalizeRun($run, $source, [
                'status' => 'blocked',
                'dry_run' => $dryRun,
                'message' => 'Compliance acknowledgement is incomplete. Confirm robots and terms checks first.',
                'errors' => ['Compliance acknowledgement is incomplete.'],
                'robots' => null,
                'discovered' => 0,
                'created' => 0,
                'duplicates' => 0,
                'skipped' => 0,
                'preview' => [],
            ]);
        }

        $robotsCheck = $this->evaluateRobotsAccess($source->source_url);
        if (!($robotsCheck['allowed'] ?? false)) {
            return $this->finalizeRun($run, $source, [
                'status' => 'blocked',
                'dry_run' => $dryRun,
                'message' => $robotsCheck['message'] ?? 'Robots policy blocked this scrape source.',
                'errors' => [$robotsCheck['message'] ?? 'Robots policy blocked this scrape source.'],
                'robots' => $robotsCheck,
                'discovered' => 0,
                'created' => 0,
                'duplicates' => 0,
                'skipped' => 0,
                'preview' => [],
            ]);
        }

        $errors = [];

        try {
            $htmlPayload = $this->fetchHtml($source->source_url);
            $candidates = $this->extractCandidates($source, (string) ($htmlPayload['html'] ?? ''), $maxCandidates);
        } catch (\Throwable $exception) {
            $errors[] = $exception->getMessage();

            return $this->finalizeRun($run, $source, [
                'status' => 'error',
                'dry_run' => $dryRun,
                'message' => 'Scrape run failed before candidate extraction.',
                'errors' => $errors,
                'robots' => $robotsCheck,
                'http' => $htmlPayload ?? null,
                'discovered' => 0,
                'created' => 0,
                'duplicates' => 0,
                'skipped' => 0,
                'preview' => [],
            ]);
        }

        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $preview = [];

        foreach ($candidates as $candidate) {
            $candidatePreview = [
                'name' => $candidate['name'] ?? null,
                'phone_normalized' => $candidate['phone_normalized'] ?? null,
                'email' => $candidate['email'] ?? null,
                'source_url' => $candidate['source_url'] ?? null,
            ];

            $isDuplicate = $this->candidateAlreadyExists($source, $candidate);
            if ($isDuplicate) {
                $duplicates++;
                if (count($preview) < 10) {
                    $candidatePreview['result'] = 'duplicate';
                    $preview[] = $candidatePreview;
                }
                continue;
            }

            if ($dryRun) {
                if (count($preview) < 10) {
                    $candidatePreview['result'] = 'new_candidate';
                    $preview[] = $candidatePreview;
                }
                continue;
            }

            try {
                $this->createLeadFromCandidate($source, $candidate, $actor);
                $created++;

                if (count($preview) < 10) {
                    $candidatePreview['result'] = 'created';
                    $preview[] = $candidatePreview;
                }
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = $exception->getMessage();
                if (count($preview) < 10) {
                    $candidatePreview['result'] = 'error';
                    $candidatePreview['error'] = mb_substr($exception->getMessage(), 0, 240);
                    $preview[] = $candidatePreview;
                }
            }
        }

        $status = 'success';
        if (count($errors) > 0 && ($created > 0 || $duplicates > 0)) {
            $status = 'partial';
        } elseif (count($errors) > 0) {
            $status = 'error';
        }

        return $this->finalizeRun($run, $source, [
            'status' => $status,
            'dry_run' => $dryRun,
            'message' => $status === 'success'
                ? 'Scrape run completed successfully.'
                : ($status === 'partial' ? 'Scrape run completed with warnings.' : 'Scrape run failed.'),
            'errors' => $errors,
            'robots' => $robotsCheck,
            'http' => [
                'status' => $htmlPayload['status'] ?? null,
                'content_type' => $htmlPayload['content_type'] ?? null,
            ],
            'discovered' => count($candidates),
            'created' => $created,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'preview' => $preview,
        ]);
    }

    private function finalizeRun(ScraperRun $run, ScraperSource $source, array $summary): array
    {
        $completedAt = now();
        $status = (string) ($summary['status'] ?? 'error');
        $discovered = (int) ($summary['discovered'] ?? 0);
        $created = (int) ($summary['created'] ?? 0);
        $duplicates = (int) ($summary['duplicates'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $errors = collect($summary['errors'] ?? [])->map(fn ($value) => (string) $value)->values()->all();
        $preview = array_values($summary['preview'] ?? []);

        $run->fill([
            'status' => $status,
            'discovered_count' => $discovered,
            'created_count' => $created,
            'duplicate_count' => $duplicates,
            'skipped_count' => $skipped,
            'error_count' => count($errors),
            'preview' => $preview,
            'result' => $summary,
            'completed_at' => $completedAt,
        ])->save();

        $source->forceFill([
            'last_run_at' => $completedAt,
            'last_run_status' => $status,
            'last_run_summary' => [
                'status' => $status,
                'dry_run' => (bool) ($summary['dry_run'] ?? false),
                'message' => $summary['message'] ?? null,
                'discovered' => $discovered,
                'created' => $created,
                'duplicates' => $duplicates,
                'skipped' => $skipped,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, 5),
                'completed_at' => $completedAt->toDateTimeString(),
            ],
        ])->save();

        return $summary;
    }

    private function fetchHtml(string $url): array
    {
        $response = Http::withHeaders([
            'User-Agent' => self::SCRAPER_USER_AGENT,
            'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1',
        ])
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(2, 300)
            ->get($url);

        if (!$response->successful()) {
            throw new \RuntimeException(sprintf('Source fetch failed with HTTP %s.', $response->status()));
        }

        $contentType = strtolower((string) $response->header('content-type', ''));
        if ($contentType !== '' && !str_contains($contentType, 'text/html')) {
            throw new \RuntimeException(sprintf('Source fetch returned unsupported content type: %s.', $contentType));
        }

        return [
            'status' => $response->status(),
            'content_type' => $contentType,
            'html' => (string) $response->body(),
        ];
    }

    private function extractCandidates(ScraperSource $source, string $html, int $maxCandidates): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $profile = in_array($source->parser_profile, self::PARSER_PROFILES, true)
            ? $source->parser_profile
            : 'contact_cards';

        $rawCandidates = $profile === 'profile_links'
            ? $this->extractFromProfileLinks($source, $xpath)
            : $this->extractFromContactCards($source, $xpath);

        $normalized = [];
        $seen = [];
        foreach ($rawCandidates as $candidate) {
            $cleaned = $this->normalizeCandidate($source, $candidate);
            if (!$cleaned) {
                continue;
            }

            $key = $this->candidateKey($source, $cleaned);
            if ($key === '' || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = $cleaned;

            if (count($normalized) >= $maxCandidates) {
                break;
            }
        }

        return $normalized;
    }

    private function extractFromContactCards(ScraperSource $source, DOMXPath $xpath): array
    {
        $rules = is_array($source->parser_rules) ? $source->parser_rules : [];
        $candidates = [];

        if (!empty($rules['row_selector'])) {
            $rows = $this->queryCss($xpath, (string) $rules['row_selector']);
            foreach ($rows as $row) {
                $text = $this->nodeText($row);
                $candidates[] = [
                    'name' => $this->selectorText($xpath, $row, $rules['name_selector'] ?? null) ?: $this->extractNameFromText($text),
                    'phone_normalized' => $this->selectorText($xpath, $row, $rules['phone_selector'] ?? null) ?: $this->extractPhoneFromText($text),
                    'email' => $this->selectorText($xpath, $row, $rules['email_selector'] ?? null) ?: $this->extractEmailFromText($text),
                    'source_url' => $this->extractHrefFromContext($xpath, $row, $source->source_url, $rules['link_selector'] ?? null) ?: $source->source_url,
                ];
            }
        }

        if (empty($candidates)) {
            $anchors = $this->queryCss($xpath, 'a[href^="tel:"],a[href^="mailto:"]');
            foreach ($anchors as $anchor) {
                $context = $anchor->parentNode instanceof DOMNode ? $anchor->parentNode : $anchor;
                $text = $this->nodeText($context);
                $href = trim((string) ($anchor->attributes?->getNamedItem('href')?->nodeValue ?? ''));

                $candidates[] = [
                    'name' => $this->extractNameFromText($text),
                    'phone_normalized' => str_starts_with(strtolower($href), 'tel:')
                        ? substr($href, 4)
                        : $this->extractPhoneFromText($text),
                    'email' => str_starts_with(strtolower($href), 'mailto:')
                        ? substr($href, 7)
                        : $this->extractEmailFromText($text),
                    'source_url' => $source->source_url,
                ];
            }
        }

        if (empty($candidates)) {
            $pageText = $this->nodeText($xpath->query('//body')->item(0));
            $heading = $this->nodeText($xpath->query('//h1|//h2')->item(0));
            $candidates[] = [
                'name' => $heading ?: $this->deriveLeadNameFromUrl($source->source_url),
                'phone_normalized' => $this->extractPhoneFromText($pageText),
                'email' => $this->extractEmailFromText($pageText),
                'source_url' => $source->source_url,
            ];
        }

        return $candidates;
    }

    private function extractFromProfileLinks(ScraperSource $source, DOMXPath $xpath): array
    {
        $rules = is_array($source->parser_rules) ? $source->parser_rules : [];
        $selector = !empty($rules['link_selector']) ? (string) $rules['link_selector'] : 'a[href]';
        $anchors = $this->queryCss($xpath, $selector);
        $sourceHost = parse_url($source->source_url, PHP_URL_HOST);
        $candidates = [];

        foreach ($anchors as $anchor) {
            $href = trim((string) ($anchor->attributes?->getNamedItem('href')?->nodeValue ?? ''));
            $resolvedUrl = $this->resolveUrl($source->source_url, $href);
            if (!$resolvedUrl) {
                continue;
            }

            $candidateHost = parse_url($resolvedUrl, PHP_URL_HOST);
            if ($sourceHost && $candidateHost && strtolower($sourceHost) !== strtolower($candidateHost)) {
                continue;
            }

            $text = $this->nodeText($anchor);
            $candidates[] = [
                'name' => $text ?: $this->deriveLeadNameFromUrl($resolvedUrl),
                'phone_normalized' => null,
                'email' => null,
                'source_url' => $resolvedUrl,
            ];
        }

        return $candidates;
    }

    private function normalizeCandidate(ScraperSource $source, array $candidate): ?array
    {
        $prefix = (string) ($source->platform?->phone_prefix ?: '254');
        $sourceUrl = $this->resolveUrl($source->source_url, (string) ($candidate['source_url'] ?? '')) ?: $source->source_url;
        $phone = $this->normalizePhone($candidate['phone_normalized'] ?? null, $prefix);
        $email = $this->normalizeEmail($candidate['email'] ?? null);
        $name = $this->normalizeName($candidate['name'] ?? null);

        if (!$name) {
            $name = $this->deriveLeadNameFromUrl($sourceUrl);
        }

        if (!$name) {
            return null;
        }

        if (!$phone && !$email && !$sourceUrl) {
            return null;
        }

        return [
            'name' => $name,
            'phone_normalized' => $phone,
            'email' => $email,
            'source_url' => $sourceUrl,
        ];
    }

    private function candidateKey(ScraperSource $source, array $candidate): string
    {
        $mode = in_array($source->dedupe_mode, self::DEDUPE_MODES, true)
            ? $source->dedupe_mode
            : 'phone_or_email';

        return match ($mode) {
            'phone_only' => $candidate['phone_normalized'] ? 'phone:' . $candidate['phone_normalized'] : '',
            'email_only' => $candidate['email'] ? 'email:' . $candidate['email'] : '',
            'source_url' => $candidate['source_url'] ? 'url:' . strtolower($candidate['source_url']) : '',
            default => $candidate['phone_normalized']
                ? 'phone:' . $candidate['phone_normalized']
                : ($candidate['email'] ? 'email:' . $candidate['email'] : 'url:' . strtolower((string) $candidate['source_url'])),
        };
    }

    private function candidateAlreadyExists(ScraperSource $source, array $candidate): bool
    {
        $mode = in_array($source->dedupe_mode, self::DEDUPE_MODES, true)
            ? $source->dedupe_mode
            : 'phone_or_email';

        $query = Lead::query()->where('platform_id', (int) $source->platform_id);

        if ($mode === 'phone_only') {
            if (empty($candidate['phone_normalized'])) {
                return false;
            }

            return $query->where('phone_normalized', $candidate['phone_normalized'])->exists();
        }

        if ($mode === 'email_only') {
            if (empty($candidate['email'])) {
                return false;
            }

            return $query->where('email', $candidate['email'])->exists();
        }

        if ($mode === 'source_url') {
            if (empty($candidate['source_url'])) {
                return false;
            }

            return $query->where('source_url', $candidate['source_url'])->exists();
        }

        return $query->where(function ($builder) use ($candidate) {
            if (!empty($candidate['phone_normalized'])) {
                $builder->orWhere('phone_normalized', $candidate['phone_normalized']);
            }
            if (!empty($candidate['email'])) {
                $builder->orWhere('email', $candidate['email']);
            }
            if (!empty($candidate['source_url'])) {
                $builder->orWhere('source_url', $candidate['source_url']);
            }
        })->exists();
    }

    private function createLeadFromCandidate(ScraperSource $source, array $candidate, User $actor): Lead
    {
        $assignedTo = $this->leadAssignmentService->assignOwnerId(
            (int) $source->platform_id,
            [
                'phone_normalized' => $candidate['phone_normalized'] ?? null,
                'email' => $candidate['email'] ?? null,
                'name' => $candidate['name'] ?? null,
            ],
            null
        );

        $lead = Lead::query()->create([
            'platform_id' => (int) $source->platform_id,
            'name' => $candidate['name'],
            'phone_normalized' => $candidate['phone_normalized'],
            'email' => $candidate['email'],
            'source_url' => $candidate['source_url'] ?? null,
            'source' => 'import',
            'status' => 'new',
            'assigned_to' => $assignedTo,
        ]);

        TimelineEvent::query()->create([
            'platform_id' => (int) $source->platform_id,
            'entity_type' => 'lead',
            'entity_id' => (int) $lead->id,
            'event_type' => 'lead_scraped',
            'actor_id' => (int) $actor->id,
            'content' => [
                'scraper_source_id' => (int) $source->id,
                'source_url' => $candidate['source_url'] ?? null,
                'parser_profile' => $source->parser_profile,
            ],
            'created_at' => now(),
        ]);

        return $lead;
    }

    private function evaluateRobotsAccess(string $url): array
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');

        if ($host === '') {
            return [
                'allowed' => false,
                'message' => 'Invalid source URL host.',
                'robots_url' => null,
                'policy' => 'invalid_url',
            ];
        }

        $target = $path !== '' ? $path : '/';
        if ($query !== '') {
            $target .= '?' . $query;
        }

        $robotsUrl = sprintf('%s://%s/robots.txt', $scheme, $host);
        $response = Http::withHeaders([
            'User-Agent' => self::SCRAPER_USER_AGENT,
            'Accept' => 'text/plain,*/*;q=0.1',
        ])
            ->connectTimeout(5)
            ->timeout(12)
            ->retry(1, 200)
            ->get($robotsUrl);

        if ($response->status() >= 500) {
            return [
                'allowed' => false,
                'message' => 'Robots endpoint returned server error. Run blocked until robots is reachable.',
                'robots_url' => $robotsUrl,
                'policy' => 'robots_server_error',
                'status' => $response->status(),
            ];
        }

        if ($response->status() >= 400) {
            return [
                'allowed' => true,
                'message' => 'robots.txt unavailable (4xx); run allowed.',
                'robots_url' => $robotsUrl,
                'policy' => 'robots_unavailable',
                'status' => $response->status(),
            ];
        }

        $body = (string) $response->body();
        $allowed = $this->isPathAllowedByRobots($body, $target);

        return [
            'allowed' => $allowed,
            'message' => $allowed
                ? 'robots policy allows this path.'
                : 'robots policy disallows this path for the configured user-agent.',
            'robots_url' => $robotsUrl,
            'policy' => 'robots_matched',
            'status' => $response->status(),
            'path' => $target,
        ];
    }

    private function isPathAllowedByRobots(string $robotsContent, string $targetPath): bool
    {
        $groups = $this->parseRobotsGroups($robotsContent);
        $rules = $this->selectRobotsRules($groups, self::SCRAPER_USER_AGENT);
        if (empty($rules)) {
            return true;
        }

        $matched = [];
        foreach ($rules as $rule) {
            $rulePath = (string) ($rule['path'] ?? '');
            if ($rulePath === '') {
                continue;
            }

            if (!$this->robotsPatternMatches($rulePath, $targetPath)) {
                continue;
            }

            $matched[] = [
                'type' => (string) ($rule['type'] ?? 'disallow'),
                'length' => strlen(rtrim($rulePath, '$')),
            ];
        }

        if (empty($matched)) {
            return true;
        }

        usort($matched, fn ($left, $right) => $right['length'] <=> $left['length']);
        $bestLength = $matched[0]['length'];
        $bestRules = array_values(array_filter($matched, fn ($rule) => $rule['length'] === $bestLength));

        foreach ($bestRules as $rule) {
            if ($rule['type'] === 'allow') {
                return true;
            }
        }

        return false;
    }

    private function parseRobotsGroups(string $robotsContent): array
    {
        $lines = preg_split('/\R+/', $robotsContent) ?: [];
        $groups = [];
        $current = ['agents' => [], 'rules' => []];

        foreach ($lines as $line) {
            $clean = trim((string) preg_replace('/#.*/', '', $line));
            if ($clean === '') {
                if (!empty($current['agents']) || !empty($current['rules'])) {
                    $groups[] = $current;
                    $current = ['agents' => [], 'rules' => []];
                }
                continue;
            }

            [$field, $value] = array_pad(explode(':', $clean, 2), 2, '');
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                if (!empty($current['rules'])) {
                    $groups[] = $current;
                    $current = ['agents' => [], 'rules' => []];
                }
                $current['agents'][] = strtolower($value);
                continue;
            }

            if (in_array($field, ['allow', 'disallow'], true)) {
                if (empty($current['agents'])) {
                    continue;
                }
                $current['rules'][] = [
                    'type' => $field,
                    'path' => $value,
                ];
            }
        }

        if (!empty($current['agents']) || !empty($current['rules'])) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function selectRobotsRules(array $groups, string $userAgent): array
    {
        $token = strtolower(trim($userAgent));
        $bestSpecificity = -1;
        $selectedRules = [];

        foreach ($groups as $group) {
            $agents = array_values(array_filter(array_map('strtolower', $group['agents'] ?? [])));
            if (empty($agents)) {
                continue;
            }

            $groupSpecificity = -1;
            foreach ($agents as $agent) {
                if ($agent === '*') {
                    $groupSpecificity = max($groupSpecificity, 0);
                    continue;
                }

                if (str_contains($token, $agent)) {
                    $groupSpecificity = max($groupSpecificity, strlen($agent));
                }
            }

            if ($groupSpecificity < 0) {
                continue;
            }

            if ($groupSpecificity > $bestSpecificity) {
                $bestSpecificity = $groupSpecificity;
                $selectedRules = $group['rules'] ?? [];
            } elseif ($groupSpecificity === $bestSpecificity) {
                $selectedRules = array_merge($selectedRules, $group['rules'] ?? []);
            }
        }

        return $selectedRules;
    }

    private function robotsPatternMatches(string $pattern, string $targetPath): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        if ($pattern === '') {
            return true;
        }

        $escaped = preg_quote($pattern, '#');
        $escaped = str_replace('\*', '.*', $escaped);
        $regex = $anchored ? '#^' . $escaped . '$#' : '#^' . $escaped . '#';

        return (bool) preg_match($regex, $targetPath);
    }

    private function queryCss(DOMXPath $xpath, string $selector, ?DOMNode $context = null): array
    {
        $selector = trim($selector);
        if ($selector === '') {
            return [];
        }

        try {
            $expr = $this->cssSelectorConverter()->toXPath($selector);
            $nodeList = $xpath->query($expr, $context);
            if (!$nodeList) {
                return [];
            }

            $nodes = [];
            foreach ($nodeList as $node) {
                if ($node instanceof DOMNode) {
                    $nodes[] = $node;
                }
            }

            return $nodes;
        } catch (\Throwable) {
            return [];
        }
    }

    private function selectorText(DOMXPath $xpath, DOMNode $context, ?string $selector): ?string
    {
        if (!$selector) {
            return null;
        }

        $node = $this->queryCss($xpath, $selector, $context)[0] ?? null;
        return $this->nodeText($node) ?: null;
    }

    private function extractHrefFromContext(DOMXPath $xpath, DOMNode $context, string $baseUrl, ?string $selector): ?string
    {
        $node = null;
        if ($selector) {
            $node = $this->queryCss($xpath, $selector, $context)[0] ?? null;
        } else {
            $node = $this->queryCss($xpath, 'a[href]', $context)[0] ?? null;
        }

        if (!$node) {
            return null;
        }

        $href = trim((string) ($node->attributes?->getNamedItem('href')?->nodeValue ?? ''));
        return $this->resolveUrl($baseUrl, $href);
    }

    private function resolveUrl(string $baseUrl, string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with(strtolower($href), 'javascript:')) {
            return null;
        }

        if (str_starts_with(strtolower($href), 'mailto:') || str_starts_with(strtolower($href), 'tel:')) {
            return null;
        }

        $parts = parse_url($href);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = (string) ($base['scheme'] ?? 'https');
        $host = (string) ($base['host'] ?? '');
        if ($host === '') {
            return null;
        }

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return sprintf('%s://%s%s', $scheme, $host, $href);
        }

        $basePath = (string) ($base['path'] ?? '/');
        $baseDir = rtrim(str_contains($basePath, '/') ? dirname($basePath) : '/', '/');
        if ($baseDir === '.') {
            $baseDir = '';
        }

        return sprintf('%s://%s%s/%s', $scheme, $host, $baseDir, ltrim($href, '/'));
    }

    private function nodeText(?DOMNode $node): string
    {
        if (!$node) {
            return '';
        }

        $text = trim((string) $node->textContent);
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        return trim($text);
    }

    private function extractPhoneFromText(string $text): ?string
    {
        if (preg_match('/(?:\+?\d[\d\-\s()]{7,}\d)/', $text, $match)) {
            return $match[0];
        }

        return null;
    }

    private function extractEmailFromText(string $text): ?string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $match)) {
            return $match[0];
        }

        return null;
    }

    private function extractNameFromText(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        $compact = mb_substr($text, 0, 90);
        $parts = preg_split('/\s{2,}|[|•]/', $compact) ?: [];
        $candidate = trim((string) ($parts[0] ?? $compact));

        return $this->normalizeName($candidate);
    }

    private function normalizePhone(?string $phone, string $prefix = '254'): ?string
    {
        if (!$phone) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, '0')) {
            $normalized = $prefix . substr($normalized, 1);
        }

        return preg_match('/^\d{10,15}$/', $normalized) ? $normalized : null;
    }

    private function normalizeEmail(?string $email): ?string
    {
        if (!$email) {
            return null;
        }

        $normalized = strtolower(trim($email));
        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : null;
    }

    private function normalizeName(?string $name): ?string
    {
        if (!$name) {
            return null;
        }

        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, 255);
    }

    private function deriveLeadNameFromUrl(string $sourceUrl): string
    {
        $path = trim((string) parse_url($sourceUrl, PHP_URL_PATH), '/');
        $host = trim((string) parse_url($sourceUrl, PHP_URL_HOST));
        $candidate = $path !== '' ? basename($path) : $host;
        $candidate = str_replace(['-', '_'], ' ', $candidate);
        $candidate = preg_replace('/[^a-zA-Z0-9 ]+/', ' ', $candidate) ?? '';
        $candidate = trim(preg_replace('/\s+/', ' ', $candidate) ?? '');

        if ($candidate === '') {
            return $host !== '' ? 'Lead from ' . $host : '';
        }

        return mb_substr(ucwords($candidate), 0, 255);
    }

    private function cssSelectorConverter(): CssSelectorConverter
    {
        if ($this->cssSelectorConverter === null) {
            $this->cssSelectorConverter = new CssSelectorConverter();
        }

        return $this->cssSelectorConverter;
    }
}
