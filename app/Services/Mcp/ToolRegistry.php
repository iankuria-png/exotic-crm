<?php

namespace App\Services\Mcp;

use App\Models\User;

class ToolRegistry
{
    private const ROLE_RANK = ['marketing' => 1, 'field_sales' => 1, 'sales' => 1, 'sub_admin' => 2, 'admin' => 3];

    private const MODERN_TOOLS = [
        'exotic_search_knowledge' => ['title' => 'Search approved knowledge', 'description' => 'Search only administrator-approved product and operating references.', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'domain' => 'knowledge', 'properties' => ['query' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 200], 'audience' => ['type' => 'string', 'enum' => ['sales', 'customer_success', 'finance', 'product', 'infrastructure', 'leadership']]], 'required' => ['query']],
        'exotic_get_document' => ['title' => 'Read approved document', 'description' => 'Read an approved document by its discoverable exotic:// URI.', 'roles' => ['admin', 'sub_admin', 'sales', 'field_sales', 'marketing'], 'domain' => 'knowledge', 'properties' => ['uri' => ['type' => 'string', 'pattern' => '^exotic://']], 'required' => ['uri']],
        'exotic_payment_flow_trace' => ['title' => 'Payment flow trace', 'description' => 'Trace safe payment lifecycle observations from an opaque locator.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['locator' => ['type' => 'string', 'pattern' => '^pay_[A-Za-z0-9_-]{16,}$']], 'required' => ['locator']],
        'exotic_payment_failure_diagnosis' => ['title' => 'Payment failure diagnosis', 'description' => 'Classify a payment failure without exposing payment identifiers or payloads.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['locator' => ['type' => 'string', 'pattern' => '^pay_[A-Za-z0-9_-]{16,}$']], 'required' => ['locator']],
        'exotic_system_vitals_live' => ['title' => 'System vitals', 'description' => 'Read the latest cached operational sample and its availability caveats.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => []],
        'exotic_error_digest_live' => ['title' => 'Error digest', 'description' => 'Read bounded recent error fingerprints without messages or paths.', 'roles' => ['admin'], 'domain' => 'operations', 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20]]],
    ];

    private const SUBSCRIPTION_AUDIT_PROPERTIES = [
        'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 160],
        'run_ref' => ['type' => 'string', 'maxLength' => 36],
        'from' => ['type' => 'string', 'maxLength' => 40],
        'to' => ['type' => 'string', 'maxLength' => 40],
        'platform_id' => ['type' => 'integer', 'minimum' => 1],
        'country' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
        'staff_handle' => ['type' => 'string', 'pattern' => '^staff_[a-f0-9]{32}$'],
        'event_type' => ['type' => 'string', 'enum' => ['activation', 'renewal', 'extension', 'observation']],
        'exceptions_only' => ['type' => 'boolean'],
        'population' => ['type' => 'string', 'enum' => ['live', 'test', 'all']],
        'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        'cursor' => ['type' => 'string', 'maxLength' => 4096],
        'event_ref' => ['type' => 'string', 'pattern' => '^evt_[a-f0-9]{64}$'],
    ];

    private const TOOLS = [
        'exotic_subscription_audit_profiles' => ['title' => 'Active profile payment audit', 'description' => 'List every active or published profile, including no-deal and older activations. Filter retained cash history separately from current-action payment and free approval evidence. Use identified=true for names and public profile URLs on an explicit named-audit administrator grant; a purpose is required, no extra per-page confirmation. Never interpret missing records as lifetime nonpayment.', 'min_role' => 'admin', 'domain' => 'subscription_audit', 'returns_no' => ['phones', 'emails', 'raw payment references', 'free text'], 'properties' => [
            'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 160], 'run_ref' => ['type' => 'string', 'maxLength' => 36],
            'platform_id' => ['type' => 'integer', 'minimum' => 1], 'country' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80],
            'profile_scope' => ['type' => 'string', 'enum' => ['active', 'published']],
            'payment_filter' => ['type' => 'string', 'enum' => ['all', 'no_successful_cash_payment_in_retained_crm', 'payment_history_unverified', 'current_action_payment_unverified', 'authorised_free', 'approval_unverified']],
            'identified' => ['type' => 'boolean'], 'page_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100], 'cursor' => ['type' => 'string', 'maxLength' => 499],
        ], 'required' => ['purpose']],
        'exotic_subscription_audit_summary' => ['title' => 'Subscription audit summary', 'description' => 'Summarize subscription actions, payment exceptions and potential exposure by currency, website and staff. CRM evidence only; missing records are not evidence of wrongdoing.', 'min_role' => 'admin', 'domain' => 'subscription_audit', 'properties' => self::SUBSCRIPTION_AUDIT_PROPERTIES, 'required' => ['purpose']],
        'exotic_subscription_audit_events' => ['title' => 'Subscription audit events', 'description' => 'Read a complete pageable list of subscription activations, renewals and extensions with CRM payment and staff evidence. Set identified=true with a purpose on a named-audit grant for advertiser names and public profile links.', 'min_role' => 'admin', 'domain' => 'subscription_audit', 'returns_no' => ['phones', 'emails', 'raw payment references', 'free text'], 'properties' => self::SUBSCRIPTION_AUDIT_PROPERTIES + ['identified' => ['type' => 'boolean']], 'required' => ['purpose']],
        'exotic_subscription_audit_trace' => ['title' => 'Subscription audit evidence trace', 'description' => 'Read one subscription event with safe action-specific payment, expiry and staff/approval provenance using its opaque event reference. Set identified=true with a purpose on a named-audit grant for names and public profile links.', 'min_role' => 'admin', 'domain' => 'subscription_audit', 'returns_no' => ['phones', 'emails', 'raw payment references', 'free text'], 'properties' => self::SUBSCRIPTION_AUDIT_PROPERTIES + ['identified' => ['type' => 'boolean']], 'required' => ['purpose', 'event_ref']],
        'exotic_subscription_audit_coverage' => ['title' => 'Subscription audit coverage', 'description' => 'Read per-website historical audit coverage, snapshot freshness and explicitly missing or unreliable sources.', 'min_role' => 'admin', 'domain' => 'subscription_audit', 'properties' => self::SUBSCRIPTION_AUDIT_PROPERTIES, 'required' => ['purpose']],
        'exotic_catalog' => ['title' => 'CRM catalog', 'description' => 'List enabled MCP tools, markets and reporting coverage.', 'min_role' => 'sales', 'domain' => 'catalog', 'properties' => []],
        'exotic_revenue_summary' => ['title' => 'Revenue summary', 'description' => 'Return CEO-dashboard revenue and customer-mix metrics for a window.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string']]],
        'exotic_revenue_trend' => ['title' => 'Revenue trend', 'description' => 'Return dashboard revenue buckets for a window.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'bucket' => ['type' => 'string'], 'platform_id' => ['type' => 'integer']]],
        'exotic_market_breakdown' => ['title' => 'Market breakdown', 'description' => 'Compare reportable revenue by market.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string']]],
        'exotic_render_revenue_dashboard' => ['title' => 'Revenue dashboard', 'description' => 'Render a read-only MCP App dashboard for revenue summary, trend and market mix. The data tools remain usable without UI.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'ui_resource' => 'ui://exotic/revenue-dashboard/v1.html', 'properties' => ['window' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string'], 'bucket' => ['type' => 'string']]],
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
        'exotic_ceo_dashboard' => ['title' => 'CEO dashboard', 'description' => 'Return the complete established CEO dashboard calculation, with its native comparison and freshness data.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['window' => ['type' => 'string'], 'from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string'], 'bucket' => ['type' => 'string']]],
        'exotic_weekly_executive_scorecard' => ['title' => 'Weekly executive scorecard', 'description' => 'Read completed CEO scorecard archives or one completed week. This never generates a new scorecard.', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'properties' => ['week_start' => ['type' => 'string']]],
        'exotic_team_performance' => ['title' => 'Team performance', 'description' => 'Read authorised team or personal performance with stable administrator-managed staff aliases.', 'min_role' => 'sales', 'domain' => 'team', 'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'week', 'month']], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string']]],
        'exotic_visitor_demand' => ['title' => 'Visitor demand', 'description' => 'Read contact-unlock demand, conversion, market, city, source and checkout-hour performance.', 'min_role' => 'sub_admin', 'domain' => 'visitors', 'properties' => ['range' => ['type' => 'string', 'enum' => ['today', '7d', '30d']], 'bucket' => ['type' => 'string'], 'platform_id' => ['type' => 'integer'], 'reporting_currency' => ['type' => 'string']]],
        'exotic_commission_summary' => ['title' => 'Field commission summary', 'description' => 'Read earned and paid field-commission aggregates. It cannot mark a payout paid.', 'min_role' => 'field_sales', 'domain' => 'commissions', 'properties' => ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 90], 'platform_id' => ['type' => 'integer']]],
        'exotic_client_operations' => ['title' => 'Client operations queue', 'description' => 'Read bounded client queue statistics. Named rows require the separate identified-client capability, confirmation and purpose.', 'min_role' => 'sub_admin', 'domain' => 'clients', 'properties' => ['queue' => ['type' => 'string', 'enum' => ['all', 'failed_payment', 'stalled_contacted']], 'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'platform_id' => ['type' => 'integer'], 'identified' => ['type' => 'boolean'], 'confirm_identified' => ['type' => 'boolean'], 'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 160]]],
        'exotic_lifecycle_intelligence' => ['title' => 'Lifecycle, retention and churn', 'description' => 'Read lifecycle movement, retention and churn risk using CRM definitions and coverage caveats.', 'min_role' => 'sub_admin', 'domain' => 'lifecycle', 'properties' => ['from' => ['type' => 'string'], 'to' => ['type' => 'string'], 'platform_id' => ['type' => 'integer']]],
        'exotic_wallet_balance' => ['title' => 'Receiving wallet balance', 'description' => 'Current balance of each office receiving wallet (for example the TZ office M-PESA wallet / SIM), exactly as stated in its latest credit SMS, with the time it was reported. Use for questions like "what is the TZ SIM balance". Never computed; later withdrawals are not reflected.', 'min_role' => 'sales', 'domain' => 'operations', 'returns_no' => ['phones', 'payer names', 'payment references', 'SMS text'], 'properties' => ['platform_id' => ['type' => 'integer', 'minimum' => 1]]],
        'exotic_recent_payments' => ['title' => 'Recent payments', 'description' => 'List the latest completed customer payments, newest first (wallet top-ups excluded): time in EAT, market, product, amount, currency, USD equivalent, channel/method and staff alias. Use for "show me the last 10 payments". Set identified=true with a purpose for advertiser profile names (administrator grant with identified-client access).', 'min_role' => 'sub_admin', 'domain' => 'revenue', 'returns_no' => ['phones', 'emails', 'raw payment references'], 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'platform_id' => ['type' => 'integer', 'minimum' => 1], 'country' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80], 'channel' => ['type' => 'string', 'enum' => ['all', 'self_service', 'manual', 'other']], 'identified' => ['type' => 'boolean'], 'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 160]]],
        'exotic_recent_subscriptions' => ['title' => 'Recent subscriptions', 'description' => 'List the most recently activated subscriptions, newest first: activation and expiry in EAT, status, market, product, plan, amount, free-trial flag, origin and staff alias. Use for "show me the last 10 subscriptions". Set identified=true with a purpose for advertiser profile names (administrator grant with identified-client access).', 'min_role' => 'sub_admin', 'domain' => 'lifecycle', 'returns_no' => ['phones', 'emails', 'raw payment references'], 'properties' => ['limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50], 'platform_id' => ['type' => 'integer', 'minimum' => 1], 'country' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80], 'status' => ['type' => 'string', 'enum' => ['any', 'active']], 'include_free_trials' => ['type' => 'boolean'], 'identified' => ['type' => 'boolean'], 'purpose' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 160]]],
        'exotic_city_performance' => ['title' => 'City performance', 'description' => 'Read city performance using the CRM 30/40/30 score and explicit analytics availability.', 'min_role' => 'sub_admin', 'domain' => 'clients', 'properties' => ['platform_id' => ['type' => 'integer']], 'required' => ['platform_id']],
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

            $definition = [
                'name' => $name,
                'title' => $meta['title'],
                'description' => $meta['description'],
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => $this->schemaProperties($meta['properties']),
                    'required' => $meta['required'] ?? [],
                    'additionalProperties' => false,
                ],
                ...($modern ? ['outputSchema' => $this->outputSchema($name), 'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false]] : []),
            ];
            if ($modern && isset($meta['ui_resource'])) {
                $definition['_meta'] = [
                    'ui' => [
                        'resourceUri' => $meta['ui_resource'],
                        'visibility' => ['model', 'app'],
                    ],
                    'openai/outputTemplate' => $meta['ui_resource'],
                    'openai/toolInvocation/invoking' => 'Building revenue dashboard',
                    'openai/toolInvocation/invoked' => 'Revenue dashboard ready',
                ];
            }

            $rows[] = $definition;
        }

        return $rows;
    }

    private function outputSchema(string $name): array
    {
        return [
            'type' => 'object',
            'required' => ['data', 'meta'],
            'additionalProperties' => false,
            'properties' => [
                'data' => $this->dataSchema($name),
                'meta' => [
                    'type' => 'object',
                    'required' => ['schema_version', 'generated_at', 'result_state', 'filters', 'coverage', 'row_count', 'caveats', 'citations'],
                    'additionalProperties' => true,
                    'properties' => [
                        'schema_version' => ['type' => 'string'],
                        'generated_at' => ['type' => 'string'],
                        'result_state' => ['type' => 'string', 'enum' => ['complete', 'partial', 'no_data', 'unavailable']],
                        'filters' => ['type' => 'object'],
                        'coverage' => ['type' => 'array'],
                        'row_count' => ['type' => 'integer'],
                        'caveats' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'citations' => ['type' => 'array'],
                    ],
                ],
            ],
        ];
    }

    private function dataSchema(string $name): array
    {
        return match ($name) {
            'exotic_revenue_summary' => [
                'type' => 'object',
                'required' => ['window', 'metrics', 'customer_mix', 'visitor_revenue', 'insights'],
                'additionalProperties' => false,
                'properties' => [
                    'window' => ['type' => 'object'],
                    'metrics' => ['type' => 'object'],
                    'customer_mix' => ['type' => 'object'],
                    'visitor_revenue' => ['type' => 'object'],
                    'insights' => ['type' => 'array'],
                ],
            ],
            'exotic_revenue_trend' => [
                'type' => 'object',
                'required' => ['window', 'bucket', 'points'],
                'additionalProperties' => false,
                'properties' => [
                    'window' => ['type' => 'object'],
                    'bucket' => ['type' => 'string'],
                    'points' => ['type' => 'array'],
                ],
            ],
            'exotic_market_breakdown' => [
                'type' => 'object',
                'required' => ['window', 'total', 'channels', 'markets'],
                'additionalProperties' => false,
                'properties' => [
                    'window' => ['type' => 'object'],
                    'total' => ['type' => 'number'],
                    'channels' => ['type' => 'array'],
                    'markets' => ['type' => 'array'],
                ],
            ],
            'exotic_render_revenue_dashboard' => [
                'type' => 'object',
                'required' => ['widget', 'summary', 'trend', 'market_breakdown'],
                'additionalProperties' => false,
                'properties' => [
                    'widget' => ['type' => 'object'],
                    'summary' => ['type' => 'object'],
                    'trend' => ['type' => 'object'],
                    'market_breakdown' => ['type' => 'object'],
                ],
            ],
            default => ['type' => 'object', 'additionalProperties' => true],
        };
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
                    'scope_required' => $modern || $this->isSubscriptionAudit($name),
                    'ui_resource' => $meta['ui_resource'] ?? null,
                    'status_detail' => $this->isSubscriptionAudit($name)
                        ? ($enabled ? 'Requires an explicit administrator subscription-audit grant.' : 'Disabled in the organisation registry.')
                        : ($modern
                        ? ($enabled ? 'Live for tokens with this permission.' : 'Unavailable until its rollout is enabled.')
                        : ($enabled ? 'Enabled in the organisation registry.' : 'Disabled in the organisation registry.')),
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
            ->reject(fn (array $tool): bool => $this->isSubscriptionAudit($tool['name']))
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

    public function isSubscriptionAudit(string $name): bool
    {
        return str_starts_with($name, 'exotic_subscription_audit_') && isset(self::TOOLS[$name]);
    }

    /** Audit access is opt-in, including for administrator-owned legacy tokens. */
    public function subscriptionAuditToolNames(User $user, McpSettingsService $settings): array
    {
        return collect($this->managementDefinitions($user, $settings))
            ->where('enabled', true)
            ->filter(fn (array $tool): bool => $this->isSubscriptionAudit($tool['name']))
            ->pluck('name')->values()->all();
    }

    public function available(string $name, User $user, McpSettingsService $settings, ?array $abilities = null): bool
    {
        $meta = self::TOOLS[$name] ?? self::MODERN_TOOLS[$name] ?? null;
        $modernTool = array_key_exists($name, self::MODERN_TOOLS);
        if (! $meta || ($modernTool && ! $this->modernToolEnabled($name)) || (! $modernTool && ! (bool) data_get($settings->tool($name), 'enabled', false))) {
            return false;
        }

        if ($this->isSubscriptionAudit($name)) {
            return $user->role === 'admin' && $user->isActive()
                && in_array('mcp:subscription-audit', (array) $abilities, true)
                && in_array('mcp:tool:'.$name, (array) $abilities, true);
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
