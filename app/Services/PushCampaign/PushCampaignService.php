<?php

namespace App\Services\PushCampaign;

use App\Exceptions\PushCampaignActivationBlockedException;
use App\Jobs\SendPushNotificationJob;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\AuditService;
use App\Services\PushNotification\PushProviderService;
use App\Support\MarketTimezone;
use App\Support\CrmAuditAction;
use Carbon\Carbon;

class PushCampaignService
{
    public function __construct(
        private readonly PushProviderService $pushProviderService,
        private readonly AuditService $auditService,
        private readonly PushCampaignDispatchReadinessService $pushCampaignDispatchReadinessService,
    ) {
    }

    public function createCampaignForPlatform(int $platformId, string $batchId, string $filename, int $actorId): PushCampaign
    {
        $campaign = PushCampaign::query()->firstOrCreate(
            [
                'platform_id' => $platformId,
                'upload_batch_id' => $batchId,
            ],
            [
                'name' => sprintf('Platform %d Push — %s', $platformId, now()->toDateString()),
                'status' => 'processing',
                'source_filename' => $filename,
                'created_by' => $actorId,
            ]
        );

        if (str_starts_with($campaign->name, 'Platform ')) {
            $platformName = $campaign->platform?->name ?: ('Platform ' . $platformId);
            $campaign->forceFill([
                'name' => sprintf('%s Push — %s', $platformName, now()->toDateString()),
            ])->save();
        }

        $this->auditService->record([
            'platform_id' => $platformId,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::PUSH_CAMPAIGN_CREATE,
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $campaign->id,
            'after_state' => [
                'status' => $campaign->status,
                'upload_batch_id' => $campaign->upload_batch_id,
                'source_filename' => $campaign->source_filename,
            ],
            'reason' => 'Created push campaign from upload batch ' . $batchId,
        ]);

        return $campaign;
    }

    public function executeCampaign(PushCampaign $campaign, int $actorId): PushCampaign
    {
        $campaign->loadMissing('platform:id,timezone');
        $activationAt = now()->utc();
        $timezone = MarketTimezone::resolve($campaign->platform?->timezone, config('app.timezone', 'UTC'));
        $readiness = $this->pushCampaignDispatchReadinessService->analyzeActivation($campaign, $activationAt, $timezone);

        if (!(bool) ($readiness['can_activate'] ?? false)) {
            throw new PushCampaignActivationBlockedException($readiness);
        }

        $campaign->forceFill([
            'status' => 'running',
            'executed_at' => $activationAt,
        ])->save();

        $queuedCount = $this->queueDispatchablePendingItems($campaign, $activationAt);

        $this->auditService->record([
            'platform_id' => (int) $campaign->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::PUSH_CAMPAIGN_EXECUTE,
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $campaign->id,
            'after_state' => [
                'status' => 'running',
                'scheduled_dispatch_count' => $queuedCount,
            ],
            'reason' => 'Executed push campaign immediately.',
        ]);

        return $campaign->fresh();
    }

    public function queueRunningCampaignPendingItems(PushCampaign $campaign, ?Carbon $referenceAtUtc = null): int
    {
        $referenceAt = ($referenceAtUtc?->copy() ?? now())->utc();
        $missedCount = $this->markMissedPendingItems($campaign, $referenceAt);
        $queuedCount = $this->queueDispatchablePendingItems($campaign, $referenceAt);

        if ($missedCount > 0) {
            $this->auditService->record([
                'platform_id' => (int) $campaign->platform_id,
                'actor_id' => null,
                'action' => CrmAuditAction::PUSH_NOTIFICATION_FAILED,
                'entity_type' => 'push_campaign',
                'entity_id' => (int) $campaign->id,
                'after_state' => [
                    'status' => 'running',
                    'missed_window_items' => $missedCount,
                    'queued_items' => $queuedCount,
                ],
                'reason' => 'Marked overdue pending items as missed before queue dispatch.',
            ]);
        }

        $this->syncCampaignOutcomeIfDone((int) $campaign->id);

        return $queuedCount;
    }

    private function queueDispatchablePendingItems(PushCampaign $campaign, Carbon $referenceAtUtc): int
    {
        $dispatchUntil = $referenceAtUtc->copy()->addHours(PushCampaignDispatchReadinessService::DISPATCH_WINDOW_HOURS);
        $graceThreshold = $referenceAtUtc->copy()->subMinutes(PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES);

        $items = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->where('status', 'pending')
            ->where(function ($query) use ($dispatchUntil, $graceThreshold) {
                $query->whereNull('scheduled_at')
                    ->orWhereBetween('scheduled_at', [$graceThreshold->toDateTimeString(), $dispatchUntil->toDateTimeString()]);
            })
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        foreach ($items as $item) {
            $scheduledAt = $item->scheduled_at?->copy()?->utc();

            if ($scheduledAt && $scheduledAt->greaterThan($referenceAtUtc)) {
                SendPushNotificationJob::dispatch((int) $item->id)->delay($scheduledAt);
            } else {
                SendPushNotificationJob::dispatch((int) $item->id);
            }

            $item->forceFill([
                'status' => 'scheduled',
            ])->save();
        }

        return (int) $items->count();
    }

    private function markMissedPendingItems(PushCampaign $campaign, Carbon $referenceAtUtc): int
    {
        $missedThreshold = $referenceAtUtc->copy()->subMinutes(PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES);

        $missedItems = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->where('status', 'pending')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<', $missedThreshold->toDateTimeString())
            ->get(['id']);

        if ($missedItems->isEmpty()) {
            return 0;
        }

        PushCampaignItem::query()
            ->whereIn('id', $missedItems->pluck('id')->all())
            ->update([
                'status' => 'failed',
                'error_message' => sprintf(
                    'missed_window: Item exceeded %d-minute grace before queue dispatch. Reschedule required.',
                    PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES
                ),
                'provider_notification_id' => null,
                'sent_at' => null,
                'updated_at' => now(),
            ]);

        PushCampaign::query()
            ->whereKey((int) $campaign->id)
            ->increment('failed_count', (int) $missedItems->count());

        return (int) $missedItems->count();
    }

    private function syncCampaignOutcomeIfDone(int $campaignId): void
    {
        $campaign = PushCampaign::query()->find($campaignId);
        if (!$campaign) {
            return;
        }

        $remaining = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->whereNotIn('status', ['sent', 'failed', 'skipped'])
            ->count();

        if ($remaining > 0) {
            return;
        }

        $sent = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'sent')
            ->count();

        $failed = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'failed')
            ->count();

        $status = $sent > 0 && $failed === 0
            ? 'completed'
            : ($sent > 0 ? 'partial' : 'failed');

        $campaign->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();
    }

    public function scheduleCampaign(PushCampaign $campaign, Carbon $scheduledAt, int $actorId): PushCampaign
    {
        $campaign->forceFill([
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
        ])->save();

        $this->auditService->record([
            'platform_id' => (int) $campaign->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::PUSH_CAMPAIGN_SCHEDULE,
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $campaign->id,
            'after_state' => [
                'status' => 'scheduled',
                'scheduled_at' => $scheduledAt->toDateTimeString(),
            ],
            'reason' => 'Scheduled push campaign for delayed execution.',
        ]);

        return $campaign->fresh();
    }

    public function cancelCampaign(PushCampaign $campaign, int $actorId): PushCampaign
    {
        $campaign->loadMissing('platform:id,timezone');
        $cancellableStatuses = ['pending_extraction', 'needs_preset', 'pending', 'scheduled'];
        $skippedItemIds = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->whereIn('status', $cancellableStatuses)
            ->pluck('id');

        $skippedCount = 0;
        if ($skippedItemIds->isNotEmpty()) {
            $skippedCount = PushCampaignItem::query()
                ->whereIn('id', $skippedItemIds->all())
                ->update([
                    'status' => 'skipped',
                    'error_message' => 'campaign_cancelled: Cancelled by CRM operator before send.',
                    'updated_at' => now(),
                ]);
        }

        $sentCount = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->where('status', 'sent')
            ->count();

        $failedCount = PushCampaignItem::query()
            ->where('campaign_id', (int) $campaign->id)
            ->where('status', 'failed')
            ->count();

        $campaign->forceFill([
            'status' => 'cancelled',
            'completed_at' => now(),
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
        ])->save();

        $this->auditService->record([
            'platform_id' => (int) $campaign->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::PUSH_CAMPAIGN_CANCEL,
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $campaign->id,
            'after_state' => [
                'status' => 'cancelled',
                'skipped_items' => $skippedCount,
                'sent_items' => $sentCount,
                'failed_items' => $failedCount,
            ],
            'reason' => 'Cancelled push campaign and skipped remaining unsent items.',
        ]);

        return $campaign->fresh();
    }

    public function refreshAnalytics(PushCampaign $campaign): PushCampaign
    {
        $items = $campaign->items()
            ->whereNotNull('provider_notification_id')
            ->get();

        foreach ($items as $item) {
            $provider = data_get($item->provider_meta, 'provider') ?: $campaign->provider;

            $stats = $this->pushProviderService->pollAnalytics(
                (string) $item->provider_notification_id,
                $provider,
                [
                    'platform_id' => (int) $campaign->platform_id,
                ]
            );

            if (!$stats) {
                continue;
            }

            $item->forceFill([
                'delivery_stats' => [
                    'total_sent' => $stats['total_sent'] ?? null,
                    'delivered' => $stats['delivered'] ?? null,
                    'clicked' => $stats['clicked'] ?? null,
                    'failed' => $stats['failed'] ?? null,
                    'closed' => $stats['closed'] ?? null,
                ],
            ])->save();
        }

        return $campaign->fresh();
    }
}
