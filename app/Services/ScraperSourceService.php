<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Platform;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\DomParserTrait;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Symfony\Component\CssSelector\CssSelectorConverter;

class ScraperSourceService
{
    use DomParserTrait {
        fetchHtml as private domFetchHtml;
        queryCss as private domQueryCss;
        nodeText as private domNodeText;
        extractPhoneFromText as private domExtractPhoneFromText;
        normalizePhone as private domNormalizePhone;
    }

    public const PARSER_PROFILES = ['contact_cards', 'profile_links'];
    public const FETCH_SCHEDULES = ['manual_only', 'daily', 'weekly'];
    public const DEDUPE_MODES = ['phone_or_email', 'phone_only', 'email_only', 'source_url'];

    private const COMPETITOR_PRESETS = [
        [
            'key' => 'massagerepublic_nairobi',
            'name' => 'MassageRepublic Nairobi',
            'host' => 'massagerepublic.com',
            'status' => 'supported',
            'source_url' => 'https://massagerepublic.com/female-escorts-in-nairobi',
            'parser_profile' => 'profile_links',
            'dedupe_mode' => 'source_url',
            'parser_rules' => [
                'link_selector' => '.listing-li h2 a.nostyle-link',
            ],
            'traffic_estimate_monthly' => 6300000,
            'traffic_band' => 'high',
            'notes' => 'High-traffic listing source; extract profile URLs and follow-up scrape at profile level if needed.',
        ],
        [
            'key' => 'bedescorts_nairobi',
            'name' => 'BedEscorts Nairobi',
            'host' => 'bedescorts.com',
            'status' => 'supported',
            'source_url' => 'https://www.bedescorts.com/escorts/kenya/nairobi/',
            'parser_profile' => 'contact_cards',
            'dedupe_mode' => 'phone_or_email',
            'parser_rules' => [
                'row_selector' => '.girl',
                'name_selector' => '.girl-name',
                'phone_selector' => 'a.inicslab-phone-link',
                'link_selector' => '.thumbwrapper > a',
            ],
            'traffic_estimate_monthly' => 46800,
            'traffic_band' => 'medium',
            'notes' => 'Card rows expose direct phone link; good fit for contact-card parser.',
        ],
        [
            'key' => 'kenyaraha_nairobi',
            'name' => 'KenyaRaha Nairobi',
            'host' => 'kenyaraha.co.ke',
            'status' => 'supported',
            'source_url' => 'https://kenyaraha.co.ke/',
            'parser_profile' => 'contact_cards',
            'dedupe_mode' => 'phone_or_email',
            'parser_rules' => [
                'row_selector' => 'li.escort-item-one',
                'name_selector' => '.profile-name h5 a',
                'phone_selector' => 'a[href^="tel:"]',
                'link_selector' => '.btn-sec a',
            ],
            'traffic_estimate_monthly' => 40100,
            'traffic_band' => 'medium',
            'notes' => 'Listing cards include tel links and profile links.',
        ],
        [
            'key' => 'mombasahot_nairobi',
            'name' => 'MombasaHot Nairobi',
            'host' => 'mombasahot.com',
            'status' => 'supported',
            'source_url' => 'https://mombasahot.com/public/escorts-from/kenya/nairobi',
            'parser_profile' => 'contact_cards',
            'dedupe_mode' => 'phone_or_email',
            'parser_rules' => [
                'row_selector' => 'li.escort-item-one',
                'name_selector' => '.profile-name h5 a',
                'phone_selector' => 'a[href^="tel:"]',
                'link_selector' => '.profile-name h5 a',
            ],
            'traffic_estimate_monthly' => 22000,
            'traffic_band' => 'medium',
            'notes' => 'Template is structurally aligned with KenyaRaha selectors.',
        ],
        [
            'key' => 'nairobihot_nairobi',
            'name' => 'NairobiHot Nairobi',
            'host' => 'nairobihot.com',
            'status' => 'blocked',
            'source_url' => 'https://nairobihot.com/public/escorts-from/kenya/nairobi',
            'parser_profile' => 'contact_cards',
            'dedupe_mode' => 'phone_or_email',
            'parser_rules' => [
                'row_selector' => 'li.escort-item-one',
                'name_selector' => '.profile-name h5 a',
                'phone_selector' => 'a[href^="tel:"]',
                'link_selector' => '.profile-name h5 a',
            ],
            'traffic_estimate_monthly' => 595000,
            'traffic_band' => 'high',
            'blocked_reason' => 'Cloudflare anti-bot challenge blocks automated scraping in the current environment.',
            'notes' => 'Keep listed for visibility, but disable direct run until challenge-solving support is added.',
        ],
    ];

    private const PREVIEW_SAMPLE_LIMIT = 20;
    private const QUALITY_HIGH_THRESHOLD = 80;
    private const QUALITY_MEDIUM_THRESHOLD = 60;
    private const SCRAPER_USER_AGENT = 'ExoticCRMLeadBot/1.0 (+https://exoticcrm.local)';

    private ?CssSelectorConverter $cssSelectorConverter = null;

    public function __construct(
        private readonly LeadAssignmentService $leadAssignmentService
    ) {
    }

    public function competitorPresets(): array
    {
        return array_map(function (array $preset): array {
            $parserProfile = in_array($preset['parser_profile'] ?? '', self::PARSER_PROFILES, true)
                ? (string) $preset['parser_profile']
                : 'contact_cards';
            $dedupeMode = in_array($preset['dedupe_mode'] ?? '', self::DEDUPE_MODES, true)
                ? (string) $preset['dedupe_mode']
                : 'phone_or_email';

            return [
                'key' => (string) ($preset['key'] ?? ''),
                'name' => (string) ($preset['name'] ?? ''),
                'host' => (string) ($preset['host'] ?? ''),
                'status' => (string) ($preset['status'] ?? 'supported'),
                'source_url' => (string) ($preset['source_url'] ?? ''),
                'traffic_estimate_monthly' => (int) ($preset['traffic_estimate_monthly'] ?? 0),
                'traffic_band' => (string) ($preset['traffic_band'] ?? 'unknown'),
                'notes' => (string) ($preset['notes'] ?? ''),
                'blocked_reason' => $preset['blocked_reason'] ?? null,
                'configuration' => [
                    'parser_profile' => $parserProfile,
                    'dedupe_mode' => $dedupeMode,
                    'fetch_schedule' => 'manual_only',
                    'is_active' => ($preset['status'] ?? 'supported') === 'supported',
                    'compliance_ack_robots' => true,
                    'compliance_ack_tos' => true,
                    'parser_rules' => $this->normalizeParserRules($preset['parser_rules'] ?? []),
                ],
            ];
        }, self::COMPETITOR_PRESETS);
    }

    public function competitorPresetByKey(?string $key): ?array
    {
        $normalized = strtolower(trim((string) $key));
        if ($normalized === '') {
            return null;
        }

        foreach ($this->competitorPresets() as $preset) {
            if (strtolower((string) ($preset['key'] ?? '')) === $normalized) {
                return $preset;
            }
        }

        return null;
    }

    public function normalizeParserRules(array $rules): array
    {
        $normalized = [];
        foreach (['row_selector', 'name_selector', 'phone_selector', 'email_selector', 'link_selector'] as $key) {
            if (!array_key_exists($key, $rules)) {
                continue;
            }

            $value = trim((string) $rules[$key]);
            if ($value !== '') {
                $normalized[$key] = mb_substr($value, 0, 255);
            }
        }

        return $normalized;
    }

    public function previewSourceConfig(
        Platform $platform,
        User $actor,
        array $sourceConfig,
        int $maxCandidates = 50
    ): array {
        $source = $this->buildTransientSource($platform, $actor, $sourceConfig);

        if (!$source->is_active) {
            return [
                'status' => 'blocked',
                'message' => 'Scraper source is inactive.',
                'errors' => ['Scraper source is inactive.'],
                'robots' => null,
                'http' => null,
                'discovered' => 0,
                'duplicates' => 0,
                'preview' => [],
                'quality' => $this->emptyQualitySummary(),
                'candidates' => [],
            ];
        }

        if (!$source->compliance_ack_robots || !$source->compliance_ack_tos) {
            return [
                'status' => 'blocked',
                'message' => 'Compliance acknowledgement is incomplete.',
                'errors' => ['Compliance acknowledgement is incomplete.'],
                'robots' => null,
                'http' => null,
                'discovered' => 0,
                'duplicates' => 0,
                'preview' => [],
                'quality' => $this->emptyQualitySummary(),
                'candidates' => [],
            ];
        }

        $robotsCheck = $this->evaluateRobotsAccess($source->source_url);
        if (!($robotsCheck['allowed'] ?? false)) {
            return [
                'status' => 'blocked',
                'message' => $robotsCheck['message'] ?? 'Robots policy blocked this scrape source.',
                'errors' => [$robotsCheck['message'] ?? 'Robots policy blocked this scrape source.'],
                'robots' => $robotsCheck,
                'http' => null,
                'discovered' => 0,
                'duplicates' => 0,
                'preview' => [],
                'quality' => $this->emptyQualitySummary(),
                'candidates' => [],
            ];
        }

        try {
            $htmlPayload = $this->fetchHtml($source->source_url);
            $extractResult = $this->extractCandidatesWithDiagnostics($source, (string) ($htmlPayload['html'] ?? ''), $maxCandidates);
            $candidates = $extractResult['candidates'];
            $extractDiagnostics = $extractResult['diagnostics'];
        } catch (\Throwable $exception) {
            return [
                'status' => 'error',
                'message' => 'Scrape preview failed before candidate extraction.',
                'errors' => [$exception->getMessage()],
                'robots' => $robotsCheck,
                'http' => $htmlPayload ?? null,
                'discovered' => 0,
                'duplicates' => 0,
                'preview' => [],
                'quality' => $this->emptyQualitySummary(),
                'candidates' => [],
            ];
        }

        $duplicatesInDb = 0;
        $preview = [];
        foreach ($candidates as $candidate) {
            $inDb = $this->candidateAlreadyExists($source, $candidate);
            if ($inDb) {
                $duplicatesInDb++;
            }

            if (count($preview) < self::PREVIEW_SAMPLE_LIMIT) {
                $preview[] = [
                    'name' => $candidate['name'] ?? null,
                    'phone_normalized' => $candidate['phone_normalized'] ?? null,
                    'email' => $candidate['email'] ?? null,
                    'source_url' => $candidate['source_url'] ?? null,
                    'result' => $inDb ? 'duplicate_in_db' : 'new_candidate',
                ];
            }
        }

        $quality = $this->buildQualitySummary($candidates, $duplicatesInDb, $extractDiagnostics);

        return [
            'status' => 'success',
            'message' => 'Scrape preview completed successfully.',
            'errors' => [],
            'robots' => $robotsCheck,
            'http' => [
                'status' => $htmlPayload['status'] ?? null,
                'content_type' => $htmlPayload['content_type'] ?? null,
            ],
            'discovered' => count($candidates),
            'duplicates' => $duplicatesInDb,
            'preview' => $preview,
            'quality' => $quality,
            'extract_diagnostics' => $extractDiagnostics,
            'candidates' => $candidates,
        ];
    }

    public function importFromPreviewCandidates(
        Platform $platform,
        User $actor,
        array $sourceConfig,
        array $candidates
    ): array {
        $source = $this->buildTransientSource($platform, $actor, $sourceConfig);
        $errors = [];
        $created = 0;
        $duplicates = 0;
        $skipped = 0;
        $preview = [];

        $normalizedCandidates = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $normalized = $this->normalizeCandidate($source, $candidate);
            if ($normalized) {
                $normalizedCandidates[] = $normalized;
            }
        }

        foreach ($normalizedCandidates as $candidate) {
            $candidatePreview = [
                'name' => $candidate['name'] ?? null,
                'phone_normalized' => $candidate['phone_normalized'] ?? null,
                'email' => $candidate['email'] ?? null,
                'source_url' => $candidate['source_url'] ?? null,
            ];

            if ($this->candidateAlreadyExists($source, $candidate)) {
                $duplicates++;
                if (count($preview) < self::PREVIEW_SAMPLE_LIMIT) {
                    $candidatePreview['result'] = 'duplicate';
                    $preview[] = $candidatePreview;
                }
                continue;
            }

            try {
                $this->createLeadFromCandidate($source, $candidate, $actor);
                $created++;
                if (count($preview) < self::PREVIEW_SAMPLE_LIMIT) {
                    $candidatePreview['result'] = 'created';
                    $preview[] = $candidatePreview;
                }
            } catch (\Throwable $exception) {
                $skipped++;
                $errors[] = $exception->getMessage();
                if (count($preview) < self::PREVIEW_SAMPLE_LIMIT) {
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

        $quality = $this->buildQualitySummary($normalizedCandidates, $duplicates, [
            'raw_total' => count($candidates),
            'duplicates_in_scrape' => 0,
            'discarded_invalid' => max(0, count($candidates) - count($normalizedCandidates)),
            'capped' => 0,
        ]);

        return [
            'status' => $status,
            'message' => $status === 'success'
                ? 'Scraped leads imported successfully.'
                : ($status === 'partial' ? 'Scraped leads imported with warnings.' : 'Scraped lead import failed.'),
            'errors' => $errors,
            'discovered' => count($normalizedCandidates),
            'created' => $created,
            'duplicates' => $duplicates,
            'skipped' => $skipped,
            'preview' => $preview,
            'quality' => $quality,
        ];
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
                'quality' => $this->emptyQualitySummary(),
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
                'quality' => $this->emptyQualitySummary(),
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
                'quality' => $this->emptyQualitySummary(),
            ]);
        }

        $errors = [];
        $extractDiagnostics = [
            'raw_total' => 0,
            'duplicates_in_scrape' => 0,
            'discarded_invalid' => 0,
            'capped' => 0,
        ];

        try {
            $htmlPayload = $this->fetchHtml($source->source_url);
            $extractResult = $this->extractCandidatesWithDiagnostics($source, (string) ($htmlPayload['html'] ?? ''), $maxCandidates);
            $candidates = $extractResult['candidates'];
            $extractDiagnostics = $extractResult['diagnostics'];
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
                'quality' => $this->emptyQualitySummary(),
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

        $quality = $this->buildQualitySummary($candidates, $duplicates, $extractDiagnostics);

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
            'quality' => $quality,
            'extract_diagnostics' => $extractDiagnostics,
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
                'quality' => $summary['quality'] ?? null,
                'completed_at' => $completedAt->toDateTimeString(),
            ],
        ])->save();

        return $summary;
    }

    private function fetchHtml(string $url): array
    {
        return $this->domFetchHtml($url);
    }

    private function extractCandidates(ScraperSource $source, string $html, int $maxCandidates): array
    {
        return $this->extractCandidatesWithDiagnostics($source, $html, $maxCandidates)['candidates'];
    }

    private function extractCandidatesWithDiagnostics(ScraperSource $source, string $html, int $maxCandidates): array
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
        $duplicatesInScrape = 0;
        $discardedInvalid = 0;
        $capped = 0;

        foreach ($rawCandidates as $candidate) {
            $cleaned = $this->normalizeCandidate($source, $candidate);
            if (!$cleaned) {
                $discardedInvalid++;
                continue;
            }

            $key = $this->candidateKey($source, $cleaned);
            if ($key === '') {
                $discardedInvalid++;
                continue;
            }

            if (isset($seen[$key])) {
                $duplicatesInScrape++;
                continue;
            }

            $seen[$key] = true;

            if (count($normalized) >= $maxCandidates) {
                $capped++;
                continue;
            }

            $normalized[] = $cleaned;
        }

        return [
            'candidates' => $normalized,
            'diagnostics' => [
                'raw_total' => count($rawCandidates),
                'duplicates_in_scrape' => $duplicatesInScrape,
                'discarded_invalid' => $discardedInvalid,
                'capped' => $capped,
            ],
        ];
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

    private function buildTransientSource(Platform $platform, User $actor, array $sourceConfig): ScraperSource
    {
        $source = new ScraperSource();
        $source->forceFill([
            'platform_id' => (int) $platform->id,
            'name' => mb_substr(trim((string) ($sourceConfig['name'] ?? ('Preset scrape: ' . $platform->name))), 0, 255),
            'source_url' => mb_substr(trim((string) ($sourceConfig['source_url'] ?? '')), 0, 500),
            'parser_profile' => in_array(($sourceConfig['parser_profile'] ?? null), self::PARSER_PROFILES, true)
                ? (string) $sourceConfig['parser_profile']
                : 'contact_cards',
            'fetch_schedule' => in_array(($sourceConfig['fetch_schedule'] ?? null), self::FETCH_SCHEDULES, true)
                ? (string) $sourceConfig['fetch_schedule']
                : 'manual_only',
            'dedupe_mode' => in_array(($sourceConfig['dedupe_mode'] ?? null), self::DEDUPE_MODES, true)
                ? (string) $sourceConfig['dedupe_mode']
                : 'phone_or_email',
            'is_active' => array_key_exists('is_active', $sourceConfig) ? (bool) $sourceConfig['is_active'] : true,
            'compliance_ack_robots' => array_key_exists('compliance_ack_robots', $sourceConfig) ? (bool) $sourceConfig['compliance_ack_robots'] : true,
            'compliance_ack_tos' => array_key_exists('compliance_ack_tos', $sourceConfig) ? (bool) $sourceConfig['compliance_ack_tos'] : true,
            'compliance_notes' => !empty($sourceConfig['compliance_notes']) ? trim((string) $sourceConfig['compliance_notes']) : null,
            'parser_rules' => $this->normalizeParserRules((array) ($sourceConfig['parser_rules'] ?? [])),
            'created_by' => (int) $actor->id,
            'updated_by' => (int) $actor->id,
        ]);

        $source->setRelation('platform', $platform);

        return $source;
    }

    private function emptyQualitySummary(): array
    {
        return [
            'total_profiles' => 0,
            'valid_contacts' => 0,
            'missing_contacts' => 0,
            'duplicates_in_scrape' => 0,
            'duplicates_in_db' => 0,
            'new_profiles' => 0,
            'name_coverage_percent' => 0,
            'phone_coverage_percent' => 0,
            'email_coverage_percent' => 0,
            'contact_coverage_percent' => 0,
            'quality_score' => 0,
            'quality_band' => 'low',
            'discarded_invalid' => 0,
            'capped' => 0,
            'raw_profiles' => 0,
        ];
    }

    private function buildQualitySummary(array $candidates, int $duplicatesInDb, array $extractDiagnostics): array
    {
        $total = count($candidates);
        $withName = 0;
        $withPhone = 0;
        $withEmail = 0;
        $withContact = 0;

        foreach ($candidates as $candidate) {
            $hasName = !empty($candidate['name']);
            $hasPhone = !empty($candidate['phone_normalized']);
            $hasEmail = !empty($candidate['email']);

            if ($hasName) {
                $withName++;
            }
            if ($hasPhone) {
                $withPhone++;
            }
            if ($hasEmail) {
                $withEmail++;
            }
            if ($hasPhone || $hasEmail) {
                $withContact++;
            }
        }

        $nameCoverage = $total > 0 ? (int) round(($withName / $total) * 100) : 0;
        $phoneCoverage = $total > 0 ? (int) round(($withPhone / $total) * 100) : 0;
        $emailCoverage = $total > 0 ? (int) round(($withEmail / $total) * 100) : 0;
        $contactCoverage = $total > 0 ? (int) round(($withContact / $total) * 100) : 0;
        $qualityScore = (int) round(
            ($nameCoverage * 0.2)
            + ($contactCoverage * 0.6)
            + ($phoneCoverage * 0.1)
            + ($emailCoverage * 0.1)
        );

        $qualityBand = 'low';
        if ($qualityScore >= self::QUALITY_HIGH_THRESHOLD) {
            $qualityBand = 'high';
        } elseif ($qualityScore >= self::QUALITY_MEDIUM_THRESHOLD) {
            $qualityBand = 'medium';
        }

        return [
            'total_profiles' => $total,
            'valid_contacts' => $withContact,
            'missing_contacts' => max(0, $total - $withContact),
            'duplicates_in_scrape' => (int) ($extractDiagnostics['duplicates_in_scrape'] ?? 0),
            'duplicates_in_db' => max(0, $duplicatesInDb),
            'new_profiles' => max(0, $total - $duplicatesInDb),
            'name_coverage_percent' => $nameCoverage,
            'phone_coverage_percent' => $phoneCoverage,
            'email_coverage_percent' => $emailCoverage,
            'contact_coverage_percent' => $contactCoverage,
            'quality_score' => $qualityScore,
            'quality_band' => $qualityBand,
            'discarded_invalid' => (int) ($extractDiagnostics['discarded_invalid'] ?? 0),
            'capped' => (int) ($extractDiagnostics['capped'] ?? 0),
            'raw_profiles' => (int) ($extractDiagnostics['raw_total'] ?? $total),
        ];
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
        return $this->domQueryCss($xpath, $selector, $context);
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
        return $this->domNodeText($node);
    }

    private function extractPhoneFromText(string $text): ?string
    {
        return $this->domExtractPhoneFromText($text);
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
        return $this->domNormalizePhone($phone, $prefix);
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
