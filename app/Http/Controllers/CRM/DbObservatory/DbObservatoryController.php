<?php

namespace App\Http\Controllers\CRM\DbObservatory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\DbObservatory\Concerns\ScopesObservatory;
use App\Models\DbScanAuditEvent;
use App\Models\DbScanConnection;
use App\Models\DbScanEvent;
use App\Models\DbScanFinding;
use App\Models\DbScanMarketRun;
use App\Models\DbScanPass;
use App\Models\DbScanRule;
use App\Models\DbScanRuleCoverage;
use App\Models\DbScanSnapshot;
use App\Models\DbScanSurfaceCoverage;
use App\Models\DbScanSweep;
use App\Models\Platform;
use App\Services\DbScanner\ObservatoryPresenter;
use App\Services\DbScanner\ScannerSettings;
use App\Services\DbScanner\Surfaces\SurfaceRegistry;
use App\Support\DbScannerPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read endpoints of the Database Observatory cockpit: overview, markets,
 * inventory, passes, market runs, events, coverage and audit. All scoped.
 */
class DbObservatoryController extends Controller
{
    use ScopesObservatory;

    public function __construct(
        private readonly ObservatoryPresenter $presenter,
        private readonly ScannerSettings $settings,
        private readonly SurfaceRegistry $surfaces,
    ) {}

    public function overview(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $ids = $this->scopeIds($request);
        $names = $this->marketNames($ids);
        $open = fn () => $this->scoped(DbScanFinding::query(), $request)->whereIn('status', DbScanFinding::OPEN_STATUSES);

        $driftRules = DbScanRule::query()->where('kind', 'drift')->pluck('key');
        $connections = $this->scoped(DbScanConnection::query(), $request)->get();
        $eligible = $connections->filter(fn ($c) => $c->enabled && $c->preflightValid())->pluck('platform_id')->all();

        $coveredRecently = DbScanSweep::query()
            ->whereIn('platform_id', $eligible ?: [0])
            ->whereIn('status', ['complete', 'complete_with_gaps'])
            ->where('finished_at', '>=', now()->subDay())
            ->distinct()
            ->pluck('platform_id')
            ->all();

        $inventoryIds = array_keys($names);
        $fullCoverage = fn ($days) => DbScanSweep::query()->whereIn('platform_id', $inventoryIds ?: [0])
            ->where('status', 'complete')->whereIn('profile', ['standard', 'deep'])
            ->where('finished_at', '>=', now()->subDays($days))->distinct()->pluck('platform_id')->all();
        $gapped = DbScanSweep::query()->whereIn('platform_id', $inventoryIds ?: [0])
            ->where('status', 'complete_with_gaps')->whereIn('profile', ['standard', 'deep'])
            ->where('finished_at', '>=', now()->subDay())->distinct()->pluck('platform_id')->count();
        $fleetRows = $open()->orderByDesc('last_seen_at')->limit(10001)->get(['id', 'platform_id', 'rule_key', 'title', 'severity', 'subject', 'evidence']);
        $fleet = (new \App\Services\DbScanner\FleetTriage)->summarize($fleetRows->take(10000), $names);
        $fleet['sampled'] = $fleetRows->count() > 10000;
        $fleet['findings_considered'] = min(10000, $fleetRows->count());
        $unreachable = DbScanMarketRun::query()
            ->whereIn('platform_id', $eligible ?: [0])
            ->where('status', 'unreachable')
            ->where('finished_at', '>=', now()->subDay())
            ->get(['platform_id', 'error_code', 'finished_at'])
            ->unique('platform_id')
            ->map(fn ($r) => ['platform_id' => $r->platform_id, 'market' => $names[$r->platform_id] ?? null, 'error_code' => $r->error_code, 'at' => $r->finished_at?->toIso8601String()])
            ->values();

        $posture = $open()
            ->selectRaw('platform_id, category, severity, COUNT(*) AS n')
            ->groupBy('platform_id', 'category', 'severity')
            ->get()
            ->groupBy('platform_id')
            ->map(function ($rows, $platformId) use ($names) {
                $cells = [];
                foreach ($rows as $row) {
                    $cells[$row->category]['total'] = ($cells[$row->category]['total'] ?? 0) + (int) $row->n;
                    $cells[$row->category][$row->severity] = (int) $row->n;
                }

                return ['platform_id' => (int) $platformId, 'market' => $names[$platformId] ?? null, 'categories' => $cells];
            })
            ->values();

        $activePasses = DbScanPass::query()
            ->whereNotIn('status', DbScanPass::TERMINAL)
            ->where('mode', '!=', 'preflight')
            ->latest('id')
            ->limit(10)
            ->get()
            ->filter(fn ($p) => ! is_array($ids) || array_intersect((array) ($p->scope['platform_ids'] ?? []), $ids) !== [])
            ->map(fn ($pass) => $this->presenter->pass($pass, $this->scoped(DbScanMarketRun::query()->where('pass_id', $pass->id), $request)->get(), $names))
            ->values();

        $row = $this->settings->row(true);

        return response()->json([
            'scanner' => [
                'deployment_enabled' => $this->settings->deploymentEnabled(),
                'enabled' => (bool) $row->enabled,
                'paused' => (bool) $row->paused,
                'emergency_stop' => (bool) $row->emergency_stop,
                'rules_enabled' => DbScanRule::query()->where('enabled', true)->where('retired', false)->count(),
                'packs' => DbScanRule::query()->where('retired', false)->selectRaw('pack, MAX(pack_version) AS v')->groupBy('pack')->pluck('v', 'pack'),
            ],
            'permissions' => DbScannerPermissions::capabilities($request->user()),
            'counts' => [
                'critical_open' => $open()->where('severity', 'critical')->count(),
                'critical_new_24h' => $open()->where('severity', 'critical')->where('first_seen_at', '>=', now()->subDay())->count(),
                'warn_open' => $open()->where('severity', 'warn')->count(),
                'resolved_24h' => $this->scoped(DbScanFinding::query(), $request)->where('status', 'resolved')->where('resolved_at', '>=', now()->subDay())->count(),
                'suppressed' => $this->scoped(DbScanFinding::query(), $request)->whereIn('status', DbScanFinding::SUPPRESSED_STATUSES)->count(),
                'drift_24h' => $this->scoped(DbScanFinding::query(), $request)->whereIn('rule_key', $driftRules)->where('first_seen_at', '>=', now()->subDay())->count(),
            ],
            'malware' => [
                'confirmed' => $open()->where('category', 'malware')->where('confidence', 'confirmed')->count(),
                'strong' => $open()->where('category', 'malware')->where('confidence', 'strong')->count(),
                'needs_review' => $open()->where('category', 'malware')->where('confidence', 'needs_review')->count(),
            ],
            'fleet' => $fleet,
            'coverage' => [
                'markets_total' => count($inventoryIds),
                'markets_complete_24h' => count($fullCoverage(1)),
                'markets_complete_7d' => count($fullCoverage(7)),
                'markets_gapped_24h' => $gapped,
                'markets_unconnected' => count(array_diff($inventoryIds, $connections->pluck('platform_id')->all())),
                'markets_eligible' => count($eligible),
                'markets_covered_24h' => count($coveredRecently),
                'markets_configured' => $connections->count(),
                'unreachable' => $unreachable,
            ],
            'posture' => $posture,
            'running' => $activePasses,
            'latest_critical' => $open()->where('severity', 'critical')->latest('first_seen_at')->limit(6)->get()
                ->map(fn ($f) => $this->presenter->finding($f, $names))->values(),
            'health' => $this->presenter->health($ids),
        ]);
    }

    public function markets(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $ids = $this->scopeIds($request);
        $admin = DbScannerPermissions::canConfigure($request->user());

        $platforms = Platform::query()->when(is_array($ids), fn ($q) => $q->whereIn('id', $ids ?: [0]))->orderBy('name')->get(['id', 'name', 'domain', 'timezone', 'health_status', 'health_checked_at', 'is_active']);
        $connections = DbScanConnection::query()->whereIn('platform_id', $platforms->pluck('id'))->get()->keyBy('platform_id');
        $open = DbScanFinding::query()->whereIn('platform_id', $platforms->pluck('id'))->whereIn('status', DbScanFinding::OPEN_STATUSES)
            ->selectRaw('platform_id, severity, COUNT(*) AS n')->groupBy('platform_id', 'severity')->get()->groupBy('platform_id');
        $malware = DbScanFinding::query()->whereIn('platform_id', $platforms->pluck('id'))->whereIn('status', DbScanFinding::OPEN_STATUSES)->where('category', 'malware')
            ->selectRaw('platform_id, COUNT(*) AS n')->groupBy('platform_id')->pluck('n', 'platform_id');

        $data = $platforms->map(function (Platform $p) use ($connections, $open, $malware, $admin) {
            $c = $connections->get($p->id);
            $lastSweep = DbScanSweep::query()->where('platform_id', $p->id)->latest('id')->first();
            $lastComplete = DbScanSweep::query()->where('platform_id', $p->id)->whereIn('status', ['complete', 'complete_with_gaps'])->latest('finished_at')->first();
            $lastRun = DbScanMarketRun::query()->where('platform_id', $p->id)->where('mode', 'scan')->latest('id')->first();
            $counts = collect($open->get($p->id, []))->mapWithKeys(fn ($r) => [$r->severity => (int) $r->n])->all();

            return [
                'platform_id' => $p->id,
                'market' => $p->name,
                'domain' => $p->domain,
                'is_active' => (bool) $p->is_active,
                'health_status' => $p->health_status,
                'health_checked_at' => $p->health_checked_at?->toIso8601String(),
                'connection' => $c ? array_filter([
                    'configured' => true,
                    'credential_source' => $c->credential_source ?: 'dedicated',
                    'enabled' => (bool) $c->enabled,
                    'load_gate_enabled' => $c->load_gate_enabled !== false,
                    'preflight_status' => $c->preflightValid() ? 'passed' : ($c->preflight_status === 'passed' ? 'stale' : $c->preflight_status),
                    'preflight_at' => $c->preflight_at?->toIso8601String(),
                    'preflight_error' => $c->preflight_error,
                    'engine' => $c->capabilities['engine'] ?? null,
                    'host_group' => $admin ? $c->host_group : null,
                ], fn ($v) => $v !== null) : ['configured' => false],
                'open' => ['critical' => $counts['critical'] ?? 0, 'warn' => $counts['warn'] ?? 0, 'info' => $counts['info'] ?? 0, 'malware' => (int) ($malware[$p->id] ?? 0)],
                'last_sweep' => $lastSweep ? ['id' => $lastSweep->id, 'status' => $lastSweep->status, 'profile' => $lastSweep->profile, 'finished_at' => $lastSweep->finished_at?->toIso8601String(), 'stop_reason' => $lastSweep->stop_reason] : null,
                'last_complete_at' => $lastComplete?->finished_at?->toIso8601String(),
                'coverage_age_hours' => $lastComplete?->finished_at ? round($lastComplete->finished_at->diffInMinutes(now()) / 60, 1) : null,
                'last_run' => $lastRun ? ['id' => $lastRun->id, 'status' => $lastRun->status, 'error_code' => $lastRun->error_code, 'finished_at' => $lastRun->finished_at?->toIso8601String()] : null,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function inventory(Request $request, int $platform): JsonResponse
    {
        $this->ensureView($request);
        $this->abortUnlessInScope($request, $platform);
        $market = Platform::query()->findOrFail($platform);

        $snapshots = DbScanSnapshot::query()->where('platform_id', $platform)->latest('id')->limit(20)->get();
        $latest = $snapshots->first();

        $timeline = [];
        $ordered = $snapshots->reverse()->values();
        for ($i = 1; $i < $ordered->count(); $i++) {
            $before = (array) $ordered[$i - 1]->components;
            $after = (array) $ordered[$i]->components;
            $changes = [];
            $adminsBefore = array_column($before['administrators']['data']['admins'] ?? [], 'login', 'id');
            $adminsAfter = array_column($after['administrators']['data']['admins'] ?? [], 'login', 'id');
            foreach (array_diff_key($adminsAfter, $adminsBefore) as $login) {
                $changes[] = 'Administrator added: '.$login;
            }
            foreach (array_diff_key($adminsBefore, $adminsAfter) as $login) {
                $changes[] = 'Administrator removed: '.$login;
            }
            foreach (array_diff($after['core_options']['data']['active_plugins'] ?? [], $before['core_options']['data']['active_plugins'] ?? []) as $plugin) {
                $changes[] = 'Plugin activated: '.$plugin;
            }
            foreach (array_diff($before['core_options']['data']['active_plugins'] ?? [], $after['core_options']['data']['active_plugins'] ?? []) as $plugin) {
                $changes[] = 'Plugin deactivated: '.$plugin;
            }
            if (($before['core_options']['data']['stylesheet'] ?? null) !== ($after['core_options']['data']['stylesheet'] ?? null)) {
                $changes[] = 'Theme changed to '.($after['core_options']['data']['stylesheet'] ?? '?');
            }
            if ($changes !== []) {
                $timeline[] = ['taken_at' => $ordered[$i]->taken_at?->toIso8601String(), 'run_id' => $ordered[$i]->market_run_id, 'changes' => $changes];
            }
        }

        $components = (array) ($latest?->components ?? []);
        $core = (array) ($components['core_options']['data'] ?? []);
        unset($core['admin_email_token']);
        $cron = $core['cron'] ?? null;
        if (is_array($cron)) {
            arsort($cron['hooks']);
            $core['cron'] = ['events' => $cron['events'], 'overdue' => $cron['overdue'], 'hooks' => array_slice($cron['hooks'], 0, 40, true), 'hook_count' => count($cron['hooks'])];
        }
        $tables = (array) ($components['tables']['data']['tables'] ?? []);
        uasort($tables, fn ($a, $b) => (int) ($b['bytes'] ?? 0) <=> (int) ($a['bytes'] ?? 0));

        return response()->json([
            'market' => ['id' => $market->id, 'name' => $market->name, 'domain' => $market->domain],
            'snapshot' => $latest ? [
                'id' => $latest->id,
                'taken_at' => $latest->taken_at?->toIso8601String(),
                'profile' => $latest->profile,
                'run_id' => $latest->market_run_id,
                'completeness' => collect($components)->map(fn ($c) => (bool) ($c['complete'] ?? false))->all(),
                'administrators' => $components['administrators']['data']['admins'] ?? [],
                'hidden_capabilities' => count($components['administrators']['data']['hidden'] ?? []),
                'core' => $core,
                'triggers' => $components['triggers'] ?? null,
                'events_routines' => $components['events_routines'] ?? null,
                'autoload' => $components['autoload']['data'] ?? null,
                'tables' => array_slice($tables, 0, 25, true),
                'table_count' => count($tables),
                'outbound_domains' => array_slice((array) ($components['outbound_domains']['data'] ?? []), 0, 100),
            ] : null,
            'timeline' => array_reverse($timeline),
        ]);
    }

    public function passes(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $ids = $this->scopeIds($request);
        $names = $this->marketNames($ids);

        $query = DbScanPass::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('mode'), fn ($q) => $q->where('mode', $request->string('mode')), fn ($q) => $q->where('mode', '!=', 'preflight'))
            ->when($request->filled('trigger'), fn ($q) => $q->where('trigger', $request->string('trigger')))
            ->when(is_array($ids), fn ($q) => $q->whereIn('id', DbScanMarketRun::query()->whereIn('platform_id', $ids ?: [0])->select('pass_id')))
            ->when($request->filled('platform_id'), fn ($q) => $q->whereIn('id', DbScanMarketRun::query()->where('platform_id', $request->integer('platform_id'))->select('pass_id')))
            ->latest('id');

        $page = $query->paginate($this->perPage($request));
        $data = collect($page->items())->map(fn ($pass) => $this->presenter->pass(
            $pass,
            $this->scoped(DbScanMarketRun::query()->where('pass_id', $pass->id), $request)->get(),
            $names
        ))->all();

        return response()->json($this->paginated($page, $data));
    }

    public function pass(Request $request, int $pass): JsonResponse
    {
        $this->ensureView($request);
        $model = $this->scopedPass($request, $pass);
        $names = $this->marketNames($this->scopeIds($request));
        $runs = $this->scoped(DbScanMarketRun::query()->where('pass_id', $model->id), $request)->orderBy('id')->get();

        return response()->json(['pass' => $this->presenter->pass($model, $runs, $names)]);
    }

    public function run(Request $request, int $run): JsonResponse
    {
        $this->ensureView($request);
        $model = $this->scopedRun($request, $run);
        $names = $this->marketNames([(int) $model->platform_id]);

        return response()->json([
            'run' => $this->presenter->run($model, $names),
            'pass' => $model->pass ? ['id' => $model->pass->id, 'status' => $model->pass->status, 'trigger' => $model->pass->trigger, 'mode' => $model->pass->mode] : null,
            'sweep' => $this->presenter->sweep($model->sweep_id ? DbScanSweep::query()->find($model->sweep_id) : null),
        ]);
    }

    public function events(Request $request, int $run): JsonResponse
    {
        $this->ensureView($request);
        $model = $this->scopedRun($request, $run);
        $limit = max(1, min(500, (int) $request->integer('limit', 200)));

        $events = DbScanEvent::query()
            ->where('market_run_id', $model->id)
            ->when($request->filled('after_id'), fn ($q) => $q->where('id', '>', $request->integer('after_id')))
            ->when($request->filled('level'), fn ($q) => $q->whereIn('level', explode(',', (string) $request->string('level'))))
            ->when($request->filled('rule_key'), fn ($q) => $q->where('rule_key', $request->string('rule_key')))
            ->when($request->filled('surface'), fn ($q) => $q->where('surface', $request->string('surface')))
            ->orderBy($request->filled('after_id') ? 'id' : 'id', $request->filled('after_id') ? 'asc' : 'desc')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->values()
            ->map(fn (DbScanEvent $e) => [
                'id' => $e->id,
                'at' => $e->at?->format('Y-m-d\TH:i:s.vP'),
                'level' => $e->level,
                'rule_key' => $e->rule_key,
                'surface' => $e->surface,
                'message' => $e->message,
                'context' => $e->context,
            ]);

        return response()->json([
            'events' => $events,
            'run' => ['status' => $model->status, 'progress' => $this->presenter->progress((array) ($model->cursor ?? []))['fraction'], 'terminal' => $model->isTerminal()],
        ]);
    }

    public function coverage(Request $request, int $run): JsonResponse
    {
        $this->ensureView($request);
        $model = $this->scopedRun($request, $run);

        $rows = DbScanSurfaceCoverage::query()->where('market_run_id', $model->id)->orderBy('surface_key')->get();
        $surfaces = $rows->map(fn ($c) => [
            'surface_key' => $c->surface_key,
            'title' => $this->surfaces->get($c->surface_key)?->title,
            'kind' => $this->surfaces->get($c->surface_key)?->kind,
            'status' => $c->status,
            'reason' => $c->reason,
            'rows_scanned' => (int) $c->rows_scanned,
            'candidates' => (int) $c->candidates,
            'bytes_read' => (int) $c->bytes_read,
            'values_truncated' => (int) $c->values_truncated,
            'decode_capped' => (int) $c->decode_capped,
            'excluded_values' => (int) $c->excluded_values,
            'cursor_start' => $c->cursor_start,
            'cursor_end' => $c->cursor_end,
            'high_water' => $c->high_water,
            'completed_at' => $c->completed_at?->toIso8601String(),
        ]);

        $counts = $rows->countBy('status');
        $flags = (array) ($model->cursor['rule_flags'] ?? []);

        return response()->json([
            'complete' => (int) ($counts['complete'] ?? 0),
            'not_applicable' => (int) ($counts['not_applicable'] ?? 0),
            'incomplete' => (int) ($counts['incomplete'] ?? 0),
            'excluded' => (int) ($counts['excluded'] ?? 0),
            'surfaces' => $surfaces,
            'rule_flags' => $flags,
            'rules' => DbScanRuleCoverage::query()->where('market_run_id', $model->id)->orderBy('rule_key')->get()
                ->map(fn ($r) => ['rule_key' => $r->rule_key, 'surface_key' => $r->surface_key, 'status' => $r->status, 'reason' => $r->reason, 'matches' => (int) $r->matches])
                ->values(),
            'time_spanning' => $model->sweep_id ? DbScanMarketRun::query()->where('sweep_id', $model->sweep_id)->count() > 1 : false,
            'statement' => $this->coverageStatement($model, $counts->all()).' Coverage is database-only; filesystem and live responses were not inspected.',
            'coverage_scope' => ['scope' => 'database', 'filesystem_inspected' => false, 'live_responses_inspected' => false],
            'scope_gaps' => [['surface_key' => 'filesystem.visibility', 'status' => 'excluded', 'reason' => 'filesystem_not_inspected']],
        ]);
    }

    public function audit(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $ids = $this->scopeIds($request);

        $query = DbScanAuditEvent::query()
            ->when(is_array($ids), fn ($q) => $q->whereIn('platform_id', $ids ?: [0]))
            ->when($request->filled('entity'), fn ($q) => $q->where('entity', $request->string('entity')))
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest('id');
        $page = $query->paginate($this->perPage($request, 50));
        $users = \App\Models\User::query()->whereIn('id', collect($page->items())->pluck('actor_id')->filter())->pluck('name', 'id');

        return response()->json($this->paginated($page, collect($page->items())->map(fn ($e) => [
            'id' => $e->id,
            'actor' => $e->actor_id ? ($users[$e->actor_id] ?? 'User #'.$e->actor_id) : 'System',
            'actor_type' => $e->actor_type,
            'scope_key' => $e->scope_key,
            'entity' => $e->entity,
            'entity_id' => $e->entity_id,
            'action' => $e->action,
            'before' => $e->before,
            'after' => $e->after,
            'created_at' => $e->created_at?->toIso8601String(),
        ])->all()));
    }

    private function coverageStatement(DbScanMarketRun $run, array $counts): string
    {
        $incomplete = (int) ($counts['incomplete'] ?? 0);
        if (! $run->isTerminal()) {
            return 'Scan in progress — coverage is not final.';
        }
        if (in_array($run->status, ['stopped', 'failed', 'unreachable', 'partial'], true)) {
            return 'This run did not finish its traversal; absence of findings here proves nothing.';
        }
        if ($incomplete > 0) {
            return sprintf('No conclusion for %d incomplete surface(s). Findings elsewhere are reported; review coverage before treating this market as clear.', $incomplete);
        }

        return 'No database indicators found beyond the listed findings in the scanned surfaces. This does not establish that the site is malware-free (files and live responses are not scanned).';
    }
}
