<?php

return [
    // Explicit per-market acceptance is required after exact WP endpoint/writer verification.
    'automatic_platforms' => array_values(array_filter(array_map('intval', explode(',', (string) env('PAYMENT_RECOVERY_AUTOMATIC_PLATFORMS', ''))))),
    'verified_operation_platforms' => array_values(array_filter(array_map('intval', explode(',', (string) env('PAYMENT_RECOVERY_VERIFIED_OPERATION_PLATFORMS', ''))))),
];
