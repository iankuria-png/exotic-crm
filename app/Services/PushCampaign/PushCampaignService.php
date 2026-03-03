<?php

namespace App\Services\PushCampaign;

use App\Jobs\SendPushNotificationJob;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Services\AuditService;
use App\Services\PushNotification\PushProviderService;
use App\Support\CrmAuditAction;
use Carbon\Carbon;

class PushCampaignService
{
    public function __construct(
        private readonly PushProviderService $pushProviderService,
        private readonly AuditService $auditService,
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
        $campaign->forceFill([
            'status' => 'running',
            'executed_at' => now(),
        ])->save();

        $dispatchUntil = now()->addDay();

        $items = PushCampaignItem::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', 'pending')
            ->where(function ($query) use ($dispatchUntil) {
                $query->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', $dispatchUntil);
            })
            ->orderBy('scheduled_at')
            ->get();

        foreach ($items as $item) {
            if ($item->scheduled_at && $item->scheduled_at->isFuture()) {
                SendPushNotificationJob::dispatch($item->id)->delay($item->scheduled_at);
            } else {
                SendPushNotificationJob::dispatch($item->id);
            }

            $item->forceFill([
                'status' => 'scheduled',
            ])->save();
        }

        $this->auditService->record([
            'platform_id' => (int) $campaign->platform_id,
            'actor_id' => $actorId,
            'action' => CrmAuditAction::PUSH_CAMPAIGN_EXECUTE,
            'entity_type' => 'push_campaign',
            'entity_id' => (int) $campaign->id,
            'after_state' => [
                'status' => 'running',
                'scheduled_dispatch_count' => $items->count(),
            ],
            'reason' => 'Executed push campaign immediately.',
        ]);

        return $campaign->fresh();
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

    public function refreshAnalytics(PushCampaign $campaign): PushCampaign
    {
        $items = $campaign->items()
            ->whereNotNull('provider_notification_id')
            ->get();

        foreach ($items as $item) {
            $stats = $this->pushProviderService->pollAnalytics(
                (string) $item->provider_notification_id,
                $campaign->provider,
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
