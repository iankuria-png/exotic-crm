<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Weekly Briefings
    |--------------------------------------------------------------------------
    | Environment defaults for the weekly briefing feature. Settings overrides
    | stored in the `ai_briefings_config` IntegrationSetting row win when present
    | (resolved by App\Services\Ai\AiBriefingSettingsService).
    */
    'briefings' => [
        'enabled' => filter_var(env('AI_BRIEFINGS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'weekly_cost_cap_usd' => (float) env('AI_BRIEFINGS_WEEKLY_COST_CAP_USD', 5.00),
        'link_ttl_days' => (int) env('AI_BRIEFINGS_LINK_TTL_DAYS', 14),
        // Base URL used to build the /b/{token} deep link in SMS.
        'base_url' => rtrim((string) env('AI_BRIEFINGS_BASE_URL', env('APP_URL', 'https://crm.exotic-online.com')), '/'),
        'timezone' => env('AI_BRIEFINGS_TIMEZONE', 'Africa/Nairobi'),
        'schedule' => [
            'ceo_enabled' => filter_var(env('AI_BRIEFINGS_CEO_ENABLED', true), FILTER_VALIDATE_BOOL),
            'sales_enabled' => filter_var(env('AI_BRIEFINGS_SALES_ENABLED', true), FILTER_VALIDATE_BOOL),
            'ceo_time' => env('AI_BRIEFINGS_CEO_TIME', '07:30'),
            'sales_time' => env('AI_BRIEFINGS_SALES_TIME', '07:45'),
        ],
        // Admin/CEO can view any briefing page for support/audit.
        'admin_override' => filter_var(env('AI_BRIEFINGS_ADMIN_OVERRIDE', true), FILTER_VALIDATE_BOOL),
        // Optional SMS provider override; null = use existing NotificationService routing.
        'sms_provider_override' => env('AI_BRIEFINGS_SMS_PROVIDER', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Talk to Your Data (NL -> SQL) — Phase 2
    |--------------------------------------------------------------------------
    */
    'insights' => [
        'enabled' => filter_var(env('AI_INSIGHTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Roles allowed in addition to CEO (is_ceo always allowed).
        'allowed_roles' => ['admin', 'sub_admin'],
        'sources' => [
            'business_data' => true,
            'sales_data' => true,
            'project_status' => true,
            'hybrid' => true,
        ],
        'default_row_limit' => (int) env('AI_INSIGHTS_DEFAULT_ROW_LIMIT', 100),
        'max_row_limit' => (int) env('AI_INSIGHTS_MAX_ROW_LIMIT', 1000),
        'sql_timeout_seconds' => (int) env('AI_INSIGHTS_SQL_TIMEOUT', 10),
        'chart_suggestions' => true,
        'show_generated_sql' => true,
        'rate_limit_per_minute' => (int) env('AI_INSIGHTS_RATE_LIMIT', 12),
        'daily_cost_cap_usd' => (float) env('AI_INSIGHTS_DAILY_COST_CAP_USD', 5.00),
    ],

    /*
    |--------------------------------------------------------------------------
    | Project Intelligence (read-only GitHub commits + deploy status) — Phase 2
    |--------------------------------------------------------------------------
    */
    'project_intelligence' => [
        'enabled' => filter_var(env('AI_PROJECT_INTELLIGENCE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'commit_lookback' => (int) env('AI_PROJECT_INTELLIGENCE_COMMIT_LOOKBACK', 50),
        'include_deployment_history' => true,
        'show_commit_urls' => true,
        'allowed_roles' => ['admin', 'sub_admin'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider / model policy
    |--------------------------------------------------------------------------
    | Default provider order reuses services.seo_engine.providers. Settings may
    | override the analytics model independently for stronger SQL generation.
    */
    'providers' => [
        'sql_model' => env('AI_SQL_MODEL', 'deepseek-v4-pro'),
        'summary_model' => env('AI_SUMMARY_MODEL', 'deepseek-v4-flash'),
        // Force a single provider for analytics calls; null = full waterfall.
        'force_provider' => env('AI_FORCE_PROVIDER'),
        // Prompt logging mode: truncated | hash_only | full
        'prompt_logging' => env('AI_PROMPT_LOGGING', 'truncated'),
        'prompt_truncate_chars' => (int) env('AI_PROMPT_TRUNCATE_CHARS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cost estimation rates (USD per 1,000,000 tokens)
    |--------------------------------------------------------------------------
    | Used by AiGateway to estimate est_cost_usd per interaction. Tunable via
    | env without code changes; falls back to deepseek rates for unknowns.
    */
    'cost_rates' => [
        'deepseek' => ['input' => 0.27, 'output' => 1.10],
        'claude' => ['input' => 3.00, 'output' => 15.00],
        'openai' => ['input' => 2.50, 'output' => 10.00],
        'gemini' => ['input' => 1.25, 'output' => 5.00],
        'default' => ['input' => 0.50, 'output' => 1.50],
    ],

    /*
    |--------------------------------------------------------------------------
    | Read-only reporting views allow-list (Phase 2 SQL safety)
    |--------------------------------------------------------------------------
    | Only these views may appear in NL->SQL FROM/JOIN clauses. Every view must
    | expose a `platform_id` column for server-side market scoping.
    */
    'reporting_views' => [
        'vw_payments_usd',
        'vw_market_revenue',
        'vw_agent_perf',
    ],
];
