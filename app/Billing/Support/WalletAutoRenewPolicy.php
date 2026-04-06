<?php

namespace App\Billing\Support;

use App\Models\Deal;
use App\Services\WalletCheckoutService;

class WalletAutoRenewPolicy
{
    public const POLICY_VERSION = '2026-04-06';

    public function __construct(
        private readonly MarketBillingMethodPolicy $marketBillingMethodPolicy,
        private readonly WalletCheckoutService $walletCheckoutService
    ) {
    }

    public function forDeal(Deal $deal): array
    {
        $deal->loadMissing(['client.platform', 'product']);

        $client = $deal->client;
        $product = $deal->product;
        $marketPolicy = $this->marketBillingMethodPolicy->forPlatform($deal->platform_id);
        $renewalMethods = array_values(array_filter((array) data_get($marketPolicy, 'renewal.methods', [])));
        $fallbackMethod = $this->resolveFallbackMethod($renewalMethods);

        if (!$client) {
            return $this->decision('escalate', 'missing_client', 'Deal has no linked client.', $marketPolicy, $fallbackMethod);
        }

        if ((string) $deal->status !== 'active') {
            return $this->decision('skip', 'deal_not_active', 'Only active subscriptions are eligible for wallet auto-renew.', $marketPolicy, $fallbackMethod);
        }

        if (!$deal->expires_at) {
            return $this->decision('escalate', 'missing_expiry', 'Subscription expiry is missing, so auto-renew cannot be evaluated safely.', $marketPolicy, $fallbackMethod);
        }

        if ((bool) $deal->renewal_reminders_paused) {
            return $this->decision('skip', 'renewal_paused', 'Renewal reminders are paused for this subscription.', $marketPolicy, $fallbackMethod);
        }

        if (!$product) {
            return $this->decision('escalate', 'missing_product', 'Subscription product metadata is missing.', $marketPolicy, $fallbackMethod);
        }

        if (!(bool) config('billing.wallet_auto_renew.enabled', false)) {
            return $this->decision('skip', 'feature_disabled', 'Wallet auto-renew feature flag is disabled.', $marketPolicy, $fallbackMethod);
        }

        if (!(bool) data_get($marketPolicy, 'renewal.wallet_auto_renew', false)) {
            return $this->decision('skip', 'market_policy_disabled', 'Wallet auto-renew is disabled by market billing policy.', $marketPolicy, $fallbackMethod);
        }

        if (!in_array('wallet_balance', $renewalMethods, true)) {
            return $this->decision('skip', 'wallet_method_disabled', 'Wallet balance renewals are not enabled for this market.', $marketPolicy, $fallbackMethod);
        }

        try {
            $pricing = $this->walletCheckoutService->resolveDealRenewalPricing($deal);
        } catch (\Throwable $exception) {
            return $this->decision(
                'escalate',
                'pricing_unavailable',
                $exception->getMessage(),
                $marketPolicy,
                $fallbackMethod
            );
        }

        $balance = round((float) ($client->wallet_balance ?? 0), 2);
        $requiredAmount = round((float) ($pricing['amount'] ?? 0), 2);

        if ($requiredAmount <= 0) {
            return $this->decision('escalate', 'invalid_pricing', 'Renewal amount must be greater than zero.', $marketPolicy, $fallbackMethod, $pricing, $balance);
        }

        if ($balance < $requiredAmount) {
            $action = $fallbackMethod ? 'send_fallback' : 'escalate';
            $reasonCode = $fallbackMethod ? 'insufficient_wallet_balance' : 'insufficient_wallet_balance_no_fallback';
            $reason = $fallbackMethod
                ? 'Wallet balance is insufficient, so fallback renewal handling is required.'
                : 'Wallet balance is insufficient and no fallback renewal method is configured.';

            return $this->decision($action, $reasonCode, $reason, $marketPolicy, $fallbackMethod, $pricing, $balance);
        }

        return $this->decision(
            'debit_wallet',
            'wallet_ready',
            'Wallet auto-renew can debit the wallet for this subscription.',
            $marketPolicy,
            $fallbackMethod,
            $pricing,
            $balance
        );
    }

    private function decision(
        string $action,
        string $reasonCode,
        string $reason,
        array $marketPolicy,
        ?string $fallbackMethod,
        ?array $pricing = null,
        ?float $walletBalance = null
    ): array {
        return [
            'version' => self::POLICY_VERSION,
            'action' => $action,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'fallback_method' => $fallbackMethod,
            'pricing' => $pricing,
            'wallet_balance' => $walletBalance !== null
                ? number_format((float) $walletBalance, 2, '.', '')
                : null,
            'market_policy' => $marketPolicy,
        ];
    }

    private function resolveFallbackMethod(array $renewalMethods): ?string
    {
        foreach (['payment_link', 'manual', 'stk_push'] as $method) {
            if (in_array($method, $renewalMethods, true)) {
                return $method;
            }
        }

        return null;
    }
}
