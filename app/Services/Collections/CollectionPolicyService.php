<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CollectionPolicyService
{
    public const CAPS = ['single_cap_minor', 'daily_cap_minor', 'unreviewed_cap_minor', 'unverified_cap_minor'];

    public function present(CollectionAccount $account): array
    {
        $policy = $account->policy ?? [];

        return ['account_id' => $account->id, 'label' => $account->label, 'currency' => $account->currency,
            'version' => $policy['version'] ?? 0, 'configured' => $this->configured($policy),
            'limits' => array_intersect_key($policy, array_flip(self::CAPS)),
            'approved_at' => $policy['limits_approved_at'] ?? null,
            'mode' => $account->mode];
    }

    public function configured(array $policy): bool
    {
        if (! is_int($policy['version'] ?? null) || $policy['version'] <= 0) {
            return false;
        }
        foreach (self::CAPS as $cap) {
            if (! array_key_exists($cap, $policy) || ($policy[$cap] !== null && (! is_int($policy[$cap]) || $policy[$cap] < 0))) {
                return false;
            }
            if ($policy[$cap] === null && (empty($policy['limits_approved_by']) || empty($policy['limits_approved_at']))) {
                return false;
            }
        }

        return true;
    }

    public function update(User $actor, string $accountId, array $input): array
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        app(CollectionAccessService::class)->account($actor, $accountId);
        if (array_diff(array_keys($input), [...self::CAPS, 'expected_version'])) {
            throw new CollectionException('unsupported_policy_fields', 422);
        }
        $rules = ['expected_version' => 'required|integer|min:0'];
        foreach (self::CAPS as $cap) {
            $rules[$cap] = 'present|nullable|integer|min:0|max:900000000000000';
        }
        $v = Validator::make($input, $rules)->validate();

        return DB::transaction(function () use ($actor, $accountId, $v) {
            $account = CollectionAccount::lockForUpdate()->findOrFail($accountId);
            $policy = $account->policy ?? [];
            if ((int) ($policy['version'] ?? 0) !== (int) $v['expected_version']) {
                throw new CollectionException('collection_policy_changed', 409);
            }
            $before = array_intersect_key($policy, array_flip(self::CAPS));
            foreach (self::CAPS as $cap) {
                $policy[$cap] = $v[$cap] === null ? null : (int) $v[$cap];
            }
            $policy['version'] = (int) ($policy['version'] ?? 0) + 1;
            $policy['limits_approved_by'] = $actor->id;
            $policy['limits_approved_at'] = now()->toIso8601String();
            $account->update(['policy' => $policy]);
            DB::table('collection_audit')->insert(['account_id' => $accountId, 'actor_id' => $actor->id,
                'action' => 'exposure_limits_updated', 'context' => json_encode(['before' => $before,
                    'after' => array_intersect_key($policy, array_flip(self::CAPS)), 'version' => $policy['version']], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $this->present($account);
        }, 3);
    }
}
