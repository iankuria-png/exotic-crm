<?php

namespace App\Services\Mcp;

use App\Models\User;

class ToolRegistry
{
    private const ROLE_RANK = ['marketing' => 1, 'field_sales' => 1, 'sales' => 1, 'sub_admin' => 2, 'admin' => 3];

    private const MODERN_TOOLS = [
        'exotic_search_knowledge' => ['title' => 'Search approved knowledge', 'description' => 'Search only administrator-approved product and operating references.', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'domain' => 'knowledge', 'properties' => ['query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200], 'audience' => ['type' => 'string', 'enum' => ['sales', 'customer_success', 'finance', 'product', 'infrastructure', 'leadership']]], 'required' => ['query']],
        'exotic_get_document' => ['title' => 'Read approved document', 'description' => 'Read an approved document by its exotic:// URI.', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'domain' => 'knowledge', 'properties' => ['uri' => ['type' => 'string', 'pattern' => '^exotic://docs/']], 'required' => ['uri']],
        'exotic_payment_flow_trace' => ['title' => 'Payment flow trace', 'description' => 'Trace safe payment lifecycle observations from an opaque locator.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['locator' => ['type' => 'string', 'pattern' => '^pay_[A-Za-z0-9_-]{16,}$']], 'required' => ['locator']],
        'exotic_payment_failure_diagnosis' => ['title' => 'Payment failure diagnosis', 'description' => 'Classify a payment failure without exposing payment identifiers or payloads.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['locator' => ['type' => 'string', 'pattern' => '^pay_[A-Za-z0-9_-]{16,}$']], 'required' => ['locator']],
        'exotic_system_vitals_live' => ['title' => 'System vitals', 'description' => 'Read the latest cached operational sample and its availability caveats.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => []],
        'exotic_error_digest_live' => ['title' => 'Error digest', 'description' => 'Read bounded recent error fingerprints without messages or paths.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]]],
    ];

    private const TOOLS = [
        'exotic_catalog' => ['title' => 'CRM catalog', 'description' => 'List enabled MCP tools, markets and reporting coverage.', 'min_role' => 'sales', 'domain' => 'catalog', 'properties' => []],
        'exotic_revenue_summary' => ['title' => 'Revenue summary', 'description' => 'Return CEO-dashboard revenue and customer-mix metrics for a window.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string']]],
        'exotic_revenue_trend' => ['title' => 'Revenue trend', 'description' => 'Return dashboard revenue buckets for a window.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'bucket' => ['type' => 'string'], 'platform_id' => ['type' => 'integer']]],
        'exotic_market_breakdown' => ['title' => 'Market breakdown', 'description' => 'Compare reportable revenue by market.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string']]],
        'exotic_agent_performance' => ['title' => 'Agent performance', 'description' => 'Return dashboard agent performance rows with staff display names for administrators.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
        'exotic_peak_hours' => ['title' => 'Peak hours', 'description' => 'Return payment volume by hour and weekday.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string']]],
        'exotic_lifecycle_summary' => ['title' => 'Lifecycle summary', 'description' => 'Count clients by lifecycle state and market.', 'min_role' => 'sub_admin', 'domain' => 'lifecycle', 'properties' => ['platform_id' => ['type' => 'integer']]],
        'exotic_churn_analysis' => ['title' => 'Churn analysis', 'description' => 'Summarize churn movement and breakdowns.', 'min_role' => 'sub_admin', 'domain' => 'lifecycle', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'breakdown' => ['type' => 'string']]],
        'exotic_cohort_retention' => ['title' => 'Cohort retention', 'description' => 'Return a safe cohort-retention placeholder until cohort views are present.', 'min_role' => 'sub_admin', 'domain' => 'lifecycle', 'properties' => ['cohort_field' => ['type' => 'string'], 'horizon_months' => ['type' => 'integer']]],
        'exotic_client_snapshot' => ['title' => 'Client snapshot', 'description' => 'Return a pseudonymous client lifecycle snapshot without deep links or PII.', 'min_role' => 'admin', 'domain' => 'clients', 'properties' => ['handle' => ['type' => 'string']]],
        'exotic_error_digest' => ['title' => 'Error digest', 'description' => 'Return sanitised operational error aggregates.', 'min_role' => 'admin', 'domain' => 'operations', 'properties' => ['since' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
        'exotic_system_vitals' => ['title' => 'System vitals', 'description' => 'Return read-only system health metrics.', 'min_role' => 'admin', 'domain' => 'operations', 'properties' => []],
        'exotic_market_health' => ['title' => 'Market health', 'description' => 'Return read-only market sync health.', 'min_role' => 'admin', 'domain' => 'operations', 'properties' => []],
        'exotic_schema_dictionary' => ['title' => 'Schema dictionary', 'description' => 'Return a constrained schema dictionary without row data.', 'min_role' => 'admin', 'domain' => 'schema', 'properties' => ['table' => ['type' => 'string'], 'search' => ['type' => 'string']]],
        'exotic_run_reporting_sql' => ['title' => 'Reporting SQL', 'description' => 'Run a SELECT against explicitly allow-listed aggregate reporting views.', 'min_role' => 'admin', 'domain' => 'schema', 'views' => ['vw_mcp_lifecycle_rollup', 'vw_mcp_revenue_rollup'], 'backing_service' => 'SqlSafetyValidator', 'returns_no' => ['names', 'phones', 'emails', 'bios', 'free text'], 'properties' => ['sql' => ['type' => 'string'], 'limit' => ['type' => 'integer']]],
    ];

    /**
     * JSON Schema requires "properties" to be an object. A parameterless tool has an
     * empty PHP array, which would encode as [] and fail strict client validation.
     */
    private function schemaProperties(array $properties): array|object
    {
        return $properties === [] ? (object) [] : $properties;
    }

    public function definitions(User $user, McpSettingsService $settings, ?array $abilities = null, bool $modern = false): array
    {
        $rows = [];
        $catalog = $modern ? array_merge(self::TOOLS, self::MODERN_TOOLS) : self::TOOLS;
        foreach ($catalog as $name => $meta) {
            if (! $this->available($name, $user, $settings, $abilities)) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $this->schemaProperties($meta['properties']),
                    'required' => $meta['required'] ?? [],
                    'additionalProperties' => false,
                ],
                ...($modern ? ['outputSchema' => ['type' => 'object', 'required' => ['data', 'meta'], 'additionalProperties' => false], 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false]] : []),
            ];
        }

        return $rows;
    }

    public function managementDefinitions(User $user, McpSettingsService $settings): array
    {
        $userRank = self::ROLE_RANK[$user->role] ?? 0;

        return collect(array_merge(self::TOOLS, self::MODERN_TOOLS))
            ->filter(fn (array $meta): bool => $userRank >= (self::ROLE_RANK[$this->minimumRole($meta)] ?? 99))
            ->map(function (array $meta, string $name) use ($settings): array {
                $modern = array_key_exists($name, self::MODERN_TOOLS);
                $configured = $modern ? [] : $settings->tool($name);
                $enabled = $modern ? $this->modernToolEnabled($name) : (bool) data_get($configured, 'enabled', false);
                $minimumRole = $this->minimumRole($meta);

                return [
                    'name' => $name,
                    'title' => $meta['title'],
                    'description' => $meta['description'],
                    'domain' => $meta['domain'] ?? 'operations',
                    'min_role' => $minimumRole,
                    'configured_role' => (string) data_get($configured, 'min_role', $minimumRole),
                    'enabled' => $enabled,
                    'management_mode' => $modern ? 'rollout' : 'registry',
                    'scope_required' => $modern,
                    'status_detail' => $modern
                        ? ($enabled ? 'Live for tokens with this permission.' : 'Unavailable until its rollout is enabled.')
                        : ($enabled ? 'Enabled in the organisation registry.' : 'Disabled in the organisation registry.'),
                    'inputSchema' => ['type' => 'object', 'properties' => $this->schemaProperties($meta['properties']), 'additionalProperties' => false],
                    'backing_service' => $meta['backing_service'] ?? 'Curated CRM service',
                    'views' => $meta['views'] ?? [],
                    'returns_no' => $meta['returns_no'] ?? ['names', 'phones', 'emails', 'bios', 'free text'],
                ];
            })
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    public function defaultToolNames(User $user, McpSettingsService $settings): array
    {
        return collect($this->managementDefinitions($user, $settings))
            ->where('enabled', true)
            ->pluck('name')
            ->values()
            ->all();
    }

    public function metadata(string $name): ?array
    {
        return self::TOOLS[$name] ?? self::MODERN_TOOLS[$name] ?? null;
    }

    public function isEnhanced(string $name): bool
    {
        return array_key_exists($name, self::MODERN_TOOLS);
    }

    public function available(string $name, User $user, McpSettingsService $settings, ?array $abilities = null): bool
    {
        $meta = self::TOOLS[$name] ?? self::MODERN_TOOLS[$name] ?? null;
        $modernTool = array_key_exists($name, self::MODERN_TOOLS);
        if (! $meta || ($modernTool && ! $this->modernToolEnabled($name)) || (! $modernTool && ! (bool) data_get($settings->tool($name), 'enabled', false))) {
            return false;
        }

        if ($modernTool) {
            if (! in_array($user->role, $meta['roles'], true)) {
                return false;
            }
            $toolAbilities = array_values(array_filter((array) ($abilities ?? []), fn ($ability) => str_starts_with((string) $ability, 'mcp:tool:')));

            return in_array('mcp:tool:'.$name, $toolAbilities, true);
        }

        if ((self::ROLE_RANK[$user->role] ?? 0) < (self::ROLE_RANK[$meta['min_role']] ?? 99)) {
            return false;
        }

        $configuredRole = (string) data_get($settings->tool($name), 'min_role', $meta['min_role']);
        if ((self::ROLE_RANK[$user->role] ?? 0) < (self::ROLE_RANK[$configuredRole] ?? 99)) {
            return false;
        }

        $toolAbilities = array_values(array_filter((array) ($abilities ?? []), fn ($ability) => str_starts_with((string) $ability, 'mcp:tool:')));

        return $toolAbilities === [] || in_array('mcp:tool:'.$name, $toolAbilities, true);
    }

    private function minimumRole(array $meta): string
    {
        if (isset($meta['min_role'])) {
            return $meta['min_role'];
        }

        return collect($meta['roles'] ?? [])
            ->sortBy(fn (string $role) => self::ROLE_RANK[$role] ?? 99)
            ->first() ?? 'admin';
    }

    private function modernToolEnabled(string $name): bool
    {
        if (! (bool) config('mcp.waves.contracts')) {
            return false;
        }

        if (in_array($name, ['exotic_search_knowledge', 'exotic_get_document'], true)) {
            return (bool) config('mcp.waves.knowledge');
        }

        return (bool) config('mcp.waves.diagnostics');
    }
}
