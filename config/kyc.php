<?php

return [
    'settings_id' => 1,
    'max_doc_bytes' => 20 * 1024 * 1024,
    'signed_put_ttl_seconds' => 300,
    'signed_get_ttl_seconds' => 60,
    'upload_jwt_ttl_seconds' => 300,
    'reverify_interval_days' => 365,
    'reverify_dispatch_pace_seconds' => 5,
    'fanout_queue_concurrency' => 4,
    'shared_key_header' => 'X-Exotic-CRM-Sync-Key',
];
