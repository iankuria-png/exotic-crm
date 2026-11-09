<?php

return [
    'pairs' => [6 => 2, 5 => 16, 27 => 9],
    'preview_minutes' => 15,
    'lookup_limit' => 50000,
    'lookup_seconds' => 10,
    // Copying TZ public profiles into new paired profiles stays Off until both
    // sites report profile-copy contract 1 and the versioned workers are live.
    'profile_copy_enabled' => (bool) env('SUBSIDIARY_PROFILE_COPY_ENABLED', false),
    'profile_copy_contract' => 1,
    // Pairs that copy new profiles by default when no saved pair policy exists.
    'profile_copy_default_pairs' => [6 => 2],
    'profile_copy_max_download_bytes' => 60 * 1024 * 1024,
    'profile_copy_slice_seconds' => 30,
];
