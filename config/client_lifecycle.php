<?php

return [
    'lock_store' => env('CLIENT_LIFECYCLE_LOCK_STORE'),
    'lock_ttl_seconds' => (int) env('CLIENT_LIFECYCLE_LOCK_TTL_SECONDS', 600),
    'lock_wait_seconds' => (int) env('CLIENT_LIFECYCLE_LOCK_WAIT_SECONDS', 5),
    'hold_during_test_transactions' => false,
];
