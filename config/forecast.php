<?php

return [
    'history_months' => 6,
    'minimum_qualifying_months' => 3,
    'settle_days' => 14,
    'cache_ttl_seconds' => 600,
    'sync_row_budget' => 250000,
    'queue_after_days' => 30,
    'volume_floors' => [
        'failed_recovery' => 20,
        'renewal' => 25,
        'new_activations' => 30,
        'signup_source_conversion' => 30,
        'churn_winback' => 15,
    ],
    'effort_weights' => [
        'failed_recovery' => 1.0,
        'renewal' => 1.2,
        'churn_winback' => 2.0,
        'new_activations' => 2.5,
        'signup_source_conversion' => 2.5,
        'new_market' => 4.0,
    ],
    'stretch_bands' => [
        'failed_recovery' => ['points' => 6.0, 'max' => 85.0],
        'renewal' => ['points' => 5.0, 'max' => 85.0],
        'signup_source_conversion' => ['points' => 5.0, 'max' => 60.0],
        'churn_winback' => ['points' => 8.0, 'max' => 40.0],
    ],
    'count_growth_caps' => [
        'new_activations' => 1.25,
    ],
    'concentration_cap' => 0.5,
    'rate_increment_points' => 0.5,
    'count_increment' => 1,
    'new_market_default_monthly_target' => 10000.0,
];
