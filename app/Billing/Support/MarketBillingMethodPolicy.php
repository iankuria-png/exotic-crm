<?php

namespace App\Billing\Support;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\Platform;
use App\Services\WalletSettingsService;

class MarketBillingMethodPolicy
{
    public const CONTRACT_VERSION = '2026-04-08';

    /**
     * @var array<string, string>
     */
    private const CANONICAL_METHOD_MAP = [
        'manual' => 'manual',
        'payment_link' => 'payment_link',
        'link' => 'payment_link',
        'stk_push' => 'stk_push',
        'stk' => 'stk_push',
        'wallet_balance' => 'wallet_balance',
        'wallet' => 'wallet_balance',
    ];

    /**
     * @var array<string, string>
     */
    private const CRM_ALIAS_MAP = [
        'manual' => 'manual',
        'payment_link' => 'link',
        'stk_push' => 'stk',
    ];

    /**
     * @var list<string>
     */
    private const DEFAULT_CANONICAL_METHODS = [
        'manual',
        'stk_push',
        'payment_link',
        'wallet_balance',
    ];

    public function __construct(
        private readonly BillingConfigurationRepository $billingConfigurationRepository,
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function forPlatform(Platform|int|null $platform): array
    {
        $resolvedPlatform = $platform instanceof Platform
            ? $platform
            : (($platform !== null && (int) $platform > 0) ? Platform::query()->find((int) $platform) : null);

        $marketId = (int) ($resolvedPlatform?->id ?? ($platform instanceof Platform ? $platform->id : (int) $platform));
        $subscriptionRule = $marketId > 0
            ? $this->billingConfigurationRepository->subscriptionRuleForMarket($marketId)
            : null;
        $walletRule = $marketId > 0
            ? $this->billingConfigurationRepository->walletRuleForMarket($marketId)
            : null;
        $manualMethods = $marketId > 0
            ? $this->billingConfigurationRepository->manualPaymentMethodsForMarket($marketId, true)
            : collect();

        $activationMethods = $this->normalizeCanonicalMethods(
            $subscriptionRule?->activation_method_json,
            self::DEFAULT_CANONICAL_METHODS
        );
        $renewalMethods = $this->normalizeCanonicalMethods(
            $subscriptionRule?->renewal_method_json,
            self::DEFAULT_CANONICAL_METHODS
        );

        $freeTrialConfigured = $this->walletSettingsService->freeTrialPinIsConfigured();
        $freeTrialEnabled = $subscriptionRule
            ? ((bool) data_get($subscriptionRule->free_trial_json, 'enabled', false) && $freeTrialConfigured)
            : $freeTrialConfigured;

        $walletAutoRenewEnabled = (bool) config('billing.wallet_auto_renew.enabled', false)
            && (bool) ($walletRule?->enabled ?? false)
            && (bool) data_get($walletRule?->auto_renew_json, 'enabled', false)
            && (bool) $this->renewalMethodAllowsAutoRenew($subscriptionRule?->renewal_method_json)
            && in_array('wallet_balance', $renewalMethods, true);

        return [
            'version' => self::CONTRACT_VERSION,
            'market' => [
                'platform_id' => $marketId > 0 ? $marketId : null,
                'currency' => $resolvedPlatform?->currency_code,
            ],
            'activation' => [
                'methods' => $activationMethods,
                'crm_methods' => $this->canonicalToCrmMethods($activationMethods, $freeTrialEnabled),
                'manual_methods' => in_array('manual', $activationMethods, true)
                    ? $manualMethods->map(fn ($method) => $this->serializeManualMethod($method))->values()->all()
                    : [],
            ],
            'renewal' => [
                'methods' => $renewalMethods,
                'crm_methods' => $this->canonicalToCrmMethods($renewalMethods, $freeTrialEnabled),
                'wallet_auto_renew' => $walletAutoRenewEnabled,
            ],
            'free_trial' => [
                'enabled' => $freeTrialEnabled,
            ],
        ];
    }

    /**
     * @param  \App\Models\BillingManualPaymentMethod  $method
     * @return array<string, mixed>
     */
    private function serializeManualMethod($method): array
    {
        $details = is_array($method->details_json) ? $method->details_json : [];

        return [
            'key' => (string) $method->method_key,
            'label' => trim((string) ($method->display_name ?: $this->defaultManualMethodLabel((string) $method->method_key))),
            'enabled' => (bool) $method->enabled,
            'instruction_intro' => $method->instruction_intro,
            'instruction_footer' => $method->instruction_footer,
            'proof_required' => (bool) $method->proof_required,
            'sender_name_required' => (bool) $method->sender_name_required,
            'transaction_id_required' => (bool) $method->transaction_id_required,
            'auto_activate_on_submission' => (bool) $method->auto_activate_on_submission,
            'details' => $details,
        ];
    }

    private function defaultManualMethodLabel(string $methodKey): string
    {
        return match (strtolower(trim($methodKey))) {
            'collector' => 'Collector',
            'paybill' => 'Paybill',
            'bank' => 'Bank transfer',
            default => ucfirst(strtolower(trim($methodKey))),
        };
    }

    public function contract(Platform|int|null $platform): array
    {
        return $this->forPlatform($platform);
    }

    public function allowsCrmMethod(Platform|int|null $platform, string $surface, string $method): bool
    {
        $policy = $this->forPlatform($platform);
        $methods = data_get($policy, $surface . '.crm_methods', []);

        return in_array(strtolower(trim($method)), $methods, true);
    }

    public function allowsCanonicalMethod(Platform|int|null $platform, string $surface, string $method): bool
    {
        $policy = $this->forPlatform($platform);
        $methods = data_get($policy, $surface . '.methods', []);

        return in_array(strtolower(trim($method)), $methods, true);
    }

    /**
     * @param  array<mixed>|null  $rule
     * @param  list<string>  $defaults
     * @return list<string>
     */
    private function normalizeCanonicalMethods(?array $rule, array $defaults): array
    {
        $rawMethods = is_array($rule['methods'] ?? null)
            ? $rule['methods']
            : (is_array($rule) ? $rule : []);

        $methods = collect($rawMethods)
            ->map(function ($value) {
                $normalized = strtolower(trim((string) $value));

                if ($normalized === 'wallet_auto_renew') {
                    return 'wallet_balance';
                }

                return self::CANONICAL_METHOD_MAP[$normalized] ?? null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($methods === []) {
            return $defaults;
        }

        return $methods;
    }

    /**
     * @param  list<string>  $canonicalMethods
     * @return list<string>
     */
    private function canonicalToCrmMethods(array $canonicalMethods, bool $includeFreeTrial): array
    {
        $methods = collect($canonicalMethods)
            ->map(fn (string $method) => self::CRM_ALIAS_MAP[$method] ?? null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($includeFreeTrial) {
            $methods[] = 'free_trial';
        }

        return array_values(array_unique($methods));
    }

    private function renewalMethodAllowsAutoRenew(?array $rule): bool
    {
        if ($rule === null) {
            return true;
        }

        if ((bool) data_get($rule, 'wallet_auto_renew', false)) {
            return true;
        }

        $rawMethods = is_array($rule['methods'] ?? null) ? $rule['methods'] : $rule;

        return collect(is_array($rawMethods) ? $rawMethods : [])
            ->contains(fn ($value) => strtolower(trim((string) $value)) === 'wallet_auto_renew');
    }
}
