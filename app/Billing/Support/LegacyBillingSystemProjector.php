<?php

namespace App\Billing\Support;

use App\Models\BillingSystemSetting;

class LegacyBillingSystemProjector
{
    /**
     * @param  array<string, mixed>  $fallback
     * @return array<string, mixed>
     */
    public function project(?BillingSystemSetting $setting, array $fallback = []): array
    {
        if ($setting === null) {
            return $fallback;
        }

        $projected = $fallback;

        $projected['enabled'] = (bool) data_get($setting->mode_json, 'enabled', $fallback['enabled'] ?? false);
        $projected['mode'] = (string) data_get($setting->mode_json, 'mode', $fallback['mode'] ?? 'disabled');
        $projected['default_currency'] = (string) data_get(
            $setting->mode_json,
            'default_currency',
            $fallback['default_currency'] ?? 'KES'
        );
        $projected['max_single_topup_default'] = (string) data_get(
            $setting->mode_json,
            'max_single_topup_default',
            $fallback['max_single_topup_default'] ?? '50000.00'
        );
        $projected['max_wallet_balance_default'] = (string) data_get(
            $setting->mode_json,
            'max_wallet_balance_default',
            $fallback['max_wallet_balance_default'] ?? '200000.00'
        );

        foreach (['sandbox', 'production'] as $environment) {
            $projected['billing_domains'][$environment] = (string) data_get(
                $setting->domain_json,
                $environment,
                data_get($fallback, "billing_domains.{$environment}", '')
            );

            $projected['billing_branding'][$environment] = [
                'business_name' => (string) data_get(
                    $setting->branding_json,
                    "{$environment}.business_name",
                    data_get($fallback, "billing_branding.{$environment}.business_name", '')
                ),
                'description' => (string) data_get(
                    $setting->branding_json,
                    "{$environment}.description",
                    data_get($fallback, "billing_branding.{$environment}.description", '')
                ),
            ];
        }

        foreach ([
            'redirect_delay_seconds',
            'wallet_refresh_rate_limit_seconds',
            'wallet_refresh_timeout_seconds',
            'topup_poll_interval_seconds',
        ] as $key) {
            $projected[$key] = (int) data_get($setting->timing_json, $key, $fallback[$key] ?? 0);
        }

        $projected['smtp'] = [
            'enabled' => (bool) data_get($setting->smtp_json, 'enabled', data_get($fallback, 'smtp.enabled', false)),
            'host' => (string) data_get($setting->smtp_json, 'host', data_get($fallback, 'smtp.host', '')),
            'port' => (int) data_get($setting->smtp_json, 'port', data_get($fallback, 'smtp.port', 587)),
            'username' => (string) data_get($setting->smtp_json, 'username', data_get($fallback, 'smtp.username', '')),
            'password' => (string) data_get($setting->smtp_json, 'password', data_get($fallback, 'smtp.password', '')),
            'encryption' => (string) data_get($setting->smtp_json, 'encryption', data_get($fallback, 'smtp.encryption', 'tls')),
            'from_address' => (string) data_get($setting->smtp_json, 'from_address', data_get($fallback, 'smtp.from_address', '')),
            'from_name' => (string) data_get($setting->smtp_json, 'from_name', data_get($fallback, 'smtp.from_name', '')),
        ];

        $projected['pin_hash'] = (string) data_get($setting->pin_policy_json, 'operator.pin_hash', $fallback['pin_hash'] ?? '');
        $projected['pin_last_updated_at'] = data_get($setting->pin_policy_json, 'operator.last_updated_at', $fallback['pin_last_updated_at'] ?? null);
        $projected['free_trial_pin_hash'] = (string) data_get($setting->pin_policy_json, 'free_trial.pin_hash', $fallback['free_trial_pin_hash'] ?? '');
        $projected['free_trial_pin_last_updated_at'] = data_get($setting->pin_policy_json, 'free_trial.last_updated_at', $fallback['free_trial_pin_last_updated_at'] ?? null);
        $projected['discount_pin_hash'] = (string) data_get($setting->pin_policy_json, 'discount.pin_hash', $fallback['discount_pin_hash'] ?? '');
        $projected['discount_pin_last_updated_at'] = data_get($setting->pin_policy_json, 'discount.last_updated_at', $fallback['discount_pin_last_updated_at'] ?? null);
        $projected['discount_config'] = [
            'max_percentage_by_platform' => (array) data_get(
                $setting->discount_policy_json,
                'max_percentage_by_platform',
                data_get($fallback, 'discount_config.max_percentage_by_platform', [])
            ),
        ];

        return $projected;
    }

    /**
     * @param  array<string, mixed>  $legacyConfig
     * @return array<string, mixed>
     */
    public function payloadFromLegacy(array $legacyConfig): array
    {
        return [
            'scope' => 'global',
            'mode_json' => [
                'enabled' => (bool) ($legacyConfig['enabled'] ?? false),
                'mode' => (string) ($legacyConfig['mode'] ?? 'disabled'),
                'default_currency' => (string) ($legacyConfig['default_currency'] ?? 'KES'),
                'max_single_topup_default' => (string) ($legacyConfig['max_single_topup_default'] ?? '50000.00'),
                'max_wallet_balance_default' => (string) ($legacyConfig['max_wallet_balance_default'] ?? '200000.00'),
            ],
            'domain_json' => (array) ($legacyConfig['billing_domains'] ?? []),
            'branding_json' => (array) ($legacyConfig['billing_branding'] ?? []),
            'timing_json' => [
                'redirect_delay_seconds' => (int) ($legacyConfig['redirect_delay_seconds'] ?? 3),
                'wallet_refresh_rate_limit_seconds' => (int) ($legacyConfig['wallet_refresh_rate_limit_seconds'] ?? 15),
                'wallet_refresh_timeout_seconds' => (int) ($legacyConfig['wallet_refresh_timeout_seconds'] ?? 15),
                'topup_poll_interval_seconds' => (int) ($legacyConfig['topup_poll_interval_seconds'] ?? 10),
            ],
            'smtp_json' => (array) ($legacyConfig['smtp'] ?? []),
            'pin_policy_json' => [
                'operator' => [
                    'pin_hash' => (string) ($legacyConfig['pin_hash'] ?? ''),
                    'last_updated_at' => $legacyConfig['pin_last_updated_at'] ?? null,
                ],
                'free_trial' => [
                    'pin_hash' => (string) ($legacyConfig['free_trial_pin_hash'] ?? ''),
                    'last_updated_at' => $legacyConfig['free_trial_pin_last_updated_at'] ?? null,
                ],
                'discount' => [
                    'pin_hash' => (string) ($legacyConfig['discount_pin_hash'] ?? ''),
                    'last_updated_at' => $legacyConfig['discount_pin_last_updated_at'] ?? null,
                ],
            ],
            'discount_policy_json' => [
                'max_percentage_by_platform' => (array) data_get($legacyConfig, 'discount_config.max_percentage_by_platform', []),
            ],
        ];
    }
}
