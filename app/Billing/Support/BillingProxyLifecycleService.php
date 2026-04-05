<?php

namespace App\Billing\Support;

use App\Models\BillingProxySession;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BillingProxyLifecycleService
{
    public function issueToken(Payment $payment, array $providerConfig): string
    {
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);
        $expiresAt = now()->addHours(24);
        $decision = $this->latestPinnedDecision($payment)
            ?? $this->createProvisionalRoutingDecision($payment, $providerConfig);

        $session = BillingProxySession::query()->firstOrNew([
            'payment_id' => $payment->id,
        ]);

        $rotationCount = (int) ($session->rotation_count ?? 0);
        $state = $session->exists ? 'rotated' : 'issued';

        $session->forceFill([
            'payment_id' => $payment->id,
            'billing_routing_decision_id' => $decision?->id,
            'provider_profile_id' => $providerConfig['provider_profile_id'] ?? $decision?->provider_profile_id,
            'provider_type_key' => trim((string) ($providerConfig['wallet_provider_key'] ?? $decision?->provider_type_key ?? '')),
            'environment' => trim((string) ($providerConfig['environment'] ?? $decision?->environment ?? 'sandbox')) ?: 'sandbox',
            'token_hash' => $tokenHash,
            'token_expires_at' => $expiresAt,
            'redirect_url' => null,
            'provider_reference' => null,
            'rotation_count' => $session->exists ? $rotationCount + 1 : 0,
            'state' => $state,
            'legacy_meta_json' => [
                'provider_key' => $providerConfig['wallet_provider_key'] ?? null,
                'provider_config_key' => $providerConfig['key'] ?? null,
                'mode' => $providerConfig['mode'] ?? null,
                'chosen_binding_id' => $providerConfig['chosen_binding_id'] ?? null,
                'billing_surface' => $providerConfig['billing_surface'] ?? null,
                'sent_at' => now()->toIso8601String(),
            ],
        ])->save();

        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy'] = [
            'token_hash' => $tokenHash,
            'token_expires_at' => $expiresAt->toIso8601String(),
            'provider_key' => $providerConfig['wallet_provider_key'] ?? null,
            'provider_config_key' => $providerConfig['key'] ?? null,
            'mode' => $providerConfig['mode'] ?? null,
            'chosen_binding_id' => $providerConfig['chosen_binding_id'] ?? null,
            'billing_surface' => $providerConfig['billing_surface'] ?? null,
            'environment' => $providerConfig['environment'] ?? 'sandbox',
            'redirect_url' => null,
            'provider_reference' => null,
            'initialized_at' => null,
            'opened_at' => null,
            'open_count' => 0,
            'sent_at' => now()->toIso8601String(),
        ];

        $payment->forceFill([
            'provider_key' => $providerConfig['wallet_provider_key'] ?? $payment->provider_key,
            'provider_environment' => $providerConfig['environment'] ?? $payment->provider_environment,
            'payment_data' => $paymentData,
        ])->save();

        return $token;
    }

    public function findSessionByToken(string $token): ?BillingProxySession
    {
        $tokenHash = hash('sha256', trim($token));

        return BillingProxySession::query()
            ->with('payment.platform', 'payment.client')
            ->where('token_hash', $tokenHash)
            ->latest('id')
            ->first();
    }

    public function markOpened(Payment $payment, ?BillingProxySession $session, array $linkProxy): array
    {
        $openedAt = $linkProxy['opened_at'] ?? now()->toIso8601String();
        $openCount = ((int) ($linkProxy['open_count'] ?? 0)) + 1;

        if ($session) {
            $session->forceFill([
                'opened_at' => $session->opened_at ?? Carbon::parse((string) $openedAt),
                'open_count' => $openCount,
                'state' => $session->initialized_at ? 'opened_initialized' : 'opened',
            ])->save();
        }

        $linkProxy['opened_at'] = $openedAt;
        $linkProxy['open_count'] = $openCount;

        return $this->persistLegacyState($payment, $linkProxy);
    }

    public function markInitialized(
        Payment $payment,
        ?BillingProxySession $session,
        array $linkProxy,
        string $redirectUrl,
        ?string $providerReference
    ): array {
        $initializedAt = now()->toIso8601String();
        $persistableRedirectUrl = $this->persistableSessionRedirectUrl($redirectUrl);

        if ($session) {
            $legacyMeta = is_array($session->legacy_meta_json) ? $session->legacy_meta_json : [];
            $legacyMeta['redirect_url'] = $redirectUrl;

            $session->forceFill([
                'redirect_url' => $persistableRedirectUrl,
                'provider_reference' => $providerReference ?: null,
                'initialized_at' => Carbon::parse($initializedAt),
                'state' => 'checkout_initialized',
                'legacy_meta_json' => $legacyMeta,
            ])->save();
        } else {
            $this->upsertFromLegacyState($payment, array_merge($linkProxy, [
                'redirect_url' => $redirectUrl,
                'provider_reference' => $providerReference ?: null,
                'initialized_at' => $initializedAt,
            ]));
        }

        $linkProxy['redirect_url'] = $redirectUrl;
        $linkProxy['provider_reference'] = $providerReference ?: null;
        $linkProxy['initialized_at'] = $initializedAt;

        return $this->persistLegacyState($payment, $linkProxy);
    }

    public function currentLinkProxy(Payment $payment): ?array
    {
        $linkProxy = is_array(data_get($payment->payment_data, 'link_proxy'))
            ? data_get($payment->payment_data, 'link_proxy')
            : null;

        if ($linkProxy) {
            return $linkProxy;
        }

        $session = BillingProxySession::query()
            ->where('payment_id', $payment->id)
            ->latest('id')
            ->first();

        if (!$session) {
            return null;
        }

        return [
            'token_hash' => $session->token_hash,
            'token_expires_at' => optional($session->token_expires_at)?->toIso8601String(),
            'provider_key' => data_get($session->legacy_meta_json, 'provider_key'),
            'provider_config_key' => data_get($session->legacy_meta_json, 'provider_config_key'),
            'mode' => data_get($session->legacy_meta_json, 'mode'),
            'chosen_binding_id' => data_get($session->legacy_meta_json, 'chosen_binding_id'),
            'billing_surface' => data_get($session->legacy_meta_json, 'billing_surface'),
            'environment' => $session->environment,
            'redirect_url' => data_get($session->legacy_meta_json, 'redirect_url', $session->redirect_url),
            'provider_reference' => $session->provider_reference,
            'initialized_at' => optional($session->initialized_at)?->toIso8601String(),
            'opened_at' => optional($session->opened_at)?->toIso8601String(),
            'open_count' => (int) $session->open_count,
            'sent_at' => data_get($session->legacy_meta_json, 'sent_at'),
        ];
    }

    private function persistLegacyState(Payment $payment, array $linkProxy): array
    {
        $paymentData = is_array($payment->payment_data) ? $payment->payment_data : [];
        $paymentData['link_proxy'] = $linkProxy;

        $payment->forceFill([
            'payment_data' => $paymentData,
        ])->save();

        return $linkProxy;
    }

    private function upsertFromLegacyState(Payment $payment, array $linkProxy): void
    {
        $decision = $this->latestPinnedDecision($payment);

        BillingProxySession::query()->updateOrCreate(
            ['payment_id' => $payment->id],
            [
                'billing_routing_decision_id' => $decision?->id,
                'provider_profile_id' => $decision?->provider_profile_id,
                'provider_type_key' => trim((string) ($linkProxy['provider_key'] ?? $decision?->provider_type_key ?? '')),
                'environment' => trim((string) ($linkProxy['environment'] ?? $decision?->environment ?? 'sandbox')) ?: 'sandbox',
                'token_hash' => (string) ($linkProxy['token_hash'] ?? ''),
                'token_expires_at' => !empty($linkProxy['token_expires_at']) ? Carbon::parse((string) $linkProxy['token_expires_at']) : now()->addHours(24),
                'redirect_url' => $this->persistableSessionRedirectUrl((string) ($linkProxy['redirect_url'] ?? '')),
                'provider_reference' => $linkProxy['provider_reference'] ?? null,
                'opened_at' => !empty($linkProxy['opened_at']) ? Carbon::parse((string) $linkProxy['opened_at']) : null,
                'open_count' => (int) ($linkProxy['open_count'] ?? 0),
                'initialized_at' => !empty($linkProxy['initialized_at']) ? Carbon::parse((string) $linkProxy['initialized_at']) : null,
                'rotation_count' => 0,
                'state' => !empty($linkProxy['initialized_at']) ? 'checkout_initialized' : (!empty($linkProxy['opened_at']) ? 'opened' : 'issued'),
                'legacy_meta_json' => [
                    'provider_key' => $linkProxy['provider_key'] ?? null,
                    'provider_config_key' => $linkProxy['provider_config_key'] ?? null,
                    'mode' => $linkProxy['mode'] ?? null,
                    'chosen_binding_id' => $linkProxy['chosen_binding_id'] ?? null,
                    'billing_surface' => $linkProxy['billing_surface'] ?? null,
                    'redirect_url' => $linkProxy['redirect_url'] ?? null,
                    'sent_at' => $linkProxy['sent_at'] ?? null,
                ],
            ]
        );
    }

    private function persistableSessionRedirectUrl(string $redirectUrl): ?string
    {
        $redirectUrl = trim($redirectUrl);

        if ($redirectUrl === '') {
            return null;
        }

        return strlen($redirectUrl) <= 255 ? $redirectUrl : null;
    }

    private function latestPinnedDecision(Payment $payment): ?BillingRoutingDecision
    {
        return $payment->routingDecisions()
            ->where('immutable_until_terminal_state', true)
            ->latest('id')
            ->first();
    }

    private function createProvisionalRoutingDecision(Payment $payment, array $providerConfig): BillingRoutingDecision
    {
        $providerTypeKey = strtolower(trim((string) ($providerConfig['wallet_provider_key'] ?? $payment->provider_key ?? 'payment_link')));
        $providerKey = strtolower(trim((string) ($providerConfig['key'] ?? $providerTypeKey)));
        $environment = strtolower(trim((string) ($providerConfig['environment'] ?? $payment->provider_environment ?? 'sandbox'))) ?: 'sandbox';
        $surface = trim((string) ($providerConfig['billing_surface'] ?? 'proxy_hosted_checkout')) ?: 'proxy_hosted_checkout';
        $executionMode = trim((string) ($providerConfig['execution_mode'] ?? 'proxy')) ?: 'proxy';
        $executionFamily = $surface === 'subscription_link' ? 'subscription_link' : 'hosted_redirect';

        return BillingRoutingDecision::query()->create([
            'payment_id' => (int) $payment->id,
            'market_id' => (int) $payment->platform_id,
            'billing_surface' => $surface,
            'chosen_binding_id' => $providerConfig['chosen_binding_id'] ?? null,
            'provider_profile_id' => $providerConfig['provider_profile_id'] ?? null,
            'provider_type_key' => $providerTypeKey,
            'execution_mode' => $executionMode,
            'environment' => $environment,
            'fallback_taken' => false,
            'decision_version' => 1,
            'shadow_diff_json' => null,
            'surface_cutover_flag' => null,
            'snapshot_json' => [
                'payment_id' => (int) $payment->id,
                'payment_purpose' => (string) ($payment->purpose ?: 'subscription'),
                'billing_surface' => $surface,
                'provider_key' => $providerKey,
                'provider_type_key' => $providerTypeKey,
                'provider_label' => (string) ($providerConfig['label'] ?? $providerKey),
                'provider_family' => 'hosted_checkout',
                'execution_family' => $executionFamily,
                'environment' => $environment,
                'execution_mode' => $executionMode,
                'callback_contract' => [
                    'type' => 'browser_completion',
                    'path' => null,
                ],
                'pricing' => [
                    'amount' => number_format((float) $payment->amount, 2, '.', ''),
                    'currency' => (string) $payment->currency,
                ],
                'fx_quote' => [
                    'mode' => 'same_currency',
                    'quote_locked' => true,
                    'market_currency' => (string) ($payment->platform?->currency_code ?: $payment->currency),
                    'payment_currency' => (string) $payment->currency,
                ],
                'link_mode' => (string) ($providerConfig['mode'] ?? null),
            ],
            'immutable_until_terminal_state' => true,
            'decision_json' => [
                'source' => 'payment_link_token_issue',
                'provider_key' => $providerKey,
                'provider_profile_id' => $providerConfig['provider_profile_id'] ?? null,
                'chosen_binding_id' => $providerConfig['chosen_binding_id'] ?? null,
            ],
            'created_at' => now(),
        ]);
    }
}
