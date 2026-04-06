<?php

namespace App\Billing\Diagnostics;

use App\Billing\Contracts\BillingDiagnosticsAssembler as BillingDiagnosticsAssemblerContract;
use App\Models\BillingProviderProfile;
use App\Models\BillingProviderTransaction;
use App\Models\BillingRoutingDecision;
use App\Models\BillingSubscriptionRule;
use App\Models\BillingWalletRule;
use App\Models\BillingWebhookEvent;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\WalletTransaction;
use App\Services\WalletSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BillingDiagnosticsAssembler implements BillingDiagnosticsAssemblerContract
{
    public function __construct(
        private readonly WalletSettingsService $walletSettingsService
    ) {
    }

    public function assembleBilling(?int $marketId = null, ?string $providerKey = null): BillingDiagnosticsView
    {
        $providerKey = $this->normalizeProviderKey($providerKey);
        $system = $this->walletSettingsService->currentSystemConfig(masked: true);
        $platforms = Platform::query()
            ->when($marketId, fn (Builder $builder) => $builder->whereKey($marketId))
            ->orderBy('id')
            ->get();

        $providerProfiles = BillingProviderProfile::query()
            ->when($marketId, fn (Builder $builder) => $builder->where('market_id', $marketId))
            ->when($providerKey, fn (Builder $builder) => $builder->whereRaw('LOWER(provider_type_key) = ?', [$providerKey]))
            ->get();

        $walletRules = BillingWalletRule::query()
            ->when($marketId, fn (Builder $builder) => $builder->where('market_id', $marketId))
            ->get();

        $subscriptionRules = BillingSubscriptionRule::query()
            ->when($marketId, fn (Builder $builder) => $builder->where('market_id', $marketId))
            ->get();

        $recentRoutingDecisions = BillingRoutingDecision::query()
            ->when($marketId, fn (Builder $builder) => $builder->where('market_id', $marketId))
            ->when($providerKey, fn (Builder $builder) => $builder->whereRaw('LOWER(provider_type_key) = ?', [$providerKey]))
            ->where('created_at', '>=', now()->subDays(14))
            ->get();

        $recentWebhookEvents = BillingWebhookEvent::query()
            ->when($marketId, fn (Builder $builder) => $builder->where('market_id', $marketId))
            ->when($providerKey, fn (Builder $builder) => $builder->whereRaw('LOWER(provider_type_key) = ?', [$providerKey]))
            ->where('received_at', '>=', now()->subDays(14))
            ->get();

        $recentFailedPayments = Payment::query()
            ->with(['client:id,name', 'platform:id,name'])
            ->when($marketId, fn (Builder $builder) => $builder->where('platform_id', $marketId))
            ->when($providerKey, fn (Builder $builder) => $this->applyPaymentProviderFilter($builder, $providerKey))
            ->where('created_at', '>=', now()->subDays(14))
            ->where('status', 'failed')
            ->latest('created_at')
            ->limit(5)
            ->get();

        $proxyDecisions = $recentRoutingDecisions->where('execution_mode', 'proxy');
        $fallbackConfiguredMarkets = $subscriptionRules
            ->filter(fn (BillingSubscriptionRule $rule) => $this->subscriptionRuleHasFallback($rule))
            ->count();
        $recentFallbackRoutes = $recentRoutingDecisions->where('fallback_taken', true);
        $walletEnabledMarkets = $walletRules->where('enabled', true)->count();
        $walletAutoRenewMarkets = $subscriptionRules
            ->filter(fn (BillingSubscriptionRule $rule) => (bool) data_get($rule->renewal_method_json, 'wallet_auto_renew', false))
            ->count();

        return new BillingDiagnosticsView(
            marketId: $marketId,
            providerKey: $providerKey,
            source: 'shared_diagnostics_backend_v1',
            sections: [
                [
                    'key' => 'readiness',
                    'title' => 'Readiness',
                    'status' => $this->resolveBillingReadinessStatus($system, $providerProfiles, $platforms),
                    'summary' => $this->resolveBillingReadinessSummary($system, $providerProfiles, $platforms, $providerKey),
                    'entries' => [
                        ['label' => 'System mode', 'value' => Str::headline((string) ($system['mode'] ?? 'disabled'))],
                        ['label' => 'Default currency', 'value' => (string) ($system['default_currency'] ?? 'KES')],
                        ['label' => 'Markets in scope', 'value' => (string) $platforms->count()],
                        ['label' => 'Active provider profiles', 'value' => (string) $providerProfiles->where('active', true)->count()],
                        ['label' => 'Wallet-enabled markets', 'value' => (string) $walletEnabledMarkets],
                        ['label' => 'Wallet auto-renew markets', 'value' => (string) $walletAutoRenewMarkets],
                    ],
                ],
                [
                    'key' => 'route_health',
                    'title' => 'Route Health',
                    'status' => $recentRoutingDecisions->isEmpty() ? 'unavailable' : 'healthy',
                    'summary' => $recentRoutingDecisions->isEmpty()
                        ? 'No routing decisions were recorded in the last 14 days for this scope.'
                        : sprintf(
                            '%d routing decisions observed in the last 14 days, including %d fallback routes.',
                            $recentRoutingDecisions->count(),
                            $recentRoutingDecisions->where('fallback_taken', true)->count()
                        ),
                    'entries' => [
                        ['label' => 'Decisions (14d)', 'value' => (string) $recentRoutingDecisions->count()],
                        ['label' => 'Fallback taken', 'value' => (string) $recentRoutingDecisions->where('fallback_taken', true)->count()],
                        ['label' => 'Proxy execution', 'value' => (string) $proxyDecisions->count()],
                        ['label' => 'Direct execution', 'value' => (string) $recentRoutingDecisions->where('execution_mode', 'direct')->count()],
                        ['label' => 'Latest decision', 'value' => $this->formatDateTime(optional($recentRoutingDecisions->sortByDesc('created_at')->first())->created_at)],
                    ],
                ],
                [
                    'key' => 'webhook_posture',
                    'title' => 'Webhook Posture',
                    'status' => $recentWebhookEvents->isEmpty()
                        ? 'unavailable'
                        : ($recentWebhookEvents->where('processing_status', 'failed')->isNotEmpty() ? 'degraded' : 'healthy'),
                    'summary' => $recentWebhookEvents->isEmpty()
                        ? 'No structured webhook inbox activity is available yet for this scope.'
                        : sprintf(
                            '%d webhook events observed, with %d still pending and %d failed.',
                            $recentWebhookEvents->count(),
                            $recentWebhookEvents->where('processing_status', 'pending')->count(),
                            $recentWebhookEvents->where('processing_status', 'failed')->count()
                        ),
                    'entries' => [
                        ['label' => 'Received (14d)', 'value' => (string) $recentWebhookEvents->count()],
                        ['label' => 'Processed', 'value' => (string) $recentWebhookEvents->where('processing_status', 'processed')->count()],
                        ['label' => 'Pending', 'value' => (string) $recentWebhookEvents->where('processing_status', 'pending')->count()],
                        ['label' => 'Failed', 'value' => (string) $recentWebhookEvents->where('processing_status', 'failed')->count()],
                        ['label' => 'Latest received', 'value' => $this->formatDateTime(optional($recentWebhookEvents->sortByDesc('received_at')->first())->received_at)],
                    ],
                ],
                [
                    'key' => 'fallback_posture',
                    'title' => 'Fallback Posture',
                    'status' => $this->resolveFallbackPostureStatus($subscriptionRules, $fallbackConfiguredMarkets, $recentFallbackRoutes),
                    'summary' => $this->resolveFallbackPostureSummary($subscriptionRules, $fallbackConfiguredMarkets, $recentFallbackRoutes),
                    'entries' => [
                        ['label' => 'Markets with fallback', 'value' => (string) $fallbackConfiguredMarkets],
                        ['label' => 'Fallback routes (14d)', 'value' => (string) $recentFallbackRoutes->count()],
                        ['label' => 'Wallet auto-renew markets', 'value' => (string) $walletAutoRenewMarkets],
                        ['label' => 'Latest fallback route', 'value' => $this->formatDateTime(optional($recentFallbackRoutes->sortByDesc('created_at')->first())->created_at)],
                    ],
                ],
                [
                    'key' => 'proxy_posture',
                    'title' => 'Proxy Posture',
                    'status' => $proxyDecisions->isEmpty() ? 'neutral' : 'healthy',
                    'summary' => $proxyDecisions->isEmpty()
                        ? 'No proxy-hosted billing routes were observed in this scope recently.'
                        : sprintf(
                            '%d proxy-routed billing decisions were recorded, across %d distinct providers.',
                            $proxyDecisions->count(),
                            $proxyDecisions->pluck('provider_type_key')->filter()->unique()->count()
                        ),
                    'entries' => [
                        ['label' => 'Proxy decisions (14d)', 'value' => (string) $proxyDecisions->count()],
                        ['label' => 'Proxy fallbacks', 'value' => (string) $proxyDecisions->where('fallback_taken', true)->count()],
                        ['label' => 'Latest proxy decision', 'value' => $this->formatDateTime(optional($proxyDecisions->sortByDesc('created_at')->first())->created_at)],
                    ],
                ],
                [
                    'key' => 'wp_contract_health',
                    'title' => 'WP Contract Health',
                    'status' => $this->resolveWpContractStatus($platforms),
                    'summary' => $this->resolveWpContractSummary($platforms),
                    'entries' => [
                        ['label' => 'API-ready markets', 'value' => (string) $platforms->filter(fn (Platform $platform) => filled($platform->wp_api_url) && filled($platform->wp_api_user) && filled($platform->wp_api_password))->count()],
                        ['label' => 'DB-ready markets', 'value' => (string) $platforms->filter(fn (Platform $platform) => filled($platform->db_host) && filled($platform->db_name) && filled($platform->db_user) && filled($platform->db_pass))->count()],
                        ['label' => 'Markets missing API auth', 'value' => (string) $platforms->reject(fn (Platform $platform) => filled($platform->wp_api_url) && filled($platform->wp_api_user) && filled($platform->wp_api_password))->count()],
                        ['label' => 'Markets missing DB auth', 'value' => (string) $platforms->reject(fn (Platform $platform) => filled($platform->db_host) && filled($platform->db_name) && filled($platform->db_user) && filled($platform->db_pass))->count()],
                    ],
                ],
                [
                    'key' => 'recent_failures',
                    'title' => 'Recent Failures',
                    'status' => $recentFailedPayments->isEmpty() ? 'neutral' : 'attention',
                    'summary' => $recentFailedPayments->isEmpty()
                        ? 'No failed payments were observed in the last 14 days for this scope.'
                        : sprintf('%d failed payments were recorded in the last 14 days.', $recentFailedPayments->count()),
                    'items' => $recentFailedPayments->map(function (Payment $payment) {
                        return [
                            'label' => sprintf(
                                '#%d • %s • %s',
                                (int) $payment->id,
                                $payment->client?->name ?: 'Unknown client',
                                $payment->platform?->name ?: 'Unknown market'
                            ),
                            'value' => $payment->failure_reason ?: 'Payment failed before a detailed reason was recorded.',
                            'meta' => [
                                'created_at' => $this->formatDateTime($payment->created_at),
                                'provider_key' => $payment->provider_key ?: 'unknown',
                            ],
                        ];
                    })->values()->all(),
                ],
            ],
            meta: [
                'generated_at' => now()->toDateTimeString(),
                'scope' => [
                    'market_id' => $marketId,
                    'provider_key' => $providerKey,
                ],
                'redacted' => true,
                'legacy_composed' => false,
            ]
        );
    }

    public function assemblePayment(int $paymentId): PaymentDiagnosticsView
    {
        $payment = Payment::query()
            ->with([
                'platform:id,name',
                'client:id,name',
                'deal:id,platform_id,client_id,status,expires_at,payment_id',
                'routingDecisions.providerProfile:id,provider_type_key,profile_name,environment',
                'providerTransactions.providerProfile:id,provider_type_key,profile_name,environment',
                'walletTransaction',
            ])
            ->findOrFail($paymentId);

        $routingDecision = $payment->routingDecisions
            ->sortByDesc(fn (BillingRoutingDecision $decision) => optional($decision->created_at)->getTimestamp() ?? 0)
            ->first();

        $providerTransactions = $payment->providerTransactions
            ->sortByDesc(fn (BillingProviderTransaction $transaction) => optional($transaction->last_status_at)->getTimestamp() ?? optional($transaction->created_at)->getTimestamp() ?? 0)
            ->values();

        $webhookEvents = BillingWebhookEvent::query()
            ->where('payment_id', $paymentId)
            ->latest('received_at')
            ->limit(10)
            ->get();

        $walletTransaction = $payment->walletTransaction
            ?: WalletTransaction::query()->where('payment_id', $paymentId)->latest('id')->first();

        $provisioningEvents = TimelineEvent::query()
            ->where(function (Builder $builder) use ($payment) {
                $builder->where(function (Builder $paymentBuilder) use ($payment) {
                    $paymentBuilder->where('entity_type', 'payment')
                        ->where('entity_id', $payment->id);
                });

                if ($payment->deal_id) {
                    $builder->orWhere(function (Builder $dealBuilder) use ($payment) {
                        $dealBuilder->where('entity_type', 'deal')
                            ->where('entity_id', (int) $payment->deal_id);
                    });
                }
            })
            ->whereIn('event_type', [
                'payment_received',
                'profile_activated',
                'deal_activated',
                'deal_extended',
                'deal_renewed',
                'wallet_auto_renew_attempted',
                'wallet_auto_renew_succeeded',
                'wallet_auto_renew_failed',
                'wallet_auto_renew_fallback_sent',
                'wallet_auto_renew_escalated',
            ])
            ->latest('created_at')
            ->limit(12)
            ->get();

        $legacyComposed = !$routingDecision && $providerTransactions->isEmpty() && $webhookEvents->isEmpty();
        $latestProviderTransaction = $providerTransactions->first();
        $latestWebhookEvent = $webhookEvents->first();
        $paymentOrigin = (string) data_get($payment->payment_data, 'origin', '');

        return new PaymentDiagnosticsView(
            paymentId: $paymentId,
            source: 'shared_diagnostics_backend_v1',
            sections: [
                [
                    'key' => 'routing',
                    'title' => 'Routing',
                    'status' => $routingDecision ? 'healthy' : ($legacyComposed ? 'legacy_composed' : 'unavailable'),
                    'summary' => $routingDecision
                        ? sprintf(
                            '%s route via %s (%s).',
                            Str::headline((string) ($routingDecision->execution_mode ?: 'unknown')),
                            $routingDecision->providerProfile?->profile_name ?: Str::headline((string) ($routingDecision->provider_type_key ?: 'unknown')),
                            strtoupper((string) ($routingDecision->environment ?: 'unknown'))
                        )
                        : sprintf(
                            'Structured routing was not recorded for this payment. Using legacy provider hints from %s.',
                            $payment->provider_key ?: 'the payment record'
                        ),
                    'entries' => [
                        ['label' => 'Provider profile', 'value' => $routingDecision?->providerProfile?->profile_name ?: 'Legacy / unavailable'],
                        ['label' => 'Provider key', 'value' => $routingDecision?->provider_type_key ?: ($payment->provider_key ?: 'unknown')],
                        ['label' => 'Route mode', 'value' => $routingDecision?->execution_mode ?: 'legacy_composed'],
                        ['label' => 'Environment', 'value' => strtoupper((string) ($routingDecision?->environment ?: $payment->provider_environment ?: 'unknown'))],
                        ['label' => 'Fallback taken', 'value' => $routingDecision ? ((bool) $routingDecision->fallback_taken ? 'Yes' : 'No') : 'Unknown'],
                    ],
                ],
                [
                    'key' => 'provider_transactions',
                    'title' => 'Provider Transaction',
                    'status' => $providerTransactions->isEmpty() ? 'unavailable' : 'healthy',
                    'summary' => $providerTransactions->isEmpty()
                        ? 'No structured provider transactions are linked to this payment yet.'
                        : sprintf(
                            '%d provider transaction records are linked, latest state is %s.',
                            $providerTransactions->count(),
                            Str::headline((string) ($latestProviderTransaction?->normalized_status ?: 'unknown'))
                        ),
                    'entries' => [
                        ['label' => 'Latest provider profile', 'value' => $latestProviderTransaction?->providerProfile?->profile_name ?: '—'],
                        ['label' => 'Latest state', 'value' => $latestProviderTransaction?->normalized_status ?: '—'],
                        ['label' => 'Attempt sequence', 'value' => $latestProviderTransaction?->attempt_sequence ? (string) $latestProviderTransaction->attempt_sequence : '—'],
                        ['label' => 'Compatibility ref', 'value' => $latestProviderTransaction?->compatibility_reference ?: '—'],
                        ['label' => 'Last status at', 'value' => $this->formatDateTime($latestProviderTransaction?->last_status_at)],
                    ],
                    'items' => $providerTransactions->take(3)->map(function (BillingProviderTransaction $transaction) {
                        return [
                            'label' => sprintf(
                                '%s #%s',
                                Str::headline((string) $transaction->provider_type_key),
                                $transaction->attempt_sequence ?: '—'
                            ),
                            'value' => sprintf(
                                '%s • %s',
                                Str::headline((string) ($transaction->normalized_status ?: 'unknown')),
                                $transaction->compatibility_reference ?: ($transaction->provider_transaction_id ?: 'No provider reference')
                            ),
                        ];
                    })->all(),
                ],
                [
                    'key' => 'webhooks',
                    'title' => 'Webhook State',
                    'status' => $webhookEvents->isEmpty()
                        ? 'unavailable'
                        : ($webhookEvents->contains(fn (BillingWebhookEvent $event) => (string) $event->processing_status === 'failed') ? 'degraded' : 'healthy'),
                    'summary' => $webhookEvents->isEmpty()
                        ? 'No webhook inbox records are linked to this payment.'
                        : sprintf(
                            '%d webhook events linked to this payment, latest processing status is %s.',
                            $webhookEvents->count(),
                            Str::headline((string) ($latestWebhookEvent?->processing_status ?: 'unknown'))
                        ),
                    'entries' => [
                        ['label' => 'Latest status', 'value' => $latestWebhookEvent?->processing_status ?: '—'],
                        ['label' => 'Signature status', 'value' => $latestWebhookEvent?->signature_status ?: '—'],
                        ['label' => 'Retry count', 'value' => $latestWebhookEvent ? (string) $latestWebhookEvent->retry_count : '—'],
                        ['label' => 'Received at', 'value' => $this->formatDateTime($latestWebhookEvent?->received_at)],
                        ['label' => 'Processed at', 'value' => $this->formatDateTime($latestWebhookEvent?->processed_at)],
                    ],
                ],
                [
                    'key' => 'wallet',
                    'title' => 'Wallet Ledger',
                    'status' => $walletTransaction ? 'healthy' : ($paymentOrigin === 'wallet_auto_subscribe' ? 'attention' : 'neutral'),
                    'summary' => $walletTransaction
                        ? sprintf(
                            'Wallet %s transaction recorded for %s %s.',
                            Str::headline((string) $walletTransaction->type),
                            $walletTransaction->currency_code ?: ($payment->currency ?: 'KES'),
                            $walletTransaction->amount
                        )
                        : ($paymentOrigin === 'wallet_auto_subscribe'
                            ? 'This payment originated from wallet auto-renew, but no wallet ledger transaction is linked.'
                            : 'This payment is not linked to a wallet ledger movement.'),
                    'entries' => [
                        ['label' => 'Origin', 'value' => $paymentOrigin !== '' ? Str::headline($paymentOrigin) : '—'],
                        ['label' => 'Wallet transaction', 'value' => $walletTransaction ? '#' . $walletTransaction->id : '—'],
                        ['label' => 'Reference type', 'value' => $walletTransaction?->reference_type ?: '—'],
                        ['label' => 'Balance after', 'value' => $walletTransaction ? sprintf('%s %s', $walletTransaction->currency_code ?: ($payment->currency ?: 'KES'), $walletTransaction->balance_after) : '—'],
                        ['label' => 'Synced to WP', 'value' => $walletTransaction?->wp_synced_at ? 'Yes' : 'No'],
                    ],
                ],
                [
                    'key' => 'provisioning',
                    'title' => 'Provisioning State',
                    'status' => $this->resolveProvisioningStatus($payment, $provisioningEvents),
                    'summary' => $this->resolveProvisioningSummary($payment, $provisioningEvents),
                    'entries' => [
                        ['label' => 'Provisioning status', 'value' => (string) data_get($payment->payment_data, 'canonical_state.provisioning_status', 'unknown')],
                        ['label' => 'Linked deal', 'value' => $payment->deal_id ? '#' . $payment->deal_id : '—'],
                        ['label' => 'Deal status', 'value' => $payment->deal?->status ?: '—'],
                        ['label' => 'Deal expiry', 'value' => $this->formatDateTime($payment->deal?->expires_at)],
                    ],
                    'items' => $provisioningEvents->take(5)->map(function (TimelineEvent $event) {
                        return [
                            'label' => Str::headline((string) $event->event_type),
                            'value' => $this->formatDateTime($event->created_at),
                        ];
                    })->all(),
                ],
            ],
            meta: [
                'generated_at' => now()->toDateTimeString(),
                'legacy_composed' => $legacyComposed,
                'redacted' => true,
            ]
        );
    }

    private function normalizeProviderKey(?string $providerKey): ?string
    {
        $normalized = strtolower(trim((string) $providerKey));

        return $normalized !== '' ? $normalized : null;
    }

    private function applyPaymentProviderFilter(Builder $builder, string $providerKey): Builder
    {
        return $builder->where(function (Builder $query) use ($providerKey) {
            $query->whereRaw('LOWER(COALESCE(provider_key, \'\')) = ?', [$providerKey])
                ->orWhereHas('routingDecisions', fn (Builder $decisionQuery) => $decisionQuery->whereRaw('LOWER(provider_type_key) = ?', [$providerKey]))
                ->orWhereHas('providerTransactions', fn (Builder $transactionQuery) => $transactionQuery->whereRaw('LOWER(provider_type_key) = ?', [$providerKey]));
        });
    }

    private function resolveBillingReadinessStatus(array $system, Collection $providerProfiles, Collection $platforms): string
    {
        if (($system['mode'] ?? 'disabled') === 'disabled') {
            return 'attention';
        }

        if ($platforms->isEmpty() || $providerProfiles->where('active', true)->isEmpty()) {
            return 'degraded';
        }

        return 'healthy';
    }

    private function resolveBillingReadinessSummary(array $system, Collection $providerProfiles, Collection $platforms, ?string $providerKey = null): string
    {
        if (($system['mode'] ?? 'disabled') === 'disabled') {
            return 'Billing system mode is disabled, so diagnostics can only report configuration posture.';
        }

        if ($platforms->isEmpty()) {
            return 'No markets are in scope for this diagnostics view.';
        }

        if ($providerProfiles->where('active', true)->isEmpty()) {
            return $providerKey
                ? sprintf('No active provider profiles are configured for %s in this scope.', Str::headline($providerKey))
                : 'No active provider profiles are configured for the current diagnostics scope.';
        }

        return sprintf(
            'Billing is running in %s mode with %d active provider profiles across %d markets.',
            Str::headline((string) ($system['mode'] ?? 'unknown')),
            $providerProfiles->where('active', true)->count(),
            $platforms->count()
        );
    }

    private function resolveWpContractStatus(Collection $platforms): string
    {
        if ($platforms->isEmpty()) {
            return 'unavailable';
        }

        $apiReady = $platforms->filter(fn (Platform $platform) => filled($platform->wp_api_url) && filled($platform->wp_api_user) && filled($platform->wp_api_password))->count();
        $dbReady = $platforms->filter(fn (Platform $platform) => filled($platform->db_host) && filled($platform->db_name) && filled($platform->db_user) && filled($platform->db_pass))->count();

        if ($apiReady === $platforms->count() && $dbReady === $platforms->count()) {
            return 'healthy';
        }

        if ($apiReady === 0 && $dbReady === 0) {
            return 'degraded';
        }

        return 'attention';
    }

    private function resolveWpContractSummary(Collection $platforms): string
    {
        if ($platforms->isEmpty()) {
            return 'No markets are in scope for WordPress contract checks.';
        }

        $apiReady = $platforms->filter(fn (Platform $platform) => filled($platform->wp_api_url) && filled($platform->wp_api_user) && filled($platform->wp_api_password))->count();
        $dbReady = $platforms->filter(fn (Platform $platform) => filled($platform->db_host) && filled($platform->db_name) && filled($platform->db_user) && filled($platform->db_pass))->count();

        return sprintf(
            '%d of %d markets have API credentials ready, and %d of %d have DB provisioning credentials ready.',
            $apiReady,
            $platforms->count(),
            $dbReady,
            $platforms->count()
        );
    }

    private function resolveFallbackPostureStatus(Collection $subscriptionRules, int $fallbackConfiguredMarkets, Collection $recentFallbackRoutes): string
    {
        if ($subscriptionRules->isEmpty() && $recentFallbackRoutes->isEmpty()) {
            return 'unavailable';
        }

        if ($fallbackConfiguredMarkets === 0) {
            return 'degraded';
        }

        if ($recentFallbackRoutes->isNotEmpty()) {
            return 'attention';
        }

        return 'healthy';
    }

    private function resolveFallbackPostureSummary(Collection $subscriptionRules, int $fallbackConfiguredMarkets, Collection $recentFallbackRoutes): string
    {
        if ($subscriptionRules->isEmpty() && $recentFallbackRoutes->isEmpty()) {
            return 'No renewal rules or fallback routing activity are available for this scope yet.';
        }

        if ($fallbackConfiguredMarkets === 0) {
            return 'No renewal fallback methods are configured in the current scope, so wallet auto-renew failures would escalate immediately.';
        }

        if ($recentFallbackRoutes->isNotEmpty()) {
            return sprintf(
                '%d renewal fallbacks were exercised in the last 14 days across %d configured fallback markets.',
                $recentFallbackRoutes->count(),
                $fallbackConfiguredMarkets
            );
        }

        return sprintf(
            'Fallback renewal handling is configured for %d market%s, with no recent fallback routes observed.',
            $fallbackConfiguredMarkets,
            $fallbackConfiguredMarkets === 1 ? '' : 's'
        );
    }

    private function subscriptionRuleHasFallback(BillingSubscriptionRule $rule): bool
    {
        return collect($this->extractRenewalMethods($rule))
            ->contains(fn (string $method) => in_array($method, ['manual', 'payment_link', 'stk_push'], true));
    }

    /**
     * @return list<string>
     */
    private function extractRenewalMethods(BillingSubscriptionRule $rule): array
    {
        $rawMethods = data_get($rule->renewal_method_json, 'methods');
        $candidates = is_array($rawMethods)
            ? $rawMethods
            : (is_array($rule->renewal_method_json) ? $rule->renewal_method_json : []);

        return collect($candidates)
            ->map(function ($value) {
                $normalized = strtolower(trim((string) $value));

                return match ($normalized) {
                    'link' => 'payment_link',
                    'stk' => 'stk_push',
                    'wallet' => 'wallet_balance',
                    default => $normalized,
                };
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function resolveProvisioningStatus(Payment $payment, Collection $provisioningEvents): string
    {
        $status = (string) data_get($payment->payment_data, 'canonical_state.provisioning_status', '');

        if ($status === 'completed') {
            return 'healthy';
        }

        if ($status === 'pending') {
            return 'attention';
        }

        if (in_array($status, ['underpaid_review_required', 'client_unresolved', 'suppressed_sandbox'], true)) {
            return 'degraded';
        }

        return $provisioningEvents->isNotEmpty() ? 'attention' : 'neutral';
    }

    private function resolveProvisioningSummary(Payment $payment, Collection $provisioningEvents): string
    {
        $status = (string) data_get($payment->payment_data, 'canonical_state.provisioning_status', '');

        if ($status !== '') {
            return sprintf('Canonical provisioning state is %s.', Str::headline($status));
        }

        if ($provisioningEvents->isNotEmpty()) {
            return sprintf(
                '%d provisioning-related timeline events were found for this payment or linked deal.',
                $provisioningEvents->count()
            );
        }

        return 'No structured provisioning state has been recorded yet.';
    }

    private function formatDateTime(mixed $value): string
    {
        if (!$value) {
            return '—';
        }

        if ($value instanceof Carbon) {
            return $value->toDateTimeString();
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            return '—';
        }
    }
}
