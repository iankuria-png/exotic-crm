<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile lifecycle
    |--------------------------------------------------------------------------
    |
    | Settings for the Active → Expired → Archived → Removed profile lifecycle.
    | `archive_after_days` controls how long a profile stays in the Expired
    | state (published, contacts hidden, still indexed) before the daily
    | crm:archive-expired command moves it to Archived (also excluded from
    | city/category listings while retaining its indexable URL).
    |
    | The policy is opt-in PER MARKET via `platforms.lifecycle_policy_enabled`.
    | `master_enabled` is a global kill switch: when false the legacy "expire =
    | take offline (private)" behaviour applies everywhere regardless of the
    | per-market flag — use it to disable the whole feature in an emergency.
    |
    */

    'lifecycle' => [
        'master_enabled' => (bool) env('CRM_LIFECYCLE_MASTER_ENABLED', true),
        'archive_after_days' => (int) env('CRM_LIFECYCLE_ARCHIVE_AFTER_DAYS', 90),
    ],

];
