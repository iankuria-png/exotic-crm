<?php

namespace App\Services\Scraper;

use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\User;
use App\Services\Scraper\Fetch\FetchDriverRegistry;
use App\Services\Scraper\Fetch\FetchRequest;
use App\Services\Scraper\Fetch\FetchResult;
use App\Services\Scraper\Fetch\UrlTools;

/**
 * Executes a scraper run: listing pages (with pagination) or sitemap profile URLs,
 * optional profile follow-up, politeness delay, budgets, cancellation and live progress.
 * Dry runs store candidates on the run for review; import runs also create leads.
 */
class ScrapeRunner
{
    private const EVENT_LIMIT = 60;

    /** @var callable(int): void */
    private $sleeper;

    private array $events = [];

    public function __construct(
        private readonly FetchDriverRegistry $drivers,
        private readonly RobotsPolicy $robots,
        private readonly TemplateLibrary $templates,
        private readonly ListingExtractor $extractor,
        private readonly Paginator $paginator,
        private readonly SitemapReader $sitemaps,
        private readonly LeadImporter $importer,
        private readonly TechFingerprinter $fingerprinter,
        private readonly BlockerDetector $blockerDetector,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $ms): void {
            if ($ms > 0) {
                usleep($ms * 1000);
            }
        };
    }

    public function run(ScraperRun $run): ScraperRun
    {
        $run->loadMissing(['source.platform', 'initiatedBy']);
        $source = $run->source;
        $actor = $run->initiatedBy;
        $this->events = [];
        $started = microtime(true);
        $budget = (int) config('scraper.limits.run_budget_seconds', 1500);

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        if (!$source || !$actor) {
            return $this->finish($run, $source, 'error', 'The site or the person who started the run no longer exists.', []);
        }
        if (!$source->is_active) {
            return $this->finish($run, $source, 'blocked', 'This site is paused. Activate it to run.', []);
        }
        if (!$source->compliance_ack_robots || !$source->compliance_ack_tos) {
            return $this->finish($run, $source, 'blocked', 'Confirm the robots.txt and terms checks for this site first.', []);
        }

        $settings = CrawlSettings::normalize((array) ($source->crawl_settings ?? []));
        $driverKey = (string) ($source->fetch_driver ?: 'http');
        $driver = $this->drivers->get($driverKey);
        if (!$driver->isAvailable()) {
            return $this->finish($run, $source, 'blocked', 'The "' . $driverKey . '" fetch method is not available.', []);
        }
        $prefix = preg_replace('/\D/', '', (string) ($source->platform?->phone_prefix ?? '')) ?: '254';
        $rules = $this->rulesFor($source);
        $paginationRules = $this->templates->pagination($source->template_key, $settings);
        $profileRules = $this->templates->profileRules($source->template_key, $settings);
        $maxCandidates = (int) config('scraper.limits.max_candidates', 1000);
        $crawlDelay = $driverKey === 'capture' ? 0.0 : (float) ($this->robots->evaluate($source->source_url)['crawl_delay'] ?? 0);
        $delay = max((int) $settings['delay_ms'], (int) round(min($crawlDelay, 15.0) * 1000));

        $request = fn (string $url) => new FetchRequest($url, CrawlSettings::headerMap($settings), $settings['cookies'], (int) config('scraper.http.timeout', 25), null, 0, null, (int) $source->id);

        $candidates = [];
        $pagesFetched = 0;
        $errors = 0;
        $stopReason = null;

        $progress = function (string $phase, array $extra = []) use ($run, &$candidates, &$pagesFetched, $settings) {
            $run->forceFill([
                'pages_fetched' => $pagesFetched,
                'discovered_count' => count($candidates),
                'progress' => array_merge([
                    'phase' => $phase,
                    'max_pages' => $settings['max_pages'],
                    'pages_fetched' => $pagesFetched,
                    'candidates' => count($candidates),
                    'with_phone' => count(array_filter($candidates, fn ($c) => $c['phone'] !== null)),
                    'events' => $this->events,
                    'updated_at' => now()->toIso8601String(),
                ], $extra),
            ])->save();
        };

        $add = function (array $row, string $origin) use (&$candidates, $source, $maxCandidates): bool {
            $key = $this->importer->key($source, $row);
            if ($key === '' || !$this->importer->importable($row)) {
                return false;
            }
            if (isset($candidates[$key])) {
                // Merge gaps (e.g. listing row without phone + profile with phone).
                foreach ($row as $field => $value) {
                    if ($value !== null && ($candidates[$key][$field] ?? null) === null) {
                        $candidates[$key][$field] = $value;
                    }
                }

                return false;
            }
            if (count($candidates) >= $maxCandidates) {
                return false;
            }
            $candidates[$key] = $row + ['found_on' => $origin];

            return true;
        };

        // ---- Phase 1: listing pages or sitemap URLs ----
        $profileQueue = [];
        if ($settings['route'] === 'sitemap') {
            $located = $this->sitemaps->locate($source->source_url, $this->robots->evaluate($source->source_url)['sitemaps'] ?? []);
            if (!$located['found']) {
                return $this->finish($run, $source, 'blocked', 'No sitemap was found for this site.', []);
            }
            $this->event('info', 'Reading sitemap ' . $located['sitemap_url']);
            $fromSitemap = $this->sitemaps->profileUrls((string) $located['sitemap_url'], $settings['profile_url_pattern'], max(1, $settings['max_profiles'] ?: 50));
            foreach ($fromSitemap['urls'] as $url) {
                $profileQueue[$url] = null;
            }
            $this->event('info', sprintf('Sitemap gave %d profile pages.', count($profileQueue)));
            $progress('profiles', ['profiles_total' => count($profileQueue), 'profiles_done' => 0]);
        } else {
            $url = $source->source_url;
            $visited = [];
            for ($page = 1; $page <= $settings['max_pages']; $page++) {
                if ($this->cancelled($run)) {
                    $stopReason = 'cancelled';
                    break;
                }
                if ((microtime(true) - $started) > $budget) {
                    $stopReason = 'time_budget';
                    $this->event('warning', 'Stopped: run time budget reached.');
                    break;
                }
                if (isset($visited[$url])) {
                    $this->event('info', 'Pagination looped back; stopping.');
                    break;
                }
                $visited[$url] = true;

                $robots = $driverKey === 'capture' ? ['allowed' => true] : $this->robots->evaluate($url);
                if (!($robots['allowed'] ?? true)) {
                    $this->event('warning', 'robots.txt disallows ' . $url . '; stopping.');
                    $stopReason = 'robots';
                    break;
                }

                if ($page > 1) {
                    ($this->sleeper)($delay);
                }
                $result = $driver->fetch($request($url));
                $pagesFetched++;

                if (!$result->ok()) {
                    $errors++;
                    $block = $this->explain($result);
                    $this->event($page === 1 ? 'error' : 'warning', sprintf('Page %d: %s', $page, $block));
                    if ($page === 1) {
                        return $this->finish($run, $source, 'blocked', $block, $candidates, $pagesFetched, $errors);
                    }
                    $stopReason = 'page_error';
                    break;
                }

                $doc = new HtmlDocument($result->html, $result->finalUrl);
                $extraction = $this->extractor->extract($doc, $rules, $result->finalUrl, $prefix);
                $new = 0;
                foreach ($extraction['rows'] as $row) {
                    if ($add($row, 'page ' . $page)) {
                        $new++;
                    }
                    if ($settings['follow_profiles'] && $row['profile_url'] && (!$settings['follow_only_missing_phone'] || $row['phone'] === null)) {
                        $profileQueue[$row['profile_url']] ??= $this->importer->key($source, $row);
                    }
                }
                $this->event('info', sprintf('Page %d: %d cards, %d new, %d with phone.', $page, $extraction['stats']['rows'], $new, $extraction['stats']['phones']));
                $progress('listing', ['current_url' => $result->finalUrl, 'page' => $page, 'estimated_pages' => $this->paginator->estimateTotalPages($doc)]);

                if ($new === 0 && $settings['stop_when_no_new'] && $page > 1) {
                    $this->event('info', 'No new profiles on this page; stopping.');
                    $stopReason = 'no_new';
                    break;
                }
                if ($extraction['stats']['rows'] === 0) {
                    $stopReason = 'empty_page';
                    break;
                }

                $next = $this->paginator->next($doc, $paginationRules, $result->finalUrl, $source->source_url, $page);
                if (!$next) {
                    $stopReason = 'last_page';
                    break;
                }
                $url = $next;
            }
            if ($stopReason === null && $pagesFetched >= $settings['max_pages']) {
                $stopReason = 'page_limit';
            }
        }

        // ---- Phase 2: profile follow-up ----
        $maxProfiles = $settings['route'] === 'sitemap' ? max(1, $settings['max_profiles'] ?: 50) : $settings['max_profiles'];
        if ($profileQueue !== [] && $maxProfiles > 0 && $stopReason !== 'cancelled') {
            $queue = array_slice($profileQueue, 0, $maxProfiles, true);
            $done = 0;
            $this->event('info', sprintf('Opening %d profile page(s).', count($queue)));
            foreach ($queue as $profileUrl => $candidateKey) {
                if ($this->cancelled($run)) {
                    $stopReason = 'cancelled';
                    break;
                }
                if ((microtime(true) - $started) > $budget) {
                    $stopReason = 'time_budget';
                    break;
                }
                if ($driverKey !== 'capture' && !($this->robots->evaluate($profileUrl)['allowed'] ?? true)) {
                    continue;
                }
                ($this->sleeper)($delay);
                $result = $driver->fetch($request($profileUrl));
                $pagesFetched++;
                $done++;
                if (!$result->ok()) {
                    $errors++;
                    $this->event('warning', 'Profile ' . $this->shortUrl($profileUrl) . ': ' . $this->explain($result));
                    continue;
                }
                $row = $this->extractor->extractProfile(new HtmlDocument($result->html, $result->finalUrl), $profileRules, $profileUrl, $prefix);
                if ($candidateKey !== null && isset($candidates[$candidateKey])) {
                    foreach ($row as $field => $value) {
                        if ($value !== null && ($candidates[$candidateKey][$field] ?? null) === null) {
                            $candidates[$candidateKey][$field] = $value;
                        }
                    }
                    // Re-key when the profile supplied the phone used for dedupe.
                    $newKey = $this->importer->key($source, $candidates[$candidateKey]);
                    if ($newKey !== $candidateKey && $newKey !== '' && !isset($candidates[$newKey])) {
                        $candidates[$newKey] = $candidates[$candidateKey];
                        unset($candidates[$candidateKey]);
                    }
                } else {
                    $add($row, 'profile');
                }
                if ($done % 5 === 0) {
                    $progress('profiles', ['profiles_total' => count($queue), 'profiles_done' => $done, 'current_url' => $profileUrl]);
                }
            }
            $progress('profiles', ['profiles_total' => count($queue), 'profiles_done' => $done]);
        }

        // ---- Phase 3: dedupe against CRM, import if requested ----
        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        foreach ($candidates as $key => &$candidate) {
            $exists = $this->importer->exists($source, $candidate);
            if ($exists) {
                $duplicates++;
                $candidate['status'] = 'existing';
                continue;
            }
            if ($run->mode !== 'import') {
                $candidate['status'] = 'new';
                continue;
            }
            try {
                $lead = $this->importer->create($source, $candidate, $actor, (int) $run->id);
                $candidate['status'] = 'created';
                $candidate['lead_id'] = (int) $lead->id;
                $created++;
            } catch (\Throwable $exception) {
                $skipped++;
                $errors++;
                $candidate['status'] = 'error';
                $candidate['error'] = mb_substr($exception->getMessage(), 0, 200);
            }
        }
        unset($candidate);

        $status = $stopReason === 'cancelled' ? 'cancelled' : ($errors > 0 && $candidates !== [] ? 'partial' : ($candidates === [] ? 'empty' : 'success'));
        $message = match ($status) {
            'cancelled' => 'Run cancelled.',
            'empty' => 'The run finished without finding importable profiles.',
            'partial' => 'Finished with some page errors.',
            default => 'Finished.',
        };

        return $this->finish($run, $source, $status, $message, $candidates, $pagesFetched, $errors, [
            'created' => $created,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'stop_reason' => $stopReason,
        ]);
    }

    public function rulesFor(ScraperSource $source): array
    {
        $overrides = (array) ($source->parser_rules ?? []);
        if ($source->template_key) {
            return $this->templates->effectiveRules($source->template_key, $overrides);
        }
        $detected = (array) ($source->site_profile['detected_rules']['rules'] ?? []);

        return array_merge($detected, array_filter($overrides, fn ($v) => trim((string) $v) !== ''));
    }

    private function finish(ScraperRun $run, ?ScraperSource $source, string $status, string $message, array $candidates, int $pagesFetched = 0, int $errors = 0, array $extra = []): ScraperRun
    {
        $level = in_array($status, ['blocked', 'error'], true) ? 'error' : 'info';
        $this->event($level, $message);
        $list = array_values($candidates);
        $summary = [
            'status' => $status,
            'message' => $message,
            'discovered' => count($list),
            'with_phone' => count(array_filter($list, fn ($c) => ($c['phone'] ?? null) !== null)),
            'new' => count(array_filter($list, fn ($c) => ($c['status'] ?? null) === 'new')),
            'created' => (int) ($extra['created'] ?? 0),
            'duplicates' => (int) ($extra['duplicates'] ?? 0),
            'skipped' => (int) ($extra['skipped'] ?? 0),
            'pages_fetched' => $pagesFetched,
            'errors' => $errors,
            'stop_reason' => $extra['stop_reason'] ?? null,
            'dry_run' => $run->mode !== 'import',
        ];

        $run->forceFill([
            'status' => $status,
            'reason' => mb_substr($message, 0, 500),
            'discovered_count' => $summary['discovered'],
            'created_count' => $summary['created'],
            'duplicate_count' => $summary['duplicates'],
            'skipped_count' => $summary['skipped'],
            'error_count' => $errors,
            'pages_fetched' => $pagesFetched,
            'preview' => $list,
            'result' => $summary,
            'progress' => array_merge((array) ($run->progress ?? []), ['phase' => 'done', 'events' => $this->events, 'pages_fetched' => $pagesFetched, 'candidates' => count($list)]),
            'completed_at' => now(),
        ])->save();

        if ($source) {
            $source->forceFill([
                'last_run_at' => now(),
                'last_run_status' => $status,
                'last_run_summary' => $summary,
            ])->save();
        }

        return $run;
    }

    private function explain(FetchResult $result): string
    {
        if ($result->error !== null) {
            return $result->error;
        }
        $doc = $result->html !== '' ? new HtmlDocument($result->html, $result->finalUrl) : null;
        $blockers = $this->blockerDetector->detect($result, $doc, null, ['rows' => 1, 'phones' => 1, 'links' => 1], $this->fingerprinter->detect($result, $doc));

        return $blockers[0]['title'] ?? ('HTTP ' . $result->status);
    }

    private function cancelled(ScraperRun $run): bool
    {
        return ScraperRun::query()->whereKey($run->id)->whereNotNull('cancel_requested_at')->exists();
    }

    private function event(string $level, string $message): void
    {
        $this->events[] = ['at' => now()->toIso8601String(), 'level' => $level, 'message' => mb_substr($message, 0, 300)];
        if (count($this->events) > self::EVENT_LIMIT) {
            $this->events = array_slice($this->events, -self::EVENT_LIMIT);
        }
    }

    private function shortUrl(string $url): string
    {
        return UrlTools::host($url) . (string) parse_url($url, PHP_URL_PATH);
    }
}
