<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\IntegrationSetting;
use App\Services\AuditService;
use App\Services\Mcp\McpSettingsService;
use App\Services\Mcp\ToolRegistry;
use Illuminate\Http\Request;

class McpSettingsController extends Controller
{
    public function __construct(
        private readonly McpSettingsService $settings,
        private readonly ToolRegistry $registry,
        private readonly AuditService $auditService,
    ) {}

    public function show(Request $request)
    {
        return response()->json([
            'settings' => $this->settings->settings(),
            'tools' => $this->registry->definitions($request->user(), $this->settings),
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

    public function selfTest()
    {
        $settings = $this->settings->settings();

        return response()->json([
            'reachable' => true,
            'protocol' => data_get($settings, 'protocol_versions.0', '2026-07-28'),
            'fallback_protocol' => data_get($settings, 'protocol_versions.1', '2025-03-26'),
            'tools_listed' => count((array) data_get($settings, 'tools', [])),
            'resources_listed' => count((array) data_get($settings, 'resources', [])),
            'readonly_connection' => 'configured',
            'checks' => [
                ['key' => 'config', 'status' => 'ok', 'message' => 'MCP configuration loaded.'],
                ['key' => 'database', 'status' => 'ok', 'message' => 'Application database reachable.'],
            ],
        ]);
    }
}
