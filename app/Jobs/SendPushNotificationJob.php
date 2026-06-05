<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\TimelineEvent;
use App\Services\PushCampaign\PushCampaignDispatchReadinessService;
use App\Services\AuditService;
use App\Services\PushNotification\PushProviderService;
use App\Support\CrmAuditAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SendPushNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $pushCampaignItemId)
    {
        $this->onQueue('push');
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

        if ($item->scheduled_at) {
            $latestAllowedSendAt = $item->scheduled_at->copy()->addMinutes(PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES);
            if (now()->greaterThan($latestAllowedSendAt)) {
                $item->forceFill([
                    'status' => 'failed',
                    'provider_notification_id' => null,
                    'sent_at' => null,
                    'error_message' => sprintf(
                        'missed_window: Item exceeded %d-minute grace at job execution. Reschedule required.',
                        PushCampaignDispatchReadinessService::LATE_GRACE_MINUTES
                    ),
                ])->save();

                if ($item->client_id) {
                    TimelineEvent::query()->create([
                        'platform_id' => (int) $campaign->platform_id,
                        'entity_type' => 'client',
                        'entity_id' => (int) $item->client_id,
                        'event_type' => 'push_notification_failed',
                        'actor_id' => $actorId,
                        'content' => [
                            'campaign_id' => (int) $campaign->id,
                            'campaign_item_id' => (int) $item->id,
                            'provider' => 'timing_guard',
                            'reason_code' => 'missed_window',
                        ],
                        'created_at' => now(),
                    ]);
                }

                PushCampaign::query()->whereKey((int) $campaign->id)->increment('failed_count');

                $auditService->record([
                    'platform_id' => (int) $campaign->platform_id,
                    'actor_id' => $actorId,
                    'action' => CrmAuditAction::PUSH_NOTIFICATION_FAILED,
                    'entity_type' => 'push_campaign_item',
                    'entity_id' => (int) $item->id,
                    'after_state' => [
                        'status' => 'failed',
                        'provider' => 'timing_guard',
                        'provider_notification_id' => null,
                    ],
                    'reason' => 'Push item skipped because scheduled send window was missed.',
                ]);

                $this->completeCampaignIfDone((int) $campaign->id);
                return;
            }
        }

        $scheduleAt = null;
        if ($item->scheduled_at && $item->scheduled_at->greaterThan(now()->addMinutes(5))) {
            $scheduleAt = $item->scheduled_at->toIso8601String();
        }

        // Resolve city: use stored value, or look up from linked client as fallback.
        $city = $item->profile_city;
        if (!$city && $item->client_id) {
            $city = Client::query()->where('id', (int) $item->client_id)->value('city');
            if ($city) {
                $item->forceFill(['profile_city' => $city])->save();
            }
        }

        $title = $item->profile_name ?: 'New profile';
        if ($city) {
            $title = "{$item->profile_name} from {$city}";
        }

        // Use profile image as both notification icon (small) and image (large banner).
        // Skip the HEAD pre-check — it can be blocked by Cloudflare bot protection on
        // the production server, causing images to be silently dropped. WebPushr handles
        // unreachable images gracefully by simply omitting them from the notification.
        $imageUrl = $item->profile_image_url ?: null;

        $notification = [
            'title' => $title,
            'message' => $item->custom_message,
            'target_url' => $item->profile_url,
            'icon_url' => $imageUrl,
            'image_url' => $imageUrl,
            'campaign_name' => $campaign->name,
            'schedule_at' => $scheduleAt,
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
            'provider_meta' => [
                'provider' => $result['provider'] ?? null,
                'fallback_attempted' => (bool) ($result['fallback_attempted'] ?? false),
                'fallback_from' => $result['fallback_from'] ?? null,
            ],
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
        return [30, 60, 120];
    }

    private function completeCampaignIfDone(int $campaignId): void
    {
        // Single query: group all item statuses for this campaign.
        $counts = PushCampaignItem::query()
            ->where('campaign_id', $campaignId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $remaining = ($counts->get('pending', 0) + $counts->get('scheduled', 0));

        if ($remaining > 0) {
            return;
        }

        $campaign = PushCampaign::query()->find($campaignId);
        if (!$campaign) {
            return;
        }

        $sent = (int) $counts->get('sent', 0);
        $failed = (int) $counts->get('failed', 0);

        $status = $sent > 0 && $failed === 0
            ? 'completed'
            : ($sent > 0 ? 'partial' : 'failed');

        $campaign->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();
    }
}
