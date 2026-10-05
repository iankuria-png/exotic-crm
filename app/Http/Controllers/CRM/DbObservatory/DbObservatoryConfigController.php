<?php

namespace App\Http\Controllers\CRM\DbObservatory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CRM\DbObservatory\Concerns\ScopesObservatory;
use App\Models\DbScanConnection;
use App\Models\DbScanList;
use App\Models\DbScanOccurrence;
use App\Models\DbScanRule;
use App\Models\DbScanRuleOverride;
use App\Models\DbScanSchedule;
use App\Models\DbScanSetting;
use App\Models\Platform;
use App\Services\DbScanner\DbScanAuditWriter;
use App\Services\DbScanner\Engine\HostBusyException;
use App\Services\DbScanner\Engine\MarketBusyException;
use App\Services\DbScanner\Engine\PassController;
use App\Services\DbScanner\Engine\Preflight;
use App\Services\DbScanner\ObservatoryPresenter;
use App\Services\DbScanner\Reader\ScannerCredentialResolver;
use App\Services\DbScanner\ScannerSettings;
use App\Support\DbScannerPermissions;
use Cron\CronExpression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DbObservatoryConfigController extends Controller
{
    use ScopesObservatory;

    public function __construct(
        private readonly DbScanAuditWriter $audit,
        private readonly ScannerSettings $settings,
        private readonly ObservatoryPresenter $presenter,
    ) {}

    // ------------------------------------------------------------------
    // Rules and lists
    // ------------------------------------------------------------------

    public function rules(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $overrides = DbScanRuleOverride::query()->get()->groupBy('rule_key');
        $names = $this->marketNames();

        $rules = DbScanRule::query()->where('retired', false)->orderBy('category')->orderBy('key')->get()->map(function (DbScanRule $rule) use ($overrides, $names, $request) {
            $definition = (array) $rule->definition;

            return [
                'key' => $rule->key,
                'title' => $rule->title,
                'pack' => $rule->pack,
                'pack_version' => $rule->pack_version,
                'category' => $rule->category,
                'kind' => $rule->kind,
                'surfaces' => $rule->surfaces,
                'profiles' => $rule->profiles,
                'enabled' => (bool) $rule->enabled,
                'default_severity' => $rule->default_severity,
                'default_confidence' => $rule->default_confidence,
                'thresholds' => (object) ($definition['thresholds'] ?? []),
                'lists' => $definition['lists'] ?? [],
                'why' => $rule->why,
                'remediation' => $rule->remediation,
                'references' => $rule->references,
                'version_hash' => substr((string) $rule->definition_hash, 0, 12),
                'overrides' => collect($overrides->get($rule->key, []))
                    ->filter(fn ($o) => $o->scope_key === 'network' || $this->inScope($request, (int) $o->platform_id))
                    ->map(fn ($o) => [
                        'id' => $o->id,
                        'scope_key' => $o->scope_key,
                        'platform_id' => $o->platform_id,
                        'market' => $o->platform_id ? ($names[$o->platform_id] ?? null) : 'Network',
                        'enabled' => $o->enabled,
                        'severity' => $o->severity,
                        'thresholds' => $o->thresholds,
                        'disabled_lists' => $o->disabled_lists,
                        'revision' => $o->revision,
                    ])->values(),
            ];
        });

        return response()->json(['data' => $rules, 'permissions' => DbScannerPermissions::capabilities($request->user())]);
    }

    /**
     * Phase 1 edits: enabled, severity, bounded thresholds and disabled lists,
     * network-wide or per market. No patterns, rule bodies or class names.
     */
    public function updateRule(Request $request, string $rule): JsonResponse
    {
        $this->ensureConfigure($request);
        $model = DbScanRule::query()->where('key', $rule)->where('retired', false)->firstOrFail();
        $definition = (array) $model->definition;

        $data = $request->validate([
            'platform_id' => ['nullable', 'integer', Rule::exists('platforms', 'id')],
            'enabled' => ['nullable', 'boolean'],
            'severity' => ['nullable', Rule::in(['critical', 'warn', 'info'])],
            'thresholds' => ['nullable', 'array'],
            'disabled_lists' => ['nullable', 'array'],
            'disabled_lists.*' => ['string', Rule::in((array) ($definition['lists'] ?? []))],
            'revision' => ['nullable', 'integer'],
            'reset' => ['sometimes', 'boolean'],
        ]);

        $defaults = (array) ($definition['thresholds'] ?? []);
        foreach ((array) ($data['thresholds'] ?? []) as $name => $value) {
            if (! array_key_exists($name, $defaults)) {
                throw ValidationException::withMessages(['thresholds.'.$name => 'This rule has no threshold called '.$name.'.']);
            }
            $default = (float) $defaults[$name];
            if (! is_numeric($value) || (float) $value < 0 || (float) $value > max(10, $default * 10)) {
                throw ValidationException::withMessages(['thresholds.'.$name => 'Must be between 0 and '.max(10, $default * 10).'.']);
            }
        }

        $scopeKey = ! empty($data['platform_id']) ? 'platform:'.$data['platform_id'] : 'network';

        $result = DB::transaction(function () use ($request, $model, $data, $scopeKey) {
            $override = DbScanRuleOverride::query()->where('rule_key', $model->key)->where('scope_key', $scopeKey)->lockForUpdate()->first();
            if ($override && isset($data['revision']) && (int) $data['revision'] !== (int) $override->revision) {
                abort(409, 'This rule was changed by someone else; reload and try again.');
            }
            $before = $override?->only(['enabled', 'severity', 'thresholds', 'disabled_lists']);

            if ($data['reset'] ?? false) {
                $override?->delete();
                $this->audit->record((int) $request->user()->id, 'rule', $model->key, 'reset_override', $before, null, $data['platform_id'] ?? null);

                return null;
            }

            $override ??= new DbScanRuleOverride(['rule_key' => $model->key, 'scope_key' => $scopeKey, 'platform_id' => $data['platform_id'] ?? null, 'revision' => 0]);
            foreach (['enabled', 'severity', 'disabled_lists'] as $field) {
                if (array_key_exists($field, $data)) {
                    $override->{$field} = $data[$field];
                }
            }
            if (array_key_exists('thresholds', $data)) {
                $override->thresholds = $data['thresholds'] ? array_map(fn ($v) => $v + 0, $data['thresholds']) : null;
            }
            $override->revision = (int) $override->revision + 1;
            $override->updated_by = $request->user()->id;
            $override->save();

            $this->audit->record((int) $request->user()->id, 'rule', $model->key, 'override', $before, $override->only(['enabled', 'severity', 'thresholds', 'disabled_lists', 'scope_key']), $data['platform_id'] ?? null);

            return $override;
        });

        return response()->json(['override' => $result, 'note' => 'Applies to new runs; running scans keep their configuration snapshot.']);
    }

    public function lists(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $names = $this->marketNames();

        $lists = DbScanList::query()->orderBy('key')->orderBy('scope_key')->get()
            ->filter(fn ($l) => $l->scope_key === 'network' || $this->inScope($request, (int) $l->platform_id))
            ->map(fn (DbScanList $l) => [
                'id' => $l->id,
                'key' => $l->key,
                'kind' => $l->kind,
                'description' => $l->description,
                'scope_key' => $l->scope_key,
                'platform_id' => $l->platform_id,
                'market' => $l->platform_id ? ($names[$l->platform_id] ?? null) : 'Network',
                'entries' => array_values((array) $l->entries),
                'revision' => $l->revision,
                'updated_at' => $l->updated_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $lists]);
    }

    public function updateList(Request $request, string $list): JsonResponse
    {
        $this->ensureConfigure($request);
        $network = DbScanList::query()->where('key', $list)->where('scope_key', 'network')->firstOrFail();

        $data = $request->validate([
            'platform_id' => ['nullable', 'integer', Rule::exists('platforms', 'id')],
            'entries' => ['present', 'array', 'max:2000'],
            'entries.*.value' => ['required', 'string', 'max:255'],
            'entries.*.note' => ['nullable', 'string', 'max:255'],
            'entries.*.expires_at' => ['nullable', 'date'],
            'revision' => ['nullable', 'integer'],
        ]);

        $scopeKey = ! empty($data['platform_id']) ? 'platform:'.$data['platform_id'] : 'network';

        $model = DB::transaction(function () use ($request, $network, $data, $scopeKey, $list) {
            $model = DbScanList::query()->where('key', $list)->where('scope_key', $scopeKey)->lockForUpdate()->first();
            if ($model && isset($data['revision']) && (int) $data['revision'] !== (int) $model->revision) {
                abort(409, 'This list was changed by someone else; reload and try again.');
            }
            $before = $model ? ['entries' => count((array) $model->entries)] : null;
            $existing = collect((array) ($model?->entries ?? []))->keyBy('value');

            $entries = collect($data['entries'])->map(function ($entry) use ($existing, $request) {
                $value = trim((string) $entry['value']);
                $previous = $existing->get($value);

                return [
                    'value' => $value,
                    'note' => isset($entry['note']) ? mb_substr((string) $entry['note'], 0, 255) : ($previous['note'] ?? null),
                    'expires_at' => $entry['expires_at'] ?? null,
                    'added_by' => $previous['added_by'] ?? $request->user()->id,
                    'added_at' => $previous['added_at'] ?? now()->toIso8601String(),
                ];
            })->unique('value')->values()->all();

            $model ??= new DbScanList(['key' => $list, 'scope_key' => $scopeKey, 'platform_id' => $data['platform_id'] ?? null, 'kind' => $network->kind, 'description' => $network->description, 'revision' => 0]);
            $model->entries = $entries;
            $model->revision = (int) $model->revision + 1;
            $model->updated_by = $request->user()->id;
            $model->save();

            $added = array_values(array_diff(array_column($entries, 'value'), $existing->keys()->all()));
            $removed = array_values(array_diff($existing->keys()->all(), array_column($entries, 'value')));
            $this->audit->record((int) $request->user()->id, 'list', $list, 'update', $before, [
                'scope' => $scopeKey, 'entries' => count($entries), 'added' => array_slice($added, 0, 50), 'removed' => array_slice($removed, 0, 50),
            ], $data['platform_id'] ?? null);

            return $model;
        });

        return response()->json(['id' => $model->id, 'revision' => $model->revision, 'entries' => count((array) $model->entries)]);
    }

    // ------------------------------------------------------------------
    // Schedules
    // ------------------------------------------------------------------

    public function schedules(Request $request): JsonResponse
    {
        $this->ensureView($request);

        return response()->json(['data' => DbScanSchedule::query()->orderBy('id')->get()->map(fn (DbScanSchedule $s) => $this->schedulePayload($s))->values()]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $this->ensureConfigure($request);
        $data = $this->validateSchedule($request, true);

        $schedule = DB::transaction(function () use ($request, $data) {
            $schedule = DbScanSchedule::query()->create($data + ['revision' => 1, 'created_by' => $request->user()->id]);
            $this->audit->record((int) $request->user()->id, 'schedule', $schedule->id, 'create', null, $schedule->only(['name', 'profile', 'cron', 'market_scope', 'window', 'enabled']));

            return $schedule;
        });

        return response()->json($this->schedulePayload($schedule), 201);
    }

    public function updateSchedule(Request $request, int $schedule): JsonResponse
    {
        $this->ensureConfigure($request);
        $data = $this->validateSchedule($request, false);

        $model = DB::transaction(function () use ($request, $schedule, $data) {
            $model = DbScanSchedule::query()->whereKey($schedule)->lockForUpdate()->firstOrFail();
            if (isset($data['revision']) && (int) $data['revision'] !== (int) $model->revision) {
                abort(409, 'This schedule was changed by someone else; reload and try again.');
            }
            unset($data['revision']);
            $before = $model->only(['name', 'profile', 'cron', 'market_scope', 'window', 'enabled']);
            $model->fill($data);

            // Timing/scope changes cancel undispatched occurrences of the old revision.
            if ($model->isDirty(['cron', 'market_scope', 'window', 'profile'])) {
                $model->revision = (int) $model->revision + 1;
                DbScanOccurrence::query()->where('schedule_id', $model->id)->where('state', 'pending')->update(['state' => 'cancelled', 'skip_reason' => 'schedule revised', 'updated_at' => now()]);
            }
            if ($model->isDirty('enabled')) {
                $model->disabled_at = $model->enabled ? null : now();
            }
            $model->save();
            $this->audit->record((int) $request->user()->id, 'schedule', $model->id, 'update', $before, $model->only(['name', 'profile', 'cron', 'market_scope', 'window', 'enabled']));

            return $model;
        });

        return response()->json($this->schedulePayload($model));
    }

    /**
     * DELETE disables future dispatch and keeps history.
     */
    public function destroySchedule(Request $request, int $schedule): JsonResponse
    {
        $this->ensureConfigure($request);

        $model = DB::transaction(function () use ($request, $schedule) {
            $model = DbScanSchedule::query()->whereKey($schedule)->lockForUpdate()->firstOrFail();
            $model->forceFill(['enabled' => false, 'disabled_at' => now()])->save();
            DbScanOccurrence::query()->where('schedule_id', $model->id)->where('state', 'pending')->update(['state' => 'cancelled', 'skip_reason' => 'schedule disabled', 'updated_at' => now()]);
            $this->audit->record((int) $request->user()->id, 'schedule', $model->id, 'disable', ['enabled' => true], ['enabled' => false]);

            return $model;
        });

        return response()->json($this->schedulePayload($model));
    }

    // ------------------------------------------------------------------
    // Settings and limits
    // ------------------------------------------------------------------

    public function settings(Request $request): JsonResponse
    {
        $this->ensureView($request);
        $row = $this->settings->row(true);

        return response()->json([
            'deployment_enabled' => $this->settings->deploymentEnabled(),
            'enabled' => (bool) $row->enabled,
            'paused' => (bool) $row->paused,
            'emergency_stop' => (bool) $row->emergency_stop,
            'revision' => (int) $row->revision,
            'limits' => $this->settings->describe(),
            'gates' => [
                'require_ops_state' => (bool) config('db_scanner.gates.require_ops_state', true),
                'require_market_health' => (bool) config('db_scanner.gates.require_market_health', true),
                'max_load_level' => (int) config('db_scanner.gates.max_load_level', 0),
            ],
            'health' => $this->presenter->health($this->scopeIds($request)),
            'permissions' => DbScannerPermissions::capabilities($request->user()),
        ]);
    }

    public function updateSettings(Request $request, PassController $passes): JsonResponse
    {
        $this->ensureConfigure($request);
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'paused' => ['sometimes', 'boolean'],
            'emergency_stop' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'revision' => ['required', 'integer'],
        ]);
        $limits = isset($data['limits']) ? $this->settings->validateLimits($data['limits']) : null;

        $resumeGlobal = false;
        $row = DB::transaction(function () use ($request, $data, $limits, &$resumeGlobal) {
            $row = DbScanSetting::query()->whereKey($this->settings->row(true)->id)->lockForUpdate()->firstOrFail();
            if ((int) $data['revision'] !== (int) $row->revision) {
                abort(409, 'Scanner settings were changed by someone else; reload and try again.');
            }
            $before = $row->only(['enabled', 'paused', 'emergency_stop', 'limits']);
            $wasHalted = ! $row->enabled || $row->paused;

            foreach (['enabled', 'paused', 'emergency_stop'] as $field) {
                if (array_key_exists($field, $data)) {
                    $row->{$field} = (bool) $data[$field];
                }
            }
            if ($limits !== null) {
                $row->limits = array_replace_recursive((array) $row->limits, $limits);
            }
            $row->revision = (int) $row->revision + 1;
            $row->updated_by = $request->user()->id;
            $row->save();

            $resumeGlobal = $wasHalted && $row->enabled && ! $row->paused && ! $row->emergency_stop;
            $this->audit->record((int) $request->user()->id, 'settings', $row->id, 'update', $before, $row->only(['enabled', 'paused', 'emergency_stop', 'limits']));

            return $row;
        });

        if ($resumeGlobal) {
            $passes->resumeGloballyPaused();
        }
        $this->settings->row(true);

        return $this->settings($request);
    }

    // ------------------------------------------------------------------
    // Reader connections (admin only; secrets are write-only)
    // ------------------------------------------------------------------

    public function connections(Request $request): JsonResponse
    {
        $this->ensureConfigure($request);
        $connections = DbScanConnection::query()->get()->keyBy('platform_id');

        $data = Platform::query()->orderBy('name')->get(['id', 'name', 'domain', 'db_host', 'db_name', 'db_prefix', 'db_user'])->map(function (Platform $p) use ($connections) {
            $c = $connections->get($p->id);

            return [
                'platform_id' => $p->id,
                'market' => $p->name,
                'domain' => $p->domain,
                'suggested' => ['host' => $p->db_host, 'database' => $p->db_name, 'prefix' => $p->db_prefix ?: 'wp_', 'site_login_available' => (bool) $p->db_user],
                'connection' => $c ? [
                    'id' => $c->id,
                    'driver' => $c->driver,
                    'credential_source' => $c->credential_source ?: 'dedicated',
                    'host' => $c->host,
                    'port' => $c->port,
                    'socket' => $c->socket,
                    'database' => $c->database,
                    'prefix' => $c->prefix,
                    'tls_mode' => $c->tls_mode,
                    'tls_ca_configured' => (bool) $c->tls_ca,
                    'host_group' => $c->host_group,
                    'username_configured' => (bool) $c->username,
                    'password_configured' => (bool) $c->password,
                    'config_version' => $c->config_version,
                    'enabled' => (bool) $c->enabled,
                    'load_gate_enabled' => $c->load_gate_enabled !== false,
                    'preflight_status' => $c->preflightValid() ? 'passed' : ($c->preflight_status === 'passed' ? 'stale' : $c->preflight_status),
                    'preflight_at' => $c->preflight_at?->toIso8601String(),
                    'preflight_error_code' => $c->preflight_error_code,
                    'preflight_error' => $c->preflight_error,
                    'capabilities' => $c->capabilities,
                    'revision' => $c->revision,
                ] : null,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function updateConnection(Request $request, int $platform): JsonResponse
    {
        $this->ensureConfigure($request);
        $market = Platform::query()->findOrFail($platform);
        $existing = DbScanConnection::query()->where('platform_id', $platform)->first();

        $source = $request->input('credential_source', $existing?->credential_source ?: 'dedicated');
        if ($source === 'site_login') {
            $request->merge([
                'host' => $market->db_host, 'database' => $market->db_name,
                'prefix' => $market->db_prefix ?: 'wp_', 'socket' => null, 'port' => 3306,
                'username' => null, 'password' => null, 'host_group' => null,
            ]);
        }
        $data = $request->validate([
            'credential_source' => ['sometimes', Rule::in(['dedicated', 'site_login'])],
            'site_login_acknowledged' => $source === 'site_login' ? ['required', 'accepted'] : ['exclude'],
            'host' => ['nullable', 'string', 'max:191', 'regex:/^[A-Za-z0-9.\-:\[\]]+$/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'socket' => ['nullable', 'string', 'max:255', 'regex:/^\/[A-Za-z0-9._\/\- ]+$/'],
            'database' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-$]+$/'],
            'prefix' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'],
            'username' => [($existing && $existing->credential_source !== 'site_login') || $source === 'site_login' ? 'nullable' : 'required', 'string', 'max:80'],
            'password' => [($existing && $existing->credential_source !== 'site_login') || $source === 'site_login' ? 'nullable' : 'required', 'string', 'max:200'],
            'tls_mode' => ['required', Rule::in(['none', 'verify'])],
            'tls_ca' => ['nullable', 'string', 'max:20000'],
            'host_group' => ['nullable', 'string', 'max:120'],
            'enabled' => ['sometimes', 'boolean'],
            'load_gate_enabled' => ['sometimes', 'boolean'],
            'revision' => ['nullable', 'integer'],
        ]);
        $data['credential_source'] = $source;
        if ($source === 'site_login' && (! $market->db_user || ! $market->db_pass)) {
            throw ValidationException::withMessages(['credential_source' => 'Add this market’s site database login in its Market Profile first.']);
        }
        if (empty($data['host']) && empty($data['socket'])) {
            throw ValidationException::withMessages(['host' => 'Provide a host or a local socket.']);
        }
        $local = ! empty($data['socket']) || in_array(strtolower((string) ($data['host'] ?? '')), ['localhost', '127.0.0.1', '::1'], true);
        if (! $local && $data['tls_mode'] !== 'verify') {
            throw ValidationException::withMessages(['tls_mode' => 'Remote scanner connections require verified TLS.']);
        }

        $model = DB::transaction(function () use ($request, $platform, $data) {
            $model = DbScanConnection::query()->where('platform_id', $platform)->lockForUpdate()->first();
            if ($model && isset($data['revision']) && (int) $data['revision'] !== (int) $model->revision) {
                abort(409, 'This connection was changed by someone else; reload and try again.');
            }
            $before = $model?->only(['host', 'port', 'socket', 'database', 'prefix', 'tls_mode', 'host_group', 'enabled', 'load_gate_enabled', 'credential_source', 'config_version']);

            $model ??= new DbScanConnection(['platform_id' => $platform, 'driver' => 'mysql', 'config_version' => 0, 'revision' => 0, 'preflight_status' => 'never']);
            $credentialFields = ['host', 'port', 'socket', 'database', 'prefix', 'tls_mode', 'credential_source'];
            $changed = false;
            foreach ($credentialFields as $field) {
                $value = $data[$field] ?? ($field === 'port' ? 3306 : null);
                if ($model->{$field} != $value) {
                    $changed = true;
                }
                $model->{$field} = $value;
            }
            if ($data['credential_source'] === 'site_login') {
                $model->username = '';
                $model->password = null;
            }
            if (! empty($data['username'])) {
                $changed = $changed || $model->username !== $data['username'];
                $model->username = $data['username'];
            }
            if (! empty($data['password'])) {
                $changed = true;
                $model->password = $data['password'];
            }
            if (array_key_exists('tls_ca', $data) && $data['tls_ca'] !== null) {
                $changed = true;
                $model->tls_ca = $data['tls_ca'] ?: null;
            }
            $model->host_group = ScannerCredentialResolver::normalizeHostGroup($data['host_group'] ?? null, $model->host, $model->socket);
            if (array_key_exists('enabled', $data)) {
                $model->enabled = (bool) $data['enabled'];
            }

            if (array_key_exists('load_gate_enabled', $data)) {
                $model->load_gate_enabled = (bool) $data['load_gate_enabled'];
            }

            // Rotation creates a new configuration version and voids preflight proof.
            if ($changed || ! $model->exists) {
                $model->config_version = (int) $model->config_version + 1;
                $model->preflight_status = 'never';
                $model->preflight_config_version = null;
                $model->preflight_credential_fingerprint = null;
            }
            $model->revision = (int) $model->revision + 1;
            $model->updated_by = $request->user()->id;
            $model->save();

            $this->audit->record((int) $request->user()->id, 'connection', $model->id, $changed ? 'rotate' : 'update', $before, $model->only(['host', 'port', 'socket', 'database', 'prefix', 'tls_mode', 'host_group', 'enabled', 'load_gate_enabled', 'credential_source', 'config_version']) + ['credentials_changed' => $changed], $platform);

            return $model;
        });

        return response()->json([
            'platform_id' => $platform,
            'credential_source' => $model->credential_source,
            'config_version' => $model->config_version,
            'preflight_status' => $model->preflightValid() ? 'passed' : $model->preflight_status,
            'host_group' => $model->host_group,
            'enabled' => (bool) $model->enabled,
            'load_gate_enabled' => $model->load_gate_enabled !== false,
            'revision' => $model->revision,
        ]);
    }

    public function preflight(Request $request, int $platform, Preflight $preflight): JsonResponse
    {
        $this->ensureConfigure($request);
        $data = $request->validate(['override_reason' => ['nullable', 'string', 'min:10', 'max:500']]);
        $override = isset($data['override_reason'])
            ? \App\Services\DbScanner\Engine\LoadOverride::issue($platform, (int) $request->user()->id, 'preflight', $data['override_reason'])
            : null;
        $connection = DbScanConnection::query()->where('platform_id', $platform)->firstOrFail();

        try {
            $result = $preflight->run($connection, (int) $request->user()->id, $override);
        } catch (MarketBusyException) {
            return response()->json(['message' => 'This market has an active scan; preflight waits until it ends.'], 409);
        } catch (HostBusyException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        $status = match ($result['status']) {
            'passed' => 200,
            'blocked' => 423,
            default => 422,
        };

        return response()->json($result, $status);
    }

    private function validateSchedule(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:80'],
            'profile' => [$creating ? 'required' : 'sometimes', Rule::in(['quick', 'standard', 'deep'])],
            'cron' => [$creating ? 'required' : 'sometimes', 'string', 'max:60'],
            'market_scope' => [$creating ? 'required' : 'sometimes', 'array'],
            'market_scope.mode' => ['required_with:market_scope', Rule::in(['enabled_connections', 'platforms'])],
            'market_scope.platform_ids' => ['nullable', 'array'],
            'market_scope.platform_ids.*' => ['integer', Rule::exists('platforms', 'id')],
            'window' => ['nullable', 'array'],
            'window.start' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'window.end' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'enabled' => ['sometimes', 'boolean'],
            'revision' => ['nullable', 'integer'],
        ]);

        if (isset($data['cron'])) {
            if (! CronExpression::isValidExpression($data['cron'])) {
                throw ValidationException::withMessages(['cron' => 'Not a valid cron expression.']);
            }
            // At most hourly: scans are heavy and continuation handles size.
            $parts = preg_split('/\s+/', trim($data['cron']));
            if (($parts[0] ?? '*') === '*' || str_contains((string) ($parts[0] ?? ''), '/') || str_contains((string) ($parts[0] ?? ''), ',')) {
                throw ValidationException::withMessages(['cron' => 'Schedules may run at most once per hour (use a fixed minute).']);
            }
        }

        return $data;
    }

    private function schedulePayload(DbScanSchedule $s): array
    {
        $recent = DbScanOccurrence::query()->where('schedule_id', $s->id)->latest('id')->limit(10)->get();

        return [
            'id' => $s->id,
            'name' => $s->name,
            'profile' => $s->profile,
            'cron' => $s->cron,
            'market_scope' => $s->market_scope,
            'window' => $s->window,
            'enabled' => (bool) $s->enabled,
            'revision' => $s->revision,
            'next_due_at' => $s->next_due_at?->toIso8601String(),
            'last_dispatched_at' => $s->last_dispatched_at?->toIso8601String(),
            'recent_occurrences' => $recent->map(fn ($o) => [
                'platform_id' => $o->platform_id,
                'due_at' => $o->due_at_utc?->toIso8601String(),
                'state' => $o->state,
                'skip_reason' => $o->skip_reason,
                'pass_id' => $o->pass_id,
            ])->values(),
        ];
    }
}
