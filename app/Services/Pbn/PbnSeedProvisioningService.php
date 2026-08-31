<?php

namespace App\Services\Pbn;

use App\Models\Client;
use App\Models\PbnSeedBatch;
use App\Models\PbnSeedEvent;
use App\Models\PbnSeedItem;
use App\Services\WpDirectProvisioningService;
use App\Services\WpSyncService;
use App\Support\WordPressSiteConnection;
use App\Support\WpProfileFieldCatalog;
use Illuminate\Support\Str;

class PbnSeedProvisioningService
{
    public function __construct(
        private readonly PbnSeedPreviewService $previewService
    ) {
    }

    public function execute(PbnSeedBatch $batch): void
    {
        $batch->loadMissing(['pbnSite', 'items.sourceClient.platform', 'targets']);
        if (!$batch->pbnSite || in_array($batch->status, [PbnSeedBatch::STATUS_COMPLETED, PbnSeedBatch::STATUS_CANCELLED], true)) {
            return;
        }

        $batch->forceFill([
            'status' => PbnSeedBatch::STATUS_RUNNING,
            'started_at' => $batch->started_at ?: now(),
        ])->save();
        $this->recordEvent($batch, null, 'batch_started', 'info', 'PBN seed batch started.', [
            'selected_count' => (int) $batch->selected_count,
            'created_count' => (int) $batch->created_count,
            'failed_count' => (int) $batch->failed_count,
        ]);

        foreach ($batch->items()->whereIn('status', [
            PbnSeedItem::STATUS_QUEUED,
            PbnSeedItem::STATUS_SELECTED,
            PbnSeedItem::STATUS_FAILED,
        ])->with('sourceClient.platform')->orderBy('id')->get() as $item) {
            $this->provisionItem($item);
        }

        $this->previewService->refreshBatchCounts($batch->fresh(['targets']));
        $fresh = $batch->fresh();
        $this->recordEvent($fresh, null, 'batch_finished', $fresh->failed_count > 0 ? 'warning' : 'info', 'PBN seed batch finished.', [
            'status' => (string) $fresh->status,
            'created_count' => (int) $fresh->created_count,
            'failed_count' => (int) $fresh->failed_count,
        ]);
    }

    public function provisionItem(PbnSeedItem $item): void
    {
        $item->loadMissing(['batch.pbnSite', 'sourceClient.platform']);
        $site = $item->batch?->pbnSite;
        $client = $item->sourceClient;

        if (!$site || !$client) {
            $this->markFailed($item, 'PBN site or source client no longer exists.');
            return;
        }

        if ($item->target_wp_post_id && in_array($item->status, [PbnSeedItem::STATUS_CREATED, PbnSeedItem::STATUS_MEDIA_PENDING], true)) {
            return;
        }

        $item->forceFill([
            'status' => PbnSeedItem::STATUS_PROVISIONING,
            'failure_reason' => null,
            'provision_started_at' => now(),
            'provision_finished_at' => null,
        ])->save();
        $this->recordEvent($item->batch, $item, 'item_started', 'info', 'PBN seed item provisioning started.', [
            'source_client_id' => (int) $item->source_client_id,
            'source_wp_post_id' => (int) $item->source_wp_post_id,
        ]);

        try {
            $sourcePayload = WpSyncService::forPlatform((int) $item->source_platform_id)
                ->getClientProfile((int) $item->source_wp_post_id);
            $provisionPayload = $this->buildProvisionPayload($item, $client, $sourcePayload);

            $result = (new WpDirectProvisioningService(WordPressSiteConnection::fromPbnSite($site)))
                ->provisionEscort($provisionPayload);

            $mediaPolicy = (string) data_get($item->batch->copy_policy, 'media', 'two_stage');
            $item->forceFill([
                'target_wp_post_id' => (int) $result['wp_post_id'],
                'target_wp_user_id' => (int) $result['wp_user_id'],
                'status' => $mediaPolicy === 'none' ? PbnSeedItem::STATUS_CREATED : PbnSeedItem::STATUS_MEDIA_PENDING,
                'failure_reason' => null,
                'provision_finished_at' => now(),
            ])->save();
            $this->recordEvent($item->batch, $item, 'item_created', 'info', 'PBN seed item created on destination WordPress.', [
                'target_wp_post_id' => (int) $result['wp_post_id'],
                'target_wp_user_id' => (int) $result['wp_user_id'],
                'media_policy' => $mediaPolicy,
            ]);
        } catch (\Throwable $exception) {
            $this->markFailed($item, $exception->getMessage());
        }
    }

    private function buildProvisionPayload(PbnSeedItem $item, Client $client, array $sourcePayload): array
    {
        $policy = $item->batch?->copy_policy ?: [];
        $payload = [
            'name' => $this->sourceValue($sourcePayload, $client, 'name') ?: $client->name,
            'email' => $this->destinationEmail($item),
            'phone' => data_get($policy, 'phone', 'copy') === 'copy' ? (string) $client->phone_normalized : '',
            'whatsapp' => data_get($policy, 'phone', 'copy') === 'copy' ? (string) $client->phone_normalized : '',
            'bio' => $this->sourceValue($sourcePayload, $client, 'content')
                ?: $this->sourceValue($sourcePayload, $client, 'bio')
                ?: (string) ($client->bio_original_html ?? ''),
            'region_id' => $item->target_region_id,
            'city_id' => $item->target_city_id,
            'post_status' => in_array(data_get($policy, 'post_status'), ['publish', 'private', 'draft', 'pending'], true)
                ? data_get($policy, 'post_status')
                : 'publish',
            'signup_source' => 'crm_provisioned',
            'provision_request_id' => $this->provisionRequestId($item),
            'currency' => $item->batch?->pbnSite?->currency_code,
        ];

        foreach (WpProfileFieldCatalog::createProvisioningFields() as $field) {
            if (array_key_exists($field, $payload) || in_array($field, ['email', 'phone', 'whatsapp', 'region_id', 'city_id', 'post_status', 'provision_request_id'], true)) {
                continue;
            }

            $value = $this->sourceValue($sourcePayload, $client, $field);
            if ($value !== null && $value !== '') {
                $payload[$field] = $value;
            }
        }

        if (data_get($policy, 'vip_flags', 'strip') === 'copy') {
            $payload['premium'] = (bool) $client->premium;
            $payload['featured'] = (bool) $client->featured;
        }
        if (data_get($policy, 'verification', 'strip') === 'copy') {
            $payload['verified'] = (bool) $client->verified;
        }

        return $payload;
    }

    private function sourceValue(array $sourcePayload, Client $client, string $field): mixed
    {
        $paths = [
            $field,
            "profile.{$field}",
            "fields.{$field}",
            "meta.{$field}",
            "post.{$field}",
        ];

        if ($field === 'content' || $field === 'bio') {
            array_unshift($paths, 'post.content', 'post.post_content', 'content', 'bio');
        }

        foreach ($paths as $path) {
            $value = data_get($sourcePayload, $path);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return match ($field) {
            'name' => $client->name,
            'city' => $client->city,
            'email' => $client->email,
            'phone', 'whatsapp', 'personal_phone' => $client->phone_normalized,
            default => null,
        };
    }

    private function destinationEmail(PbnSeedItem $item): string
    {
        $domain = parse_url((string) ($item->batch?->pbnSite?->domain ?? ''), PHP_URL_HOST)
            ?: (string) ($item->batch?->pbnSite?->domain ?? 'pbn.local');
        $domain = preg_replace('/[^a-z0-9.-]/', '', strtolower($domain)) ?: 'pbn.local';

        return sprintf('pbn+%d-%d@%s', (int) $item->source_platform_id, (int) $item->source_client_id, $domain);
    }

    private function provisionRequestId(PbnSeedItem $item): string
    {
        return substr(sprintf(
            'pbn-%d-%d-%s',
            (int) $item->pbn_site_id,
            (int) $item->id,
            (string) $item->payload_hash
        ), 0, 64);
    }

    private function markFailed(PbnSeedItem $item, string $message): void
    {
        $item->forceFill([
            'status' => PbnSeedItem::STATUS_FAILED,
            'failure_reason' => Str::limit($message, 1000, ''),
            'provision_finished_at' => now(),
        ])->save();
        $this->recordEvent($item->batch, $item, 'item_failed', 'error', 'PBN seed item failed.', [
            'source_client_id' => (int) $item->source_client_id,
            'failure_reason' => Str::limit($message, 500, ''),
        ]);
    }

    private function recordEvent(?PbnSeedBatch $batch, ?PbnSeedItem $item, string $type, string $level, string $message, array $context = []): void
    {
        $siteId = (int) ($batch?->pbn_site_id ?: $item?->pbn_site_id ?: 0);
        if ($siteId < 1) {
            return;
        }

        PbnSeedEvent::create([
            'pbn_site_id' => $siteId,
            'batch_id' => $batch?->id,
            'item_id' => $item?->id,
            'actor_id' => $batch?->created_by,
            'type' => $type,
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ]);
    }
}
