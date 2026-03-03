<?php

namespace App\Jobs;

use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\TimelineEvent;
use App\Services\AuditService;
use App\Services\PushNotification\PushProviderService;
use App\Support\CrmAuditAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPushNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $pushCampaignItemId)
    {
    }

    public function handle(PushProviderService $pushProviderService, AuditService $auditService): void
    {
        $item = PushCampaignItem::query()
            ->with('campaign')
            ->find($this->pushCampaignItemId);

        if (!$item || !$item->campaign) {
            return;
        }

        $campaign = $item->campaign;
        $actorId = $campaign->created_by ? (int) $campaign->created_by : null;

        if (in_array((string) $item->status, ['sent', 'failed', 'skipped'], true)) {
            return;
        }

        $notification = [
            'title' => $item->profile_name ?: 'New profile',
            'message' => $item->custom_message,
            'target_url' => $item->profile_url,
            'icon_url' => $item->profile_image_url,
            'campaign_name' => $campaign->name,
            'schedule_at' => $item->scheduled_at?->toIso8601String(),
        ];

        $result = $pushProviderService->sendPush($notification, [
            'platform_id' => (int) $campaign->platform_id,
            'provider' => $campaign->provider,
        ]);

        $success = (bool) ($result['success'] ?? false);

        $item->forceFill([
            'status' => $success ? 'sent' : 'failed',
            'provider_notification_id' => $result['provider_notification_id'] ?? null,
            'sent_at' => now(),
            'error_message' => $success
                ? null
                : (is_string($result['provider_response'] ?? null)
                    ? $result['provider_response']
                    : json_encode($result['provider_response'] ?? null)),
        ])->save();

        if ($item->client_id) {
            TimelineEvent::query()->create([
                'platform_id' => (int) $campaign->platform_id,
                'entity_type' => 'client',
                'entity_id' => (int) $item->client_id,
                'event_type' => $success ? 'push_notification_sent' : 'push_notification_failed',
                'actor_id' => $actorId,
                'content' => [
                    'campaign_id' => (int) $campaign->id,
                    'campaign_item_id' => (int) $item->id,
                    'provider' => $result['provider'] ?? null,
                ],
                'created_at' => now(),
            ]);
        }

        PushCampaign::query()->whereKey($campaign->id)->increment($success ? 'sent_count' : 'failed_count');

        $auditService->record([
            'platform_id' => (int) $campaign->platform_id,
            'actor_id' => $actorId,
            'action' => $success ? CrmAuditAction::PUSH_NOTIFICATION_SENT : CrmAuditAction::PUSH_NOTIFICATION_FAILED,
            'entity_type' => 'push_campaign_item',
            'entity_id' => (int) $item->id,
            'after_state' => [
                'status' => $item->status,
                'provider' => $result['provider'] ?? null,
                'provider_notification_id' => $result['provider_notification_id'] ?? null,
            ],
            'reason' => $success ? 'Push item delivered to provider.' : 'Push provider rejected notification.',
        ]);

        $this->completeCampaignIfDone($campaign->id);
    }

    public function uniqueId(): string
    {
        return 'push-item-' . $this->pushCampaignItemId;
    }

    public function backoff(): array
    {
        return [60, 120, 300];
    }

    private function completeCampaignIfDone(int $campaignId): void
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
}
