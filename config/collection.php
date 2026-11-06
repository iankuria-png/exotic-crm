<?php

return [
    // Capture may be enabled independently. No entitlement route is accepted by default.
    'capture_enabled' => (bool) env('COLLECTION_CAPTURE_ENABLED', false),
    'workspace_enabled' => (bool) env('COLLECTION_WORKSPACE_ENABLED', false),
    'entitlement_platforms' => [],
    'automatic_accounts' => [],
    'verified_writer_platforms' => [],
    'verified_correction_platforms' => [],
    'enrichment_seconds' => 25,
    'signature_max_age_seconds' => 300,
    'device_stale_seconds' => 300,
    'max_body_bytes' => 32768,
    'payer_fingerprint_key' => env('COLLECTION_FINGERPRINT_KEY'),
    // Ian approved the full purchased period from correction on 6 November.
    'correction_duration_policy' => 'full_purchased',
    'rule_version' => 'tz-reviewed-v1',
    'parser_versions' => ['vodacom_mpesa_tz' => 'tz-mpesa-v1'],
    'subsidiary_platforms' => [6 => 2],
];
