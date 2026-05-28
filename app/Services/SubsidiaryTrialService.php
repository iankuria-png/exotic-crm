<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Deal;
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
        private readonly AuditService $auditService
    ) {
    }

    public function targetsForDeal(Deal $main, User $user): array
    {
        $main->loadMissing('client.platform');
        $query = Platform::query()
            ->where('id', '!=', (int) $main->platform_id)
            ->orderBy('name');

        $this->marketAuthorizationService->applyPlatformScope($query, $user, 'id');

        return [
            'targets' => $query->get()->map(fn (Platform $platform) => $this->targetPayload($platform))->values()->all(),
        ];
    }

    public function previewForDeal(Deal $main, Platform $target): array
    {
        $main->loadMissing(['client.platform']);
        $client = $main->client;
        if (!$client) {
            throw new \InvalidArgumentException('Deal has no associated client.');
        }

        $phone = $this->clientResolver->targetPhone($client, $target, []);
        $match = null;
        if ($phone) {
            $matchClient = Client::query()
                ->where('platform_id', (int) $target->id)
                ->where('phone_normalized', $phone)
                ->latest('id')
                ->first();
            $match = $matchClient ? $this->matchPayload($matchClient) : null;
        }

        return [
            'target_platform' => $this->targetPayload($target),
            'config_errors' => $this->configErrors($target),
            'match' => $match,
            'will_create' => $match ? null : [
                'name' => $client->name,
                'phone_normalized' => $phone,
                'email' => $client->email,
                'city' => $client->city,
            ],
            'default_duration_days' => max(1, (int) ($target->field_trial_duration_days ?? 7)),
        ];
    }

    public function activateIfPending(Deal $main): ?Deal
    {
        $main = $main->fresh(['client.platform', 'linkedDeal']);
        if (!$main) {
            return null;
        }

        $intent = $this->intent($main);
        if (($intent['status'] ?? null) !== 'pending' || (int) ($main->linked_deal_id ?? 0) > 0) {
            return $main->linkedDeal;
        }

        $target = Platform::query()->find((int) ($intent['platform_id'] ?? 0));
        if (!$target) {
            return $this->fail($main, $intent, 'target_missing', 'Subsidiary market no longer exists.');
        }

        try {
            $errors = $this->configErrors($target);
            if (in_array('missing_trial_product', $errors, true)) {
                return $this->fail($main, $intent, 'no_trial_product', 'Subsidiary trial product is not configured.');
            }
            if (in_array('missing_wp_api_credentials', $errors, true)) {
                return $this->fail($main, $intent, 'missing_wp_api_credentials', 'Subsidiary WordPress API credentials are incomplete.');
            }

            $client = $this->resolveClientForIntent($main, $target, $intent);
            if ((int) ($client->wp_post_id ?? 0) <= 0) {
                return $this->fail($main, $intent, 'client_not_wp_linked', 'Subsidiary client is not linked to a WordPress profile.');
            }

            $activeTrial = Deal::query()
                ->where('client_id', (int) $client->id)
                ->where('is_free_trial', true)
                ->where('status', 'active')
                ->latest('id')
                ->first();
            if ($activeTrial) {
                $this->linkDeals($main, $activeTrial, $intent);
                $this->auditService->record([
                    'platform_id' => (int) $target->id,
                    'actor_id' => (int) ($intent['requested_by_user_id'] ?? 0) ?: null,
                    'action' => CrmAuditAction::DEAL_SUBSIDIARY_TRIAL_REUSED,
                    'entity_type' => 'deal',
                    'entity_id' => (int) $activeTrial->id,
                    'before_state' => null,
                    'after_state' => [
                        'main_deal_id' => (int) $main->id,
                        'subsidiary_deal_id' => (int) $activeTrial->id,
                    ],
                    'reason' => 'Linked existing active subsidiary free trial',
                ]);

                return $activeTrial->fresh(['client', 'product', 'platform']);
            }

            $product = Product::query()
                ->where('platform_id', (int) $target->id)
                ->where('is_active', true)
                ->find((int) $target->field_trial_product_id);
            if (!$product) {
                return $this->fail($main, $intent, 'no_trial_product', 'Configured subsidiary trial product is unavailable.');
            }

            $durationDays = max(1, min(90, (int) ($intent['duration_days'] ?? $target->field_trial_duration_days ?? 7)));
            $actorId = (int) ($intent['requested_by_user_id'] ?? 0);

            return DB::transaction(function () use ($main, $client, $product, $durationDays, $actorId, $intent, $target): Deal {
                $trial = Deal::query()
                    ->where('client_id', (int) $client->id)
                    ->where('product_id', (int) $product->id)
                    ->where('status', 'pending')
                    ->whereNull('activated_at')
                    ->latest('id')
                    ->first();

                if (!$trial) {
                    $trial = $this->dealPaymentService->createPendingDealFromCatalog(
                        $client,
                        (int) $product->id,
                        null,
                        'weekly',
                        $actorId,
                        null
                    );
                }

                $activated = $this->subscriptionProvisioningService->activateDeal($trial, [
                    'payment_method' => 'free_trial',
                    'duration_days' => $durationDays,
                    'is_free_trial' => true,
                    'free_trial_approved_by' => 'subsidiary_trial',
                    'actor_id' => $actorId,
                    'timeline_context' => [
                        'source' => 'subsidiary_trial_from_deal',
                        'main_deal_id' => (int) $main->id,
                    ],
                ]);

                $this->linkDeals($main, $activated, $intent);

                $this->auditService->record([
                    'platform_id' => (int) $target->id,
                    'actor_id' => $actorId ?: null,
                    'action' => CrmAuditAction::DEAL_SUBSIDIARY_TRIAL_ACTIVATE,
                    'entity_type' => 'deal',
                    'entity_id' => (int) $activated->id,
                    'before_state' => null,
                    'after_state' => [
                        'main_deal_id' => (int) $main->id,
                        'subsidiary_deal_id' => (int) $activated->id,
                        'duration_days' => $durationDays,
                    ],
                    'reason' => 'Activated subsidiary free trial from main deal',
                ]);

                return $activated->fresh(['client', 'product', 'platform']);
            });
        } catch (SubsidiaryProvisioningException $exception) {
            return $this->fail($main, $intent, $exception->errorCode(), $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Subsidiary trial activation failed', [
                'main_deal_id' => $main->id,
                'error' => $exception->getMessage(),
            ]);

            return $this->fail($main, $intent, 'activation_failed', $exception->getMessage());
        }
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

    private function targetPayload(Platform $platform): array
    {
        return [
            'id' => (int) $platform->id,
            'name' => $platform->name,
            'field_trial_product_id' => $platform->field_trial_product_id ? (int) $platform->field_trial_product_id : null,
            'field_trial_duration_days' => max(1, (int) ($platform->field_trial_duration_days ?? 7)),
            'wp_api_ready' => $this->wpApiReady($platform),
            'wp_provisioning_ready' => $this->wpProvisioningReady($platform),
            'config_errors' => $this->configErrors($platform),
        ];
    }

    private function configErrors(Platform $platform): array
    {
        $errors = [];
        if (!$platform->field_trial_product_id) {
            $errors[] = 'missing_trial_product';
        }
        if (!$this->wpApiReady($platform)) {
            $errors[] = 'missing_wp_api_credentials';
        }
        if (!$this->wpProvisioningReady($platform)) {
            $errors[] = 'missing_wp_db_credentials';
        }

        return $errors;
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
            'no_trial_product' => 'Subsidiary trial product is not configured.',
            'missing_wp_api_credentials' => 'Subsidiary WordPress API credentials are incomplete.',
            'missing_wp_db_credentials' => 'Subsidiary WordPress database credentials are incomplete.',
            'wp_provisioning_failed' => 'Subsidiary WordPress provisioning failed.',
            'client_sync_failed' => 'Subsidiary client sync failed after provisioning.',
            default => 'Subsidiary trial activation failed.',
        };
    }
}
