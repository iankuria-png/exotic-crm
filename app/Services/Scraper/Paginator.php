<?php

namespace App\Services\Scraper;

use App\Services\Scraper\Fetch\UrlTools;

/**
 * Finds the next listing page: explicit next selector, then <link rel=next>, then a
 * {n} URL pattern. Also estimates total pages from numbered links.
 */
class Paginator
{
    public function next(HtmlDocument $doc, array $pagination, string $currentUrl, string $startUrl, int $currentPage): ?string
    {
        $selector = trim((string) ($pagination['next_selector'] ?? ''));
        foreach ([$selector, 'link[rel="next"]', 'a[rel="next"]'] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            foreach ($doc->select($candidate) as $node) {
                $url = UrlTools::resolve($currentUrl, (string) $node->getAttribute('href'));
                if ($url && $url !== $currentUrl && UrlTools::sameSite($url, $startUrl)) {
                    return $url;
                }
            }
        }

        $pattern = trim((string) ($pagination['pattern'] ?? ''));
        if ($pattern !== '' && $this->numberedLinksExist($doc, $currentPage + 1)) {
            return $this->fromPattern($pattern, $startUrl, $currentPage + 1);
        }

        return null;
    }

    public function fromPattern(string $pattern, string $startUrl, int $page): string
    {
        $base = rtrim(preg_replace('#/page/\d+/?$#', '/', $startUrl) ?? $startUrl, '/') . '/';
        $url = strtok($startUrl, '#') ?: $startUrl;
        $joiner = str_contains($url, '?') ? '&' : '?';
        $out = str_replace(['{base}', '{url}', '{n}'], [$base, $url, (string) $page], $pattern);

        return str_replace($url . '?', $url . $joiner, $out);
    }

    public function estimateTotalPages(HtmlDocument $doc): ?int
    {
        $max = null;
        foreach ($doc->select('a[href]') as $a) {
            $href = (string) $a->getAttribute('href');
            if (preg_match('#/page/(\d+)/?(?:$|\?)|[?&](?:page|pg|p)=(\d+)#', $href, $m)) {
                $n = (int) ($m[1] !== '' ? $m[1] : ($m[2] ?? 0));
                if ($n > 0 && $n < 10000) {
                    $max = max($max ?? 1, $n);
                }
            }
        }

        return $max;
    }

    public function detectPattern(HtmlDocument $doc): ?string
    {
        foreach ($doc->select('a[href]') as $a) {
            $href = (string) $a->getAttribute('href');
            if (preg_match('#/page/\d+/?#', $href)) {
                return '{base}page/{n}/';
            }
            if (preg_match('#[?&]page=\d+#', $href)) {
                return '{url}?page={n}';
            }
        }

        return null;
    }

    private function numberedLinksExist(HtmlDocument $doc, int $page): bool
    {
        foreach ($doc->select('a[href]') as $a) {
            if (preg_match('#/page/' . $page . '/?(?:$|\?)|[?&](?:page|pg|p)=' . $page . '(?:&|$)#', (string) $a->getAttribute('href'))) {
                return true;
            }
        }

        return false;
    }
}
