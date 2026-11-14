<?php

return [
    // Capture may be enabled independently. Entitlement writes are released per exact site/account only.
    'capture_enabled' => (bool) env('COLLECTION_CAPTURE_ENABLED', false),
    'workspace_enabled' => (bool) env('COLLECTION_WORKSPACE_ENABLED', false),
    // 9 Nov 2026: TZ (6) and its Tanzania subsidiary (2) run WP sync v2/compensate2 with durable owner,
    // independently verified. Release ONLY the TZ office M-PESA wallet. Automatic activation still needs
    // every recorded sign-off, approved limits, a fresh phone check-in and a manager switch-on.
    'entitlement_platforms' => [6, 2],
    'automatic_accounts' => ['c12d64b4-d300-4927-bea1-94301e28c1ef'],
    'verified_writer_platforms' => [6, 2],
    'verified_correction_platforms' => [6, 2],
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
