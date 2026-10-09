<?php

namespace App\Services\DbContainment;

use App\Models\DbContainmentCampaign;
use App\Models\DbContainmentOperation;
use App\Models\DbScanFinding;
use App\Models\User;
use App\Services\MarketOperationCoordinator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CampaignService
{
    public function __construct(private readonly ContainmentService $service, private readonly ContainmentPolicy $policy, private readonly ContainmentCrypto $crypto) {}

    public function preview(User $actor, array $targets, string $key): DbContainmentCampaign
    {
        $this->policy->authorize($actor);
        if (count($targets) < 1 || count($targets) > 50) {
            throw new ContainmentException('campaign_target_limit');
        }
        $existing = DbContainmentCampaign::query()->where('actor_id', $actor->id)->where('request_key', $key)->first();
        $input = $this->crypto->digest($targets, 'campaign-input');
        if ($existing) {
            if (($existing->members[0]['input_digest'] ?? '') !== $input) {
                throw new ContainmentException('idempotency_payload_mismatch');
            }

            return $existing;
        }
        $groups = [];
        foreach ($targets as $target) {
            $finding = DbScanFinding::query()->findOrFail($target['finding_id']);
            $groups[$finding->platform_id][] = [$finding, $target['actions']];
        }
        ksort($groups);
        $members = [];
        try {
            foreach ($groups as $group) {
                $op = $this->service->previewGroup($actor, $group, 'campaign:'.$key.':'.$group[0][0]->platform_id);
                $members[] = ['id' => $op->id, 'digest' => $op->preview_digest, 'platform_id' => $op->platform_id, 'input_digest' => $input];
            }

        } catch (\Throwable $error) {
            foreach ($members as $member) {
                $this->service->cancel($actor, DbContainmentOperation::query()->findOrFail($member['id']));
            }
            throw $error;
        }

        return DB::transaction(function () use ($actor, $key, $members) {
            $campaign = DbContainmentCampaign::query()->create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'request_key' => $key, 'members' => $members, 'preview_digest' => $this->crypto->digest($members, 'campaign'), 'expires_at' => now()->addSeconds(config('db_containment.approval_seconds')), 'status' => 'preview']);
            DbContainmentOperation::query()->whereIn('id', array_column($members, 'id'))->update(['campaign_id' => $campaign->id]);

            return $campaign;
        });
    }

    public function confirm(User $actor, DbContainmentCampaign $campaign, string $phrase, string $digest, array $privileged): DbContainmentCampaign
    {
        $this->policy->authorize($actor);
        DB::transaction(function () use ($actor, $campaign, $phrase, $digest, $privileged) {
            MarketOperationCoordinator::lock([0, ...array_column($campaign->members, 'platform_id')]);
            $campaign = DbContainmentCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($campaign->preview_digest, $this->crypto->digest($campaign->members, 'campaign'))) {
                throw new ContainmentException('campaign_membership_changed');
            }
            if ((int) $campaign->actor_id !== $actor->id || ! hash_equals($campaign->preview_digest, $digest) || $phrase !== 'CONTAIN '.count($campaign->members).' MARKETS') {
                throw new ContainmentException('campaign_confirmation_mismatch');
            }
            if ($campaign->approved_at) {
                return;
            }
            if ($campaign->status !== 'preview' || $campaign->expires_at->isPast()) {
                throw new ContainmentException('campaign_preview_expired_or_cancelled');
            }
            foreach ($campaign->members as $member) {
                $op = DbContainmentOperation::query()->whereKey($member['id'])->lockForUpdate()->firstOrFail();
                if ($op->campaign_id !== $campaign->id || $op->preview_digest !== $member['digest'] || $op->status !== 'preview') {
                    throw new ContainmentException('campaign_child_changed');
                }
                $this->service->validateApproval($actor, $op, $op->preview['confirmation'], $privileged[$op->id] ?? null, $member['digest']);
            }
            $campaign->update(['status' => 'approved', 'approved_at' => now(), 'expires_at' => now()->addSeconds(config('db_containment.approval_seconds'))]);
            foreach ($campaign->members as $member) {
                $this->service->approve(DbContainmentOperation::query()->findOrFail($member['id']));
            }
        });
        $this->service->publish();

        return $campaign->fresh();
    }

    public function cancel(User $actor, DbContainmentCampaign $campaign): DbContainmentCampaign
    {
        $this->policy->authorize($actor);
        DB::transaction(function () use ($actor, $campaign) {
            MarketOperationCoordinator::lock([0, ...array_column($campaign->members, 'platform_id')]);
            $campaign = DbContainmentCampaign::query()->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            foreach ($campaign->members as $member) {
                $this->service->cancel($actor, DbContainmentOperation::query()->findOrFail($member['id']));
            }
            $campaign->update(['status' => 'cancel_requested']);
        });

        return $campaign->fresh();
    }

    public function present(DbContainmentCampaign $campaign): array
    {
        $ops = DbContainmentOperation::query()->where('campaign_id', $campaign->id)->orderBy('platform_id')->get();

        $counts = $ops->countBy('status');
        $status = $campaign->status;
        if ($campaign->approved_at && $ops->count() === count($campaign->members) && $ops->every(fn ($op) => in_array($op->status, ['verified', 'conflict', 'not_applied', 'expired', 'cancelled'], true))) {
            $status = ($counts['verified'] ?? 0) === $ops->count() ? 'complete' : (($counts['cancelled'] ?? 0) === $ops->count() ? 'cancelled' : 'partial');
        }

        return ['campaign' => array_merge($campaign->toArray(), ['status' => $status]), 'operations' => $ops, 'confirmation' => 'CONTAIN '.count($campaign->members).' MARKETS', 'counts' => $counts];
    }
}
