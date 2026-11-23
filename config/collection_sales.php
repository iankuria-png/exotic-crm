<?php

return [
    // Each entry requires accepted exact WP operation/all-writer and receipt-account evidence.
    // Accepted staff-manual TZ receipt adapter: 7 November 2026; automatic policy is separate.
    // Ghana office MTN MoMo wallet (site 3): writers and expiry bridge verified live 18 Nov 2026 (20eb2100).
    // Subsidiary Tanzania uses its existing approval workflow; it is not a paid receiving market here.
    'verified_accounts' => ['c12d64b4-d300-4927-bea1-94301e28c1ef', '5c1b4a9f-b95e-4e64-bbf9-76ccc5c713d6', '8d5c1032-4ed1-45e0-a842-268491000001'],
    'verified_platforms' => [6, 3, 1],
    'max_items' => 20,
    'preview_minutes' => 5,
];
