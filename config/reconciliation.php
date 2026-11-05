<?php

return [
    'enabled' => (bool) env('DAILY_RECONCILIATION_ENABLED', true),
    'trials_enabled' => (bool) env('TRIAL_APPROVAL_WORKSPACE_ENABLED', true),
    'timezone' => 'Africa/Nairobi',
    'max_units' => 10000,
];
