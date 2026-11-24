<?php

return [
    // Deliberately closed until the CRM pilot is configured and jointly accepted.
    'enabled' => env('EWMS_KPI_ENABLED', false),
    'allowed_platform_ids' => array_values(array_filter(array_map(
        'trim', explode(',', (string) env('EWMS_KPI_ALLOWED_PLATFORM_IDS', ''))
    ), static fn (string $id): bool => $id !== '')),
];
