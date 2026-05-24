<?php

namespace App\Services;

use App\Models\Client;
use App\Models\ClientRetentionInsight;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\RetentionMetricSnapshot;
use App\Models\TimelineEvent;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ClientRetentionInsightService
{
    public const COMPONENT_WEIGHTS = [
        'payments' => 30,
        'subscription_lifecycle' => 25,
        'engagement_recency' => 15,
        'reminder_responsiveness' => 10,
        'notification_responsiveness' => 10,
        'market_baseline' => 10,
    ];

    public const BANDS = [
        'Stable',
        'Watchlist',
        'Needs Attention',
        'Critical',
    ];

    public const WATCH_BANDS = [
        'Watchlist',
        'Needs Attention',
        'Critical',
    ];

    private const SIGNAL_WINDOW_DAYS = 120;
    private const PAYMENT_WINDOW_DAYS = 90;
    private const MIN_MARKET_COHORT = 15;

    public function getOrRefreshForClient(Client $client, int $staleAfterMinutes = 720): ClientRetentionInsight
    {
        $client->loadMissing('retentionInsight');

        $current = $client->retentionInsight;
        if (
            $current instanceof ClientRetentionInsight
            && $current->computed_at
            && $current->computed_at->gte(now()->subMinutes($staleAfterMinutes))
        ) {
            return $current;
        }

        return $this->refreshForClient($client) ?? ClientRetentionInsight::make([
            'client_id' => (int) $client->id,
            'platform_id' => $client->platform_id ? (int) $client->platform_id : null,
            'score' => 25,
            'band' => 'Stable',
            'primary_tag' => 'Stable',
            'secondary_tags' => [],
            'component_scores' => [],
            'top_drivers' => [],
            'signals' => [],
            'computed_at' => now(),
        ]);
    }

    public function refreshForClient(Client|int $client): ?ClientRetentionInsight
    {
        $client = $client instanceof Client
            ? $client->fresh(['platform'])
            : Client::query()->with('platform')->find((int) $client);

        if (!$client) {
            return null;
        }

        $insightData = $this->computeInsight($client);

        $insight = ClientRetentionInsight::query()->updateOrCreate(
            ['client_id' => (int) $client->id],
            array_merge($insightData, [
                'platform_id' => $client->platform_id ? (int) $client->platform_id : null,
            ])
        );

        // Log daily history snapshot for trend charts (max 1 per client per day)
        try {
            DB::table('client_retention_insight_history')->updateOrInsert(
                ['client_id' => (int) $client->id, 'recorded_date' => now()->toDateString()],
                ['score' => (int) ($insightData['score'] ?? 0), 'band' => (string) ($insightData['band'] ?? 'Stable'), 'created_at' => now()]
            );
        } catch (\Throwable $e) {
            Log::debug('Failed to log retention insight history.', ['client_id' => (int) $client->id, 'error' => $e->getMessage()]);
        }

        return $insight;
    }

    public function refreshForClientIds(array $clientIds): void
    {
        $ids = collect($clientIds)
            ->filter(static fn ($id) => (int) $id > 0)
            ->map(static fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        Client::query()
            ->whereIn('id', $ids)
            ->with('platform')
            ->chunkById(100, function (Collection $clients): void {
                foreach ($clients as $client) {
                    $this->refreshForClient($client);
                }
            });
    }

    public function refreshAll(?array $platformIds = null): void
    {
        $query = Client::query()->with('platform');
        if (is_array($platformIds) && !empty($platformIds)) {
            $query->whereIn('platform_id', array_map('intval', $platformIds));
        }

        $query->chunkById(100, function (Collection $clients): void {
            foreach ($clients as $client) {
                $this->refreshForClient($client);
            }
        });
    }

    public function buildClientPayload(ClientRetentionInsight $insight): array
    {
        return [
            'client_id' => (int) $insight->client_id,
            'score' => (int) $insight->score,
            'band' => (string) $insight->band,
            'primary_tag' => (string) $insight->primary_tag,
            'secondary_tags' => is_array($insight->secondary_tags) ? $insight->secondary_tags : [],
            'component_scores' => is_array($insight->component_scores) ? $insight->component_scores : [],
            'top_drivers' => is_array($insight->top_drivers) ? $insight->top_drivers : [],
            'signals' => is_array($insight->signals) ? $insight->signals : [],
            'computed_at' => optional($insight->computed_at)?->toIso8601String(),
            'is_watchlist' => in_array((string) $insight->band, self::WATCH_BANDS, true),
        ];
    }

    public function buildDashboardSummary(?array $platformIds = null, int $topLimit = 5): array
    {
        $scopePlatformIds = is_array($platformIds) && !empty($platformIds)
            ? array_map('intval', $platformIds)
            : null;

        $baseQuery = ClientRetentionInsight::query();
        if ($scopePlatformIds !== null) {
            $baseQuery->whereIn('platform_id', $scopePlatformIds);
        }

        $bandCounts = (clone $baseQuery)
            ->selectRaw('band, COUNT(*) as aggregate')
            ->groupBy('band')
            ->pluck('aggregate', 'band');

        $bandDistribution = collect(self::BANDS)->mapWithKeys(
            fn (string $band): array => [$band => (int) ($bandCounts[$band] ?? 0)]
        )->all();

        $behaviorDistribution = (clone $baseQuery)
            ->selectRaw('primary_tag, COUNT(*) as aggregate')
            ->groupBy('primary_tag')
            ->pluck('aggregate', 'primary_tag')
            ->map(fn ($count): int => (int) $count)
            ->sortDesc()
            ->all();

        $watchCount = (clone $baseQuery)
            ->whereIn('band', self::WATCH_BANDS)
            ->count();

        $topWatchClients = (clone $baseQuery)
            ->with('client.platform')
            ->whereIn('band', self::WATCH_BANDS)
            ->orderByDesc('score')
            ->limit($topLimit)
            ->get([
                'client_id',
                'platform_id',
                'score',
                'band',
                'primary_tag',
                'signals',
                'computed_at',
            ])
            ->map(function (ClientRetentionInsight $row): array {
                $client = $row->client;

                return [
                    'client_id' => (int) $row->client_id,
                    'name' => $client?->name ?: "Client #{$row->client_id}",
                    'platform_id' => $row->platform_id ? (int) $row->platform_id : null,
                    'platform_name' => $client?->platform?->name,
                    'band' => (string) $row->band,
                    'score' => (int) $row->score,
                    'primary_tag' => (string) $row->primary_tag,
                    'last_online_at' => $client?->last_online_at,
                    'profile_status' => $client?->profile_status,
                ];
            })
            ->all();

        $latestSnapshotDate = RetentionMetricSnapshot::query()->max('snapshot_date');
        $snapshotQuery = RetentionMetricSnapshot::query();
        if ($latestSnapshotDate) {
            $snapshotQuery->whereDate('snapshot_date', $latestSnapshotDate);
        }

        if ($scopePlatformIds !== null) {
            $snapshotQuery->whereIn('platform_id', $scopePlatformIds);
        } else {
            $snapshotQuery->where('platform_id', 0);
        }

        $snapshotRows = $snapshotQuery->get();
        $baselineTotal = (int) $snapshotRows->sum('active_baseline_count');
        $churnedTotal = (int) $snapshotRows->sum('churned_count');

        if ($baselineTotal === 0) {
            [$baselineTotal, $churnedTotal] = $this->computeLogoChurnCountsForScope(
                $latestSnapshotDate ? Carbon::parse((string) $latestSnapshotDate)->startOfDay() : now()->startOfDay(),
                $scopePlatformIds
            );
        }

        $logoChurn = $baselineTotal > 0 ? round(($churnedTotal / $baselineTotal) * 100, 2) : 0.0;

        return [
            'watch_count' => (int) $watchCount,
            'band_distribution' => $bandDistribution,
            'behavior_distribution' => $behaviorDistribution,
            'logo_churn_30d' => $logoChurn,
            'top_watch_clients' => $topWatchClients,
            'snapshot_date' => $latestSnapshotDate,
        ];
    }

    public function recordDailyMetricSnapshots(?Carbon $snapshotDate = null, ?array $platformIds = null): void
    {
        $snapshotDate = ($snapshotDate ?: now())->copy()->startOfDay();
        $platformIds = is_array($platformIds) && !empty($platformIds)
            ? array_values(array_unique(array_map('intval', $platformIds)))
            : Platform::query()->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        $targets = array_merge([0], $platformIds);

        foreach ($targets as $platformId) {
            [$baselineCount, $churnedCount] = $this->computeLogoChurnCounts(
                $snapshotDate,
                $platformId === 0 ? null : (int) $platformId
            );

            RetentionMetricSnapshot::query()->updateOrCreate(
                [
                    'snapshot_date' => $snapshotDate->toDateString(),
                    'platform_id' => (int) $platformId,
                ],
                [
                    'active_baseline_count' => $baselineCount,
                    'churned_count' => $churnedCount,
                    'logo_churn_30d' => $baselineCount > 0 ? round(($churnedCount / $baselineCount) * 100, 2) : 0,
                    'meta' => [
                        'window_start' => $snapshotDate->copy()->subDays(29)->toDateString(),
                        'window_end' => $snapshotDate->toDateString(),
                    ],
                    'computed_at' => now(),
                ]
            );
        }
    }

    public static function scheduleRefreshForClientId(?int $clientId): void
    {
        if (!$clientId) {
            return;
        }

        DB::afterCommit(function () use ($clientId): void {
            try {
                app(self::class)->refreshForClient((int) $clientId);
            } catch (\Throwable $exception) {
                Log::warning('Failed to refresh client retention insight after commit.', [
                    'client_id' => (int) $clientId,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    public static function scheduleRefreshForEntity(string $entityType, int $entityId): void
    {
        DB::afterCommit(function () use ($entityType, $entityId): void {
            try {
                $service = app(self::class);
                $clientId = match ($entityType) {
                    'client' => $entityId,
                    'deal' => (int) (Deal::query()->whereKey($entityId)->value('client_id') ?: 0),
                    'payment' => (int) (Payment::query()->whereKey($entityId)->value('client_id') ?: 0),
                    default => 0,
                };

                if ($clientId > 0) {
                    $service->refreshForClient($clientId);
                }
            } catch (\Throwable $exception) {
                Log::warning('Failed to refresh client retention insight from entity trigger.', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'error' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function computeInsight(Client $client): array
    {
        $components = [
            'payments' => $this->buildPaymentsComponent($client),
            'subscription_lifecycle' => $this->buildSubscriptionLifecycleComponent($client),
            'engagement_recency' => $this->buildEngagementComponent($client),
            'reminder_responsiveness' => $this->buildReminderComponent($client),
            'notification_responsiveness' => $this->buildNotificationComponent($client),
            'market_baseline' => $this->buildMarketBaselineComponent($client),
        ];

        $availableComponents = collect($components)
            ->filter(fn (array $component): bool => (bool) ($component['available'] ?? false));

        if ($availableComponents->isEmpty()) {
            $fallbackDrivers = [[
                'label' => 'Limited relationship history',
                'detail' => 'Retention insight is waiting for enough payment, activity, or subscription history.',
                'severity' => 25,
            ]];

            return [
                'score' => 25,
                'band' => 'Stable',
                'primary_tag' => 'Stable',
                'secondary_tags' => [],
                'component_scores' => [],
                'top_drivers' => $fallbackDrivers,
                'signals' => [],
                'computed_at' => now(),
            ];
        }

        $baseWeightTotal = (float) $availableComponents
            ->sum(fn (array $component): float => (float) ($component['base_weight'] ?? 0));

        $weightedScore = 0.0;
        $componentScores = [];

        foreach ($availableComponents as $key => $component) {
            $baseWeight = (float) ($component['base_weight'] ?? 0);
            $effectiveWeight = $baseWeightTotal > 0
                ? round(($baseWeight / $baseWeightTotal) * 100, 2)
                : 0.0;

            $component['effective_weight'] = $effectiveWeight;
            $componentScores[$key] = $component;
            $weightedScore += ((float) ($component['score'] ?? 0) * $effectiveWeight) / 100;
        }

        $score = (int) round($weightedScore);
        $band = $this->resolveBand($score);
        [$primaryTag, $secondaryTags] = $this->deriveBehaviorTags($client, $score, $componentScores);

        return [
            'score' => $score,
            'band' => $band,
            'primary_tag' => $primaryTag,
            'secondary_tags' => $secondaryTags,
            'component_scores' => $componentScores,
            'top_drivers' => $this->resolveTopDrivers($componentScores),
            'signals' => $this->collectSignals($componentScores),
            'computed_at' => now(),
        ];
    }

    private function buildPaymentsComponent(Client $client): array
    {
        $payments = Payment::query()
            ->businessVisible()
            ->excludingWalletTopups()
            ->where('client_id', (int) $client->id)
            ->whereIn('status', array_values(array_unique(array_merge(Payment::SUCCESSFUL_STATUSES, ['failed', 'initiated', 'pending']))))
            ->where('created_at', '>=', now()->subDays(self::SIGNAL_WINDOW_DAYS))
            ->get(['status', 'created_at', 'completed_at', 'amount', 'transaction_reference', 'reconciliation_state']);

        if ($payments->isEmpty()) {
            return $this->unavailableComponent('Payments');
        }

        $manualReviewPending = $payments->where('reconciliation_state', 'manual_review')->count();
        $completed = $payments
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->reject(fn (Payment $payment): bool => (string) $payment->reconciliation_state === 'manual_review')
            ->count();
        $failed = $payments->where('status', 'failed')->count();
        $pending = $payments->whereIn('status', ['initiated', 'pending'])->count() + $manualReviewPending;
        $recentCompleted = $payments
            ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
            ->reject(fn (Payment $payment): bool => (string) $payment->reconciliation_state === 'manual_review')
            ->where('created_at', '>=', now()->subDays(self::PAYMENT_WINDOW_DAYS))
            ->count();

        if ($completed > 0 && $failed === 0 && $pending === 0) {
            $score = max(6, 18 - min(10, $recentCompleted * 2));
        } else {
            $score = 22 + min(45, $failed * 20) + min(22, $pending * 9) - min(18, $recentCompleted * 4);
        }

        $score = (int) max(5, min(100, round($score)));
        $latestCompletedAt = optional(
            $payments
                ->whereIn('status', Payment::SUCCESSFUL_STATUSES)
                ->reject(fn (Payment $payment): bool => (string) $payment->reconciliation_state === 'manual_review')
                ->sortByDesc('created_at')
                ->first()
        )->created_at;

        return [
            'available' => true,
            'label' => 'Payment history',
            'base_weight' => self::COMPONENT_WEIGHTS['payments'],
            'score' => $score,
            'summary' => $failed > 0 || $pending > 0
                ? "Recent payments: {$failed} failed, {$pending} still pending."
                : "{$completed} successful payment(s) in the recent period.",
            'signals' => [
                'completed_count' => $completed,
                'failed_count' => $failed,
                'pending_count' => $pending,
                'latest_completed_at' => optional($latestCompletedAt)?->toIso8601String(),
            ],
            'drivers' => collect([
                $failed > 0 ? [
                    'label' => "{$failed} failed payment" . ($failed === 1 ? '' : 's'),
                    'detail' => 'Recovery friction is increasing churn pressure.',
                    'severity' => min(96, 52 + ($failed * 10)),
                ] : null,
                $pending > 0 ? [
                    'label' => "{$pending} payment" . ($pending === 1 ? '' : 's') . ' awaiting completion',
                    'detail' => 'Unfinished payment journeys are lowering conversion confidence.',
                    'severity' => min(85, 40 + ($pending * 9)),
                ] : null,
                $completed >= 3 && $failed === 0 ? [
                    'label' => 'Healthy payment cadence',
                    'detail' => 'Recent completed payments are offsetting churn risk.',
                    'severity' => 12,
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function buildSubscriptionLifecycleComponent(Client $client): array
    {
        $deals = Deal::query()
            ->where('client_id', (int) $client->id)
            ->orderByDesc('created_at')
            ->get(['id', 'status', 'plan_type', 'is_free_trial', 'activated_at', 'expires_at', 'renewal_reminders_paused', 'created_at']);

        if ($deals->isEmpty()) {
            return $this->unavailableComponent('Subscription Lifecycle');
        }

        $currentDeal = $deals
            ->first(fn (Deal $deal): bool => $deal->status === 'active' && (!$deal->expires_at || $deal->expires_at->gte(now())));
        $latestDeal = $deals->first();
        $recentCancellations = $deals
            ->filter(fn (Deal $deal): bool => $deal->status === 'cancelled' && optional($deal->updated_at)->gte(now()->subDays(self::SIGNAL_WINDOW_DAYS)))
            ->count();
        $awaitingPayment = $deals->where('status', 'awaiting_payment')->count();

        if ($currentDeal) {
            $daysToExpiry = $currentDeal->expires_at
                ? now()->diffInDays($currentDeal->expires_at, false)
                : null;
            $score = match (true) {
                $daysToExpiry === null => 20,
                $daysToExpiry > 21 => 10,
                $daysToExpiry > 14 => 18,
                $daysToExpiry > 7 => 30,
                $daysToExpiry > 3 => 42,
                $daysToExpiry >= 0 => 58,
                default => 82,
            };
        } elseif ($awaitingPayment > 0) {
            $daysToExpiry = null;
            $score = 66;
        } else {
            $daysToExpiry = null;
            $score = match ((string) $latestDeal?->status) {
                'cancelled' => 85,
                'expired', 'deactivated' => 78,
                'pending' => 58,
                default => 52,
            };
        }

        $score += min(14, $recentCancellations * 7);
        $score = (int) max(6, min(100, $score));

        return [
            'available' => true,
            'label' => 'Subscription status',
            'base_weight' => self::COMPONENT_WEIGHTS['subscription_lifecycle'],
            'score' => $score,
            'summary' => $currentDeal
                ? ($daysToExpiry !== null
                    ? "Current {$currentDeal->plan_type} plan ends in {$daysToExpiry} day(s)."
                    : "Current {$currentDeal->plan_type} plan is active with no confirmed end date.")
                : "Latest subscription is {$latestDeal?->status} with {$awaitingPayment} waiting for payment.",
            'signals' => [
                'active_deal_id' => $currentDeal?->id,
                'active_plan_type' => $currentDeal?->plan_type,
                'active_is_free_trial' => $currentDeal ? (bool) $currentDeal->is_free_trial : false,
                'days_to_expiry' => $daysToExpiry,
                'latest_status' => $latestDeal?->status,
                'awaiting_payment_count' => $awaitingPayment,
                'recent_cancellations' => $recentCancellations,
            ],
            'drivers' => collect([
                $currentDeal && $daysToExpiry !== null && $daysToExpiry <= 7 ? [
                    'label' => 'Subscription expires within a week',
                    'detail' => 'Renewal urgency is now a primary churn driver.',
                    'severity' => min(94, 60 + max(0, 7 - $daysToExpiry) * 4),
                ] : null,
                $awaitingPayment > 0 ? [
                    'label' => "{$awaitingPayment} renewal or activation payment" . ($awaitingPayment === 1 ? '' : 's') . ' pending',
                    'detail' => 'Conversion is waiting on payment completion.',
                    'severity' => min(88, 52 + ($awaitingPayment * 7)),
                ] : null,
                $recentCancellations > 0 ? [
                    'label' => "{$recentCancellations} recent cancellation" . ($recentCancellations === 1 ? '' : 's'),
                    'detail' => 'Recent churn events are raising the lifecycle score.',
                    'severity' => min(90, 58 + ($recentCancellations * 6)),
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function buildEngagementComponent(Client $client): array
    {
        if (!$client->last_online_at) {
            return $this->unavailableComponent('Engagement Recency');
        }

        $lastOnlineAt = Carbon::createFromTimestamp((int) $client->last_online_at);
        $daysSinceOnline = max(0, $lastOnlineAt->diffInDays(now()));

        $score = match (true) {
            $daysSinceOnline <= 2 => 8,
            $daysSinceOnline <= 7 => 18,
            $daysSinceOnline <= 14 => 34,
            $daysSinceOnline <= 30 => 52,
            $daysSinceOnline <= 60 => 74,
            default => 88,
        };

        return [
            'available' => true,
            'label' => 'Recent activity',
            'base_weight' => self::COMPONENT_WEIGHTS['engagement_recency'],
            'score' => $score,
            'summary' => "Last online {$daysSinceOnline} day(s) ago.",
            'signals' => [
                'last_online_at' => $lastOnlineAt->toIso8601String(),
                'days_since_online' => $daysSinceOnline,
            ],
            'drivers' => collect([
                $daysSinceOnline > 14 ? [
                    'label' => 'No recent client activity',
                    'detail' => "Last online {$daysSinceOnline} day(s) ago.",
                    'severity' => min(88, 42 + min(40, $daysSinceOnline / 2)),
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function buildReminderComponent(Client $client): array
    {
        $dealIds = Deal::query()
            ->where('client_id', (int) $client->id)
            ->pluck('id');

        $events = TimelineEvent::query()
            ->where('entity_type', 'deal')
            ->whereIn('entity_id', $dealIds)
            ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed', 'renewal_whatsapp_sent', 'renewal_whatsapp_failed'])
            ->where('created_at', '>=', now()->subDays(self::SIGNAL_WINDOW_DAYS))
            ->get(['event_type', 'created_at']);

        $currentDeal = Deal::query()
            ->where('client_id', (int) $client->id)
            ->where('status', 'active')
            ->latest('expires_at')
            ->first(['id', 'renewal_reminders_paused', 'expires_at']);

        if ($events->isEmpty() && !$currentDeal) {
            return $this->unavailableComponent('Reminder Responsiveness');
        }

        $sent = $events->whereIn('event_type', ['renewal_sms_sent', 'renewal_whatsapp_sent'])->count();
        $failed = $events->whereIn('event_type', ['renewal_sms_failed', 'renewal_whatsapp_failed'])->count();
        $lastReminderAt = optional($events->sortByDesc('created_at')->first())->created_at;

        $responseReceived = false;
        if ($lastReminderAt) {
            $responseReceived = Payment::query()
                ->reportableSuccessful()
                ->where('client_id', (int) $client->id)
                ->excludingWalletTopups()
                ->where('created_at', '>=', $lastReminderAt)
                ->exists();
        }

        if ($events->isEmpty()) {
            $score = $currentDeal && (bool) $currentDeal->renewal_reminders_paused ? 64 : 34;
        } else {
            $score = 22
                + ($failed > 0 ? min(24, $failed * 12) : 0)
                + ($responseReceived ? -10 : min(26, $sent * 5))
                + ($currentDeal && (bool) $currentDeal->renewal_reminders_paused ? 16 : 0);
        }

        $score = (int) max(8, min(100, round($score)));

        return [
            'available' => true,
            'label' => 'Reminder follow-through',
            'base_weight' => self::COMPONENT_WEIGHTS['reminder_responsiveness'],
            'score' => $score,
            'summary' => $events->isEmpty()
                ? ((bool) ($currentDeal?->renewal_reminders_paused) ? 'Renewal reminders are turned off for this subscription.' : 'No recent renewal reminder history.')
                : "Reminders sent: {$sent}. Delivery issues: {$failed}. Response " . ($responseReceived ? 'received' : 'not seen yet') . '.',
            'signals' => [
                'sent_count' => $sent,
                'failed_count' => $failed,
                'response_received' => $responseReceived,
                'last_reminder_at' => optional($lastReminderAt)?->toIso8601String(),
                'reminders_paused' => (bool) ($currentDeal?->renewal_reminders_paused),
            ],
            'drivers' => collect([
                !$responseReceived && $sent > 0 ? [
                    'label' => 'Reminders are not converting',
                    'detail' => 'Renewal prompts were sent without a follow-up payment or reactivation.',
                    'severity' => min(84, 44 + ($sent * 5)),
                ] : null,
                $failed > 0 ? [
                    'label' => "{$failed} renewal reminder" . ($failed === 1 ? '' : 's') . ' failed',
                    'detail' => 'Reminder delivery issues are weakening renewal recovery.',
                    'severity' => min(78, 38 + ($failed * 10)),
                ] : null,
                $currentDeal && (bool) $currentDeal->renewal_reminders_paused ? [
                    'label' => 'Renewal reminders paused',
                    'detail' => 'Paused reminders reduce automated save attempts.',
                    'severity' => 68,
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function buildNotificationComponent(Client $client): array
    {
        $events = TimelineEvent::query()
            ->where('entity_type', 'client')
            ->where('entity_id', (int) $client->id)
            ->whereIn('event_type', [
                'conversation_sms_sent',
                'conversation_sms_failed',
                'conversation_whatsapp_sent',
                'conversation_whatsapp_failed',
                'sms_sent',
                'sms_delivered',
                'sms_failed',
                'whatsapp_sent',
                'whatsapp_delivered',
                'whatsapp_failed',
                'push_notification_sent',
                'push_notification_failed',
            ])
            ->where('created_at', '>=', now()->subDays(self::SIGNAL_WINDOW_DAYS))
            ->get(['event_type', 'created_at']);

        if ($events->isEmpty()) {
            return $this->unavailableComponent('Notification Responsiveness');
        }

        $failed = $events->whereIn('event_type', ['conversation_sms_failed', 'conversation_whatsapp_failed', 'sms_failed', 'whatsapp_failed', 'push_notification_failed'])->count();
        $delivered = $events->whereIn('event_type', ['sms_delivered', 'whatsapp_delivered'])->count();
        $sent = $events->whereIn('event_type', ['conversation_sms_sent', 'conversation_whatsapp_sent', 'sms_sent', 'whatsapp_sent', 'push_notification_sent'])->count();

        $attempted = max(1, $sent + $delivered + $failed);
        $failureRatio = $failed / $attempted;
        $deliveryRatio = ($delivered + $sent) / $attempted;
        $score = (int) max(8, min(100, round((20 + ($failureRatio * 55) - ($deliveryRatio * 10)))));

        return [
            'available' => true,
            'label' => 'Message delivery',
            'base_weight' => self::COMPONENT_WEIGHTS['notification_responsiveness'],
            'score' => $score,
            'summary' => "Messages tracked: {$attempted}. Delivery issues: {$failed}.",
            'signals' => [
                'attempted_count' => $attempted,
                'sent_count' => $sent,
                'delivered_count' => $delivered,
                'failed_count' => $failed,
            ],
            'drivers' => collect([
                $failed > 0 ? [
                    'label' => 'Notification delivery friction',
                    'detail' => "{$failed} send or delivery failure(s) are reducing response confidence.",
                    'severity' => min(82, 35 + ($failed * 8)),
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function buildMarketBaselineComponent(Client $client): array
    {
        if (!(int) $client->platform_id) {
            return $this->unavailableComponent('Market Baseline');
        }

        $now = now();

        $cohortQuery = Client::query()
            ->where('platform_id', (int) $client->platform_id)
            ->where(function ($builder): void {
                $builder->whereNotNull('last_online_at')
                    ->orWhereExists(function ($subQuery): void {
                        $subQuery->selectRaw('1')
                            ->from('deals')
                            ->whereColumn('deals.client_id', 'clients.id');
                    })
                    ->orWhereExists(function ($subQuery): void {
                        $subQuery->selectRaw('1')
                            ->from('payments')
                            ->whereColumn('payments.client_id', 'clients.id')
                            ->where(function ($builder): void {
                                $builder->whereNull('payments.record_classification')
                                    ->orWhere('payments.record_classification', '!=', Payment::RECORD_CLASSIFICATION_TEST);
                            })
                            ->where(function ($builder): void {
                                $builder->whereNull('payments.provider_environment')
                                    ->orWhereRaw("LOWER(payments.provider_environment) != ?", ['sandbox']);
                            })
                            ->where(function ($builder): void {
                                $builder->whereNull('payments.payment_data->test_mode')
                                    ->orWhere('payments.payment_data->test_mode', false);
                            });
                    });
            });

        $cohortSize = (int) $cohortQuery->count();
        if ($cohortSize < self::MIN_MARKET_COHORT) {
            return $this->unavailableComponent('Market Baseline');
        }

        $averageOnlineAge = (float) Client::query()
            ->where('platform_id', (int) $client->platform_id)
            ->whereNotNull('last_online_at')
            ->pluck('last_online_at')
            ->map(fn ($timestamp): int => Carbon::createFromTimestamp((int) $timestamp)->diffInDays($now))
            ->avg();

        $averageCompletedPayments = (float) Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->where('created_at', '>=', now()->subDays(self::PAYMENT_WINDOW_DAYS))
            ->whereIn('client_id', function ($subQuery) use ($client): void {
                $subQuery->select('id')
                    ->from('clients')
                    ->where('platform_id', (int) $client->platform_id);
            })
            ->selectRaw('client_id, COUNT(*) as completed_count')
            ->groupBy('client_id')
            ->pluck('completed_count')
            ->avg();

        $clientOnlineAge = $client->last_online_at
            ? Carbon::createFromTimestamp((int) $client->last_online_at)->diffInDays($now)
            : null;
        $clientCompletedPayments = (int) Payment::query()
            ->reportableSuccessful()
            ->excludingWalletTopups()
            ->where('client_id', (int) $client->id)
            ->where('created_at', '>=', now()->subDays(self::PAYMENT_WINDOW_DAYS))
            ->count();

        $score = 30;
        if ($clientOnlineAge !== null && $averageOnlineAge > 0 && $clientOnlineAge > ($averageOnlineAge * 1.75)) {
            $score += 25;
        } elseif ($clientOnlineAge !== null && $averageOnlineAge > 0 && $clientOnlineAge < ($averageOnlineAge * 0.75)) {
            $score -= 10;
        }

        if ($averageCompletedPayments > 0 && $clientCompletedPayments < ($averageCompletedPayments * 0.5)) {
            $score += 18;
        } elseif ($averageCompletedPayments > 0 && $clientCompletedPayments >= $averageCompletedPayments) {
            $score -= 10;
        }

        if ($client->profile_status !== 'publish') {
            $score += 10;
        }

        $score = (int) max(8, min(100, round($score)));

        return [
            'available' => true,
            'label' => 'Similar client benchmark',
            'base_weight' => self::COMPONENT_WEIGHTS['market_baseline'],
            'score' => $score,
            'summary' => "Based on {$cohortSize} similar clients in this market.",
            'signals' => [
                'cohort_size' => $cohortSize,
                'average_online_age_days' => round($averageOnlineAge, 1),
                'client_online_age_days' => $clientOnlineAge,
                'average_completed_payments_90d' => round($averageCompletedPayments, 2),
                'client_completed_payments_90d' => $clientCompletedPayments,
            ],
            'drivers' => collect([
                $clientOnlineAge !== null && $averageOnlineAge > 0 && $clientOnlineAge > ($averageOnlineAge * 1.75) ? [
                    'label' => 'Less active than similar clients',
                    'detail' => 'Recent activity is lower than similar clients in this market.',
                    'severity' => 70,
                ] : null,
                $averageCompletedPayments > 0 && $clientCompletedPayments < ($averageCompletedPayments * 0.5) ? [
                    'label' => 'Payments are lower than similar clients',
                    'detail' => 'This client is paying less often than similar clients in this market.',
                    'severity' => 66,
                ] : null,
            ])->filter()->values()->all(),
        ];
    }

    private function resolveBand(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Critical',
            $score >= 55 => 'Needs Attention',
            $score >= 30 => 'Watchlist',
            default => 'Stable',
        };
    }

    private function deriveBehaviorTags(Client $client, int $score, array $components): array
    {
        $signals = $this->collectSignals($components);
        $subscriptionSignals = $signals['subscription_lifecycle'] ?? [];
        $paymentSignals = $signals['payments'] ?? [];
        $engagementSignals = $signals['engagement_recency'] ?? [];

        $secondary = [];
        $primary = 'Stable';

        if (($subscriptionSignals['active_is_free_trial'] ?? false) && $score < 45) {
            $primary = 'Trial Converting';
        } elseif (($paymentSignals['failed_count'] ?? 0) >= 2 || ($paymentSignals['pending_count'] ?? 0) >= 2) {
            $primary = 'Payment Friction';
        } elseif (($subscriptionSignals['days_to_expiry'] ?? 999) <= 7 && ($subscriptionSignals['days_to_expiry'] ?? 999) >= 0) {
            $primary = 'Renewal Risk';
        } elseif (($engagementSignals['days_since_online'] ?? 0) >= 30) {
            $primary = 'Dormant';
        } elseif (
            in_array((string) ($subscriptionSignals['latest_status'] ?? ''), ['cancelled', 'expired', 'deactivated'], true)
            && $client->profile_status !== 'publish'
        ) {
            $primary = 'Win-back Candidate';
        } elseif ($score <= 20 && ($paymentSignals['completed_count'] ?? 0) >= 2) {
            $primary = 'Champion';
        }

        if ($primary !== 'Payment Friction' && (($paymentSignals['failed_count'] ?? 0) > 0 || ($paymentSignals['pending_count'] ?? 0) > 0)) {
            $secondary[] = 'Payment Friction';
        }

        if ($primary !== 'Renewal Risk' && (($subscriptionSignals['days_to_expiry'] ?? 999) <= 14 && ($subscriptionSignals['days_to_expiry'] ?? 999) >= 0)) {
            $secondary[] = 'Renewal Risk';
        }

        if ($primary !== 'Dormant' && (($engagementSignals['days_since_online'] ?? 0) >= 21)) {
            $secondary[] = 'Dormant';
        }

        return [$primary, array_values(array_unique($secondary))];
    }

    private function resolveTopDrivers(array $components): array
    {
        return collect($components)
            ->flatMap(fn (array $component): array => is_array($component['drivers'] ?? null) ? $component['drivers'] : [])
            ->sortByDesc(fn (array $driver): int => (int) ($driver['severity'] ?? 0))
            ->take(3)
            ->values()
            ->all();
    }

    private function collectSignals(array $components): array
    {
        $signals = [];

        foreach ($components as $key => $component) {
            $signals[$key] = is_array($component['signals'] ?? null) ? $component['signals'] : [];
        }

        return $signals;
    }

    private function unavailableComponent(string $label): array
    {
        return [
            'available' => false,
            'label' => $label,
            'base_weight' => 0,
            'score' => null,
            'summary' => 'Insufficient data for this component.',
            'signals' => [],
            'drivers' => [],
        ];
    }

    private function computeLogoChurnCounts(Carbon $snapshotDate, ?int $platformId = null): array
    {
        $windowStart = $snapshotDate->copy()->subDays(29)->startOfDay();
        $windowEnd = $snapshotDate->copy()->endOfDay();

        $baselineQuery = Client::query();
        if ($platformId !== null) {
            $baselineQuery->where('platform_id', $platformId);
        }

        $baselineIds = $baselineQuery
            ->where(function ($query) use ($windowStart): void {
                $query->where('profile_status', 'publish')
                    ->orWhereExists(function ($subQuery) use ($windowStart): void {
                        $subQuery->selectRaw('1')
                            ->from('deals')
                            ->whereColumn('deals.client_id', 'clients.id')
                            ->where('activated_at', '<=', $windowStart)
                            ->where(function ($expiresQuery) use ($windowStart): void {
                                $expiresQuery->whereNull('expires_at')
                                    ->orWhere('expires_at', '>=', $windowStart);
                            });
                    });
            })
            ->pluck('id');

        $baselineCount = $baselineIds->count();
        if ($baselineCount === 0) {
            return [0, 0];
        }

        $activeNowIds = Deal::query()
            ->whereIn('client_id', $baselineIds)
            ->where('status', 'active')
            ->where(function ($query) use ($windowEnd): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>=', $windowEnd);
            })
            ->pluck('client_id')
            ->unique();

        $publishVisibleIds = Client::query()
            ->whereIn('id', $baselineIds)
            ->where('profile_status', 'publish')
            ->pluck('id')
            ->unique();

        $retainedIds = $activeNowIds->merge($publishVisibleIds)->unique();
        $churnedCount = max(0, $baselineCount - $retainedIds->count());

        return [$baselineCount, $churnedCount];
    }

    private function computeLogoChurnCountsForScope(Carbon $snapshotDate, ?array $platformIds = null): array
    {
        if (!is_array($platformIds) || empty($platformIds)) {
            return $this->computeLogoChurnCounts($snapshotDate, null);
        }

        $baselineTotal = 0;
        $churnedTotal = 0;

        foreach ($platformIds as $platformId) {
            [$baselineCount, $churnedCount] = $this->computeLogoChurnCounts($snapshotDate, (int) $platformId);
            $baselineTotal += $baselineCount;
            $churnedTotal += $churnedCount;
        }

        return [$baselineTotal, $churnedTotal];
    }
}
