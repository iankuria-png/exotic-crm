<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Jobs\RunScraperSourceJob;
use App\Models\Platform;
use App\Models\ScraperCapture;
use App\Models\ScraperRun;
use App\Models\ScraperSource;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MarketAuthorizationService;
use App\Services\Scraper\CrawlSettings;
use App\Services\Scraper\Fetch\FetchDriverRegistry;
use App\Services\Scraper\Fetch\UrlSafety;
use App\Services\Scraper\Fetch\UrlTools;
use App\Services\Scraper\LeadImporter;
use App\Services\Scraper\LocationSupplyService;
use App\Services\Scraper\OverrideCatalog;
use App\Services\Scraper\PlacementMatcher;
use App\Services\Scraper\SiteProbeService;
use App\Services\Scraper\TemplateLibrary;
use App\Services\ScraperSourceService;
use App\Support\CrmAuditAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Lead Scraper workspace API: sites with health/tech insight, transient probes,
 * queued multi-page runs, selective import and browser capture overrides.
 * Viewing, probing and dry runs need market access; saving sites and importing
 * follow the same market scope, with site configuration limited to admin/sub-admin.
 */
class ScraperController extends Controller
{
    private const RULE_FIELDS = [
        'row_selector', 'name_selector', 'phone_selector', 'whatsapp_selector', 'email_selector',
        'link_selector', 'city_selector', 'city_text', 'age_selector', 'image_selector', 'city_from_url',
    ];

    public function __construct(
        private readonly MarketAuthorizationService $markets,
        private readonly AuditService $audit,
        private readonly SiteProbeService $probe,
        private readonly TemplateLibrary $templates,
        private readonly FetchDriverRegistry $drivers,
        private readonly ScraperSourceService $legacy,
        private readonly LeadImporter $importer,
        private readonly UrlSafety $urlSafety,
    ) {}

    public function overview(Request $request)
    {
        $user = $request->user();
        $query = ScraperSource::query()->with('platform:id,name,country,phone_prefix')->orderByDesc('updated_at');
        $this->markets->applyPlatformScope($query, $user);
        if ($request->filled('platform_id')) {
            $query->where('platform_id', (int) $request->input('platform_id'));
        }

        $sources = $query->limit(200)->get();
        $latestRuns = ScraperRun::query()
            ->whereIn('scraper_source_id', $sources->pluck('id'))
            ->orderByDesc('id')
            ->get(['id', 'scraper_source_id', 'status', 'mode', 'progress', 'result', 'created_at', 'completed_at'])
            ->unique('scraper_source_id')
            ->keyBy('scraper_source_id');

        $existingUrls = $sources->map(fn ($s) => UrlTools::host($s->source_url))->filter()->values()->all();

        $platforms = Platform::query()->where('is_active', true)->orderBy('name');
        $this->markets->applyPlatformScope($platforms, $user, 'id');

        return response()->json([
            'platforms' => $platforms->get(['id', 'name', 'country', 'phone_prefix']),
            'sites' => $sources->map(fn (ScraperSource $s) => $this->serializeSite($s, $latestRuns->get($s->id)))->values(),
            'library' => array_map(fn (array $preset) => $preset + ['added' => in_array(UrlTools::host($preset['source_url']), $existingUrls, true)], $this->legacy->competitorPresets()),
            'templates' => $this->templates->summaries(),
            'drivers' => array_values($this->drivers->describe()),
            'overrides' => (new OverrideCatalog)->describe(array_keys(OverrideCatalog::OPTIONS)),
            'defaults' => CrawlSettings::normalize([]),
            'limits' => config('scraper.limits'),
            'dedupe_modes' => ScraperSourceService::DEDUPE_MODES,
            'can_manage' => $this->markets->isManager($user),
        ]);
    }

    public function probe(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url|max:500',
            'platform_id' => 'required|integer|exists:platforms,id',
            'template_key' => ['nullable', 'string', Rule::in(array_keys($this->templates->all()))],
            'driver' => ['nullable', Rule::in(FetchDriverRegistry::KEYS)],
            'parser_rules' => 'nullable|array',
            'crawl_settings' => 'nullable|array',
        ]);
        $platform = $this->platform($request->user(), (int) $validated['platform_id']);

        $profile = $this->probe->probe($validated['url'], $this->prefix($platform), [
            'driver' => $validated['driver'] ?? 'http',
            'template_key' => $validated['template_key'] ?? null,
            'parser_rules' => $this->rules($validated['parser_rules'] ?? []),
            'crawl_settings' => $validated['crawl_settings'] ?? [],
        ]);

        return response()->json(['profile' => $profile]);
    }

    public function store(Request $request)
    {
        $this->markets->ensureManager($request->user(), 'Only admin or sub-admin users can add scraper sites.');
        $validated = $request->validate($this->siteRules(true));
        $platform = $this->platform($request->user(), (int) $validated['platform_id']);

        $url = trim((string) $validated['source_url']);
        if ($reason = $this->urlSafety->unsafeReason($url)) {
            return response()->json(['message' => $reason], 422);
        }
        if (ScraperSource::query()->where('platform_id', $platform->id)->where('source_url', $url)->exists()) {
            return response()->json(['message' => 'This address is already a site for the selected market.'], 422);
        }

        $source = ScraperSource::query()->create([
            'platform_id' => $platform->id,
            'name' => trim((string) ($validated['name'] ?? '')) ?: UrlTools::host($url),
            'source_url' => $url,
            'parser_profile' => 'contact_cards',
            'template_key' => $validated['template_key'] ?? null,
            'fetch_driver' => $validated['fetch_driver'] ?? 'http',
            'parser_rules' => $this->rules($validated['parser_rules'] ?? []),
            'crawl_settings' => CrawlSettings::normalize($validated['crawl_settings'] ?? []),
            'fetch_schedule' => 'manual_only',
            'dedupe_mode' => $validated['dedupe_mode'] ?? 'phone_or_email',
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'compliance_ack_robots' => (bool) ($validated['compliance_ack_robots'] ?? false),
            'compliance_ack_tos' => (bool) ($validated['compliance_ack_tos'] ?? false),
            'compliance_notes' => $validated['compliance_notes'] ?? null,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        if (is_array($validated['site_profile'] ?? null)) {
            $source->forceFill([
                'site_profile' => $validated['site_profile'],
                'health_status' => (string) ($validated['site_profile']['verdict'] ?? 'unknown'),
                'last_probed_at' => now(),
            ])->save();
        }

        $this->audit->fromRequest($request, $platform->id, CrmAuditAction::SCRAPER_SOURCE_CREATE, 'scraper_source', (int) $source->id, null, $this->auditState($source), $validated['reason'] ?? 'Added site in Lead Scraper');

        return response()->json(['site' => $this->serializeSite($source->load('platform:id,name,country,phone_prefix'), null, true)], 201);
    }

    public function show(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $scraperSource->load('platform:id,name,country,phone_prefix');
        $latest = $scraperSource->runs()->orderByDesc('id')->first();

        return response()->json(['site' => $this->serializeSite($scraperSource, $latest, true)]);
    }

    public function update(Request $request, ScraperSource $scraperSource)
    {
        $this->markets->ensureManager($request->user(), 'Only admin or sub-admin users can change scraper sites.');
        $this->authorizeSource($request->user(), $scraperSource);
        $validated = $request->validate($this->siteRules(false));
        $before = $this->auditState($scraperSource);

        if (isset($validated['source_url'])) {
            $url = trim((string) $validated['source_url']);
            if ($reason = $this->urlSafety->unsafeReason($url)) {
                return response()->json(['message' => $reason], 422);
            }
            $clash = ScraperSource::query()->where('platform_id', $scraperSource->platform_id)->where('source_url', $url)->whereKeyNot($scraperSource->id)->exists();
            if ($clash) {
                return response()->json(['message' => 'Another site already uses this address.'], 422);
            }
            $scraperSource->source_url = $url;
        }
        foreach (['name', 'dedupe_mode', 'is_active', 'compliance_ack_robots', 'compliance_ack_tos', 'compliance_notes', 'fetch_driver'] as $field) {
            if (array_key_exists($field, $validated)) {
                $scraperSource->{$field} = $validated[$field];
            }
        }
        if (array_key_exists('template_key', $validated)) {
            $scraperSource->template_key = $validated['template_key'] ?: null;
        }
        if (array_key_exists('parser_rules', $validated)) {
            $scraperSource->parser_rules = $this->rules($validated['parser_rules'] ?? []);
        }
        if (array_key_exists('crawl_settings', $validated)) {
            $scraperSource->crawl_settings = CrawlSettings::normalize($validated['crawl_settings'] ?? []);
        }
        $scraperSource->updated_by = $request->user()->id;
        $scraperSource->save();

        $this->audit->fromRequest($request, (int) $scraperSource->platform_id, CrmAuditAction::SCRAPER_SOURCE_UPDATE, 'scraper_source', (int) $scraperSource->id, $before, $this->auditState($scraperSource), $validated['reason'] ?? 'Updated site in Lead Scraper');

        return response()->json(['site' => $this->serializeSite($scraperSource->load('platform:id,name,country,phone_prefix'), $scraperSource->runs()->orderByDesc('id')->first(), true)]);
    }

    public function probeSite(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $validated = $request->validate([
            'template_key' => ['nullable', 'string', Rule::in(array_keys($this->templates->all()))],
            'driver' => ['nullable', Rule::in(FetchDriverRegistry::KEYS)],
            'parser_rules' => 'nullable|array',
            'crawl_settings' => 'nullable|array',
            'test_only' => 'nullable|boolean',
        ]);

        // test_only: try unsaved settings without changing the stored health.
        if ($request->boolean('test_only')) {
            $scraperSource->loadMissing('platform');
            $profile = $this->probe->probe((string) $scraperSource->source_url, $this->probe->phonePrefix($scraperSource), [
                'driver' => $validated['driver'] ?? $scraperSource->fetch_driver ?? 'http',
                'template_key' => array_key_exists('template_key', $validated) ? $validated['template_key'] : $scraperSource->template_key,
                'parser_rules' => $this->rules($validated['parser_rules'] ?? (array) $scraperSource->parser_rules),
                'crawl_settings' => $validated['crawl_settings'] ?? (array) $scraperSource->crawl_settings,
                'source_id' => (int) $scraperSource->id,
            ]);

            return response()->json(['profile' => $profile]);
        }

        $profile = $this->probe->probeSource($scraperSource);

        return response()->json(['profile' => $profile, 'site' => $this->serializeSite($scraperSource->fresh(['platform:id,name,country,phone_prefix']), $scraperSource->runs()->orderByDesc('id')->first(), true)]);
    }

    public function runs(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $runs = $scraperSource->runs()->with('initiatedBy:id,name')->orderByDesc('id')->limit(30)->get();

        return response()->json(['runs' => $runs->map(fn (ScraperRun $r) => $this->serializeRun($r, false))->values()]);
    }

    public function startRun(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $validated = $request->validate([
            'mode' => ['required', Rule::in(['dry_run', 'import'])],
            'reason' => 'nullable|string|max:500',
            'locations' => 'nullable|array|max:20',
            'locations.*' => 'string|max:80',
            'include_unknown_location' => 'nullable|boolean',
            'save_as_default' => 'nullable|boolean',
        ]);

        // Location filter for this run: explicit choice, else the site's saved default.
        $crawl = CrawlSettings::normalize((array) ($scraperSource->crawl_settings ?? []));
        $filters = array_key_exists('locations', $validated)
            ? ['locations' => CrawlSettings::locations($validated['locations'] ?? []), 'include_unknown_location' => (bool) ($validated['include_unknown_location'] ?? false)]
            : ['locations' => $crawl['locations'], 'include_unknown_location' => $crawl['include_unknown_location']];

        if ($request->boolean('save_as_default')) {
            $this->markets->ensureManager($request->user(), 'Only admin or sub-admin users can change a site\'s default location.');
            $before = $this->auditState($scraperSource);
            $scraperSource->forceFill([
                'crawl_settings' => CrawlSettings::normalize(array_merge($crawl, $filters)),
                'updated_by' => $request->user()->id,
            ])->save();
            $this->audit->fromRequest($request, (int) $scraperSource->platform_id, CrmAuditAction::SCRAPER_SOURCE_UPDATE, 'scraper_source', (int) $scraperSource->id, $before, $this->auditState($scraperSource), 'Saved default location from run options');
        }

        $active = $scraperSource->runs()->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subHour())->first();
        if ($active) {
            return response()->json(['message' => 'A run is already in progress for this site.', 'run' => $this->serializeRun($active, false)], 409);
        }

        $run = $this->queueRun($request, $scraperSource, $validated['mode'], $filters, $validated['reason'] ?? null);

        return response()->json(['run' => $this->serializeRun($run->fresh('initiatedBy:id,name'), true)], 202);
    }

    /**
     * Location gaps: our available profiles per place against what the market's
     * competitor sites list there, fewest of ours first.
     */
    public function locationSupply(Request $request, LocationSupplyService $supply)
    {
        $validated = $request->validate(['platform_id' => 'required|integer|exists:platforms,id']);
        $platform = $this->platform($request->user(), (int) $validated['platform_id']);

        return response()->json(['platform' => ['id' => $platform->id, 'name' => $platform->name, 'country' => $platform->country]] + $supply->forMarket($platform));
    }

    /**
     * Loosely match scraped places to the market's WordPress cities (sanitized first),
     * with our available profile count per city so thin places are obvious.
     */
    public function placements(Request $request, PlacementMatcher $matcher)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'places' => 'present|array|max:200',
            'places.*' => 'nullable|string|max:200',
        ]);
        $platform = $this->platform($request->user(), (int) $validated['platform_id']);
        try {
            $options = $matcher->options($platform);
        } catch (\Throwable) {
            return response()->json(['message' => 'WordPress locations for '.$platform->name.' are unavailable right now. Try again shortly.'], 503);
        }

        $matches = [];
        foreach (array_unique(array_map(fn ($p) => (string) $p, $validated['places'])) as $place) {
            $matches[] = $matcher->match($place, $options);
        }

        return response()->json(['options' => $options, 'matches' => $matches]);
    }

    /**
     * Start location-limited runs on several sites of one market at once.
     */
    public function batchRuns(Request $request)
    {
        $validated = $request->validate([
            'platform_id' => 'required|integer|exists:platforms,id',
            'site_ids' => 'required|array|min:1|max:30',
            'site_ids.*' => 'integer',
            'locations' => 'required|array|min:1|max:20',
            'locations.*' => 'string|max:80',
            'mode' => ['required', Rule::in(['dry_run', 'import'])],
            'include_unknown_location' => 'nullable|boolean',
        ]);
        $platform = $this->platform($request->user(), (int) $validated['platform_id']);
        $filters = ['locations' => CrawlSettings::locations($validated['locations']), 'include_unknown_location' => (bool) ($validated['include_unknown_location'] ?? false)];
        if ($filters['locations'] === []) {
            return response()->json(['message' => 'Choose at least one place.'], 422);
        }

        $started = [];
        $skipped = [];
        $sources = ScraperSource::query()->where('platform_id', $platform->id)->whereIn('id', array_unique($validated['site_ids']))->get();
        foreach ($sources as $source) {
            $reason = match (true) {
                ! $source->is_active => 'Paused',
                ! $source->compliance_ack_robots || ! $source->compliance_ack_tos => 'Checks not confirmed',
                $source->runs()->whereIn('status', ['queued', 'running'])->where('created_at', '>=', now()->subHour())->exists() => 'Already running',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = ['site_id' => $source->id, 'name' => $source->name, 'reason' => $reason];

                continue;
            }
            $run = $this->queueRun($request, $source, $validated['mode'], $filters, 'Location gaps: '.implode(', ', $filters['locations']));
            $started[] = ['site_id' => $source->id, 'name' => $source->name, 'run' => $this->serializeRun($run->fresh(), false)];
        }
        foreach (array_diff(array_unique($validated['site_ids']), $sources->pluck('id')->all()) as $missing) {
            $skipped[] = ['site_id' => (int) $missing, 'name' => null, 'reason' => 'Not a site in this market'];
        }

        return response()->json(['started' => $started, 'skipped' => $skipped, 'filters' => $filters], $started ? 202 : 422);
    }

    private function queueRun(Request $request, ScraperSource $source, string $mode, array $filters, ?string $reason): ScraperRun
    {
        $run = ScraperRun::query()->create([
            'scraper_source_id' => $source->id,
            'platform_id' => $source->platform_id,
            'initiated_by' => $request->user()->id,
            'mode' => $mode,
            'status' => 'queued',
            'reason' => $reason,
            'filters' => $filters,
            'progress' => ['phase' => 'queued', 'events' => []],
        ]);

        $this->audit->fromRequest($request, (int) $source->platform_id, CrmAuditAction::SCRAPER_RUN, 'scraper_source', (int) $source->id, null, [
            'run_id' => $run->id,
            'mode' => $run->mode,
            'fetch_driver' => $source->fetch_driver,
            'locations' => $filters['locations'],
        ], $reason ?? 'Lead Scraper run');

        RunScraperSourceJob::dispatch((int) $run->id);

        return $run;
    }

    public function showRun(Request $request, ScraperRun $scraperRun)
    {
        $this->authorizeSource($request->user(), $scraperRun->source);

        return response()->json(['run' => $this->serializeRun($scraperRun->load('initiatedBy:id,name'), true)]);
    }

    public function cancelRun(Request $request, ScraperRun $scraperRun)
    {
        $this->authorizeSource($request->user(), $scraperRun->source);
        if (in_array($scraperRun->status, ['queued', 'running'], true)) {
            $scraperRun->forceFill(['cancel_requested_at' => now()])->save();
            if ($scraperRun->status === 'queued') {
                $scraperRun->forceFill(['status' => 'cancelled', 'reason' => 'Cancelled before it started.', 'completed_at' => now()])->save();
            }
        }

        return response()->json(['run' => $this->serializeRun($scraperRun->fresh('initiatedBy:id,name'), false)]);
    }

    public function importRun(Request $request, ScraperRun $scraperRun)
    {
        $source = $scraperRun->source;
        $this->authorizeSource($request->user(), $source);
        $validated = $request->validate([
            'indexes' => 'nullable|array|max:1000',
            'indexes.*' => 'integer|min:0',
            'reason' => 'nullable|string|max:500',
        ]);
        if ($scraperRun->mode !== 'dry_run' || ! in_array($scraperRun->status, ['success', 'partial'], true)) {
            return response()->json(['message' => 'Only a finished preview run can be imported.'], 422);
        }

        $candidates = (array) ($scraperRun->preview ?? []);
        $indexes = isset($validated['indexes']) ? array_flip($validated['indexes']) : null;
        $created = 0;
        $duplicates = 0;
        $errors = [];
        foreach ($candidates as $i => &$candidate) {
            if ($indexes !== null && ! isset($indexes[$i])) {
                continue;
            }
            if (in_array($candidate['status'] ?? null, ['created', 'existing'], true)) {
                continue;
            }
            $row = $candidate + ['phone' => null, 'email' => null, 'profile_url' => null, 'name' => null];
            if (! $this->importer->importable($row)) {
                continue;
            }
            if ($this->importer->exists($source, $row)) {
                $candidate['status'] = 'existing';
                $duplicates++;

                continue;
            }
            try {
                $lead = $this->importer->create($source, $row, $request->user(), (int) $scraperRun->id);
                $candidate['status'] = 'created';
                $candidate['lead_id'] = $lead->id;
                $created++;
            } catch (\Throwable $exception) {
                $candidate['status'] = 'error';
                $candidate['error'] = mb_substr($exception->getMessage(), 0, 200);
                $errors[] = $candidate['error'];
            }
        }
        unset($candidate);

        $result = (array) ($scraperRun->result ?? []);
        $result['created'] = (int) ($result['created'] ?? 0) + $created;
        $result['duplicates'] = (int) ($result['duplicates'] ?? 0) + $duplicates;
        $result['new'] = count(array_filter($candidates, fn ($c) => ($c['status'] ?? null) === 'new'));
        $scraperRun->forceFill([
            'preview' => $candidates,
            'result' => $result,
            'created_count' => (int) $scraperRun->created_count + $created,
            'duplicate_count' => (int) $scraperRun->duplicate_count + $duplicates,
        ])->save();

        $this->audit->fromRequest($request, (int) $source->platform_id, CrmAuditAction::SCRAPER_RUN, 'scraper_source', (int) $source->id, null, [
            'run_id' => $scraperRun->id,
            'imported' => $created,
            'duplicates' => $duplicates,
        ], $validated['reason'] ?? 'Imported leads from Lead Scraper preview');

        return response()->json([
            'created' => $created,
            'duplicates' => $duplicates,
            'errors' => array_slice($errors, 0, 10),
            'run' => $this->serializeRun($scraperRun->fresh('initiatedBy:id,name'), true),
        ]);
    }

    public function createCapture(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $token = Str::random(48);
        $capture = ScraperCapture::query()->create([
            'scraper_source_id' => $scraperSource->id,
            'platform_id' => $scraperSource->platform_id,
            'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', $token),
            'expected_host' => UrlTools::host($scraperSource->source_url),
            'method' => 'bookmarklet',
            'status' => 'pending',
            'expires_at' => now()->addMinutes((int) config('scraper.capture.token_ttl_minutes', 30)),
        ]);

        $endpoint = url('/api/crm/scraper/captures/receive');
        $bookmarklet = 'javascript:(function(){var f=document.createElement("form");f.method="POST";f.action='.json_encode($endpoint).';f.target="_blank";f.acceptCharset="UTF-8";'
            .'var a={token:'.json_encode($token).',url:location.href,title:document.title,html:"<!doctype html>"+document.documentElement.outerHTML};'
            .'for(var k in a){var i=document.createElement("input");i.type="hidden";i.name=k;i.value=a[k];f.appendChild(i);}document.body.appendChild(f);f.submit();f.remove();})();';

        return response()->json([
            'capture' => $this->serializeCapture($capture),
            'bookmarklet' => $bookmarklet,
            'open_url' => $scraperSource->source_url,
        ], 201);
    }

    /**
     * Public (token-authenticated) endpoint the bookmarklet posts to from the
     * person's browser tab. Single use, expires, host-checked.
     */
    public function receiveCapture(Request $request)
    {
        $token = (string) $request->input('token', '');
        $html = (string) $request->input('html', '');
        $url = trim((string) $request->input('url', ''));

        $capture = $token !== '' ? ScraperCapture::query()->where('token_hash', hash('sha256', $token))->first() : null;
        if (! $capture || $capture->status !== 'pending' || $capture->expires_at->isPast()) {
            return $this->capturePage('This capture link has expired or was already used. Start a new capture from the CRM.', false, 410);
        }
        if ($html === '' || strlen($html) > (int) config('scraper.capture.max_bytes')) {
            return $this->capturePage('The page was empty or too large to capture.', false, 422);
        }
        if (! preg_match('#^https?://#i', $url) || ($capture->expected_host && ! str_ends_with(UrlTools::host($url), $capture->expected_host) && ! str_ends_with($capture->expected_host, UrlTools::host($url)))) {
            return $this->capturePage('This page is not on '.$capture->expected_host.'. Open the site\'s listing page and try again.', false, 422);
        }

        $this->storeCaptureHtml($capture, $html, $url, 'bookmarklet');

        return $this->capturePage('Sent to the CRM. You can close this tab and go back to Lead Scraper.', true, 200);
    }

    public function uploadCapture(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $validated = $request->validate([
            'url' => 'nullable|url|max:1000',
            'html' => 'nullable|string',
            'file' => 'nullable|file|max:8192',
        ]);
        $html = (string) ($validated['html'] ?? '');
        if ($request->hasFile('file')) {
            $html = (string) file_get_contents($request->file('file')->getRealPath());
        }
        if (trim($html) === '' || ! preg_match('/<(html|body|div|ul|li|a)\b/i', $html)) {
            return response()->json(['message' => 'That does not look like a web page. Save the page as HTML or copy its source.'], 422);
        }
        if (strlen($html) > (int) config('scraper.capture.max_bytes')) {
            return response()->json(['message' => 'The page is too large.'], 422);
        }

        $capture = ScraperCapture::query()->create([
            'scraper_source_id' => $scraperSource->id,
            'platform_id' => $scraperSource->platform_id,
            'user_id' => $request->user()->id,
            'token_hash' => hash('sha256', Str::random(48)),
            'expected_host' => UrlTools::host($scraperSource->source_url),
            'method' => $request->hasFile('file') ? 'upload' : 'paste',
            'status' => 'pending',
            'expires_at' => now()->addMinutes(5),
        ]);
        $this->storeCaptureHtml($capture, $html, (string) ($validated['url'] ?? $scraperSource->source_url), $capture->method);

        return response()->json(['capture' => $this->serializeCapture($capture->fresh())], 201);
    }

    public function captures(Request $request, ScraperSource $scraperSource)
    {
        $this->authorizeSource($request->user(), $scraperSource);
        $captures = $scraperSource->captures()->with('user:id,name')->orderByDesc('id')->limit(20)->get();

        return response()->json(['captures' => $captures->map(fn ($c) => $this->serializeCapture($c))->values()]);
    }

    public function showCapture(Request $request, ScraperCapture $scraperCapture)
    {
        $this->authorizeSource($request->user(), $scraperCapture->source);

        return response()->json(['capture' => $this->serializeCapture($scraperCapture)]);
    }

    // ---------------------------------------------------------------------

    private function storeCaptureHtml(ScraperCapture $capture, string $html, string $url, string $method): void
    {
        $path = sprintf('%s/%d/%d-%s.html', trim((string) config('scraper.capture.directory'), '/'), (int) $capture->scraper_source_id, $capture->id, Str::random(8));
        Storage::disk((string) config('scraper.capture.disk', 'local'))->put($path, $html);
        $capture->forceFill([
            'url' => mb_substr($url, 0, 1000),
            'storage_path' => $path,
            'bytes' => strlen($html),
            'method' => $method,
            'status' => 'received',
            'received_at' => now(),
        ])->save();
    }

    private function capturePage(string $message, bool $ok, int $status)
    {
        $color = $ok ? '#047857' : '#b91c1c';
        $icon = $ok ? '✓' : '!';
        $html = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Lead Scraper capture</title></head>'
            .'<body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f8fafc;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0">'
            .'<div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 32px;max-width:420px;text-align:center">'
            .'<div style="width:44px;height:44px;border-radius:50%;background:'.$color.';color:#fff;font-size:24px;line-height:44px;margin:0 auto 12px">'.$icon.'</div>'
            .'<p style="color:#0f172a;font-size:15px;margin:0">'.e($message).'</p></div></body></html>';

        return response($html, $status)->header('Content-Type', 'text/html; charset=utf-8');
    }

    private function siteRules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'platform_id' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:platforms,id'],
            'name' => 'nullable|string|max:255',
            'source_url' => $req.'|url|max:500',
            'template_key' => ['nullable', 'string', Rule::in(array_keys($this->templates->all()))],
            'fetch_driver' => ['nullable', Rule::in(FetchDriverRegistry::KEYS)],
            'dedupe_mode' => ['nullable', Rule::in(ScraperSourceService::DEDUPE_MODES)],
            'parser_rules' => 'nullable|array',
            'parser_rules.*' => 'nullable|string|max:255',
            'crawl_settings' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'compliance_ack_robots' => 'nullable|boolean',
            'compliance_ack_tos' => 'nullable|boolean',
            'compliance_notes' => 'nullable|string|max:500',
            'site_profile' => 'nullable|array',
            'reason' => 'nullable|string|max:500',
        ];
    }

    private function rules(array $input): array
    {
        $out = [];
        foreach (self::RULE_FIELDS as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value !== '') {
                $out[$field] = mb_substr($value, 0, 255);
            }
        }

        return $out;
    }

    private function platform(User $user, int $platformId): Platform
    {
        $this->markets->ensureUserCanAccessPlatform($user, $platformId);

        return Platform::query()->findOrFail($platformId);
    }

    private function prefix(Platform $platform): string
    {
        return preg_replace('/\D/', '', (string) $platform->phone_prefix) ?: '254';
    }

    private function authorizeSource(User $user, ?ScraperSource $source): void
    {
        abort_if(! $source, 404);
        $this->markets->ensureUserCanAccessPlatform($user, (int) $source->platform_id);
    }

    private function serializeSite(ScraperSource $s, ?ScraperRun $latest, bool $detail = false): array
    {
        $profile = (array) ($s->site_profile ?? []);
        $base = [
            'id' => $s->id,
            'name' => $s->name,
            'source_url' => $s->source_url,
            'host' => UrlTools::host($s->source_url),
            'platform' => $s->platform ? ['id' => $s->platform->id, 'name' => $s->platform->name, 'country' => $s->platform->country, 'phone_prefix' => $s->platform->phone_prefix] : null,
            'platform_id' => $s->platform_id,
            'is_active' => (bool) $s->is_active,
            'health_status' => $s->health_status ?? ($profile['verdict'] ?? null),
            'headline' => $profile['headline'] ?? null,
            'tech' => array_slice(array_map(fn ($t) => ['key' => $t['key'], 'name' => $t['name'], 'category' => $t['category']], (array) ($profile['tech'] ?? [])), 0, 12),
            'blocker_count' => count(array_filter((array) ($profile['blockers'] ?? []), fn ($b) => ($b['severity'] ?? '') === 'blocking')),
            'template_key' => $s->template_key,
            'template_label' => $s->template_key ? ($this->templates->get($s->template_key)['label'] ?? $s->template_key) : ($profile['template']['label'] ?? null),
            'fetch_driver' => $s->fetch_driver ?: 'http',
            'default_locations' => CrawlSettings::locations(((array) ($s->crawl_settings ?? []))['locations'] ?? []),
            'last_probed_at' => optional($s->last_probed_at)->toIso8601String(),
            'last_run_at' => optional($s->last_run_at)->toIso8601String(),
            'last_run_status' => $s->last_run_status,
            'last_run_summary' => $s->last_run_summary,
            'latest_run' => $latest ? $this->serializeRun($latest, false) : null,
        ];

        if (! $detail) {
            return $base;
        }

        return $base + [
            'parser_rules' => (array) ($s->parser_rules ?? []),
            'crawl_settings' => CrawlSettings::normalize((array) ($s->crawl_settings ?? [])),
            'dedupe_mode' => $s->dedupe_mode,
            'compliance_ack_robots' => (bool) $s->compliance_ack_robots,
            'compliance_ack_tos' => (bool) $s->compliance_ack_tos,
            'compliance_notes' => $s->compliance_notes,
            'site_profile' => $profile ?: null,
        ];
    }

    private function serializeRun(ScraperRun $r, bool $withCandidates): array
    {
        $out = [
            'id' => $r->id,
            'scraper_source_id' => $r->scraper_source_id,
            'mode' => $r->mode,
            'status' => $r->status,
            'reason' => $r->reason,
            'progress' => $r->progress,
            'filters' => $r->filters,
            'result' => $r->result,
            'pages_fetched' => (int) ($r->pages_fetched ?? 0),
            'discovered_count' => (int) $r->discovered_count,
            'created_count' => (int) $r->created_count,
            'duplicate_count' => (int) $r->duplicate_count,
            'cancel_requested' => $r->cancel_requested_at !== null,
            'initiated_by' => $r->relationLoaded('initiatedBy') && $r->initiatedBy ? ['id' => $r->initiatedBy->id, 'name' => $r->initiatedBy->name] : null,
            'created_at' => optional($r->created_at)->toIso8601String(),
            'started_at' => optional($r->started_at)->toIso8601String(),
            'completed_at' => optional($r->completed_at)->toIso8601String(),
        ];
        if ($withCandidates) {
            $out['candidates'] = array_values((array) ($r->preview ?? []));
        }

        return $out;
    }

    private function serializeCapture(ScraperCapture $c): array
    {
        return [
            'id' => $c->id,
            'status' => $c->status,
            'method' => $c->method,
            'url' => $c->url,
            'bytes' => (int) $c->bytes,
            'expected_host' => $c->expected_host,
            'expires_at' => optional($c->expires_at)->toIso8601String(),
            'received_at' => optional($c->received_at)->toIso8601String(),
            'user' => $c->relationLoaded('user') && $c->user ? ['id' => $c->user->id, 'name' => $c->user->name] : null,
        ];
    }

    private function auditState(ScraperSource $s): array
    {
        return [
            'name' => $s->name,
            'source_url' => $s->source_url,
            'template_key' => $s->template_key,
            'fetch_driver' => $s->fetch_driver,
            'parser_rules' => $s->parser_rules,
            'crawl_settings' => $s->crawl_settings,
            'dedupe_mode' => $s->dedupe_mode,
            'is_active' => (bool) $s->is_active,
            'compliance_ack_robots' => (bool) $s->compliance_ack_robots,
            'compliance_ack_tos' => (bool) $s->compliance_ack_tos,
        ];
    }
}
