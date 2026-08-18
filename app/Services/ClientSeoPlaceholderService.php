<?php

namespace App\Services;

use App\Models\Client;
use App\Models\TimelineEvent;
use App\Support\ClientLifecycleState;
use App\Support\CrmAuditAction;
use App\Support\DeactivationRequest;
use App\Support\DealDeactivationReason;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Log;

class ClientSeoPlaceholderService
{
    private const LEGACY_IMPORT_WINDOW_START = '2026-03-01 00:00:00';

    private const LEGACY_IMPORT_WINDOW_END = '2026-03-31 23:59:59';

    public function __construct(
        private readonly AuditService $auditService,
        private readonly ClientSubscriptionDeactivationService $deactivationService,
    ) {}

    public static function applyCandidateScope(Builder $query): Builder
    {
        return $query
            ->where('profile_status', 'publish')
            ->where(function (Builder $builder): void {
                $builder->whereNull('needs_payment')->orWhere('needs_payment', false);
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('notactive')->orWhere('notactive', false);
            })
            ->whereIn('lifecycle_state', [
                ClientLifecycleState::ACTIVE,
                ClientLifecycleState::EXPIRED,
            ])
            ->whereNull('lifecycle_restored_at')
            ->where('wp_post_id', '>', 0)
            ->whereNull('closed_at')
            ->whereDoesntHave('deals')
            ->whereDoesntHave('payments')
            ->where(function (Builder $builder): void {
                $builder->whereNull('escort_expire')->orWhere('escort_expire', '<=', 0);
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('premium_expire')->orWhere('premium_expire', '<=', 0);
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('featured_expire')->orWhere('featured_expire', '<=', 0);
            })
            ->where(function (Builder $builder): void {
                $builder->whereNull('signup_source')
                    ->orWhere('signup_source', '')
                    ->orWhere('signup_source', 'crm_manual');
            })
            ->where(function (Builder $builder): void {
                $builder->whereBetween('wp_created_at', [
                    self::LEGACY_IMPORT_WINDOW_START,
                    self::LEGACY_IMPORT_WINDOW_END,
                ])
                    ->orWhere(function (Builder $fallback): void {
                        $fallback->whereNull('wp_created_at')
                            ->whereBetween('created_at', [
                                self::LEGACY_IMPORT_WINDOW_START,
                                self::LEGACY_IMPORT_WINDOW_END,
                            ]);
                    });
            });
    }

    public function isCandidate(Client $client): bool
    {
        if ((string) $client->profile_status !== 'publish') {
            return false;
        }

        if ((bool) $client->needs_payment || (bool) $client->notactive) {
            return false;
        }

        if (! in_array(($client->lifecycle_state ?? ClientLifecycleState::ACTIVE), [
            ClientLifecycleState::ACTIVE,
            ClientLifecycleState::EXPIRED,
        ], true)) {
            return false;
        }

        if ($client->lifecycle_restored_at !== null) {
            return false;
        }

        if ((int) ($client->wp_post_id ?? 0) <= 0 || $client->closed_at !== null) {
            return false;
        }

        if ((int) ($client->escort_expire ?? 0) > 0
            || (int) ($client->premium_expire ?? 0) > 0
            || (int) ($client->featured_expire ?? 0) > 0) {
            return false;
        }

        $source = trim((string) ($client->signup_source ?? ''));
        if ($source !== '' && $source !== 'crm_manual') {
            return false;
        }

        if ($client->relationLoaded('deals') && $client->deals->isNotEmpty()) {
            return false;
        }

        if ($client->relationLoaded('payments') && $client->payments->isNotEmpty()) {
            return false;
        }

        if (! $client->relationLoaded('deals') && $client->deals()->exists()) {
            return false;
        }

        if (! $client->relationLoaded('payments') && $client->payments()->exists()) {
            return false;
        }

        return $this->isInLegacyImportWindow($client);
    }

    public function annotate(iterable $clients): void
    {
        if ($clients instanceof EloquentCollection) {
            $clients->loadMissing(['deals:id,client_id', 'payments:id,client_id']);
        }

        foreach ($clients as $client) {
            if ($client instanceof Client) {
                $client->setAttribute('seo_placeholder_candidate', $this->isCandidate($client));
            }
        }
    }

    public function bulkTakePrivate(array $clientIds, int $actorId, string $reason): array
    {
        $ids = collect($clientIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $clients = Client::query()
            ->with(['platform', 'deals', 'payments'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $deactivationRequest = new DeactivationRequest(
            DealDeactivationReason::OTHER,
            $reason
        );

        $results = [];
        $summary = [
            'total' => count($ids),
            'privatized' => 0,
            'skipped' => max(0, count($ids) - $clients->count()),
            'failed' => 0,
        ];

        foreach ($clients as $client) {
            $beforeState = $this->clientState($client);

            if (! $this->isCandidate($client)) {
                $summary['skipped']++;
                $results[] = [
                    'client_id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'action' => 'skipped',
                    'message' => 'Client no longer matches the SEO placeholder guard.',
                ];

                continue;
            }

            try {
                $fresh = $this->deactivationService->deactivate($client, $deactivationRequest, $actorId);

                if ((string) $fresh->profile_status !== 'private') {
                    $summary['failed']++;
                    $results[] = [
                        'client_id' => (int) $client->id,
                        'name' => (string) $client->name,
                        'action' => 'failed',
                        'message' => 'WordPress still reports this profile as public after deactivation.',
                    ];
                    $this->recordAudit($fresh, $actorId, $beforeState, $this->clientState($fresh), $reason, false);

                    continue;
                }

                $summary['privatized']++;
                $results[] = [
                    'client_id' => (int) $fresh->id,
                    'name' => (string) $fresh->name,
                    'action' => 'privatized',
                    'message' => 'Profile was set private in WordPress.',
                ];
                $this->recordAudit($fresh, $actorId, $beforeState, $this->clientState($fresh), $reason, true);
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $results[] = [
                    'client_id' => (int) $client->id,
                    'name' => (string) $client->name,
                    'action' => 'failed',
                    'message' => $exception->getMessage(),
                ];

                Log::warning('SEO placeholder bulk private failed', [
                    'client_id' => (int) $client->id,
                    'platform_id' => (int) $client->platform_id,
                    'wp_post_id' => (int) ($client->wp_post_id ?? 0),
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'summary' => $summary,
            'results' => $results,
        ];
    }

    private function isInLegacyImportWindow(Client $client): bool
    {
        $createdAt = $client->wp_created_at ?: $client->created_at;

        if ($createdAt === null) {
            return false;
        }

        $timezone = config('app.timezone');
        $start = CarbonImmutable::parse(self::LEGACY_IMPORT_WINDOW_START, $timezone);
        $end = CarbonImmutable::parse(self::LEGACY_IMPORT_WINDOW_END, $timezone);

        return $createdAt->greaterThanOrEqualTo($start)
            && $createdAt->lessThanOrEqualTo($end);
    }

    private function clientState(Client $client): array
    {
        return [
            'profile_status' => $client->profile_status,
            'lifecycle_state' => $client->lifecycle_state,
            'lifecycle_restored_at' => optional($client->lifecycle_restored_at)->toDateTimeString(),
            'needs_payment' => (bool) $client->needs_payment,
            'notactive' => (bool) $client->notactive,
            'escort_expire' => $client->escort_expire,
            'premium_expire' => $client->premium_expire,
            'featured_expire' => $client->featured_expire,
            'main_image_url' => $client->main_image_url,
            'display_image_url' => $client->display_image_url,
            'wp_post_id' => $client->wp_post_id,
            'wp_created_at' => optional($client->wp_created_at)->toDateTimeString(),
            'created_at' => optional($client->created_at)->toDateTimeString(),
        ];
    }

    private function recordAudit(
        Client $client,
        int $actorId,
        array $beforeState,
        array $afterState,
        string $reason,
        bool $success,
    ): void {
        $this->auditService->record([
            'platform_id' => (int) $client->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::CLIENT_SUBSCRIPTION_DEACTIVATE,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'before_state' => $beforeState,
            'after_state' => array_merge($afterState, [
                'deactivation_scope' => 'seo_placeholder_cleanup',
                'verified_private' => $success,
            ]),
            'reason' => $reason,
        ]);

        TimelineEvent::create([
            'platform_id' => (int) $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => (int) $client->id,
            'event_type' => 'seo_placeholder_cleanup',
            'actor_id' => $actorId,
            'content' => [
                'verified_private' => $success,
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);
    }
}
