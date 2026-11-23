<?php

return [
    // Capture may be enabled independently. Entitlement writes are released per exact site/account only.
    'capture_enabled' => (bool) env('COLLECTION_CAPTURE_ENABLED', false),
    'workspace_enabled' => (bool) env('COLLECTION_WORKSPACE_ENABLED', false),
    // TZ (6), its Tanzania subsidiary (2), and Ghana (3) run the independently verified WP receipt contract.
    // Ghana's plugin writers and theme expiry bridge were verified live on 18 Nov 2026.
    // Release only the TZ office M-PESA and Ghana office MTN MoMo wallets. Automatic activation still needs
    // every recorded sign-off, approved limits, a fresh phone check-in and a manager switch-on.
    'entitlement_platforms' => [6, 2, 3, 1], // Kenya operation/hold/correction contracts independently verified 23 Nov 2026.
    'automatic_accounts' => ['c12d64b4-d300-4927-bea1-94301e28c1ef', '5c1b4a9f-b95e-4e64-bbf9-76ccc5c713d6', ...((bool) env('KOPOKOPO_COLLECTION_AUTOMATIC_RELEASED', false) ? ['8d5c1032-4ed1-45e0-a842-268491000001'] : [])],
    'verified_writer_platforms' => [6, 2, 3, 1], // Kenya operation/hold/correction contracts independently verified 23 Nov 2026.
    'verified_correction_platforms' => [6, 2, 3, 1], // Kenya operation/hold/correction contracts independently verified 23 Nov 2026.
    'enrichment_seconds' => 25,
    'signature_max_age_seconds' => 300,
    'device_stale_seconds' => 300,
    'max_body_bytes' => 32768,
    'payer_fingerprint_key' => env('COLLECTION_FINGERPRINT_KEY'),
    // Ian approved the full purchased period from correction on 6 November.
    'correction_duration_policy' => 'full_purchased',
    'rule_version' => 'tz-reviewed-v1',
    'parser_versions' => ['vodacom_mpesa_tz' => 'tz-mpesa-v1', 'mtn_momo_gh' => 'gh-mtn-momo-v1', 'kopokopo_buygoods_ke' => 'ke-kopokopo-v1'],
    // Ghana MoMo: customer-submitted transaction IDs are checked against phone receipts (flag only, 18 Nov 2026).
    'claim_check_grace_minutes' => 30,
    'subsidiary_platforms' => [6 => 2],
];
