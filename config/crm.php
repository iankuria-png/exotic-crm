<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile lifecycle
    |--------------------------------------------------------------------------
    |
    | Settings for the Active → Expired → Archived → Removed profile lifecycle.
    | `archive_after_days` controls how long a profile stays in the Expired
    | state (published, contacts hidden, still indexed) before the daily
    | crm:archive-expired command moves it to Archived (also excluded from
    | city/category listings while retaining its indexable URL).
    |
    | The policy is opt-in PER MARKET via `platforms.lifecycle_policy_enabled`.
    | `master_enabled` is a global kill switch: when false the legacy "expire =
    | take offline (private)" behaviour applies everywhere regardless of the
    | per-market flag — use it to disable the whole feature in an emergency.
    |
    */

    'lifecycle' => [
        'master_enabled' => (bool) env('CRM_LIFECYCLE_MASTER_ENABLED', true),
        'archive_after_days' => (int) env('CRM_LIFECYCLE_ARCHIVE_AFTER_DAYS', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image proxy
    |--------------------------------------------------------------------------
    |
    | Every proxied asset occupies one PHP-FPM child for the whole upstream
    | fetch, and the production pool is small. These bounds exist so that a
    | slow WordPress market cannot convert its own latency into CRM downtime;
    | see the 8-9 September 2026 worker-starvation incidents.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Missed chats tile
    |--------------------------------------------------------------------------
    |
    | The dashboard reads this figure from cache only. `refresh_minutes` is how
    | often the scheduled refresh recomputes it, and `max_pages` bounds how far
    | one market's conversation list is walked — a guard against a pagination
    | bug becoming an unbounded crawl, not a limit markets are meant to reach.
    |
    */

    'missed_chats' => [
        'max_pages' => (int) env('CRM_MISSED_CHATS_MAX_PAGES', 20),
        'refresh_minutes' => (int) env('CRM_MISSED_CHATS_REFRESH_MINUTES', 10),
    ],

    'image_proxy' => [
        'connect_timeout' => (int) env('CRM_IMAGE_PROXY_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('CRM_IMAGE_PROXY_TIMEOUT', 8),
        // Larger assets are redirected to origin rather than pulled through a
        // worker. Images are far below this; long profile videos are not.
        'max_bytes' => (int) env('CRM_IMAGE_PROXY_MAX_BYTES', 8 * 1024 * 1024),
        'skip_unhealthy_markets' => (bool) env('CRM_IMAGE_PROXY_SKIP_UNHEALTHY', true),
        // Sized against the FPM pool, not the browser's appetite. A pool of N
        // workers can sustain at most N/hold_seconds requests per second in
        // aggregate, whatever a rate limit claims.
        'per_ip_per_minute' => (int) env('CRM_IMAGE_PROXY_PER_IP_PER_MINUTE', 40),
        'global_per_minute' => (int) env('CRM_IMAGE_PROXY_GLOBAL_PER_MINUTE', 120),
    ],

];
