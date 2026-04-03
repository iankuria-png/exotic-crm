<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote WordPress Sync Guard
    |--------------------------------------------------------------------------
    |
    | Non-production CRM environments should not write to remote WordPress
    | sites by default. This prevents local development from clobbering live
    | wallet configuration and credentials. Set the env var to true only when
    | you intentionally need a non-production CRM to push to a remote WP site.
    |
    */
    'allow_remote_sync_from_non_production' => env('WALLET_ALLOW_REMOTE_SYNC_FROM_NON_PRODUCTION', false),
];
