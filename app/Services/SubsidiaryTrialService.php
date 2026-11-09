<?php

namespace App\Services;

use App\Billing\Repositories\BillingConfigurationRepository;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubsidiaryTrialService
{
    public function __construct(
        private readonly MarketAuthorizationService $marketAuthorizationService,
        private readonly SubsidiaryClientResolver $clientResolver,
        private readonly DealPaymentService $dealPaymentService,
        private readonly SubscriptionProvisioningService $subscriptionProvisioningService,
        private readonly AuditService $auditService,
        private readonly BillingConfigurationRepository $billingConfigurationRepository
    ) {
    }

    /** Exact-period collection effects use the existing catalogue mapping, without trial reuse or duration clipping. */
    public function collectionProduct(Platform $target, Deal $main): ?Product
    {
        return $this->resolveTrialProduct($target, $main);
    }

    public function targetsForDeal(Deal $main, User $user): array
    {
        $main->loadMissing('client.platform', 'product');
        $query = Platform::query()
            ->where('id', '!=', (int) $main->platform_id)
            ->orderBy('name');

        $this->marketAuthorizationService->applyPlatformScope($query, $user, 'id');

        $targets = $query->get()->map(fn (Platform $platform) => $this->targetPayload($platform, $main))->values();
        $pair = (int) config('subsidiary_trials.pairs.'.$main->platform_id, 0);
        $recommended = $targets->first(fn ($t) => $t['id'] === $pair && empty($t['config_errors']));
        return ['targets' => $targets->all(), 'recommended_platform_id' => $recommended['id'] ?? null,
            'paired_platform_id' => $pair ?: null, 'pair_unavailable' => $pair > 0 && ! $recommended];
    }

    /**
     * Options (all optional): duration_mode, duration_days, copy (bool, loads the
     * read-only profile copy preview), copy_region_id, copy_city_id, copy_currency_id.
     */
    public function previewForDeal(Deal $main, Platform $target, ?int $selected = null, array $options = []): array
    {
        $main->loadMissing(['client.platform', 'product']);
        abort_unless($main->client, 422, 'Deal has no associated client.');
        $helper = app(SubsidiaryActivationPreviewService::class);
        $resolution = $helper->resolve($main, $target, $selected);
        $client = $resolution['client_id'] ? Client::find($resolution['client_id']) : null;
        $errors = $this->configErrors($target, $main);
        if ($client && $client->wp_post_id) $errors = array_values(array_diff($errors, ['missing_wp_db_credentials']));
        if ($resolution['kind'] === 'already_active') $errors = array_values(array_intersect($errors, ['inactive_market']));
        $policies = app(SubsidiaryPromotionPolicyService::class);
        $duration = $policies->resolve($main, $target, $options['duration_mode'] ?? null, ! empty($options['duration_days']) ? (int) $options['duration_days'] : null, $this->freeTrialDurationDays($target));
        $duration['message'] = $policies->message($duration['error']);
        $copy = ['status' => 'off'];
        if (! empty($options['copy']) && $resolution['kind'] === 'create_if_needed' && ! $errors) {
            $copy = app(SubsidiaryProfileCopyService::class)->preview($main, $target, $options);
        }
        return ['target_platform' => $this->targetPayload($target, $main), 'config_errors' => $errors,
            'match' => $client ? $this->matchPayload($client) : null, 'resolution' => $resolution,
            'will_create' => $client ? null : $helper->seed($main, $target),
            'default_duration_days' => $duration['days'] ?? $this->freeTrialDurationDays($target),
            'duration' => $duration, 'copy_preview' => $copy,
            'source_market' => $main->client?->platform?->name];
    }

    public function activateIfPending(Deal $main): ?Deal
    {
        $main = $main->fresh(['linkedDeal']);
        if (!$main) return null;
        if ($main->linked_deal_id) return $main->linkedDeal;
        $intent = $this->intent($main);
        if (($intent['status'] ?? '') !== 'pending') return null;
        // Historical intents cannot borrow the global PIN or an unrelated approval.
        $request = \App\Models\FreeTrialRequest::find($intent['trial_request_id'] ?? 0);
        if (!$request || $request->action !== 'subsidiary' || (int) ($request->terms_json['source_deal_id'] ?? 0) !== (int) $main->id) return null;
        if ($request->status !== 'approved' || $main->status !== 'active') return null;
        $execute = function () use ($request) {
            try { app(\App\Services\Trials\TrialActivationService::class)->execute($request); }
            catch (\Throwable $exception) {
                // Optional subsidiary failures must never roll back the primary paid activation.
                Log::warning('Subsidiary approval remains queued for review.', ['trial_request_id' => $request->id]);
            }
        };
        if (DB::transactionLevel() > 0) DB::afterCommit($execute); else $execute();
        return $main->fresh()->linkedDeal;
    }

    public function resetForRetry(Deal $main, int $actorId): Deal
    {
        $intent = $this->intent($main);
        $intent['status'] = 'pending';
        $intent['pin_verified_at'] = now()->toDateTimeString();
        $intent['pin_verified_by_user_id'] = $actorId;
        $intent['attempt_count'] = (int) ($intent['attempt_count'] ?? 0) + 1;
        $intent['last_error'] = null;

        $main->forceFill(['pending_subsidiary_trial' => $intent])->save();

        return $main->fresh(['client.platform']) ?? $main;
    }

    public function statusPayload(Deal $main): ?array
    {
        $intent = $this->intent($main);
        if (empty($intent) && !(int) ($main->linked_deal_id ?? 0)) {
            return null;
        }

        return [
            'status' => $intent['status'] ?? ((int) ($main->linked_deal_id ?? 0) > 0 ? 'satisfied' : null),
            'linked_deal_id' => (int) ($main->linked_deal_id ?? 0) ?: null,
            'platform_id' => isset($intent['platform_id']) ? (int) $intent['platform_id'] : null,
            'error' => !empty($intent['last_error'])
                ? [
                    'code' => (string) $intent['last_error'],
                    'message' => $this->errorMessage((string) $intent['last_error']),
                ]
                : null,
        ];
    }

    private function linkDeals(Deal $main, Deal $subsidiary, array $intent): void
    {
        $main->forceFill([
            'linked_deal_id' => (int) $subsidiary->id,
            'pending_subsidiary_trial' => null,
        ])->save();

        $subsidiary->forceFill([
            'linked_deal_id' => (int) $main->id,
        ])->save();

        foreach ([[$main, $subsidiary], [$subsidiary, $main]] as [$deal, $sibling]) {
            TimelineEvent::create([
                'platform_id' => (int) $deal->platform_id,
                'entity_type' => 'deal',
                'entity_id' => (int) $deal->id,
                'event_type' => 'subsidiary_trial_linked',
                'actor_id' => (int) ($intent['requested_by_user_id'] ?? 0) ?: null,
                'content' => [
                    'sibling_deal_id' => (int) $sibling->id,
                    'sibling_platform_id' => (int) $sibling->platform_id,
                ],
                'created_at' => now(),
            ]);
        }
    }

    private function fail(Deal $main, array $intent, string $code, string $message): null
    {
        $intent['status'] = 'failed';
        $intent['last_error'] = $code;
        $intent['last_attempt_at'] = now()->toDateTimeString();
        $intent['attempt_count'] = (int) ($intent['attempt_count'] ?? 0) + 1;

        $main->forceFill(['pending_subsidiary_trial' => $intent])->save();

        $this->auditService->record([
            'platform_id' => isset($intent['platform_id']) ? (int) $intent['platform_id'] : (int) $main->platform_id,
            'actor_id' => (int) ($intent['requested_by_user_id'] ?? 0) ?: null,
            'action' => CrmAuditAction::DEAL_SUBSIDIARY_TRIAL_FAILED,
            'entity_type' => 'deal',
            'entity_id' => (int) $main->id,
            'before_state' => null,
            'after_state' => [
                'error_code' => $code,
                'message' => $message,
            ],
            'reason' => 'Subsidiary trial activation failed',
        ]);

        return null;
    }

    private function matchPayload(Client $client): array
    {
        $latestDeal = $client->deals()->latest('created_at')->first();

        return [
            'id' => (int) $client->id,
            'name' => $client->name,
            'phone_normalized' => $client->phone_normalized,
            'wp_post_id' => (int) ($client->wp_post_id ?? 0),
            'deal_count' => $client->deals()->count(),
            'last_seen' => optional($latestDeal?->created_at)->toDateString(),
            'has_active_trial' => $client->deals()
                ->where('is_free_trial', true)
                ->where('status', 'active')
                ->exists(),
        ];
    }

    private function resolveClientForIntent(Deal $main, Platform $target, array $intent): Client
    {
        $clientId = (int) ($intent['subsidiary_client_id'] ?? 0);
        if ($clientId > 0) {
            $client = Client::query()
                ->where('platform_id', (int) $target->id)
                ->find($clientId);
            if (!$client) {
                throw new SubsidiaryProvisioningException(
                    'Selected subsidiary client no longer exists in the target market.',
                    'subsidiary_client_missing'
                );
            }

            if ((int) ($client->wp_post_id ?? 0) <= 0) {
                return $this->clientResolver->resolve($main->client, $target, array_merge((array) ($intent['subsidiary_client_seed'] ?? []), [
                    'name' => $client->name,
                    'phone_normalized' => $client->phone_normalized,
                    'email' => $client->email,
                    'city' => $client->city,
                ]));
            }

            return $client;
        }

        return $this->clientResolver->resolve($main->client, $target, (array) ($intent['subsidiary_client_seed'] ?? []));
    }

    private function subsidiaryTrialPayment(Deal $main, Deal $trial, Client $client, Product $product, int $durationDays, int $actorId): Payment
    {
        $existing = Payment::query()
            ->where('deal_id', (int) $trial->id)
            ->where('status', 'completed')
            ->where('amount', 0)
            ->where('transaction_reference', 'SUB-TRIAL-' . (int) $main->id . '-' . (int) $trial->id)
            ->first();

        if ($existing) {
            return $existing;
        }

        return Payment::create([
            'platform_id' => (int) $trial->platform_id,
            'product_id' => (int) $product->id,
            'deal_id' => (int) $trial->id,
            'client_id' => (int) $client->id,
            'phone' => $client->phone_normalized,
            'amount' => 0,
            'currency' => $trial->currency ?: ($client->platform?->currency_code ?: $product->currency),
            'transaction_uuid' => 'subsidiary_trial_' . (int) $main->id . '_' . (int) $trial->id,
            'transaction_reference' => 'SUB-TRIAL-' . (int) $main->id . '-' . (int) $trial->id,
            'status' => 'completed',
            'duration' => $trial->duration,
            'subscription_lifecycle' => $trial->subscription_lifecycle,
            'subscription_lifecycle_source' => $trial->subscription_lifecycle_source,
            'subscription_lifecycle_reason' => $trial->subscription_lifecycle_reason,
            'raw_payload' => [
                'source' => 'subsidiary_trial_from_deal',
                'main_deal_id' => (int) $main->id,
                'subsidiary_deal_id' => (int) $trial->id,
                'duration_days' => $durationDays,
                'approval_mode' => 'pin',
            ],
            'match_confidence' => 'manual',
            'confirmed_by' => $actorId ?: null,
            'confirmed_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function targetPayload(Platform $platform, Deal $main): array
    {
        $product = $this->resolveTrialProduct($platform, $main);

        return [
            'id' => (int) $platform->id,
            'name' => $platform->name,
            'free_trial_enabled' => $this->freeTrialEnabled($platform),
            'free_trial_duration_days' => $this->freeTrialDurationDays($platform),
            'trial_product_id' => $product ? (int) $product->id : null,
            'trial_product_name' => $product?->name,
            'wp_api_ready' => $this->wpApiReady($platform),
            'wp_provisioning_ready' => $this->wpProvisioningReady($platform),
            'config_errors' => $this->configErrors($platform, $main),
        ];
    }

    private function configErrors(Platform $platform, Deal $main): array
    {
        $errors = [];
        if (! $platform->is_active) $errors[] = 'inactive_market';
        if (!$this->freeTrialEnabled($platform)) {
            $errors[] = 'free_trial_disabled';
        }
        if (!$this->resolveTrialProduct($platform, $main)) {
            $errors[] = 'missing_matching_product';
        }
        if (!$this->wpApiReady($platform)) {
            $errors[] = 'missing_wp_api_credentials';
        }
        if (!$this->wpProvisioningReady($platform)) {
            $errors[] = 'missing_wp_db_credentials';
        }

        return $errors;
    }

    private function freeTrialEnabled(Platform $platform): bool
    {
        $rule = $this->billingConfigurationRepository->subscriptionRuleForMarket((int) $platform->id);

        return (bool) data_get($rule?->free_trial_json, 'enabled', false);
    }

    private function freeTrialDurationDays(Platform $platform): int
    {
        $rule = $this->billingConfigurationRepository->subscriptionRuleForMarket((int) $platform->id);
        $days = (int) data_get($rule?->free_trial_json, 'duration_days', 0);

        return max(1, min(90, $days > 0 ? $days : 7));
    }

    private function resolveTrialProduct(Platform $target, Deal $main): ?Product
    {
        $main->loadMissing('product');
        $base = Product::query()
            ->where('platform_id', (int) $target->id)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_archived', false)
                    ->orWhereNull('is_archived');
            });

        if ((int) ($target->product_id ?? 0) > 0) {
            $default = (clone $base)->find((int) $target->product_id);
            if ($default) {
                return $default;
            }
        }

        $source = $main->product;
        if ($source) {
            $slug = trim((string) ($source->slug ?? ''));
            if ($slug !== '') {
                $match = (clone $base)->where('slug', $slug)->first();
                if ($match) {
                    return $match;
                }
            }

            $tier = strtolower(trim((string) ($source->tier ?? '')));
            if ($tier !== '' && $tier !== 'custom') {
                $match = (clone $base)->whereRaw('LOWER(tier) = ?', [$tier])->orderBy('sort_order')->orderBy('id')->first();
                if ($match) {
                    return $match;
                }
            }

            $name = ProductCatalogService::normalizePackageName((string) ($source->name ?? ''));
            if ($name !== '') {
                $match = (clone $base)
                    ->where(function ($query) use ($name) {
                        $query->whereRaw('UPPER(name) = ?', [$name])
                            ->orWhereRaw('UPPER(display_name) = ?', [$name]);
                    })
                    ->first();
                if ($match) {
                    return $match;
                }
            }
        }

        $planType = strtolower(trim((string) ($main->plan_type ?? '')));
        if ($planType !== '' && $planType !== 'custom') {
            $match = (clone $base)->whereRaw('LOWER(tier) = ?', [$planType])->orderBy('sort_order')->orderBy('id')->first();
            if ($match) {
                return $match;
            }
        }

        $single = (clone $base)->limit(2)->get();

        return $single->count() === 1 ? $single->first() : null;
    }

    private function wpApiReady(Platform $platform): bool
    {
        try {
            return trim((string) $platform->wp_api_url) !== ''
                && trim((string) $platform->wp_api_user) !== ''
                && trim((string) $platform->wp_api_password) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function wpProvisioningReady(Platform $platform): bool
    {
        $config = $platform->getConnectionConfig();
        foreach (['host', 'database', 'username'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return trim((string) ($config['password'] ?? '')) !== '';
    }

    private function intent(Deal $deal): array
    {
        return is_array($deal->pending_subsidiary_trial) ? $deal->pending_subsidiary_trial : [];
    }

    private function errorMessage(string $code): string
    {
        return match ($code) {
            'free_trial_disabled' => 'Free trials are not enabled for the subsidiary market.',
            'no_matching_product' => 'No matching subscription package is available in the subsidiary market.',
            'no_trial_product' => 'No matching subscription package is available in the subsidiary market.',
            'missing_wp_api_credentials' => 'Subsidiary WordPress API credentials are incomplete.',
            'missing_wp_db_credentials' => 'Subsidiary WordPress database credentials are incomplete.',
            'wp_provisioning_failed' => 'Subsidiary WordPress provisioning failed.',
            'client_sync_failed' => 'Subsidiary client sync failed after provisioning.',
            default => 'Subsidiary trial activation failed.',
        };
    }
}
