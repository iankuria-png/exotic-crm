<?php

namespace App\Services\Collections;

use App\Services\WpSyncService;
use Illuminate\Support\Facades\DB;

class CollectionCorrectionOwnership
{
    /** Persist identities before any remote acquire, then acquire every site before a write. */
    public function acquireAll(string $correctionId, array $profiles): void
    {
        foreach ($profiles as $p) {
            DB::table('collection_site_correction_holds')->insertOrIgnore(['correction_id' => $correctionId,
                'platform_id' => $p['platform_id'], 'client_id' => $p['client_id'], 'wp_post_id' => $p['wp_post_id'],
                'source_operation_id' => $p['source_operation_id'] ?? null, 'expected_fingerprint' => $p['expected_fingerprint'],
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($this->rows($correctionId) as $hold) {
            if ($hold->state === 'released') {
                throw new CollectionException('released_correction_owner_requires_review');
            }
            $sync = WpSyncService::forPlatform($hold->platform_id);
            $remote = $sync->subscriptionCorrectionOwner($hold->wp_post_id)['owner'] ?? null;
            if ($remote && $remote['state'] !== 'released') {
                if ($remote['correction_id'] !== $correctionId || ($hold->generation !== null && (int) $hold->generation !== (int) $remote['generation'])
                    || ! hash_equals($hold->expected_fingerprint, $remote['initial_fingerprint'])) {
                    throw new CollectionException('wordpress_correction_owner_conflict');
                }
                $generation = (int) $remote['generation'];
            } else {
                $generation = $hold->generation === null ? (int) ($remote['generation'] ?? 0) + 1 : (int) $hold->generation;
                DB::table('collection_site_correction_holds')->where('id', $hold->id)->update(['generation' => $generation, 'state' => 'acquiring', 'updated_at' => now()]);
                $response = $sync->updateSubscriptionCorrectionOwner($hold->wp_post_id, ['action' => 'acquire', 'correction_id' => $correctionId,
                    'owner_generation' => $generation, 'source_operation_id' => $hold->source_operation_id ?? '', 'expected_fingerprint' => $hold->expected_fingerprint]);
                if (data_get($response, 'owner.correction_id') !== $correctionId || data_get($response, 'owner.state') !== 'owned'
                    || (int) data_get($response, 'owner.generation') !== $generation) {
                    throw new CollectionException('wordpress_correction_owner_unconfirmed');
                }
            }
            DB::table('collection_site_correction_holds')->where('id', $hold->id)->update(['generation' => $generation, 'state' => 'owned', 'updated_at' => now()]);
        }
    }

    public function proof(string $correctionId, int $platformId, int $postId): array
    {
        $hold = DB::table('collection_site_correction_holds')->where('correction_id', $correctionId)->where('platform_id', $platformId)->where('wp_post_id', $postId)->first();
        if (! $hold || $hold->state !== 'owned' || ! $hold->generation) {
            throw new CollectionException('wordpress_correction_owner_required');
        }
        $sync = WpSyncService::forPlatform($platformId);
        $read = $sync->subscriptionOperation($postId);
        $proof = ['correction_id' => $correctionId, 'owner_generation' => (int) $hold->generation];
        $renewed = $sync->updateSubscriptionCorrectionOwner($postId, $proof + ['action' => 'renew', 'expected_fingerprint' => $read['snapshot']['fingerprint']]);
        if (data_get($renewed, 'owner.state') !== 'owned' || data_get($renewed, 'owner.correction_id') !== $correctionId) {
            throw new CollectionException('wordpress_correction_owner_unconfirmed');
        }

        return $proof;
    }

    /** Call only after every source, target and dependent effect and CRM finalization is confirmed. */
    public function releaseAll(string $correctionId, array $operations): void
    {
        foreach ($this->rows($correctionId) as $hold) {
            if ($hold->state === 'released') {
                continue;
            }
            $sync = WpSyncService::forPlatform($hold->platform_id);
            $read = $sync->subscriptionOperation($hold->wp_post_id);
            $ids = array_values(array_map(fn ($operation) => $operation['id'], array_filter($operations,
                fn ($operation) => (int) $operation['platform_id'] === (int) $hold->platform_id && (int) $operation['wp_post_id'] === (int) $hold->wp_post_id)));
            $result = $sync->updateSubscriptionCorrectionOwner($hold->wp_post_id, ['action' => 'release', 'correction_id' => $correctionId,
                'owner_generation' => (int) $hold->generation, 'expected_fingerprint' => $read['snapshot']['fingerprint'], 'confirmed_operations' => $ids]);
            if (data_get($result, 'owner.state') !== 'released' || data_get($result, 'owner.correction_id') !== $correctionId) {
                throw new CollectionException('wordpress_correction_release_unconfirmed');
            }
            DB::table('collection_site_correction_holds')->where('id', $hold->id)->update(['state' => 'released', 'updated_at' => now()]);
        }
    }

    private function rows(string $id)
    {
        return DB::table('collection_site_correction_holds')->where('correction_id', $id)->orderBy('platform_id')->orderBy('wp_post_id')->get();
    }
}
