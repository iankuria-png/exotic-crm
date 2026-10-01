<?php

/*
|--------------------------------------------------------------------------
| Database Observatory — market database scanner
|--------------------------------------------------------------------------
|
| The scanner is off unless BOTH this deployment switch and the admin
| setting (db_scan_settings.enabled) are on. Every value in `envelope` is the
| tested safety ceiling: the admin UI may lower limits inside it, never raise
| them. Per-host concurrency is fixed at one.
|
*/

return [
    'enabled' => (bool) env('DB_SCANNER_ENABLED', false),

    // Bounded slices run on their own queue so they never share a worker with
    // payments, alerts, sync or heavy work.
    'queue_connection' => env('DB_SCANNER_QUEUE_CONNECTION', 'database_long'),
    'queue' => 'db_scan',

    // SQLite fixture databases are a test-only reader target. Production
    // readers are MySQL/MariaDB with dedicated SELECT-only credentials.
    'allow_sqlite_fixtures' => (bool) env('DB_SCANNER_ALLOW_SQLITE', false),

    'envelope' => [
        'statement_timeout_seconds' => ['min' => 2, 'max' => 10, 'default' => 10],
        'global_slots' => ['min' => 1, 'max' => 2, 'default' => 2],
        'host_slots' => 1,
        'chunk_rows' => ['min' => 100, 'max' => 1000, 'default' => 1000],
        'chunk_bytes' => 4 * 1024 * 1024,
        'value_bytes' => 64 * 1024,
        'connect_timeout_seconds' => 5,
        'slice_seconds' => 45,
        'lease_seconds' => 90,
        'recovery_grace_seconds' => 30,
        'budgets' => [
            'quick' => ['min' => 10, 'max' => 30, 'default' => 30],
            'standard' => ['min' => 30, 'max' => 180, 'default' => 180],
            'deep' => ['min' => 60, 'max' => 600, 'default' => 600],
        ],
        'daily_market_seconds' => ['min' => 60, 'max' => 1800, 'default' => 1800],
        'continuation_gap_seconds' => 60,
        'run_deadline_hours' => 24,
        'sweep_days' => 7,
        'max_transient_failures' => 3,
        'transient_backoff_seconds' => [15, 60, 300],
        'contention_backoff_seconds' => [15, 60],
        'max_findings_per_run' => 10000,
        'max_evidence_bytes_per_run' => 20 * 1024 * 1024,
        'test_sample_limit' => 20,
    ],

    'gates' => [
        // Both default on and must stay on in production. A local machine
        // without the ops sampler or market health checks may turn them off.
        'require_ops_state' => (bool) env('DB_SCANNER_REQUIRE_OPS_STATE', true),
        'require_market_health' => (bool) env('DB_SCANNER_REQUIRE_MARKET_HEALTH', true),

        // Ops state older than this pauses scanning (fail closed).
        'ops_state_max_age_seconds' => 180,
        // Market health must be "healthy" and checked within this window.
        'health_max_age_seconds' => 600,
        // Scanner admission fails closed at this LoadShedder level or above,
        // even while global enforcement is observe-only.
        'max_load_level' => 0,
    ],

    'decoder' => [
        'max_input_bytes' => 64 * 1024,
        'max_output_bytes' => 256 * 1024,
        'max_expansion_ratio' => 16,
        'max_depth' => 3,
        'max_branches' => 16,
        'max_nodes' => 10000,
        'cpu_budget_ms' => 20,
        'pcre_backtrack_limit' => 100000,
        'pcre_recursion_limit' => 1000,
    ],

    'evidence' => [
        'excerpts_per_finding' => 5,
        'excerpt_chars' => 200,
    ],

    'retention' => [
        'event_days' => 30,
        'run_summary_days' => 180,
        'resolved_evidence_days' => 180,
        'audit_days' => 365,
    ],

    // Defaults that seed the editable allowlists on first pack sync.
    'company_email_domains' => ['exotic-online.com', 'exotic-africa.com'],
    'network_domains' => ['exotic-ads.com', 'exotic-online.com', 'exotic-africa.com', 'erotic-africa.com'],
];
