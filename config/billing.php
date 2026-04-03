<?php

$flag = static fn (string $key, bool $default = false): bool => filter_var(
    env($key, $default),
    FILTER_VALIDATE_BOOL
);

return [
    'enabled' => $flag('BILLING_ENABLED'),

    'registry' => [
        'enabled' => $flag('BILLING_FEATURE_REGISTRY'),
    ],

    'routing' => [
        'enabled' => $flag('BILLING_FEATURE_ROUTING'),
    ],

    'provider_transactions' => [
        'enabled' => $flag('BILLING_FEATURE_PROVIDER_TRANSACTIONS'),
    ],

    'dual_write' => [
        'enabled' => $flag('BILLING_FEATURE_DUAL_WRITE'),
    ],

    'shadow_read' => [
        'enabled' => $flag('BILLING_FEATURE_SHADOW_READ'),
    ],

    'billing_system_live_read' => [
        'enabled' => $flag('BILLING_FEATURE_BILLING_SYSTEM_LIVE_READ'),
    ],

    'workspace' => [
        'enabled' => $flag('BILLING_FEATURE_WORKSPACE'),
    ],

    'diagnostics' => [
        'v2' => [
            'enabled' => $flag('BILLING_FEATURE_DIAGNOSTICS_V2'),
        ],
    ],

    'wordpress' => [
        'versioned_payloads' => [
            'enabled' => $flag('BILLING_FEATURE_WORDPRESS_VERSIONED_PAYLOADS'),
        ],
    ],

    'wallet_auto_renew' => [
        'enabled' => $flag('BILLING_FEATURE_WALLET_AUTO_RENEW'),
    ],

    'provider_family' => [
        'daraja' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_DARAJA'),
        ],
        'kopokopo' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_KOPOKOPO'),
        ],
        'pawapay' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_PAWAPAY'),
        ],
        'elemitech' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_ELEMITECH'),
        ],
        'dusupay' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_DUSUPAY'),
        ],
        'nowpayments' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_NOWPAYMENTS'),
        ],
        'pesapal' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_PESAPAL'),
        ],
        'paystack' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_PAYSTACK'),
        ],
        'paypal' => [
            'enabled' => $flag('BILLING_PROVIDER_FAMILY_PAYPAL'),
        ],
    ],

    'market_surface_cutover' => [],
];
