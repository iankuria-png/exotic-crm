<?php

namespace App\Services;

use App\Models\ClientNote;
use App\Models\Deal;
use App\Models\RenewalCampaign;
use App\Models\RenewalRun;
use App\Models\Template;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use App\Support\CrmAuditAction;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class RenewalService
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly TemplateService $templateService,
        private readonly AuditService $auditService
    ) {
    }

    public function buildOverview(array $filters = [], int $perPage = 50, ?User $viewer = null): array
    {
        $query = Deal::query()
            ->with(['client.platform', 'product', 'assignedAgent'])
            ->select('deals.*')
            ->selectSub(function ($builder) {
                $builder->from('timeline_events')
                    ->selectRaw('COUNT(*)')
                    ->where('entity_type', 'deal')
                    ->where('event_type', 'renewal_sms_sent')
                    ->whereColumn('entity_id', 'deals.id');
            }, 'reminders_sent_count')
            ->selectSub(function ($builder) {
                $builder->from('timeline_events')
                    ->selectRaw('COUNT(*)')
                    ->where('entity_type', 'deal')
                    ->where('event_type', 'renewal_sms_failed')
                    ->whereColumn('entity_id', 'deals.id');
            }, 'reminders_failed_count')
            ->selectSub(function ($builder) {
                $builder->from('timeline_events')
                    ->select('created_at')
                    ->where('entity_type', 'deal')
                    ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed'])
                    ->whereColumn('entity_id', 'deals.id')
                    ->orderByDesc('created_at')
                    ->limit(1);
            }, 'last_renewal_reminder_at')
            ->whereIn('status', ['active', 'expired']);

        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $query->whereIn('platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $query->where('platform_id', (int) $filters['platform_id']);
        }

        if (!empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->whereHas('client', function (Builder $builder) use ($search) {
                $builder->where('name', 'like', "%{$search}%")
                    ->orWhere('phone_normalized', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['bucket'])) {
            $this->applyBucketFilter($query, (string) $filters['bucket']);
        }

        /** @var LengthAwarePaginator $targets */
        $targets = $query
            ->orderBy('expires_at')
            ->paginate($perPage)
            ->through(function (Deal $deal) {
                $daysLeft = $this->daysUntil($deal->expires_at);
                $remindersPaused = $this->isReminderPaused($deal);
                $renewalBucket = $remindersPaused ? 'paused' : $this->bucketForDays($daysLeft);

                return array_merge($deal->toArray(), [
                    'days_left' => $daysLeft,
                    'renewal_bucket' => $renewalBucket,
                    'reminders_sent_count' => (int) ($deal->reminders_sent_count ?? 0),
                    'reminders_failed_count' => (int) ($deal->reminders_failed_count ?? 0),
                    'last_renewal_reminder_at' => $deal->last_renewal_reminder_at,
                    'reminders_paused' => $remindersPaused,
                    'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
                    'renewal_pause_reason' => $deal->renewal_pause_reason,
                ]);
            });

        $summaryBase = Deal::query()->where('status', 'active');
        if (!empty($filters['platform_ids']) && is_array($filters['platform_ids'])) {
            $summaryBase->whereIn('platform_id', $filters['platform_ids']);
        } elseif (!empty($filters['platform_id'])) {
            $summaryBase->where('platform_id', (int) $filters['platform_id']);
        }

        $summary = [
            'active_deals' => (int) $summaryBase->count(),
            'risk' => (int) (clone $summaryBase)
                ->whereBetween('expires_at', [now(), now()->addDays(3)])
                ->count(),
            'pending' => (int) (clone $summaryBase)
                ->whereBetween('expires_at', [now()->addDays(4), now()->addDays(14)])
                ->count(),
            'renewed_this_month' => (int) (clone $summaryBase)
                ->whereNotNull('activated_at')
                ->where('activated_at', '>=', now()->startOfMonth())
                ->count(),
            'paused_reminders' => (int) (clone $summaryBase)
                ->where('renewal_reminders_paused', true)
                ->where(function (Builder $builder) {
                    $builder->whereNull('renewal_paused_until')
                        ->orWhere('renewal_paused_until', '>=', now());
                })
                ->count(),
        ];

        $campaigns = RenewalCampaign::query()
            ->with('template:id,title,channel,status')
            ->orderBy('trigger_days')
            ->get();

        $recentRuns = RenewalRun::query()
            ->with(['campaign.template:id,title', 'runner:id,name'])
            ->when(
                $viewer && $viewer->role !== MarketAuthorizationService::ROLE_ADMIN,
                fn (Builder $builder) => $builder->where('run_by', $viewer->id)
            )
            ->orderByDesc('run_at')
            ->limit(10)
            ->get();

        return [
            'summary' => $summary,
            'targets' => $targets,
            'campaigns' => $campaigns,
            'recent_runs' => $recentRuns,
        ];
    }

    public function runCampaigns(?int $campaignId = null, ?int $actorId = null, ?array $platformIds = null): array
    {
        $campaigns = RenewalCampaign::query()
            ->with('template')
            ->where('enabled', true)
            ->when($campaignId, fn (Builder $builder) => $builder->where('id', $campaignId))
            ->orderBy('trigger_days')
            ->get();

        if ($campaigns->isEmpty()) {
            return [
                'campaigns' => [],
                'totals' => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0],
            ];
        }

        $results = [];
        $totals = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'targeted' => 0];

        foreach ($campaigns as $campaign) {
            $result = $this->runSingleCampaign($campaign, $actorId, $platformIds);
            $results[] = $result;

            $totals['sent'] += $result['sent_count'];
            $totals['failed'] += $result['failed_count'];
            $totals['skipped'] += $result['skipped_count'];
            $totals['targeted'] += $result['total_targeted'];
        }

        return [
            'campaigns' => $results,
            'totals' => $totals,
        ];
    }

    public function sendManualReminder(Deal $deal, ?int $templateId = null, ?int $actorId = null): array
    {
        $deal->loadMissing(['client.platform', 'product']);

        if ($this->isReminderPaused($deal)) {
            $resumeOn = $deal->renewal_paused_until ? Carbon::parse($deal->renewal_paused_until)->toDateTimeString() : 'manual resume';

            return [
                'success' => false,
                'status' => 'paused',
                'reason' => 'Renewal reminders are paused for this subscription until ' . $resumeOn . '.',
            ];
        }

        if (!$deal->client) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Deal does not have a linked client.',
            ];
        }

        $template = $templateId
            ? Template::query()->where('id', $templateId)->where('channel', 'sms')->first()
            : $this->resolveDefaultRenewalTemplate($deal);

        if (!$template) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'No SMS template available for this reminder.',
            ];
        }

        $variables = $this->templateService->buildClientVariables($deal->client, $deal, [
            'trigger_days' => $this->suggestTriggerDays($deal),
        ]);

        $rendered = $this->templateService->renderTemplate($template, $variables);
        if (!empty($rendered['missing'])) {
            return [
                'success' => false,
                'status' => 'failed',
                'reason' => 'Template rendering missing variables: ' . implode(', ', $rendered['missing']),
                'missing' => $rendered['missing'],
            ];
        }

        $delivery = $this->notificationService->sendSmsToClient($deal->client, $rendered['body'], [
            'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
            'template_id' => $template->id,
            'deal_id' => $deal->id,
            'mode' => 'manual',
        ]);

        $eventType = $delivery['success'] ? 'renewal_sms_sent' : 'renewal_sms_failed';
        $notePrefix = $delivery['success'] ? '[Renewal SMS]' : '[Renewal SMS Failed]';

        DB::transaction(function () use ($deal, $template, $delivery, $eventType, $notePrefix, $rendered, $actorId) {
            ClientNote::create([
                'client_id' => $deal->client_id,
                'author_id' => $this->resolveActorId($actorId),
                'note_type' => 'system',
                'content' => sprintf(
                    '%s Template #%d: %s',
                    $notePrefix,
                    $template->id,
                    $rendered['body']
                ),
                'follow_up_at' => null,
                'created_at' => now(),
            ]);

            TimelineEvent::create([
                'platform_id' => $deal->platform_id,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'event_type' => $eventType,
                'actor_id' => $actorId,
                'content' => [
                    'template_id' => $template->id,
                    'delivery_status' => $delivery['status'],
                    'provider_response' => $delivery['provider_response'] ?? null,
                    'manual' => true,
                ],
                'created_at' => now(),
            ]);

            $this->auditService->record([
                'platform_id' => $deal->platform_id,
                'actor_id' => $actorId,
                'action' => $delivery['success'] ? CrmAuditAction::RENEWAL_SMS_SENT : CrmAuditAction::RENEWAL_SMS_FAILED,
                'entity_type' => 'deal',
                'entity_id' => $deal->id,
                'before_state' => null,
                'after_state' => [
                    'template_id' => $template->id,
                    'delivery_status' => $delivery['status'],
                ],
                'reason' => 'Manual renewal reminder',
            ]);
        });

        return array_merge([
            'success' => (bool) $delivery['success'],
            'status' => $delivery['status'],
            'template_id' => $template->id,
            'message' => $rendered['body'],
        ], $delivery);
    }

    public function pauseReminders(Deal $deal, string $reason, ?int $actorId = null, ?string $pauseUntil = null): array
    {
        $pauseUntilDate = null;
        if ($pauseUntil) {
            $pauseUntilDate = Carbon::parse($pauseUntil)->endOfDay();
        }

        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => true,
            'renewal_paused_until' => $pauseUntilDate,
            'renewal_pause_reason' => $reason,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_paused',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_PAUSE,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => true,
                'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
                'renewal_pause_reason' => $reason,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'paused',
            'deal_id' => $deal->id,
            'renewal_paused_until' => optional($pauseUntilDate)->toDateTimeString(),
        ];
    }

    public function resumeReminders(Deal $deal, string $reason, ?int $actorId = null): array
    {
        $beforeState = [
            'renewal_reminders_paused' => (bool) $deal->renewal_reminders_paused,
            'renewal_paused_until' => optional($deal->renewal_paused_until)->toDateTimeString(),
            'renewal_pause_reason' => $deal->renewal_pause_reason,
        ];

        $deal->update([
            'renewal_reminders_paused' => false,
            'renewal_paused_until' => null,
            'renewal_pause_reason' => null,
        ]);

        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => 'renewal_reminders_resumed',
            'actor_id' => $actorId,
            'content' => [
                'reason' => $reason,
            ],
            'created_at' => now(),
        ]);

        $this->auditService->record([
            'platform_id' => $deal->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::RENEWAL_RESUME,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'before_state' => $beforeState,
            'after_state' => [
                'renewal_reminders_paused' => false,
                'renewal_paused_until' => null,
                'renewal_pause_reason' => null,
            ],
            'reason' => $reason,
        ]);

        return [
            'success' => true,
            'status' => 'active',
            'deal_id' => $deal->id,
        ];
    }

    private function runSingleCampaign(RenewalCampaign $campaign, ?int $actorId, ?array $platformIds = null): array
    {
        $campaign->loadMissing('template');

        $deals = $this->targetDealsForCampaign($campaign, $platformIds);
        $runnerId = $this->resolveActorId($actorId);

        $run = RenewalRun::create([
            'campaign_id' => $campaign->id,
            'run_at' => now(),
            'total_targeted' => $deals->count(),
            'sent_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
            'run_by' => $runnerId,
            'status' => 'completed',
            'created_at' => now(),
        ]);

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($deals as $deal) {
            if ($this->alreadyAttemptedToday($deal->id, $campaign->id)) {
                $skipped++;
                continue;
            }

            $deal->loadMissing(['client.platform', 'product']);
            if (!$deal->client || !$campaign->template) {
                $failed++;
                $this->writeRenewalTimeline($deal, $campaign, $run, false, 'Missing client or template');
                continue;
            }

            $variables = $this->templateService->buildClientVariables($deal->client, $deal, [
                'trigger_days' => $campaign->trigger_days,
            ]);
            $rendered = $this->templateService->renderTemplate($campaign->template, $variables);

            if (!empty($rendered['missing'])) {
                $failed++;
                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    false,
                    'Missing variables: ' . implode(', ', $rendered['missing'])
                );
                continue;
            }

            $delivery = $this->notificationService->sendSmsToClient($deal->client, $rendered['body'], [
                'phone_prefix' => optional($deal->client->platform)->phone_prefix ?? '254',
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
            ]);

            if ($delivery['success']) {
                $sent++;
            } else {
                $failed++;
            }

            DB::transaction(function () use ($deal, $campaign, $run, $rendered, $delivery, $runnerId) {
                $notePrefix = $delivery['success'] ? '[RC' . $campaign->id . '] Renewal SMS' : '[RC' . $campaign->id . '] Renewal SMS Failed';

                ClientNote::create([
                    'client_id' => $deal->client_id,
                    'author_id' => $runnerId,
                    'note_type' => 'system',
                    'content' => sprintf('%s: %s', $notePrefix, $rendered['body']),
                    'follow_up_at' => null,
                    'created_at' => now(),
                ]);

                $this->writeRenewalTimeline(
                    $deal,
                    $campaign,
                    $run,
                    (bool) $delivery['success'],
                    $delivery['provider_response'] ?? null
                );

                $this->auditService->record([
                    'platform_id' => $deal->platform_id,
                    'actor_id' => $runnerId,
                    'action' => $delivery['success'] ? CrmAuditAction::RENEWAL_SMS_SENT : CrmAuditAction::RENEWAL_SMS_FAILED,
                    'entity_type' => 'deal',
                    'entity_id' => $deal->id,
                    'after_state' => [
                        'campaign_id' => $campaign->id,
                        'run_id' => $run->id,
                        'delivery_status' => $delivery['status'],
                    ],
                    'reason' => 'Automated renewal campaign',
                ]);
            });
        }

        $status = 'completed';
        if ($failed > 0 && $sent === 0) {
            $status = 'failed';
        } elseif ($failed > 0 || $skipped > 0) {
            $status = 'partial';
        }

        $run->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ]);

        return [
            'campaign_id' => $campaign->id,
            'trigger_days' => $campaign->trigger_days,
            'run_id' => $run->id,
            'total_targeted' => $run->total_targeted,
            'sent_count' => $sent,
            'failed_count' => $failed,
            'skipped_count' => $skipped,
            'status' => $status,
        ];
    }

    private function targetDealsForCampaign(RenewalCampaign $campaign, ?array $platformIds = null): Collection
    {
        $targetDate = now()->startOfDay()->addDays($campaign->trigger_days * -1);

        return Deal::query()
            ->whereIn('status', ['active', 'expired'])
            ->whereDate('expires_at', $targetDate->toDateString())
            ->where(function (Builder $builder) {
                $builder->where('renewal_reminders_paused', false)
                    ->orWhere(function (Builder $pausedBuilder) {
                        $pausedBuilder->where('renewal_reminders_paused', true)
                            ->whereNotNull('renewal_paused_until')
                            ->where('renewal_paused_until', '<', now());
                    });
            })
            ->when(
                is_array($platformIds),
                fn (Builder $builder) => $builder->whereIn('platform_id', $platformIds)
            )
            ->when($campaign->product_id, fn (Builder $builder) => $builder->where('product_id', $campaign->product_id))
            ->with(['client.platform', 'product'])
            ->get();
    }

    private function applyBucketFilter(Builder $query, string $bucket): void
    {
        if ($bucket === 'paused') {
            $query->where('renewal_reminders_paused', true)
                ->where(function (Builder $builder) {
                    $builder->whereNull('renewal_paused_until')
                        ->orWhere('renewal_paused_until', '>=', now());
                });
            return;
        }

        if ($bucket === 'risk') {
            $query->whereBetween('expires_at', [now(), now()->addDays(3)]);
            return;
        }

        if ($bucket === 'pending') {
            $query->whereBetween('expires_at', [now()->addDays(4), now()->addDays(14)]);
            return;
        }

        if ($bucket === 'stable') {
            $query->where('expires_at', '>', now()->addDays(14));
            return;
        }

        if ($bucket === 'expired') {
            $query->where('expires_at', '<', now());
        }
    }

    private function alreadyAttemptedToday(int $dealId, int $campaignId): bool
    {
        return TimelineEvent::query()
            ->where('entity_type', 'deal')
            ->where('entity_id', $dealId)
            ->whereIn('event_type', ['renewal_sms_sent', 'renewal_sms_failed'])
            ->whereDate('created_at', now()->toDateString())
            ->where('content', 'like', '%"campaign_id":' . $campaignId . '%')
            ->exists();
    }

    private function writeRenewalTimeline(Deal $deal, RenewalCampaign $campaign, RenewalRun $run, bool $success, ?string $response): void
    {
        TimelineEvent::create([
            'platform_id' => $deal->platform_id,
            'entity_type' => 'deal',
            'entity_id' => $deal->id,
            'event_type' => $success ? 'renewal_sms_sent' : 'renewal_sms_failed',
            'actor_id' => null,
            'content' => [
                'campaign_id' => $campaign->id,
                'run_id' => $run->id,
                'template_id' => $campaign->template_id,
                'response' => $response,
            ],
            'created_at' => now(),
        ]);
    }

    private function resolveDefaultRenewalTemplate(Deal $deal): ?Template
    {
        $trigger = $this->suggestTriggerDays($deal);

        $campaign = RenewalCampaign::query()
            ->where('enabled', true)
            ->where('trigger_days', $trigger)
            ->with('template')
            ->first();

        if ($campaign?->template) {
            return $campaign->template;
        }

        return Template::query()
            ->where('category', 'renewal')
            ->where('channel', 'sms')
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();
    }

    private function suggestTriggerDays(Deal $deal): int
    {
        $daysLeft = $this->daysUntil($deal->expires_at);

        if ($daysLeft === null) {
            return 0;
        }

        if ($daysLeft >= 7) {
            return -7;
        }

        if ($daysLeft >= 3) {
            return -3;
        }

        if ($daysLeft >= 0) {
            return 0;
        }

        return 3;
    }

    private function daysUntil($dateValue): ?int
    {
        if (!$dateValue) {
            return null;
        }

        $date = $dateValue instanceof Carbon ? $dateValue : Carbon::parse($dateValue);

        return now()->diffInDays($date, false);
    }

    private function bucketForDays(?int $daysLeft): string
    {
        if ($daysLeft === null) {
            return 'unknown';
        }

        if ($daysLeft < 0) {
            return 'expired';
        }

        if ($daysLeft <= 3) {
            return 'risk';
        }

        if ($daysLeft <= 14) {
            return 'pending';
        }

        return 'stable';
    }

    private function isReminderPaused(Deal $deal): bool
    {
        if (!(bool) $deal->renewal_reminders_paused) {
            return false;
        }

        if (!$deal->renewal_paused_until) {
            return true;
        }

        return Carbon::parse($deal->renewal_paused_until)->isFuture();
    }

    private function resolveActorId(?int $actorId): int
    {
        if ($actorId) {
            return $actorId;
        }

        $userId = User::query()
            ->where('role', 'admin')
            ->orderBy('id')
            ->value('id');

        if ($userId) {
            return (int) $userId;
        }

        $fallback = User::query()->orderBy('id')->value('id');
        if ($fallback) {
            return (int) $fallback;
        }

        throw new \RuntimeException('No users available to set renewal run owner.');
    }
}
