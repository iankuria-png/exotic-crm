<?php

namespace App\Services\Mcp;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use App\Services\Ai\Exceptions\SqlValidationException;
use App\Services\Ai\SqlSafetyValidator;
use App\Services\Ai\SqlValidationPolicy;
use App\Services\CeoDashboardDataService;
use App\Services\ChurnAggregatorService;
use App\Services\MarketAuthorizationService;
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
    ) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $started = microtime(true);
        $payload = $request->json()->all();
        $id = $payload['id'] ?? null;
        $method = (string) ($payload['method'] ?? '');
        $params = is_array($payload['params'] ?? null) ? $payload['params'] : [];
        $user = $request->user();
        $token = $request->attributes->get('mcp_token');
        $requestId = (string) ($request->attributes->get('mcp_request_id') ?: Str::uuid());

        try {
            $this->validateTransport($request, $payload, $method, $params);
            $result = $this->dispatch($request, $method, $params, $user, $token?->abilities);

            if (! array_key_exists('id', $payload)) {
                $response = response('', Response::HTTP_ACCEPTED);
                $this->audit($request, $method, $params, 'success', null, [], $started, $requestId);

                return $response;
            }

            $response = $this->success($id, $method, $result);
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

    private function dispatch(Request $request, string $method, array $params, ?User $user, ?array $abilities): array
    {
        if (! $user) {
            throw McpProtocolException::auth();
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

        if ($headerVersion === null && ! $initializing) {
            throw McpProtocolException::rpc(-32020, 'Header mismatch: MCP-Protocol-Version is required.', 'header_mismatch', 400);
        }
        if ($headerVersion !== null && ! in_array($headerVersion, $supportedVersions, true)) {
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

    private function success($id, string $method, array $result): JsonResponse
    {
        // A JSON-RPC result must serialise as an object. An empty PHP array would
        // encode as [] and fail strict client-side schema validation.
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
                'generated_sql_redacted' => data_get($request->attributes->get('mcp_sql_audit'), 'preview'),
                'platform_scope' => data_get($request->attributes->get('mcp_sql_audit'), 'scope'),
            ]);
        } catch (Throwable) {
        }
    }

    private function argumentSummary(array $params): array
    {
        $arguments = (array) ($params['arguments'] ?? $params);

        return collect($arguments)->mapWithKeys(fn ($value, $key) => [
            $key => is_array($value) ? ['type' => 'array', 'count' => count($value)] : ['type' => gettype($value), 'value' => is_scalar($value) ? mb_substr((string) $value, 0, 80) : null],
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
