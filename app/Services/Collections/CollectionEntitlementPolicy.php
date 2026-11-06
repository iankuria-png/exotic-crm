<?php

namespace App\Services\Collections;

class CollectionEntitlementPolicy
{
    public function tier(array $snapshot): string
    {
        $m = $snapshot['meta'] ?? [];

        return ($m['vvip'] ?? '') === '1' ? 'vvip' : (($m['featured'] ?? '') === '1' ? 'vip' : (($m['premium'] ?? '') === '1' ? 'premium' : 'basic'));
    }

    public function expiry(array $snapshot): int
    {
        return max((int) data_get($snapshot, 'meta.escort_expire', 0), (int) data_get($snapshot, 'meta.agency_expire', 0));
    }

    public function assertSnapshot(array $snapshot, array $identity): void
    {
        if (empty($snapshot['fingerprint']) || (int) ($snapshot['post_id'] ?? 0) !== (int) $identity['wp_post_id']
            || (int) ($snapshot['wp_user_id'] ?? 0) !== (int) $identity['wp_user_id']
            || ! in_array($snapshot['post_status'] ?? '', ['private', 'publish'])
            || in_array(data_get($snapshot, 'meta.crm_lifecycle_state'), ['archived', 'removed', 'paused', 'closed', 'reverted'])) {
            throw new CollectionException('wordpress_profile_identity_or_state_changed');
        }
    }

    public function target(array $before, array $purchase, ?array $interval = null): array
    {
        $this->assertSnapshot($before, $purchase);
        $expiry = $this->expiry($before);
        $tier = $purchase['tier'];
        if ($tier === 'vvip' || ! in_array($tier, ['basic', 'premium', 'vip'], true)) {
            throw new CollectionException('placement_or_tier_requires_review');
        }
        if ($expiry > now()->timestamp && $this->tier($before) !== $tier) {
            throw new CollectionException('mixed_tier_coverage_not_representable');
        }
        $start = $interval['start'] ?? max(now()->timestamp, $expiry);
        $end = $interval['end'] ?? ($start + ((int) $purchase['duration_days'] * 86400));
        if ($interval && $start > now()->timestamp && $expiry < $start) {
            throw new CollectionException('subsidiary_future_interval_requires_review');
        }
        if ($end <= now()->timestamp) {
            throw new CollectionException('pinned_interval_elapsed');
        }
        $target = max($expiry, $end);
        if ($target > now()->addDays(367)->timestamp) {
            throw new CollectionException('duration_exceeds_target_capability');
        }

        return ['expected_fingerprint' => $before['fingerprint'], 'target_expiry' => $target, 'product_type' => $tier,
            'contribution' => ['start' => $start, 'end' => $end, 'tier' => $tier, 'duration_days' => $purchase['duration_days'], 'anchor_at' => now()->timestamp]];
    }
}
