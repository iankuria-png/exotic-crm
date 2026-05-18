<?php

namespace App\Services\Kyc;

use App\Models\Client;
use App\Models\KycSetting;
use App\Models\Platform;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class KycSettingsService
{
    public function __construct(private readonly AuditService $auditService)
    {
    }

    public function get(): KycSetting
    {
        $settings = KycSetting::query()->firstOrCreate(
            ['id' => (int) config('kyc.settings_id', 1)],
            [
                'enabled_platform_ids' => [],
                'required_document_kinds' => ['id_front', 'selfie'],
                'max_doc_bytes' => (int) config('kyc.max_doc_bytes', 20 * 1024 * 1024),
                'reject_reason_options' => [
                    ['key' => 'blurry_id', 'label' => 'ID photo is blurry — please retake in good lighting'],
                    ['key' => 'name_mismatch', 'label' => "The name on your ID doesn't match your profile name"],
                    ['key' => 'selfie_mismatch', 'label' => 'Selfie does not match the person on the ID'],
                    ['key' => 'other', 'label' => 'Other'],
                ],
                'search_boost_enabled' => true,
                'active_storage_driver' => 'db',
                'exempt_plan_keys' => ['forever'],
                'grace_days_default' => 30,
                'grace_days_per_platform' => [],
                'email_warning_days' => [0, 7, 14, 21, 29],
                'escalation_rule_per_platform' => [],
                'reverify_interval_days' => (int) config('kyc.reverify_interval_days', 365),
                'reverify_auto_sweep_enabled' => true,
                'reverify_dispatch_pace_seconds' => (int) config('kyc.reverify_dispatch_pace_seconds', 5),
                'fanout_queue_concurrency' => (int) config('kyc.fanout_queue_concurrency', 4),
                'reviewer_notification_channels' => ['in_app_badge'],
            ]
        );

        $this->applyRuntimeS3Config($settings);

        return $settings;
    }

    public function activeStorageDriver(): string
    {
        return (string) ($this->get()->active_storage_driver ?: 'db');
    }

    public function requiredDocumentKinds(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) ($this->get()->required_document_kinds ?? ['id_front', 'selfie'])))));
    }

    public function maxDocBytes(): int
    {
        return (int) ($this->get()->max_doc_bytes ?: config('kyc.max_doc_bytes', 20 * 1024 * 1024));
    }

    public function isPlatformEnabled(?int $platformId): bool
    {
        if (!$platformId) {
            return false;
        }

        return in_array((int) $platformId, array_map('intval', (array) ($this->get()->enabled_platform_ids ?? [])), true);
    }

    public function isExempt(Client $client): bool
    {
        $activeDeal = $client->relationLoaded('activeDeal') ? $client->activeDeal : $client->activeDeal()->with('product')->first();
        if (!$activeDeal) {
            return false;
        }

        $candidateKeys = array_values(array_filter([
            (string) ($activeDeal->plan_type ?? ''),
            (string) optional($activeDeal->product)->slug,
            (string) optional($activeDeal->product)->tier,
        ]));

        $exempt = array_map('strtolower', array_map('strval', (array) ($this->get()->exempt_plan_keys ?? [])));
        foreach ($candidateKeys as $key) {
            if (in_array(strtolower($key), $exempt, true)) {
                return true;
            }
        }

        return false;
    }

    public function graceDaysForPlatform(?int $platformId): int
    {
        $settings = $this->get();
        $map = (array) ($settings->grace_days_per_platform ?? []);
        if ($platformId && array_key_exists((string) $platformId, $map)) {
            return max(0, (int) $map[(string) $platformId]);
        }

        return max(0, (int) ($settings->grace_days_default ?? 30));
    }

    public function escalationRuleForPlatform(?int $platformId): string
    {
        $map = (array) ($this->get()->escalation_rule_per_platform ?? []);
        $rule = $platformId ? (string) ($map[(string) $platformId] ?? 'notify_only') : 'notify_only';
        return in_array($rule, ['notify_only', 'remove_badge', 'auto_suspend'], true) ? $rule : 'notify_only';
    }

    public function recomputeClientRequirement(Client $client): bool
    {
        $required = !$this->isExempt($client);
        if ((bool) $client->kyc_required !== $required) {
            $client->forceFill(['kyc_required' => $required])->save();
            $client->loadMissing('kycSubject');
            if (!$required && $client->kycSubject) {
                $client->kycSubject->forceFill(['status' => 'unverified'])->save();
            }
        }

        return $required;
    }

    public function queueCountForUser(?User $user = null): array
    {
        $query = \App\Models\KycSubject::query()
            ->whereIn('status', ['in_review', 'info_requested'])
            ->whereHas('client', fn ($builder) => $builder->where('kyc_required', true));

        $inReviewCount = (clone $query)->where('status', 'in_review')->count();

        if (!$user) {
            return ['in_review_count' => $inReviewCount, 'mine_count' => $inReviewCount];
        }

        $mineQuery = clone $query;
        if (($user->role ?? '') === 'sales') {
            $platformIds = $user->assignedMarketIds();
            $mineQuery->whereHas('client', fn ($builder) => $builder->whereIn('platform_id', $platformIds ?: [0]));
        }

        return [
            'in_review_count' => $inReviewCount,
            'mine_count' => $mineQuery->count(),
        ];
    }

    public function totalBlobBytes(): int
    {
        return (int) \DB::table('kyc_document_blobs')->sum(\DB::raw('octet_length(body)'));
    }

    public function update(array $payload, User $actor): KycSetting
    {
        $settings = $this->get();
        $before = $settings->toArray();

        $ruleMap = (array) Arr::get($payload, 'escalation_rule_per_platform', $settings->escalation_rule_per_platform ?? []);
        if ($actor->role !== 'admin' && collect($ruleMap)->contains(fn ($value) => $value === 'auto_suspend')) {
            throw new InvalidArgumentException('Only admin users can enable auto_suspend.');
        }

        if (($payload['active_storage_driver'] ?? $settings->active_storage_driver) === 's3') {
            $this->probeS3Connectivity($payload + $settings->toArray());
        }

        $settings->fill($payload);
        $settings->updated_by = $actor->id;
        $settings->save();

        $auditPlatformId = $this->resolveAuditPlatformId($settings, $actor);

        $this->auditService->record([
            'platform_id' => $auditPlatformId,
            'actor_id' => $actor->id,
            'action' => 'kyc.settings_updated',
            'entity_type' => 'kyc_setting',
            'entity_id' => (int) $settings->id,
            'before_state' => $before,
            'after_state' => $settings->fresh()?->toArray(),
        ]);

        return $settings->fresh();
    }

    public function probeS3Connectivity(array $candidate): array
    {
        $this->applyRuntimeS3ConfigFromArray($candidate);
        $disk = Storage::disk('s3_kyc');
        $key = 'kyc-probe/' . now()->timestamp . '-' . bin2hex(random_bytes(4));

        $disk->put($key, '');
        $exists = $disk->exists($key);
        $disk->delete($key);

        if (!$exists) {
            throw new InvalidArgumentException('S3 connectivity probe failed.');
        }

        return ['ok' => true, 'key' => $key];
    }

    private function applyRuntimeS3Config(KycSetting $settings): void
    {
        $this->applyRuntimeS3ConfigFromArray($settings->toArray());
    }

    private function applyRuntimeS3ConfigFromArray(array $values): void
    {
        if (!empty($values['s3_bucket'])) {
            config([
                'filesystems.disks.s3_kyc.bucket' => $values['s3_bucket'],
                'filesystems.disks.s3_kyc.region' => $values['s3_region'] ?: config('filesystems.disks.s3_kyc.region'),
                'filesystems.disks.s3_kyc.endpoint' => $values['s3_endpoint_override'] ?: config('filesystems.disks.s3_kyc.endpoint'),
            ]);
        }
    }

    private function resolveAuditPlatformId(KycSetting $settings, User $actor): int
    {
        $enabled = array_values(array_filter(array_map('intval', (array) ($settings->enabled_platform_ids ?? []))));
        if ($enabled !== []) {
            return (int) $enabled[0];
        }

        $assigned = array_values(array_filter(array_map('intval', (array) ($actor->assigned_market_ids ?? []))));
        if ($assigned !== []) {
            return (int) $assigned[0];
        }

        return (int) (Platform::query()->orderBy('id')->value('id') ?: 0);
    }
}
