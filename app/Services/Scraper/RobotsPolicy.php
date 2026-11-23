<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\FetchRequest;
use App\Services\Scraper\Fetch\HttpFetchDriver;

/**
 * robots.txt evaluation for the declared CRM user agent, plus Sitemap and Crawl-delay
 * lines. Same matching rules as the original ScraperSourceService (longest match wins,
 * Allow beats Disallow on ties, * and $ wildcards). Parsed files are memoised per host.
 */
class RobotsPolicy
{
    /** @var array<string, array{status: int, body: string, error: ?string}> */
    private array $cache = [];

    public function __construct(private readonly HttpFetchDriver $http)
    {
    }

    /**
     * @return array{allowed: bool, policy: string, message: string, robots_url: ?string, status?: int, sitemaps: array<int, string>, crawl_delay: ?float}
     */
    public function evaluate(string $url): array
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? '');
        if ($host === '') {
            return ['allowed' => false, 'policy' => 'invalid_url', 'message' => 'Invalid address.', 'robots_url' => null, 'sitemaps' => [], 'crawl_delay' => null];
        }

        $target = ($parts['path'] ?? '') !== '' ? (string) $parts['path'] : '/';
        if (($parts['query'] ?? '') !== '') {
            $target .= '?' . $parts['query'];
        }

        $robotsUrl = sprintf('%s://%s%s/robots.txt', $scheme, $host, isset($parts['port']) ? ':' . $parts['port'] : '');
        $file = $this->cache[$robotsUrl] ??= $this->load($robotsUrl);

        if ($file['status'] === 0) {
            return ['allowed' => true, 'policy' => 'robots_unreachable', 'message' => 'robots.txt could not be fetched; treated as no rules.', 'robots_url' => $robotsUrl, 'status' => 0, 'sitemaps' => [], 'crawl_delay' => null];
        }
        if ($file['status'] >= 500) {
            return ['allowed' => false, 'policy' => 'robots_server_error', 'message' => 'robots.txt returned a server error; runs wait until it is reachable.', 'robots_url' => $robotsUrl, 'status' => $file['status'], 'sitemaps' => [], 'crawl_delay' => null];
        }
        if ($file['status'] >= 400 || $this->looksLikeHtml($file['body'])) {
            return ['allowed' => true, 'policy' => 'robots_unavailable', 'message' => 'No robots.txt rules published.', 'robots_url' => $robotsUrl, 'status' => $file['status'], 'sitemaps' => [], 'crawl_delay' => null];
        }

        $groups = $this->parseGroups($file['body']);
        $rules = $this->selectRules($groups, (string) config('scraper.user_agent'));
        $allowed = $this->isAllowed($rules, $target);

        return [
            'allowed' => $allowed,
            'policy' => 'robots_matched',
            'message' => $allowed ? 'robots.txt allows this page.' : 'robots.txt disallows this page for the CRM user agent.',
            'robots_url' => $robotsUrl,
            'status' => $file['status'],
            'path' => $target,
            'sitemaps' => $this->sitemaps($file['body']),
            'crawl_delay' => $this->crawlDelay($groups, (string) config('scraper.user_agent')),
        ];
    }

    private function load(string $robotsUrl): array
    {
        $result = $this->http->fetch(new FetchRequest($robotsUrl, ['Accept' => 'text/plain,*/*;q=0.1'], [], 12));

        return ['status' => $result->status, 'body' => $result->html, 'error' => $result->error];
    }

    private function looksLikeHtml(string $body): bool
    {
        return (bool) preg_match('/^\s*<(!doctype|html)/i', $body);
    }

    private function sitemaps(string $body): array
    {
        preg_match_all('/^\s*sitemap:\s*(\S+)/im', $body, $m);

        return array_values(array_unique(array_slice($m[1] ?? [], 0, 10)));
    }

    private function crawlDelay(array $groups, string $userAgent): ?float
    {
        foreach ($groups as $group) {
            if (in_array('*', $group['agents'], true) || $this->agentMatches($group['agents'], $userAgent)) {
                if (isset($group['delay'])) {
                    return (float) $group['delay'];
                }
            }
        }

        return null;
    }

    private function agentMatches(array $agents, string $userAgent): bool
    {
        $token = strtolower($userAgent);
        foreach ($agents as $agent) {
            if ($agent !== '*' && $agent !== '' && str_contains($token, $agent)) {
                return true;
            }
        }

        return false;
    }

    private function isAllowed(array $rules, string $target): bool
    {
        $matched = [];
        foreach ($rules as $rule) {
            $path = (string) ($rule['path'] ?? '');
            if ($path === '' || !$this->patternMatches($path, $target)) {
                continue;
            }
            $matched[] = ['type' => $rule['type'], 'length' => strlen(rtrim($path, '$'))];
        }
        if ($matched === []) {
            return true;
        }
        usort($matched, fn ($a, $b) => $b['length'] <=> $a['length']);
        foreach ($matched as $rule) {
            if ($rule['length'] !== $matched[0]['length']) {
                break;
            }
            if ($rule['type'] === 'allow') {
                return true;
            }
        }

        return false;
    }

    private function parseGroups(string $content): array
    {
        $groups = [];
        $current = ['agents' => [], 'rules' => []];
        foreach (preg_split('/\R+/', $content) ?: [] as $line) {
            $clean = trim((string) preg_replace('/#.*/', '', $line));
            if ($clean === '') {
                continue;
            }
            [$field, $value] = array_pad(explode(':', $clean, 2), 2, '');
            $field = strtolower(trim($field));
            $value = trim($value);
            if ($field === 'user-agent') {
                if ($current['rules'] !== [] || isset($current['delay'])) {
                    $groups[] = $current;
                    $current = ['agents' => [], 'rules' => []];
                }
                $current['agents'][] = strtolower($value);
            } elseif (in_array($field, ['allow', 'disallow'], true) && $current['agents'] !== []) {
                $current['rules'][] = ['type' => $field, 'path' => $value];
            } elseif ($field === 'crawl-delay' && $current['agents'] !== [] && is_numeric($value)) {
                $current['delay'] = (float) $value;
            }
        }
        if ($current['agents'] !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function selectRules(array $groups, string $userAgent): array
    {
        $token = strtolower(trim($userAgent));
        $best = -1;
        $selected = [];
        foreach ($groups as $group) {
            $specificity = -1;
            foreach ($group['agents'] as $agent) {
                if ($agent === '*') {
                    $specificity = max($specificity, 0);
                } elseif ($agent !== '' && str_contains($token, $agent)) {
                    $specificity = max($specificity, strlen($agent));
                }
            }
            if ($specificity < 0) {
                continue;
            }
            if ($specificity > $best) {
                $best = $specificity;
                $selected = $group['rules'];
            } elseif ($specificity === $best) {
                $selected = array_merge($selected, $group['rules']);
            }
        }

        return $selected;
    }

    private function patternMatches(string $pattern, string $target): bool
    {
        $anchored = str_ends_with($pattern, '$');
        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }
        if ($pattern === '') {
            return true;
        }
        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return (bool) preg_match($anchored ? '#^' . $regex . '$#' : '#^' . $regex . '#', $target);
    }
}
