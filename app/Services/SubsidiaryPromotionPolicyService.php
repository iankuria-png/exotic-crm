<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Platform;
use App\Models\ProductPrice;
use App\Models\SubsidiaryPromotionPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Paired-market promotion period. The default matches the purchased primary
 * package's actual duration_days; fixed and custom periods stay within 1..90.
 */
class SubsidiaryPromotionPolicyService
{
    public const CONTRACT = 1;

    public const CUSTOM_MAX = 90;

    public const MATCHED_MAX = 365;

    public function paired(int $source, int $target): bool
    {
        return (int) config('subsidiary_trials.pairs.'.$source, 0) === $target && $target > 0;
    }

    public function policy(int $source, int $target): array
    {
        $row = $this->stored($source, $target);

        return ['source_platform_id' => $source, 'target_platform_id' => $target, 'paired' => $this->paired($source, $target),
            'duration_mode' => $row?->duration_mode ?? 'match_primary', 'fixed_days' => $row?->fixed_days,
            'allow_day_override' => $row?->allow_day_override ?? true,
            'copy_new_profile' => $row?->copy_new_profile ?? ((int) config('subsidiary_trials.profile_copy_default_pairs.'.$source, 0) === $target),
            'version' => $row?->version ?? 0, 'saved' => (bool) $row];
    }

    /** Actual purchased days from the selected price or confirmed deal; never inferred from labels. */
    public function purchasedDays(Deal $main): ?int
    {
        if ((int) $main->product_price_id > 0) {
            $days = (int) (ProductPrice::find($main->product_price_id)?->duration_days ?? 0);
            if ($days > 0) {
                return $days;
            }
        }
        $days = (int) ($main->duration_days ?? 0);

        return $days > 0 ? $days : null;
    }

    /**
     * @return array{mode:string,days:?int,purchased_days:?int,policy_version:int,allow_override:bool,max:int,error:?string,paired:bool}
     */
    public function resolve(Deal $main, Platform $target, ?string $mode, ?int $days, int $legacyDefault): array
    {
        $policy = $this->policy((int) $main->platform_id, (int) $target->id);
        $purchased = $this->purchasedDays($main);
        $base = ['purchased_days' => $purchased, 'policy_version' => $policy['version'], 'allow_override' => $policy['allow_day_override'], 'paired' => $policy['paired'], 'error' => null];
        if (! $policy['paired']) {
            $value = $days ?: $legacyDefault;

            return $base + ['mode' => 'legacy', 'days' => $value, 'max' => self::CUSTOM_MAX, 'error' => $value < 1 || $value > self::CUSTOM_MAX ? 'days_out_of_range' : null];
        }
        $default = $policy['duration_mode'] === 'fixed_days' && $policy['fixed_days'] ? 'fixed_days' : 'match_primary';
        if (! in_array($mode, ['match_primary', 'custom'], true)) {
            // Callers that send only a day count keep working: a different count is an explicit custom period.
            $defaultDays = $default === 'fixed_days' ? (int) $policy['fixed_days'] : $purchased;
            $mode = $days && $days !== $defaultDays ? 'custom' : $default;
        }
        if ($mode === 'custom') {
            $error = ! $policy['allow_day_override'] ? 'override_not_allowed' : (! $days || $days < 1 || $days > self::CUSTOM_MAX ? 'days_out_of_range' : null);

            return array_merge($base, ['mode' => 'custom', 'days' => $days, 'max' => self::CUSTOM_MAX, 'error' => $error]);
        }
        if ($mode === 'fixed_days') {
            return array_merge($base, ['mode' => 'fixed_days', 'days' => (int) $policy['fixed_days'], 'max' => self::CUSTOM_MAX]);
        }
        $error = ! $purchased ? 'purchased_duration_missing' : ($purchased > self::MATCHED_MAX ? 'purchased_duration_unsupported' : null);

        return array_merge($base, ['mode' => 'match_primary', 'days' => $error ? null : $purchased, 'max' => self::MATCHED_MAX, 'error' => $error]);
    }

    public function message(?string $error): ?string
    {
        return match ($error) {
            null => null,
            'override_not_allowed' => 'This pair uses its saved period. Ask an admin to allow a different number of days.',
            'days_out_of_range' => 'Choose between 1 and '.self::CUSTOM_MAX.' days.',
            'purchased_duration_missing' => 'The purchased package has no fixed number of days. Choose a number of days.',
            'purchased_duration_unsupported' => 'The purchased package is longer than '.self::MATCHED_MAX.' days. Choose a number of days.',
            default => 'Review the subscription period.',
        };
    }

    /** Frozen terms for the versioned paired-market contract. */
    public function terms(Deal $main, array $resolved): array
    {
        if (! $resolved['paired']) {
            return [];
        }

        return ['promotion_contract_version' => self::CONTRACT, 'promotion_duration_mode' => $resolved['mode'], 'promotion_duration_days' => (int) $resolved['days'],
            'promotion_policy_version' => (int) $resolved['policy_version'],
            'source_purchase' => ['deal_id' => (int) $main->id, 'product_id' => (int) $main->product_id, 'product_price_id' => $main->product_price_id ? (int) $main->product_price_id : null,
                'duration_days' => $resolved['purchased_days']]];
    }

    /** Allowed days for frozen subsidiary terms: matched purchases may reach 365 on the versioned contract only. */
    public function maxDays(array $terms): int
    {
        $matched = (int) ($terms['promotion_contract_version'] ?? 0) === self::CONTRACT && ($terms['promotion_duration_mode'] ?? '') === 'match_primary'
            && (int) ($terms['duration_days'] ?? 0) === (int) ($terms['source_purchase']['duration_days'] ?? -1);

        return $matched ? self::MATCHED_MAX : self::CUSTOM_MAX;
    }

    /** Current source facts that must not change under an authorization. */
    public function sourceFacts(array $terms): ?array
    {
        if ((int) ($terms['promotion_contract_version'] ?? 0) !== self::CONTRACT) {
            return null;
        }
        $main = Deal::find($terms['source_deal_id'] ?? 0);
        $facts = ['product_price_id' => $main?->product_price_id ? (int) $main->product_price_id : null];
        if (($terms['promotion_duration_mode'] ?? '') === 'match_primary') {
            $facts['purchased_days'] = $main ? $this->purchasedDays($main) : null;
        }

        return $facts;
    }

    /** Material policy changes after authorization require a fresh decision. */
    public function stillAllowed(array $terms): bool
    {
        if ((int) ($terms['promotion_contract_version'] ?? 0) !== self::CONTRACT) {
            return true;
        }
        $main = Deal::find($terms['source_deal_id'] ?? 0);
        if (! $main) {
            return false;
        }
        $policy = $this->policy((int) $main->platform_id, (int) $terms['platform_id']);

        return $policy['paired'] && ($terms['promotion_duration_mode'] !== 'custom' || $policy['allow_day_override']);
    }

    public function save(User $actor, int $source, int $target, array $input, string $reason): array
    {
        abort_unless($this->paired($source, $target), 422, 'Only configured paired markets have promotion settings.');
        abort_unless(Schema::hasTable('subsidiary_promotion_policies'), 503, 'Paired-market settings are being installed.');
        if ($input['duration_mode'] === 'fixed_days') {
            abort_unless(($input['fixed_days'] ?? 0) >= 1 && $input['fixed_days'] <= self::CUSTOM_MAX, 422, 'Choose a fixed period between 1 and '.self::CUSTOM_MAX.' days.');
        }

        return DB::transaction(function () use ($actor, $source, $target, $input, $reason) {
            $row = SubsidiaryPromotionPolicy::where('source_platform_id', $source)->where('target_platform_id', $target)->lockForUpdate()->first();
            abort_unless(($row?->version ?? 0) === (int) $input['version'], 409, 'Paired-market settings changed. Reload them before saving.');
            $before = $this->policy($source, $target);
            $values = ['duration_mode' => $input['duration_mode'], 'fixed_days' => $input['duration_mode'] === 'fixed_days' ? (int) $input['fixed_days'] : null,
                'allow_day_override' => (bool) $input['allow_day_override'], 'copy_new_profile' => (bool) $input['copy_new_profile'], 'updated_by' => $actor->id];
            if ($row) {
                $row->update($values + ['version' => $row->version + 1]);
            } else {
                SubsidiaryPromotionPolicy::create($values + ['source_platform_id' => $source, 'target_platform_id' => $target, 'version' => 1]);
            }
            $after = $this->policy($source, $target);
            abort_unless(app(AuditService::class)->record(['platform_id' => $target, 'actor_id' => $actor->id, 'entity_type' => 'subsidiary_promotion_policy', 'entity_id' => $target,
                'action' => 'subsidiary_promotion_policy_changed', 'before_state' => $before, 'after_state' => $after, 'reason' => $reason]), 500, 'Settings audit could not be saved.');

            return $after;
        });
    }

    private function stored(int $source, int $target): ?SubsidiaryPromotionPolicy
    {
        if (! Schema::hasTable('subsidiary_promotion_policies')) {
            return null;
        }

        return SubsidiaryPromotionPolicy::where('source_platform_id', $source)->where('target_platform_id', $target)->first();
    }
}
