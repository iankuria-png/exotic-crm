<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Models\McpToolCall;
use App\Services\AuditService;
use App\Services\Mcp\McpProtocolException;
use App\Services\Mcp\McpServer;
use App\Services\Mcp\McpSettingsService;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class McpSettingsController extends Controller
{
    public function __construct(
        private readonly McpSettingsService $settings,
        private readonly ToolRegistry $registry,
        private readonly AuditService $auditService,
        private readonly McpServer $server,
    ) {}

    public function show(Request $request)
    {
        return response()->json([
            'settings' => $this->settings->settings(),
            'tools' => $this->registry->managementDefinitions($request->user(), $this->settings),
            'endpoint' => url('/api/mcp'),
        ]);
    }

    public function update(Request $request)
    {
        abort_unless($request->user()?->role === 'admin', 403);
        $before = $this->settings->settings();
        $payload = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'pii_mode' => ['sometimes', 'in:pseudonymous,aggregate_only'],
            'tools' => ['sometimes', 'array'],
            'limits' => ['sometimes', 'array'],
            'sql_hatch' => ['sometimes', 'array'],
            'token_policy' => ['sometimes', 'array'],
            'sanitisation' => ['sometimes', 'array'],
        ]);

        $after = $this->settings->save($payload, $request->user()->id);
        $settingId = (int) IntegrationSetting::query()->where('key', McpSettingsService::KEY)->value('id');
        if ($settingId > 0) {
            $this->auditService->fromSystemRequest(
                $request,
                'mcp_config_update',
                'ops_mcp_config',
                $settingId,
                $before,
                $after,
            );
        }

        return response()->json(['settings' => $after]);
    }

    public function selfTest(Request $request)
    {
        $settings = $this->settings->settings();
        $tools = $this->registry->managementDefinitions($request->user(), $this->settings);
        $enabledTools = collect($tools)->where('enabled', true)->count();
        $checks = [
            ['key' => 'config', 'status' => 'ok', 'message' => 'MCP configuration loaded.', 'remediation' => 'Review the enabled tools and endpoint in this workspace.'],
            ['key' => 'endpoint', 'status' => $this->settings->enabled() ? 'ok' : 'warning', 'message' => $this->settings->enabled() ? 'MCP endpoint is enabled.' : 'MCP endpoint is disabled.', 'remediation' => $this->settings->enabled() ? 'Ready.' : 'Enable the server before connecting a client.'],
            ['key' => 'protocol', 'status' => ! empty(data_get($settings, 'protocol_versions', [])) ? 'ok' : 'fail', 'message' => ! empty(data_get($settings, 'protocol_versions', [])) ? 'Protocol versions are configured.' : 'No MCP protocol version is configured.', 'remediation' => 'Set at least one supported MCP protocol version.'],
            ['key' => 'tools', 'status' => $enabledTools > 0 ? 'ok' : 'warning', 'message' => $enabledTools > 0 ? "{$enabledTools} tools are available to this account." : 'No tools are enabled for this account.', 'remediation' => 'Review the Tools panel and role floors.'],
            ['key' => 'resources', 'status' => count((array) data_get($settings, 'resources', [])) > 0 ? 'ok' : 'warning', 'message' => count((array) data_get($settings, 'resources', [])) > 0 ? 'Context resources are configured.' : 'No context resources are configured.', 'remediation' => 'Review the MCP resource configuration.'],
        ];

        try {
            DB::connection()->select('select 1');
            $checks[] = ['key' => 'database', 'status' => 'ok', 'message' => 'Application database reachable.', 'remediation' => 'Ready.'];
        } catch (\Throwable $exception) {
            $checks[] = ['key' => 'database', 'status' => 'fail', 'message' => 'Application database is not reachable.', 'remediation' => 'Check the application database connection settings.'];
        }

        try {
            $this->server->previewTool($request, 'exotic_catalog', [], $request->user());
            $checks[] = ['key' => 'sample_call', 'status' => 'ok', 'message' => 'Sample catalog call passed.', 'remediation' => 'Ready.'];
        } catch (\Throwable $exception) {
            $checks[] = ['key' => 'sample_call', 'status' => 'fail', 'message' => 'Sample catalog call failed.', 'remediation' => 'Open Tools and preview exotic_catalog for the detailed error.'];
        }

        return response()->json([
            'reachable' => true,
            'protocol' => data_get($settings, 'protocol_versions.0', '2025-06-18'),
            'fallback_protocol' => data_get($settings, 'protocol_versions.1', '2025-03-26'),
            'tools_listed' => $enabledTools,
            'resources_listed' => count((array) data_get($settings, 'resources', [])),
            'readonly_connection' => 'configured',
            'checks' => $checks,
        ]);
    }

    public function preview(Request $request, string $tool)
    {
        abort_unless(in_array($request->user()?->role, ['admin', 'sub_admin'], true), 403);
        $data = $request->validate(['arguments' => ['nullable', 'array']]);

        $started = microtime(true);
        try {
            $payload = $this->server->previewTool(
                $request,
                $tool,
                (array) ($data['arguments'] ?? []),
                $request->user(),
            );
            McpToolCall::create([
                'user_id' => $request->user()?->id,
                'tool' => 'preview:'.$tool,
                'argument_summary' => ['keys' => array_keys((array) $request->input('arguments', []))],
                'status' => 'success',
                'row_count' => (int) data_get($payload, 'row_count', 0),
                'bytes_out' => (int) data_get($payload, 'bytes', 0),
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return response()->json($payload);
        } catch (McpProtocolException $exception) {
            McpToolCall::create([
                'user_id' => $request->user()?->id,
                'tool' => 'preview:'.$tool,
                'argument_summary' => ['keys' => array_keys((array) $request->input('arguments', []))],
                'status' => 'refused',
                'refusal_reason' => $exception->reason,
                'latency_ms' => (int) round((microtime(true) - $started) * 1000),
                'request_id' => (string) Str::uuid(),
                'created_at' => now(),
            ]);

            return response()->json(['message' => $exception->getMessage(), 'reason' => $exception->reason], $exception->httpStatus);
        }
    }
}
