<?php

namespace App\Services\Scraper;

use App\Models\ScraperSource;
use App\Services\Scraper\Fetch\FetchDriverRegistry;
use App\Services\Scraper\Fetch\FetchRequest;
use App\Services\Scraper\Fetch\UrlSafety;

/**
 * One-page reconnaissance that powers the "at a glance" view: health verdict,
 * technology, blockers with overrides, robots/sitemap, template match, pagination
 * and sample rows. Makes at most ~6 requests (page, robots, up to 4 sitemap probes
 * only when the page is blocked or empty).
 */
class SiteProbeService
{
    public function __construct(
        private readonly FetchDriverRegistry $drivers,
        private readonly UrlSafety $urlSafety,
        private readonly RobotsPolicy $robots,
        private readonly TechFingerprinter $fingerprinter,
        private readonly BlockerDetector $blockerDetector,
        private readonly TemplateLibrary $templates,
        private readonly AutoSelectorDetector $autoDetector,
        private readonly ListingExtractor $extractor,
        private readonly Paginator $paginator,
        private readonly SitemapReader $sitemaps,
        private readonly OverrideCatalog $overrides,
    ) {}

    /**
     * @param  array{driver?: string, template_key?: ?string, parser_rules?: array, crawl_settings?: array, source_id?: ?int}  $options
     */
    public function probe(string $url, string $phonePrefix, array $options = []): array
    {
        $startedAt = microtime(true);
        $url = trim($url);
        $driverKey = (string) ($options['driver'] ?? 'http');
        $crawl = CrawlSettings::normalize($options['crawl_settings'] ?? []);

        $unsafe = $this->urlSafety->unsafeReason($url);
        if ($unsafe !== null) {
            return $this->finish([
                'verdict' => 'error',
                'url' => $url,
                'blockers' => [[
                    'key' => 'unsafe_url', 'severity' => 'blocking', 'title' => 'Address not allowed',
                    'detail' => $unsafe, 'overrides' => [],
                ]],
            ], $startedAt);
        }

        $robots = $this->robots->evaluate($url);
        $result = $this->drivers->get($driverKey)->fetch(new FetchRequest(
            $url,
            CrawlSettings::headerMap($crawl),
            $crawl['cookies'],
            (int) config('scraper.http.timeout', 25),
            null,
            0,
            null,
            $options['source_id'] ?? null,
        ));

        $doc = $result->html !== '' ? new HtmlDocument($result->html, $result->finalUrl) : null;
        $tech = $this->fingerprinter->detect($result, $doc);

        $match = null;
        $detected = null;
        $templateKey = $options['template_key'] ?? null;
        if ($doc) {
            $match = $this->templates->match($doc, $tech);
            if (! $match || $match['rows'] < 3) {
                $detected = $this->autoDetector->detect($doc, $result->finalUrl);
                // A stale template that matches one or two cards loses to a clear auto-detected layout.
                if ($match && $detected && ! $templateKey && $detected['rows'] > $match['rows'] + 2) {
                    $match = null;
                } elseif ($match) {
                    $detected = null;
                }
            }
        }
        $chosenTemplate = $templateKey ?: ($match['key'] ?? null);
        $overridesRules = (array) ($options['parser_rules'] ?? []);
        $baseRules = $chosenTemplate ? $this->templates->effectiveRules($chosenTemplate, []) : (array) ($detected['rules'] ?? []);
        $rules = array_merge($baseRules, array_filter($overridesRules, fn ($v) => trim((string) $v) !== ''));

        $extraction = ['rows' => [], 'stats' => ['rows' => 0, 'phones' => 0, 'whatsapp' => 0, 'links' => 0, 'names' => 0], 'errors' => []];
        $pagination = ['next_url' => null, 'estimated_pages' => null, 'pattern' => null];
        if ($doc && $result->status >= 200 && $result->status < 400) {
            $extraction = $this->extractor->extract($doc, $rules, $result->finalUrl, $phonePrefix);
            $paginationRules = $this->templates->pagination($chosenTemplate, $crawl);
            if (empty($paginationRules['pattern'])) {
                $paginationRules['pattern'] = $this->paginator->detectPattern($doc);
            }
            $pagination = [
                'next_url' => $this->paginator->next($doc, $paginationRules, $result->finalUrl, $url, 1),
                'estimated_pages' => $this->paginator->estimateTotalPages($doc),
                'pattern' => $paginationRules['pattern'] ?? null,
                'next_selector' => $paginationRules['next_selector'] ?? null,
            ];
        }

        $stats = $extraction['stats'];
        $preliminary = $this->blockerDetector->detect($result, $doc, $robots, $stats, $tech);
        $sitemap = ['found' => false, 'sitemap_url' => null, 'declared' => $robots['sitemaps'] ?? []];
        if (BlockerDetector::verdict($preliminary, $stats['rows']) !== 'ready' && $driverKey === 'http') {
            $sitemap = $this->sitemaps->locate($url, $robots['sitemaps'] ?? []) + ['declared' => $robots['sitemaps'] ?? []];
        }
        $blockers = $sitemap['found']
            ? $this->blockerDetector->detect($result, $doc, $robots, $stats, $tech, true)
            : $preliminary;

        $verdict = BlockerDetector::verdict($blockers, $stats['rows']);
        $available = [
            'render_worker' => $this->drivers->renderWorkerAvailable(),
            'sitemap_route' => (bool) $sitemap['found'],
        ];
        foreach ($blockers as &$blocker) {
            $blocker['override_options'] = $this->overrides->describe($blocker['overrides'], $available);
        }
        unset($blocker);

        return $this->finish([
            'verdict' => $verdict,
            'headline' => $this->headline($verdict, $stats, $blockers, $pagination),
            'url' => $url,
            'driver' => $driverKey,
            'http' => $result->toSummary(),
            'title' => $doc?->title() ?: null,
            'tech' => $tech,
            'blockers' => $blockers,
            'robots' => [
                'allowed' => (bool) ($robots['allowed'] ?? true),
                'policy' => $robots['policy'] ?? null,
                'message' => $robots['message'] ?? null,
                'crawl_delay' => $robots['crawl_delay'] ?? null,
                'robots_url' => $robots['robots_url'] ?? null,
            ],
            'sitemap' => $sitemap,
            'template' => $chosenTemplate ? [
                'key' => $chosenTemplate,
                'label' => (string) ($this->templates->get($chosenTemplate)['label'] ?? $chosenTemplate),
                'confidence' => (int) ($match['confidence'] ?? 0),
                'matched_by' => $match['matched_by'] ?? [],
                'forced' => (bool) $templateKey,
            ] : null,
            'detected_rules' => $detected ? $detected + ['source' => 'auto'] : null,
            'effective_rules' => $rules,
            'pagination' => $pagination,
            'counts' => $stats,
            'locations' => LocationFilter::facets(array_column($extraction['rows'], 'city')),
            'with_location' => count(array_filter($extraction['rows'], fn ($r) => ($r['city'] ?? null) !== null)),
            'extraction_errors' => $extraction['errors'],
            'sample' => array_slice($extraction['rows'], 0, 8),
        ], $startedAt);
    }

    /**
     * Probe a saved source using its own configuration and persist the result.
     */
    public function probeSource(ScraperSource $source): array
    {
        $source->loadMissing('platform');
        $profile = $this->probe((string) $source->source_url, $this->phonePrefix($source), [
            'driver' => (string) ($source->fetch_driver ?: 'http'),
            'template_key' => $source->template_key,
            'parser_rules' => (array) ($source->parser_rules ?? []),
            'crawl_settings' => (array) ($source->crawl_settings ?? []),
            'source_id' => (int) $source->id,
        ]);

        $source->forceFill([
            'site_profile' => $profile,
            'health_status' => $profile['verdict'],
            'last_probed_at' => now(),
        ])->save();

        return $profile;
    }

    public function phonePrefix(ScraperSource $source): string
    {
        $prefix = preg_replace('/\D/', '', (string) ($source->platform?->phone_prefix ?? ''));

        return $prefix !== '' ? $prefix : '254';
    }

    private function headline(string $verdict, array $stats, array $blockers, array $pagination): string
    {
        if ($verdict === 'blocked' || $verdict === 'error') {
            return $blockers[0]['title'] ?? 'Blocked';
        }
        $parts = [sprintf('%d profile%s on this page', $stats['rows'], $stats['rows'] === 1 ? '' : 's')];
        if ($stats['rows'] > 0) {
            $parts[] = sprintf('%d with phone', $stats['phones']);
        }
        if (! empty($pagination['estimated_pages']) && $pagination['estimated_pages'] > 1) {
            $parts[] = sprintf('~%d pages', $pagination['estimated_pages']);
        } elseif (! empty($pagination['next_url'])) {
            $parts[] = 'more pages';
        }

        return implode(' · ', $parts);
    }

    private function finish(array $profile, float $startedAt): array
    {
        $profile += [
            'verdict' => 'error', 'headline' => null, 'url' => null, 'driver' => 'http', 'http' => null,
            'title' => null, 'tech' => [], 'blockers' => [], 'robots' => null, 'sitemap' => null,
            'template' => null, 'detected_rules' => null, 'effective_rules' => [], 'pagination' => null,
            'counts' => ['rows' => 0, 'phones' => 0, 'whatsapp' => 0, 'links' => 0, 'names' => 0],
            'extraction_errors' => [], 'sample' => [], 'locations' => [], 'with_location' => 0,
        ];
        if ($profile['headline'] === null && $profile['blockers'] !== []) {
            $profile['headline'] = $profile['blockers'][0]['title'];
        }
        array_walk_recursive($profile, static function (&$value): void {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $value = mb_scrub($value, 'UTF-8');
            }
        });
        $profile['probed_at'] = now()->toIso8601String();
        $profile['probe_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        return $profile;
    }
}
