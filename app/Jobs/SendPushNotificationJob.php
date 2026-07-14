<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\PushCampaign;
use App\Models\PushCampaignItem;
use App\Models\TimelineEvent;
use App\Services\ClientProfileImageService;
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
use Illuminate\Support\Facades\Log;

class SendPushNotificationJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public int $uniqueFor = 600;

    /**
     * Provider failure codes that are transient — worth another attempt on the
     * queue instead of being marked `failed` immediately. Covers upstream 5xx
     * (provider_error), unclassified non-2xx (http_error), and rate limits
     * across every provider that emits the structured `{code, message}` shape.
     */
    private const RETRIABLE_CODES = [
        'epe_provider_error', 'epe_http_error', 'epe_rate_limited',
        'webpushr_provider_error', 'webpushr_http_error', 'webpushr_rate_limited',
        'wonderpush_provider_error', 'wonderpush_http_error', 'wonderpush_rate_limited',
        'izooto_provider_error', 'izooto_http_error', 'izooto_rate_limited',
    ];

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

        // Resolve city / image / URL against the linked client at send time. The item
        // is a snapshot from campaign-build time; if the client's data was incomplete
        // then (WP sync lag, image just uploaded, pretty permalink synced later), the
        // snapshot is stale. Re-reading now and persisting back keeps future retries
        // and analytics consistent.
        $clientRow = null;
        if ($item->client_id
            && (!$item->profile_city || !$item->profile_image_url || $this->isFallbackProfileUrl((string) $item->profile_url))
        ) {
            $clientRow = Client::query()->find((int) $item->client_id);
        }

        $city = $item->profile_city;
        if (!$city && $clientRow?->city) {
            $city = $clientRow->city;
            $item->forceFill(['profile_city' => $city])->save();
        }

        $imageUrl = trim((string) ($item->profile_image_url ?? '')) ?: null;
        if (!$imageUrl && $clientRow) {
            // Tier 2: stored fields (main_image_url from WP sync, then display_image_url
            // computed from the WP media library by ClientProfileImageService).
            $imageUrl = $clientRow->resolvePushImageUrl();

            // Tier 3: live-refresh from WP media library. When the sync payload never
            // provided main_image_url AND display_image_url hasn't been computed yet
            // (or was cleared), fetch the media list now. Fails soft — the send still
            // ships without an image rather than blocking on WP being reachable.
            if (!$imageUrl && (int) ($clientRow->wp_post_id ?? 0) > 0) {
                try {
                    $selection = app(ClientProfileImageService::class)
                        ->refreshClient($clientRow, verifyReachable: false);
                    $imageUrl = isset($selection['url']) ? trim((string) $selection['url']) : null;
                    $imageUrl = $imageUrl ?: null;
                } catch (\Throwable $exception) {
                    Log::warning('Push image live-refresh failed', [
                        'item_id' => (int) $item->id,
                        'client_id' => (int) $item->client_id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            if ($imageUrl) {
                $item->forceFill(['profile_image_url' => $imageUrl])->save();
            }
        }

        if (!$imageUrl) {
            Log::warning('Push item shipping without image', [
                'item_id' => (int) $item->id,
                'client_id' => $item->client_id,
                'campaign_id' => (int) $campaign->id,
                'wp_post_id' => (int) ($clientRow->wp_post_id ?? 0),
            ]);
        }

        // Prefer the client's synced pretty permalink over the /?p=NN fallback that
        // WordPress can silently redirect to the homepage when the target post is
        // archived/private/deleted (issue #3).
        $targetUrl = (string) $item->profile_url;
        $permalink = trim((string) ($clientRow?->wp_profile_permalink ?? ''));
        if ($permalink !== '' && ($targetUrl === '' || $this->isFallbackProfileUrl($targetUrl))) {
            $targetUrl = $permalink;
            $item->forceFill(['profile_url' => $targetUrl])->save();
        }

        $title = $item->profile_name ?: 'New profile';
        if ($city) {
            $title = "{$item->profile_name} from {$city}";
        }

        // Use profile image as both notification icon (small) and image (large banner).
        // Skip the HEAD pre-check — it can be blocked by Cloudflare bot protection on
        // the production server, causing images to be silently dropped. The provider
        // handles unreachable images gracefully by omitting them from the notification.

        $notification = [
            'title' => $title,
            'message' => $item->custom_message,
            'target_url' => $targetUrl,
            'icon_url' => $imageUrl,
            'image_url' => $imageUrl,
            'campaign_name' => $campaign->name,
            'schedule_at' => $scheduleAt,
        ];

        $result = $pushProviderService->sendPush($notification, [
            'platform_id' => (int) $campaign->platform_id,
            'provider' => $campaign->provider,
            'idempotency_key' => 'epe-item-' . $item->id,
        ]);

        $success = (bool) ($result['success'] ?? false);

        // Transient upstream failures (provider 5xx, unclassified HTTP, rate
        // limits) get another attempt on the queue instead of being marked
        // `failed`. Laravel's backoff() schedules the retry [30s, 60s, 120s].
        // The last attempt falls through and marks the item failed as usual.
        if (!$success) {
            $providerCode = data_get($result, 'provider_response.code');
            if (is_string($providerCode)
                && in_array($providerCode, self::RETRIABLE_CODES, true)
                && $this->attempts() < $this->tries
            ) {
                Log::info('Push send scheduled for retry', [
                    'item_id' => (int) $item->id,
                    'campaign_id' => (int) $campaign->id,
                    'attempt' => $this->attempts(),
                    'max_attempts' => $this->tries,
                    'code' => $providerCode,
                    'idempotency_key' => 'epe-item-' . $item->id,
                ]);

                // Idempotency-Key on the provider side dedupes the retry so the
                // subscriber never receives a duplicate on a redriven send.
                throw new \RuntimeException("Retriable push failure ({$providerCode}) — re-queuing.");
            }
        }

        $item->forceFill([
            'status' => $success ? 'sent' : 'failed',
            'provider_notification_id' => $result['provider_notification_id'] ?? null,
            'sent_at' => now(),
            'provider_meta' => [
                'provider' => $result['provider'] ?? null,
                'fallback_attempted' => (bool) ($result['fallback_attempted'] ?? false),
                'fallback_from' => $result['fallback_from'] ?? null,
            ],
            'error_message' => $success ? null : $this->formatProviderError($result['provider_response'] ?? null),
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

    /**
     * Normalize a provider's failure response into a compact `<code>: <message>` string
     * the CRM UI can parse and render as a friendly badge. Providers that emit the
     * structured shape `{code, message, ...}` win; legacy string/array shapes fall
     * back cleanly instead of dumping a raw JSON blob into the item.
     */
    private function formatProviderError(mixed $providerResponse): string
    {
        if (is_string($providerResponse)) {
            return $providerResponse;
        }

        if (is_array($providerResponse)) {
            $code = trim((string) ($providerResponse['code'] ?? ''));
            $message = trim((string) ($providerResponse['message'] ?? ''));

            if ($code !== '' && $message !== '') {
                return "{$code}: {$message}";
            }

            if ($code !== '') {
                return $code;
            }

            if ($message !== '') {
                return "provider_error: {$message}";
            }

            return 'provider_error: ' . (string) json_encode($providerResponse);
        }

        return 'provider_error: unknown';
    }

    /**
     * True when the URL is the "{domain}/?p={wp_post_id}" fallback shape produced by
     * {@see \App\Support\ClientProfileUrl::resolve()} when a client has no synced
     * pretty permalink. WordPress may fail to redirect these when the post has since
     * been unpublished, so we prefer the client's wp_profile_url if it's available.
     */
    private function isFallbackProfileUrl(string $url): bool
    {
        return $url !== '' && (bool) preg_match('#/\?p=\d+/?$#', $url);
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
