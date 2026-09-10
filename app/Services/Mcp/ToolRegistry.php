<?php

namespace App\Services\Mcp;

use App\Models\User;

class ToolRegistry
{
    private const TOOLS = [
        'exotic_catalog' => ['title' => 'CRM catalog', 'description' => 'List enabled MCP tools, markets and reporting coverage.', 'min_role' => 'sales', 'properties' => []],
        'exotic_revenue_summary' => ['title' => 'Revenue summary', 'description' => 'Return CEO-dashboard revenue and customer-mix metrics for a window.', 'min_role' => 'sub_admin', 'properties' => ['window' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string']]],
        'exotic_revenue_trend' => ['title' => 'Revenue trend', 'description' => 'Return dashboard revenue buckets for a window.', 'min_role' => 'sub_admin', 'properties' => ['window' => ['type' => 'string'], 'bucket' => ['type' => 'string'], 'platform_id' => ['type' => 'integer']]],
        'exotic_market_breakdown' => ['title' => 'Market breakdown', 'description' => 'Compare reportable revenue by market.', 'min_role' => 'sub_admin', 'properties' => ['window' => ['type' => 'string']]],
        'exotic_agent_performance' => ['title' => 'Agent performance', 'description' => 'Return dashboard agent performance rows with pseudonymous handles.', 'min_role' => 'sub_admin', 'properties' => ['window' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
        'exotic_peak_hours' => ['title' => 'Peak hours', 'description' => 'Return payment volume by hour and weekday.', 'min_role' => 'sub_admin', 'properties' => ['window' => ['type' => 'string']]],
        'exotic_lifecycle_summary' => ['title' => 'Lifecycle summary', 'description' => 'Count clients by lifecycle state and market.', 'min_role' => 'sub_admin', 'properties' => ['platform_id' => ['type' => 'integer']]],
        'exotic_churn_analysis' => ['title' => 'Churn analysis', 'description' => 'Summarize churn movement and breakdowns.', 'min_role' => 'sub_admin', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'breakdown' => ['type' => 'string']]],
        'exotic_cohort_retention' => ['title' => 'Cohort retention', 'description' => 'Return a safe cohort-retention placeholder until cohort views are present.', 'min_role' => 'sub_admin', 'properties' => ['cohort_field' => ['type' => 'string'], 'horizon_months' => ['type' => 'integer']]],
        'exotic_client_snapshot' => ['title' => 'Client snapshot', 'description' => 'Return a pseudonymous client lifecycle snapshot without deep links or PII.', 'min_role' => 'admin', 'properties' => ['handle' => ['type' => 'string']]],
        'exotic_error_digest' => ['title' => 'Error digest', 'description' => 'Return sanitised operational error aggregates.', 'min_role' => 'admin', 'properties' => ['since' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
        'exotic_system_vitals' => ['title' => 'System vitals', 'description' => 'Return read-only system health metrics.', 'min_role' => 'admin', 'properties' => []],
        'exotic_market_health' => ['title' => 'Market health', 'description' => 'Return read-only market sync health.', 'min_role' => 'admin', 'properties' => []],
        'exotic_schema_dictionary' => ['title' => 'Schema dictionary', 'description' => 'Return a constrained schema dictionary without row data.', 'min_role' => 'admin', 'properties' => ['table' => ['type' => 'string'], 'search' => ['type' => 'string']]],
        'exotic_run_reporting_sql' => ['title' => 'Reporting SQL', 'description' => 'Run a SELECT against explicitly allow-listed aggregate reporting views.', 'min_role' => 'admin', 'properties' => ['sql' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
    ];

    public function definitions(User $user, McpSettingsService $settings, ?array $abilities = null): array
    {
        $rows = [];
        foreach (self::TOOLS as $name => $meta) {
            if (! $this->available($name, $user, $settings, $abilities)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $meta['properties'],
                    'additionalProperties' => false,
                ],
            ];
        }

        return $rows;
    }

    public function metadata(string $name): ?array
    {
        return self::TOOLS[$name] ?? null;
    }

    public function available(string $name, User $user, McpSettingsService $settings, ?array $abilities = null): bool
    {
        $meta = self::TOOLS[$name] ?? null;
        if (! $meta || ! (bool) data_get($settings->tool($name), 'enabled', false)) {
            return false;
        }

        $rank = ['marketing' => 1, 'field_sales' => 1, 'sales' => 1, 'sub_admin' => 2, 'admin' => 3];
        if (($rank[$user->role] ?? 0) < ($rank[$meta['min_role']] ?? 99)) {
            return false;
        }

        $configuredRole = (string) data_get($settings->tool($name), 'min_role', $meta['min_role']);
        if (($rank[$user->role] ?? 0) < ($rank[$configuredRole] ?? 99)) {
            return false;
        }

        $toolAbilities = array_values(array_filter((array) ($abilities ?? []), fn ($ability) => str_starts_with((string) $ability, 'mcp:tool:')));

        return $toolAbilities === [] || in_array('mcp:tool:'.$name, $toolAbilities, true);
    }
}
