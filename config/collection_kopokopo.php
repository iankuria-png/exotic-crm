<?php

return [
    'enabled' => (bool) env('KOPOKOPO_COLLECTION_ENABLED', true),
    'account_id' => '8d5c1032-4ed1-45e0-a842-268491000001',
    'platform_id' => 1,
    'till' => '268491',
    'automatic_rollout_enabled' => (bool) env('KOPOKOPO_COLLECTION_AUTOMATIC_RELEASED', false),
    'parser_version' => 'ke-kopokopo-v1',
    'max_webhook_bytes' => 65536,
    'max_poll_bytes' => 2097152,
    'max_poll_rows' => 2000,
    'poll_window_seconds' => 3600,
    'poll_overlap_seconds' => 300,
    'coverage_stale_seconds' => 900,
    'poll_stale_seconds' => 1200,
];
