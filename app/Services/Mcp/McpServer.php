<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Models\ErrorLogGroup;
use App\Models\Platform;
use App\Models\User;
use App\Services\Ai\Exceptions\SqlValidationException;
use App\Services\Ai\SqlSafetyValidator;
use App\Services\Ai\SqlValidationPolicy;
use App\Services\CeoDashboardDataService;
use App\Services\ChurnAggregatorService;
use App\Services\MarketAuthorizationService;
use App\Services\Mcp\Diagnostics\PaymentFlowTraceService;
use App\Services\Mcp\Knowledge\KnowledgeSearch;
use App\Services\Mcp\Protocol\McpProtocolContext;
use App\Services\Mcp\Results\ToolResult;
use App\Services\Ops\VitalsSampler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class McpServer
{
    public function __construct(
        private readonly McpSettingsService $settings,
        private readonly ToolRegistry $registry,
        private readonly ToolResultSanitizer $sanitizer,
        private readonly PseudonymService $pseudonyms,
        private readonly McpRequestAdapter $adapter,
        private readonly CeoDashboardDataService $dashboard,
        private readonly ChurnAggregatorService $churn,
        private readonly MarketAuthorizationService $marketAuth,
        private readonly ResourceRegistry $resourcesRegistry,
        private readonly PromptRegistry $prompts,
        private readonly McpDailyBudget $budget,
        private readonly McpJsonSerializer $serializer,
        private readonly McpResultNormalizer $normalizer,
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $started = microtime(true);
        $payload = $request->json()->all();
        if (! is_array($payload) || array_is_list($payload) || ($payload['jsonrpc'] ?? null) !== '2.0' || ! isset($payload['method']) || ! is_string($payload['method']) || (isset($payload['params']) && ! is_array($payload['params'])) || (array_key_exists('id', $payload) && ! is_string($payload['id']) && ! is_int($payload['id']) && $payload['id'] !== null)) {
            return $this->error(null, -32600, 'Invalid JSON-RPC request.', 400);
        }
        $id = $payload['id'] ?? null;
        $method = (string) ($payload['method'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $user = $request->user();
        $token = $request->attributes->get('mcp_token');
        $requestId = (string) ($request->attributes->get('mcp_request_id') ?: Str::uuid());

        try {
            $context = $this->protocolContext($request, $params);
            $this->validateTransport($request, $payload, $method, $params);
            $result = $this->dispatch($request, $method, $params, $user, $token?->abilities, $context);

            if (! array_key_exists('id', $payload)) {
                $response = response('', Response::HTTP_ACCEPTED);
                $this->audit($request, $method, $params, 'success', null, [], $started, $requestId);

                return $response;
            }

            $response = $this->success($id, $method, $result, $context);
            if ($context->modern() && $token && ! $this->budget->reserve($token, (int) data_get($result, '_meta.exotic/rowCount', 0), strlen($response->getContent()))) {
                throw McpProtocolException::rpc(-32029, 'MCP daily response budget exhausted.', 'daily_budget', 429);
            }
            $this->audit($request, $method, $params, 'success', null, $response->getData(true), $started, $requestId);

            return $response;
        } catch (McpProtocolException $exception) {
            $response = $this->error($id, $exception->getCode() ?: -32600, $exception->getMessage(), $exception->httpStatus);
            $this->audit($request, $method ?: 'rpc:transport', $params, 'refused', $exception->reason, $response->getData(true), $started, $requestId);

            return $response;
        } catch (Throwable $exception) {
            report($exception);
            $response = $this->error($id, -32603, 'The MCP request could not be completed.', 500);
            $this->audit($request, $method ?: 'rpc:failure', $params, 'failed', 'internal_error', $response->getData(true), $started, $requestId);

            return $response;
        }
    }

    public function previewTool(Request $request, string $name, array $arguments, User $user): array
    {
        if (! $this->registry->available($name, $user, $this->settings)) {
            throw McpProtocolException::rpc(-32601, 'Tool is unknown, disabled, or unavailable to this account.', 'tool_disabled', 403);
        }

        $result = $this->callTool($request, ['name' => $name, 'arguments' => $arguments], $user, null);
        $text = (string) data_get($result, 'content.0.text', '{}');
        $payload = json_decode($text, true);

        return [
            'tool' => $name,
            'payload' => is_array($payload) ? $payload : ['value' => $text],
            'bytes' => strlen($text),
            'row_count' => (int) data_get($result, '_meta.exotic/rowCount', 0),
            'pii_scan' => [
                'clean' => true,
                'fields_checked' => ['name', 'phone', 'email', 'bio', 'raw entity ids', 'raw entity URLs'],
            ],
        ];
    }

    private function dispatch(Request $request, string $method, array $params, ?User $user, ?array $abilities, McpProtocolContext $context): array
    {
        if (! $user) {
            throw McpProtocolException::auth();
        }

        if ($context->modern()) {
            return $this->dispatchModern($request, $method, $params, $user, (array) $abilities);
        }

        return match ($method) {
            'server/discover' => [
                'supportedVersions' => (array) data_get($this->settings->settings(), 'protocol_versions', ['2025-06-18', '2025-03-26']),
                'capabilities' => ['tools' => ['listChanged' => true], 'resources' => ['listChanged' => false]],
                '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'exotic-crm', 'version' => '1.0.0']],
            ],
            'initialize' => [
                'protocolVersion' => $this->initializeProtocolVersion($params),
                'capabilities' => ['tools' => ['listChanged' => true], 'resources' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'exotic-crm', 'version' => '1.0.0'],
            ],
            'notifications/initialized' => [],
            'tools/list' => [
                'tools' => $this->registry->definitions($user, $this->settings, $abilities),
                '_meta' => ['exotic/resultType' => 'complete'],
            ],
            'resources/list' => [
                'resources' => $this->resources($user),
                '_meta' => ['exotic/resultType' => 'complete'],
            ],
            'resources/read' => $this->readResource($params, $user),
            'tools/call' => $this->callTool($request, $params, $user, $abilities),
            default => throw McpProtocolException::rpc(-32601, 'Method not found: '.$method, 'method_not_found', 404),
        };
    }

    private function dispatchModern(Request $request, string $method, array $params, User $user, array $abilities): array
    {
        $auth = McpAuthorizationContext::for($user, $abilities, $this->marketAuth);

        return match ($method) {
            'server/discover' => ['supportedVersions' => ['2026-07-28'], 'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['listChanged' => false], 'prompts' => ['listChanged' => false]], '_meta' => ['io.modelcontextprotocol/serverInfo' => ['name' => 'exotic-crm', 'version' => '1.0.0'], 'exotic/cacheScope' => 'private', 'exotic/ttlMs' => 30000]],
            'tools/list' => ['tools' => $this->registry->definitions($user, $this->settings, $abilities, true), '_meta' => ['exotic/resultType' => 'complete', 'exotic/cacheScope' => 'private', 'exotic/ttlMs' => 30000]],
            'resources/list' => ['resources' => $this->resourcesRegistry->list($auth, $this->settings, true), '_meta' => ['exotic/resultType' => 'complete', 'exotic/cacheScope' => 'private', 'exotic/ttlMs' => 30000]],
            'resources/read' => ['contents' => [['uri' => (string) ($params['uri'] ?? ''), 'mimeType' => 'text/markdown', 'text' => $this->sanitizer->sanitize($this->resourcesRegistry->read((string) ($params['uri'] ?? ''), $auth, $this->settings, true))]]],
            'prompts/list' => ['prompts' => $this->prompts->list($auth)],
            'prompts/get' => $this->prompts->get((string) ($params['name'] ?? ''), $auth, (array) ($params['arguments'] ?? [])),
            'tools/call' => $this->modernToolCall($params, $auth),
            default => throw McpProtocolException::rpc(-32601, 'Method not found.', 'method_not_found', 404),
        };
    }

    private function modernToolCall(array $params, McpAuthorizationContext $auth): array
    {
        $name = (string) ($params['name'] ?? '');
        $arguments = (array) ($params['arguments'] ?? []);
        if (! $this->registry->available($name, $auth->user, $this->settings, $auth->abilities)) {
            throw McpProtocolException::rpc(-32601, 'Tool not found.', 'tool_not_found', 404);
        }
        $this->validateModernArguments($name, $arguments);
        $data = match ($name) {
            'exotic_search_knowledge' => ['hits' => app(KnowledgeSearch::class)->search((string) $arguments['query'], $auth, $arguments['audience'] ?? null)],
            'exotic_get_document' => ['document' => app(ResourceRegistry::class)->read((string) $arguments['uri'], $auth, $this->settings, true)],
            'exotic_payment_flow_trace' => app(PaymentFlowTraceService::class)->trace((string) $arguments['locator'], $auth),
            'exotic_payment_failure_diagnosis' => $this->paymentDiagnosis((string) $arguments['locator'], $auth),
            'exotic_system_vitals_live' => $this->modernVitals(),
            'exotic_error_digest_live' => $this->modernErrors((int) ($arguments['limit'] ?? 10)),
            default => $this->legacyData($name, $arguments, $auth->user),
        };
        $safe = $this->sanitizer->sanitize($this->normalizer->money($this->stripUnsafeDashboardKeys($data), ['rows.*.amount', 'total', 'revenue']));
        $envelope = ToolResult::envelope(is_array($safe) ? $safe : ['value' => $safe]);
        $text = $this->serializer->encode($envelope);

        return ['content' => [['type' => 'text', 'text' => $text]], 'structuredContent' => $envelope, 'isError' => false, '_meta' => ['exotic/resultType' => 'complete', 'exotic/rowCount' => count((array) data_get($safe, 'rows', [])), 'exotic/cacheScope' => 'private', 'exotic/ttlMs' => 30000]];
    }

    private function callTool(Request $request, array $params, User $user, ?array $abilities): array
    {
        $name = (string) ($params['name'] ?? '');
        if (! $this->registry->available($name, $user, $this->settings, $abilities)) {
            throw McpProtocolException::rpc(-32601, 'Tool is unknown, disabled, or unavailable to this token.', 'tool_disabled', 404);
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $data = match ($name) {
            'exotic_catalog' => $this->catalog($user, $abilities),
            'exotic_revenue_summary' => $this->dashboardSummary($arguments, $user),
            'exotic_revenue_trend' => $this->dashboard->revenueTrend($this->adapter->build($arguments, $user)),
            'exotic_market_breakdown' => $this->dashboard->marketPie($this->adapter->build($arguments, $user)),
            'exotic_agent_performance' => $this->dashboard->agentPerformance($this->adapter->build($arguments, $user)),
            'exotic_peak_hours' => $this->dashboard->peakHours($this->adapter->build($arguments, $user)),
            'exotic_lifecycle_summary' => $this->lifecycleSummary($arguments, $user),
            'exotic_churn_analysis' => $this->churnSummary($arguments, $user),
            'exotic_cohort_retention' => ['status' => 'available', 'message' => 'Cohort retention requires the MCP reporting migration before row-level cohorts are enabled.'],
            'exotic_client_snapshot' => $this->clientSnapshot($arguments, $user),
            'exotic_error_digest' => ['status' => 'available', 'message' => 'Error digest is restricted to sanitised operational summaries.'],
            'exotic_system_vitals' => ['status' => 'available', 'message' => 'System vitals are available from the Settings health workspace.'],
            'exotic_market_health' => $this->marketHealth($arguments, $user),
            'exotic_schema_dictionary' => $this->schemaDictionary($arguments),
            'exotic_run_reporting_sql' => $this->runReportingSql($arguments, $user),
            default => throw McpProtocolException::rpc(-32601, 'Tool not found.', 'tool_not_found', 404),
        };

        $safe = $this->sanitizer->sanitize($this->stripUnsafeDashboardKeys($data));

        return [
            'content' => [['type' => 'text', 'text' => json_encode($safe, JSON_THROW_ON_ERROR)]],
            'isError' => false,
            '_meta' => [
                'exotic/resultType' => 'complete',
                'exotic/rowCount' => is_array($safe) && isset($safe['rows']) && is_array($safe['rows']) ? count($safe['rows']) : 0,
            ],
        ];
    }

    private function legacyData(string $name, array $arguments, User $user): array
    {
        return match ($name) {
            'exotic_catalog' => $this->catalog($user, null),
            'exotic_revenue_summary' => $this->dashboardSummary($arguments, $user),
            'exotic_revenue_trend' => $this->dashboard->revenueTrend($this->adapter->build($arguments, $user)),
            'exotic_market_breakdown' => $this->dashboard->marketPie($this->adapter->build($arguments, $user)),
            'exotic_agent_performance' => $this->dashboard->agentPerformance($this->adapter->build($arguments, $user)),
            'exotic_peak_hours' => $this->dashboard->peakHours($this->adapter->build($arguments, $user)),
            'exotic_lifecycle_summary' => $this->lifecycleSummary($arguments, $user),
            'exotic_churn_analysis' => $this->churnSummary($arguments, $user),
            'exotic_client_snapshot' => $this->clientSnapshot($arguments, $user),
            'exotic_market_health' => $this->marketHealth($arguments, $user),
            'exotic_schema_dictionary' => $this->schemaDictionary($arguments),
            default => throw McpProtocolException::rpc(-32601, 'Tool not found.', 'tool_not_found', 404),
        };
    }

    private function validateModernArguments(string $name, array $arguments): void
    {
        $meta = $this->registry->metadata($name) ?: [];
        $properties = (array) ($meta['properties'] ?? []);
        foreach ((array) ($meta['required'] ?? []) as $required) {
            if (! array_key_exists($required, $arguments)) {
                throw McpProtocolException::rpc(-32602, "Missing required argument: {$required}", 'invalid_params', 422);
            }
        }
        if (array_diff(array_keys($arguments), array_keys($properties))) {
            throw McpProtocolException::rpc(-32602, 'Unexpected tool argument.', 'invalid_params', 422);
        }
        foreach ($arguments as $key => $value) {
            $schema = $properties[$key];
            $type = $schema['type'] ?? null;
            if (($type === 'string' && ! is_string($value)) || ($type === 'integer' && ! is_int($value))) {
                throw McpProtocolException::rpc(-32602, "Invalid argument type: {$key}", 'invalid_params', 422);
            }
            if (isset($schema['minLength']) && mb_strlen((string) $value) < $schema['minLength']) {
                throw McpProtocolException::rpc(-32602, "Invalid argument length: {$key}", 'invalid_params', 422);
            }
            if (isset($schema['maxLength']) && mb_strlen((string) $value) > $schema['maxLength']) {
                throw McpProtocolException::rpc(-32602, "Invalid argument length: {$key}", 'invalid_params', 422);
            }
            if (isset($schema['minimum']) && $value < $schema['minimum'] || isset($schema['maximum']) && $value > $schema['maximum']) {
                throw McpProtocolException::rpc(-32602, "Invalid argument range: {$key}", 'invalid_params', 422);
            }
            if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
                throw McpProtocolException::rpc(-32602, "Invalid argument value: {$key}", 'invalid_params', 422);
            }
            if (isset($schema['pattern']) && ! preg_match('/'.$schema['pattern'].'/', (string) $value)) {
                throw McpProtocolException::rpc(-32602, "Invalid argument format: {$key}", 'invalid_params', 422);
            }
        }
    }

    private function modernVitals(): array
    {
        $sample = app(VitalsSampler::class)->latest();
        if (! $sample) {
            return ['availability' => 'unavailable', 'caveats' => ['No cached vitals sample is available.']];
        }

        return ['availability' => 'sampled', 'sampled_at' => $sample['sampled_at'] ?? null, 'signals' => collect($sample['signals'] ?? [])->map(fn ($signal) => ['key' => $signal['key'] ?? null, 'value' => $signal['value'] ?? null, 'available' => $signal['available'] ?? false, 'status' => $signal['status'] ?? 'unknown'])->all()];
    }

    private function modernErrors(int $limit): array
    {
        return ['fingerprints' => ErrorLogGroup::query()->latest('last_seen_at')->limit(min(20, max(1, $limit)))->get(['signature', 'level', 'source', 'occurrence_count', 'last_seen_at', 'resolved_at'])->map(fn ($row) => ['fingerprint' => substr(hash('sha256', $row->signature), 0, 16), 'severity' => $row->level, 'source' => $row->source, 'occurrences' => (int) $row->occurrence_count, 'last_seen_at' => optional($row->last_seen_at)->toIso8601String(), 'resolved' => $row->resolved_at !== null])->all()];
    }

    private function paymentDiagnosis(string $locator, McpAuthorizationContext $auth): array
    {
        $trace = app(PaymentFlowTraceService::class)->trace($locator, $auth);
        $stages = collect($trace['stages']);
        $provider = $stages->firstWhere('stage', 'provider')['state'] ?? 'unobserved';
        $callback = $stages->firstWhere('stage', 'callback')['state'] ?? 'unobserved';
        $classification = $provider === 'failed' ? 'provider_failure' : ($callback === 'failed' ? 'callback_failure' : (($trace['stages'][3]['state'] ?? '') === 'not_observed' ? 'activation_not_observed' : 'healthy_or_pending'));

        return ['locator' => $locator, 'classification' => $classification, 'trace' => $trace, 'recommended_next_step' => 'Use the approved payment activation runbook; this tool does not mutate payment state.'];
    }

    private function protocolContext(Request $request, array $params): McpProtocolContext
    {
        $version = (string) ($request->header('MCP-Protocol-Version') ?: data_get($params, '_meta.io.modelcontextprotocol/protocolVersion') ?: $params['protocolVersion'] ?? '2025-03-26');
        if ($version === '2026-07-28' && ! config('mcp.waves.contracts_2026')) {
            throw McpProtocolException::rpc(-32022, 'Unsupported MCP protocol version "2026-07-28". Supported versions: 2026-07-28 (when enabled), 2025-06-18, 2025-03-26.', 'unsupported_protocol', 400);
        }
        $abilities = (array) ($request->attributes->get('mcp_token')?->abilities ?? []);
        if ($version === '2026-07-28' && ! in_array('mcp:protocol:2026-07-28', $abilities, true)) {
            throw McpProtocolException::rpc(-32022, 'This token is not granted the 2026-07-28 protocol.', 'protocol_not_granted', 403);
        }

        return new McpProtocolContext($version);
    }

    private function runReportingSql(array $arguments, User $user): array
    {
        $settings = $this->settings->settings();
        if (! (bool) data_get($settings, 'sql_hatch.enabled', false)) {
            throw McpProtocolException::rpc(-32010, 'The SQL hatch is disabled.', 'tool_disabled', 403);
        }

        $policy = new SqlValidationPolicy(
            allowedViews: array_values((array) data_get($settings, 'sql_hatch.views', [])),
            defaultRowLimit: (int) data_get($settings, 'sql_hatch.default_row_limit', 50),
            maxRowLimit: (int) data_get($settings, 'sql_hatch.max_row_limit', 500),
            timeoutSeconds: (int) data_get($settings, 'sql_hatch.timeout_seconds', 10),
            forbiddenColumns: array_values((array) data_get($settings, 'sql_hatch.forbidden_columns', [])),
        );

        try {
            $validated = app(SqlSafetyValidator::class)->validateWithPolicy(
                (string) ($arguments['sql'] ?? ''),
                $this->marketAuth->resolveAccessiblePlatformIds($user),
                $policy,
            );
        } catch (SqlValidationException $exception) {
            throw McpProtocolException::rpc(-32011, $exception->getMessage(), 'sql_validation', 422);
        }

        $this->request()->attributes->set('mcp_sql_audit', [
            'hash' => hash('sha256', $validated['sql']),
            'preview' => mb_substr($validated['sql'], 0, 400),
            'scope' => $validated['scoped'] ? $this->marketAuth->resolveAccessiblePlatformIds($user) : null,
        ]);

        $connection = (string) config('ai.insights.read_connection', 'mysql_readonly');
        $rows = DB::connection($connection)->select($validated['sql']);

        return [
            'rows' => array_map(static fn ($row) => (array) $row, $rows),
            'row_count' => count($rows),
            'limit' => $validated['limit'],
            'views' => $validated['views'],
        ];
    }

    private function request(): Request
    {
        return app('request');
    }

    private function dashboardSummary(array $arguments, User $user): array
    {
        return $this->dashboard->summary($this->adapter->build($arguments, $user));
    }

    private function catalog(User $user, ?array $abilities): array
    {
        $allowed = $this->marketAuth->resolveAccessiblePlatformIds($user);
        $markets = Platform::query()
            ->when(is_array($allowed), fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('id')
            ->get(['id', 'name', 'country', 'currency_code'])
            ->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'market_name' => (string) $platform->name,
                'country' => (string) $platform->country,
                'currency' => (string) $platform->currency_code,
            ])->all();

        return [
            'tools' => array_column($this->registry->definitions($user, $this->settings, $abilities), 'name'),
            'markets' => $markets,
            'coverage' => ['from' => Client::query()->min('created_at'), 'to' => now()->toDateString()],
        ];
    }

    private function lifecycleSummary(array $arguments, User $user): array
    {
        $allowed = $this->marketAuth->resolveAccessiblePlatformIds($user);
        $query = Client::query()
            ->select(['platform_id', 'lifecycle_state'])
            ->selectRaw('COUNT(*) as client_count')
            ->when(is_array($allowed), fn ($builder) => $builder->whereIn('platform_id', $allowed))
            ->when(isset($arguments['platform_id']), fn ($builder) => $builder->where('platform_id', (int) $arguments['platform_id']))
            ->groupBy('platform_id', 'lifecycle_state');

        return ['rows' => $query->get()->map(fn ($row) => [
            'platform_id' => (int) $row->platform_id,
            'lifecycle_state' => (string) $row->lifecycle_state,
            'client_count' => (int) $row->client_count,
        ])->all()];
    }

    private function churnSummary(array $arguments, User $user): array
    {
        $from = Carbon::parse((string) ($arguments['from'] ?? now()->subDays(29)->toDateString()))->startOfDay();
        $to = Carbon::parse((string) ($arguments['to'] ?? now()->toDateString()))->endOfDay();
        $platformIds = $this->marketAuth->resolveAccessiblePlatformIds($user) ?? [];

        return $this->churn->summary($from, $to, $platformIds);
    }

    private function clientSnapshot(array $arguments, User $user): array
    {
        $client = $this->pseudonyms->resolveClient((string) ($arguments['handle'] ?? ''));
        if (! $client) {
            throw McpProtocolException::rpc(-32602, 'Unknown client handle.', 'invalid_handle', 422);
        }
        $this->marketAuth->ensureUserCanAccessPlatform($user, (int) $client->platform_id);

        return [
            'handle' => $this->pseudonyms->handle('client', (int) $client->id),
            'platform_id' => (int) $client->platform_id,
            'city' => (string) ($client->city ?? ''),
            'region' => (string) ($client->region ?? ''),
            'lifecycle_state' => (string) ($client->lifecycle_state ?? ''),
            'profile_status' => (string) ($client->profile_status ?? ''),
            'created_date' => optional($client->created_at)->toDateString(),
        ];
    }

    private function marketHealth(array $arguments, User $user): array
    {
        $allowed = $this->marketAuth->resolveAccessiblePlatformIds($user);

        return ['markets' => Platform::query()
            ->when(is_array($allowed), fn ($query) => $query->whereIn('id', $allowed))
            ->get(['id', 'name', 'country'])
            ->map(fn (Platform $platform) => [
                'platform_id' => (int) $platform->id,
                'market_name' => (string) $platform->name,
                'country' => (string) $platform->country,
            ])->all()];
    }

    private function schemaDictionary(array $arguments): array
    {
        $tables = array_values(array_filter([
            (string) ($arguments['table'] ?? ''),
            'clients', 'payments', 'platforms', 'deals',
        ]));
        $tables = array_values(array_unique($tables));
        $rows = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = Schema::getColumnListing($table);
            $search = strtolower((string) ($arguments['search'] ?? ''));
            if ($search !== '') {
                $columns = array_values(array_filter($columns, fn ($column) => str_contains(strtolower($column), $search)));
            }
            $rows[] = ['table' => $table, 'columns' => array_values(array_map(fn ($column) => ['column' => $column], $columns))];
        }

        return ['tables' => $rows];
    }

    private function resources(User $user): array
    {
        return collect((array) data_get($this->settings->settings(), 'resources', []))
            ->filter(fn ($item) => (bool) ($item['enabled'] ?? false))
            ->map(fn ($item, $uri) => ['uri' => $uri, 'name' => basename((string) $uri), 'mimeType' => 'text/markdown'])
            ->values()->all();
    }

    private function readResource(array $params, User $user): array
    {
        $uri = (string) ($params['uri'] ?? '');
        $resource = data_get($this->settings->settings(), 'resources.'.$uri);
        if (! $resource || ! (bool) ($resource['enabled'] ?? false)) {
            throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
        }

        $path = resource_path((string) $resource['path']);
        if (! is_file($path)) {
            throw McpProtocolException::rpc(-32001, 'Resource not found.', 'resource_not_found', 404);
        }

        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => file_get_contents($path) ?: '']]];
    }

    private function validateTransport(Request $request, array $payload, string $method, array $params): void
    {
        $headerVersion = $request->header('MCP-Protocol-Version');
        $supportedVersions = array_values((array) data_get($this->settings->settings(), 'protocol_versions', []));
        $meta = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $metaVersion = $meta['io.modelcontextprotocol/protocolVersion']
            ?? data_get($meta, 'io.modelcontextprotocol.protocolVersion');
        $initializeVersion = $method === 'initialize' ? ($params['protocolVersion'] ?? null) : null;
        $bodyVersion = $metaVersion ?? $initializeVersion;
        $perRequestProtocol = $headerVersion === '2025-06-18' && $request->header('Mcp-Method') !== null;
        $initializing = $method === 'initialize';
        $modern = $headerVersion === '2026-07-28';

        if ($modern) {
            if (! str_contains(strtolower((string) $request->header('Content-Type', '')), 'application/json')) {
                throw McpProtocolException::rpc(-32020, 'Header mismatch: Content-Type must be application/json.', 'header_mismatch', 400);
            }
            if ($request->header('Mcp-Method') !== $method) {
                throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Method does not match request.', 'header_mismatch', 400);
            }
            if (($meta['io.modelcontextprotocol/protocolVersion'] ?? null) !== '2026-07-28' || ! is_array($meta['io.modelcontextprotocol/clientCapabilities'] ?? null)) {
                throw McpProtocolException::rpc(-32602, 'Modern MCP requests require protocolVersion and clientCapabilities metadata.', 'invalid_params', 422);
            }
            if (in_array($method, ['initialize', 'notifications/initialized'], true)) {
                throw McpProtocolException::rpc(-32601, 'Initialize is not used by stateless 2026 MCP requests.', 'method_not_found', 404);
            }
            $named = in_array($method, ['tools/call', 'resources/read', 'prompts/get'], true);
            if ($named) {
                $expected = (string) ($params[$method === 'tools/call' ? 'name' : ($method === 'resources/read' ? 'uri' : 'name')] ?? '');
                $received = (string) $request->header('Mcp-Name', '');
                if (str_starts_with($received, 'base64:')) {
                    $decoded = base64_decode(substr($received, 7), true);
                    if ($decoded === false) {
                        throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Name encoding is invalid.', 'header_mismatch', 400);
                    }
                    $received = $decoded;
                }
                if ($received !== $expected) {
                    throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Name does not match request.', 'header_mismatch', 400);
                }
            } elseif ($request->header('Mcp-Name') !== null) {
                throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Name is not valid for this method.', 'header_mismatch', 400);
            }
        }

        if ($headerVersion === null && ! $initializing) {
            throw McpProtocolException::rpc(-32020, 'Header mismatch: MCP-Protocol-Version is required.', 'header_mismatch', 400);
        }
        if ($headerVersion !== null && ! in_array($headerVersion, $supportedVersions, true) && ! ($headerVersion === '2026-07-28' && config('mcp.waves.contracts_2026'))) {
            throw McpProtocolException::rpc(-32022, sprintf(
                'Unsupported MCP protocol version "%s" in the MCP-Protocol-Version header. Supported versions: %s.',
                $headerVersion,
                implode(', ', $supportedVersions) ?: 'none configured',
            ), 'unsupported_protocol', 400);
        }
        if ($headerVersion !== null && ($perRequestProtocol || $initializing) && $headerVersion !== $bodyVersion) {
            throw McpProtocolException::rpc(-32020, 'MCP-Protocol-Version header does not match the request protocol version.', 'header_mismatch', 400);
        }
        $accept = strtolower((string) $request->header('Accept', ''));
        if ($headerVersion !== null && (! str_contains($accept, 'application/json') || ! str_contains($accept, 'text/event-stream'))) {
            throw McpProtocolException::rpc(-32020, 'Header mismatch: Accept must include application/json and text/event-stream.', 'header_mismatch', 400);
        }
        if ($method !== '' && ($perRequestProtocol || $request->header('Mcp-Method') !== null) && $request->header('Mcp-Method') !== $method) {
            throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Method does not match request.', 'header_mismatch', 400);
        }
        if ($perRequestProtocol && in_array($method, ['tools/call', 'resources/read'], true)) {
            $expectedName = $method === 'tools/call' ? (string) ($params['name'] ?? '') : (string) ($params['uri'] ?? '');
            if ($request->header('Mcp-Name') !== $expectedName) {
                throw McpProtocolException::rpc(-32020, 'Header mismatch: Mcp-Name does not match request.', 'header_mismatch', 400);
            }
        }
        if ($request->header('Origin') && ! empty(config('mcp.allowed_origins')) && ! in_array($request->header('Origin'), (array) config('mcp.allowed_origins'), true)) {
            throw McpProtocolException::rpc(-32021, 'Origin is not allowed.', 'origin_rejected', 403);
        }
    }

    private function initializeProtocolVersion(array $params): string
    {
        $supported = (array) data_get($this->settings->settings(), 'protocol_versions', ['2025-06-18', '2025-03-26']);
        $requested = (string) ($params['protocolVersion'] ?? '');

        if ($requested !== '' && in_array($requested, $supported, true)) {
            return $requested;
        }

        return in_array('2025-03-26', $supported, true)
            ? '2025-03-26'
            : (string) ($supported[0] ?? '2025-03-26');
    }

    private function success($id, string $method, array $result, ?McpProtocolContext $context = null): JsonResponse
    {
        // A JSON-RPC result must serialise as an object. An empty PHP array would
        // encode as [] and fail strict client-side schema validation.
        if ($context?->modern()) {
            $result['_meta']['io.modelcontextprotocol/serverInfo'] = ['name' => 'exotic-crm', 'version' => '1.0.0'];
        }

        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? (object) [] : $result]);
    }

    private function error($id, int $code, string $message, int $status): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function stripUnsafeDashboardKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $child) {
            if (in_array((string) $key, ['selected_market', 'name', 'agent_name', 'payment_id', 'agent_id'], true)) {
                continue;
            }
            $result[$key] = $this->stripUnsafeDashboardKeys($child);
        }

        return $result;
    }

    private function audit(Request $request, string $method, array $params, string $status, ?string $reason, array $response, float $started, string $requestId): void
    {
        try {
            \App\Models\McpToolCall::create([
                'token_id' => $request->attributes->get('mcp_token')?->id,
                'user_id' => $request->user()?->id,
                'tool' => $method === 'tools/call' ? (string) ($params['name'] ?? 'unknown') : 'rpc:'.$method,
                'argument_summary' => $this->argumentSummary($params),
                'status' => $status,
                'refusal_reason' => $reason,
                'row_count' => (int) data_get($response, 'result._meta.exotic/rowCount', 0),
                'bytes_out' => strlen(json_encode($response)),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_id' => $requestId,
                'created_at' => now(),
                'generated_sql_sha256' => data_get($request->attributes->get('mcp_sql_audit'), 'hash'),
                // Store only a keyed digest, never an SQL prefix or a scalar input.
                'generated_sql_redacted' => data_get($request->attributes->get('mcp_sql_audit'), 'hash') ? '[redacted; sha256 recorded]' : null,
                'platform_scope' => data_get($request->attributes->get('mcp_sql_audit'), 'scope'),
            ]);
        } catch (Throwable) {
        }
    }

    private function argumentSummary(array $params): array
    {
        $arguments = (array) ($params['arguments'] ?? $params);

        return collect($arguments)->mapWithKeys(fn ($value, $key) => [
            $key => is_array($value) ? ['type' => 'array', 'count' => count($value)] : ['type' => gettype($value), 'hash' => is_scalar($value) ? hash_hmac('sha256', (string) $value, (string) config('app.key')) : null],
        ])->all();
    }
}

class McpProtocolException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code,
        public readonly string $reason,
        public readonly int $httpStatus
    ) {
        parent::__construct($message, $code);
    }

    public static function auth(): self
    {
        return new self('Unauthenticated.', -32001, 'missing_ability', 401);
    }

    public static function rpc(int $code, string $message, string $reason, int $status): self
    {
        return new self($message, $code, $reason, $status);
    }
}
