<?php

namespace App\Services\FlexibleAmounts;

use App\Billing\Support\BillingSurface;
use App\Billing\Support\MarketBillingMethodPolicy;
use App\Models\BillingMarketProviderBinding;
use App\Models\Client;
use App\Models\ClientWalletBalance;
use App\Models\FlexibleCheckoutAttempt;
use App\Models\Platform;
use App\Models\WalletTransaction;
use App\Services\BillingModeService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentLinkService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/** Customer-owned dashboard methods; never used by anonymous pay links. */
class FlexiblePaymentMethodsService
{
    public function balance(Client $client, string $currency): string
    {
        $currency = strtoupper($currency);
        $row = ClientWalletBalance::where('client_id', $client->id)->where('currency', $currency)->first();
        if ($row) {
            return (string) $row->balance;
        }
        $legacyCurrency = strtoupper(trim((string) $client->wallet_currency));
        $primary = $client->platform->primaryCurrency();

        return $currency === $primary && ($legacyCurrency === '' || $legacyCurrency === $currency)
            ? number_format((float) $client->wallet_balance, 2, '.', '') : '0.00';
    }

    private function routes(Platform $market, Client $client, array $state): array
    {
        $mode = app(BillingModeService::class);
        $policy = app(MarketBillingMethodPolicy::class);
        $surface = ($state['live'] ?? false) ? 'renewal' : 'activation';
        $wallet = $mode->walletContext($market);
        $routes = [];
        $resolved = app(PaymentLinkService::class)->resolveProviderConfig($market);
        if ($policy->allowsCanonicalMethod($market, $surface, 'payment_link') && is_array($resolved)
            && ($resolved['config']['mode'] ?? '') === PaymentLinkService::MODE_PROXY_HOSTED_CHECKOUT
            && str_starts_with((string) ($resolved['config']['wallet_provider_key'] ?? $resolved['key'] ?? ''), 'pawapay')) {
            try {
                $context = $mode->providerContext($market, 'pawapay', requireEnabled: false,
                    environmentOverride: $resolved['config']['environment'] ?? null, surface: 'self_checkout');
                $binding = BillingMarketProviderBinding::with('providerProfile')->find($context['chosen_binding_id'] ?? 0);
                // Legacy markets put the customer gate on either the selected
                // self_checkout binding or its matching hosted binding.
                $hosted = $binding && BillingMarketProviderBinding::where('market_id', $market->id)
                    ->where('provider_profile_id', $binding->provider_profile_id)->where('billing_surface', 'proxy_hosted_checkout')
                    ->where('enabled', true)->where('self_service_enabled', true)->exists();
                if ($binding && $binding->enabled && ($binding->self_service_enabled || $hosted)
                    && (int) $binding->market_id === (int) $market->id && $binding->providerProfile?->active
                    && ($binding->providerProfile->market_id === null || (int) $binding->providerProfile->market_id === (int) $market->id)) {
                    $routes['pawapay'] = ['key' => 'pawapay', 'label' => 'pawaPay', 'kind' => 'hosted',
                        'context' => $context, 'resolved' => $resolved];
                }
            } catch (\InvalidArgumentException) {
                // No silent fallback to another provider or environment.
            }
        }
        // The existing customer-initiated push contract uses the configured
        // activation STK method. CRM staff renewal methods do not support STK.
        if ($policy->allowsCanonicalMethod($market, 'activation', 'stk_push') && strtoupper($market->currency_code) === 'KES'
            && (string) $market->phone_prefix === '254') {
            $environment = $routes['pawapay']['context']['environment'] ?? $wallet['environment'];
            $binding = BillingMarketProviderBinding::with('providerProfile')->where('market_id', $market->id)
                ->where('billing_surface', 'subscription_push')->where('enabled', true)->where('self_service_enabled', true)
                ->whereHas('providerProfile', fn ($q) => $q->where('provider_type_key', 'kopokopo')
                    ->where('active', true)->where('environment', $environment))
                ->orderBy('priority')->orderBy('id')->first();
            if ($binding && ($binding->providerProfile->market_id === null || (int) $binding->providerProfile->market_id === (int) $market->id)) {
                try {
                    $context = $mode->profileBackedProviderContext($market, $binding->providerProfile, $binding->id, false,
                        $environment, BillingSurface::SubscriptionPush);
                    $routes['kopokopo'] = ['key' => 'kopokopo', 'label' => 'M-Pesa', 'kind' => 'phone_prompt', 'context' => $context,
                        'resolved' => ['key' => 'kopokopo_subscription_push', 'config' => ['label' => 'M-Pesa', 'wallet_provider_key' => 'kopokopo',
                            'mode' => 'subscription_push', 'billing_surface' => 'subscription_push', 'execution_mode' => 'direct',
                            'environment' => $environment, 'chosen_binding_id' => $binding->id, 'provider_profile_id' => $binding->provider_profile_id]]];
                } catch (\InvalidArgumentException) {
                    // Keep incomplete or disabled configured methods unavailable.
                }
            }
        }
        if ($policy->allowsCanonicalMethod($market, $surface, 'wallet_balance') && in_array($wallet['mode'], ['production', 'sandbox'], true)) {
            $routes['wallet'] = ['key' => 'wallet', 'label' => 'Wallet', 'kind' => 'wallet', 'context' => $wallet,
                'balance' => $this->balance($client, $market->currency_code), 'currency' => strtoupper($market->currency_code)];
        }

        return $routes;
    }

    public function choices(Platform $market, Client $client, array $state): array
    {
        return array_values(array_map(fn ($route) => array_intersect_key($route,
            array_flip(['key', 'label', 'kind', 'balance', 'currency'])), $this->routes($market, $client, $state)));
    }

    public function resolve(Platform $market, Client $client, array $state, string $method): array
    {
        $route = $this->routes($market, $client, $state)[$method] ?? null;
        if (! $route) {
            throw ValidationException::withMessages(['payment_method' => 'This payment method is unavailable. Choose another available method.']);
        }

        return $route;
    }

    public function debitKey(FlexibleCheckoutAttempt $attempt): string
    {
        return 'flexible-wallet:'.$attempt->operation_key;
    }

    public function assertDebit(FlexibleCheckoutAttempt $attempt): WalletTransaction
    {
        $payment = $attempt->payment;
        $debit = WalletTransaction::where('client_id', $attempt->client_id)->where('currency_code', $payment->currency)
            ->where('idempotency_key', $this->debitKey($attempt))->first();
        abort_unless($payment->source === 'wallet' && $payment->provider_key === 'wallet' && $debit
            && $debit->type === 'debit' && (int) $debit->payment_id === (int) $payment->id
            && (int) $debit->id === (int) $payment->wallet_transaction_id
            && (int) $payment->client_id === (int) $attempt->client_id
            && (int) $debit->platform_id === (int) $attempt->platform_id
            && bccomp((string) $debit->amount, (string) $payment->amount, 2) === 0
            && bccomp((string) $payment->amount, (string) data_get($payment->payment_data, 'flexible_quote.charge'), 2) === 0,
            409, 'Your wallet payment needs a team check. Please do not pay again.');

        return $debit;
    }

    /** Recover only this authenticated owner's already-debited immutable attempt. */
    public function completeWallet(FlexibleCheckoutAttempt $attempt): FlexibleCheckoutAttempt
    {
        $store = trim((string) config('client_lifecycle.lock_store', ''));
        if (app()->environment('production') && ($store === '' || in_array(config("cache.stores.$store.driver"), ['', 'array', 'file'], true))) {
            throw new \RuntimeException('Wallet completion requires the configured shared lifecycle cache store.');
        }
        $cache = $store !== '' ? Cache::store($store) : Cache::store();
        try {
            return $cache->lock('flexible-wallet-completion:'.$attempt->operation_key,
                max(30, (int) config('client_lifecycle.lock_ttl_seconds', 600)))->block(5, function () use ($attempt) {
                $attempt = $attempt->fresh(['payment.client.platform', 'payment.deal']);
                $this->assertDebit($attempt);
                if (in_array($attempt->state, ['finished', 'needs_review'], true)) {
                    return $attempt;
                }
                abort_unless(in_array($attempt->state, ['wallet_debited', 'completing'], true), 409,
                    'Your saved wallet payment is still being checked. Please do not pay again.');
                $attempt->update(['state' => 'completing', 'dispatch_started_at' => $attempt->dispatch_started_at ?: now()]);
                $payment = $attempt->payment;
                try {
                    app(FlexibleAttemptGuard::class)->owned($payment, fn () => app(PaymentCompletionService::class)
                        ->completeSubscriptionPayment($payment, [], ['client' => $payment->client, 'payment_method' => 'wallet',
                            'duration_days' => (int) data_get($payment->payment_data, 'flexible_quote.days'), 'confirmed_at' => $payment->completed_at ?: now(),
                            'settled_amount' => (string) $payment->amount, 'settled_currency' => $payment->currency]));
                    if (data_get($payment->fresh()->payment_data, 'canonical_state.provisioning_status') === 'suppressed_sandbox') {
                        app(FlexibleAttemptGuard::class)->finish($payment);
                    }
                } catch (\Throwable $e) {
                    $attempt->update(['state' => 'needs_review']);
                    $payment = $payment->fresh();
                    $payment->update(['reconciliation_state' => 'manual_review',
                        'payment_data' => array_merge($payment->payment_data ?? [], ['provisioning_status' => 'needs_review'])]);
                    report($e);
                }

                return $attempt->fresh('payment');
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // A concurrent request owns completion. It must never cause another debit.
            return $attempt->fresh('payment');
        }
    }
}
