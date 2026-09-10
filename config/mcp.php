<?php

return [
    'enabled' => (bool) env('MCP_ENABLED', false),
    'endpoint' => env('MCP_ENDPOINT', '/api/mcp'),
    'protocol_versions' => ['2025-06-18', '2025-03-26'],
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('MCP_ALLOWED_ORIGINS', ''))
    ))),
    'tools' => [
        'exotic_catalog' => ['enabled' => true, 'min_role' => 'sales'],
        'exotic_revenue_summary' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_revenue_trend' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_market_breakdown' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_agent_performance' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_peak_hours' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_lifecycle_summary' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_churn_analysis' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_cohort_retention' => ['enabled' => true, 'min_role' => 'sub_admin'],
        'exotic_client_snapshot' => ['enabled' => true, 'min_role' => 'admin'],
        'exotic_error_digest' => ['enabled' => true, 'min_role' => 'admin'],
        'exotic_system_vitals' => ['enabled' => true, 'min_role' => 'admin'],
        'exotic_market_health' => ['enabled' => true, 'min_role' => 'admin'],
        'exotic_schema_dictionary' => ['enabled' => true, 'min_role' => 'admin'],
        'exotic_run_reporting_sql' => ['enabled' => false, 'min_role' => 'admin'],
    ],
    'resources' => [
        'exotic://context/who-is-who' => ['enabled' => true, 'path' => 'mcp/who-is-who.md'],
        'exotic://context/reporting-views' => ['enabled' => true, 'path' => 'mcp/reporting-views.md'],
        'exotic://context/conventions' => ['enabled' => true, 'path' => 'mcp/conventions.md'],
    ],
    'sql_hatch' => [
        'enabled' => false,
        'views' => ['vw_mcp_lifecycle_rollup', 'vw_mcp_revenue_rollup'],
        'default_row_limit' => 50,
        'max_row_limit' => 500,
        'timeout_seconds' => 10,
        'forbidden_columns' => [
            'client_id', 'deal_id', 'payment_id', 'lead_id', 'agent_id', 'user_id',
            'name', 'phone', 'email', 'bio', 'notes', 'message', 'body',
            'raw_payload', 'payment_data',
        ],
    ],
    'limits' => [
        'rate_per_minute' => 30,
        'daily_row_budget' => 200000,
        'daily_bytes_budget' => 50000000,
        'on_exhaustion' => 'throttle',
    ],
    'token_policy' => [
        'default_ttl_days' => 90,
        'max_ttl_days' => 365,
        'allow_tool_allowlist' => true,
    ],
    'sanitisation' => [
        'truncate_chars' => 500,
        'strip_urls' => true,
        'strip_credentials' => true,
    ],
    'audit' => [
        'retain_days' => 90,
        'alert_bytes_per_hour' => 10000000,
    ],
];
