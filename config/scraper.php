<?php

return [
    /*
    | Declared identity for every scraper request. Never spoof a browser here.
    */
    'user_agent' => env('SCRAPER_USER_AGENT', 'ExoticCRMLeadBot/1.0 (+https://crm.exotic-online.com)'),

    'http' => [
        'connect_timeout' => 6,
        'timeout' => 25,
        'max_bytes' => 6 * 1024 * 1024,
        'max_redirects' => 5,
    ],

    // Hard ceilings; per-site crawl settings are clamped to these.
    'limits' => [
        'max_pages' => 25,
        'max_profiles' => 150,
        'max_candidates' => 1000,
        'min_delay_ms' => 500,
        'max_delay_ms' => 15000,
        'run_budget_seconds' => 1500,
    ],

    'defaults' => [
        'max_pages' => 5,
        'max_profiles' => 0,
        'delay_ms' => 1500,
        'follow_profiles' => false,
        'stop_when_no_new' => true,
    ],

    'capture' => [
        'token_ttl_minutes' => 30,
        'max_bytes' => 8 * 1024 * 1024,
        'disk' => 'local',
        'directory' => 'scraper-captures',
    ],

    'render_worker' => [
        'enabled' => (bool) env('SCRAPER_RENDER_WORKER_ENABLED', false),
        'url' => env('SCRAPER_RENDER_WORKER_URL'),
        'secret' => env('SCRAPER_RENDER_WORKER_SECRET'),
        'timeout_seconds' => (int) env('SCRAPER_RENDER_WORKER_TIMEOUT', 60),
    ],
];
