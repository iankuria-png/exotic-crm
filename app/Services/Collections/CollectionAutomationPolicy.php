<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CollectionAutomationPolicy
{
    public const FIELDS = ['automation_enabled', 'match_threshold', 'max_auto_age_seconds', 'observation_pilot_hours', 'purchase_matching', 'catalogue_price_defaults'];

    public function present(CollectionAccount $account): array
    {
        $p = $account->policy ?? [];

        return ['enabled' => ($p['automation_enabled'] ?? false) === true,
            'match_threshold' => $p['match_threshold'] ?? 80, 'purchase_matching' => $p['purchase_matching'] ?? 'reviewed_only', 'catalogue_price_defaults' => $p['catalogue_price_defaults'] ?? [],
            'max_auto_age_seconds' => $p['max_auto_age_seconds'] ?? null,
            'enabled_from' => $p['enabled_from'] ?? null, 'include_backlog' => false,
            'observation_exception' => $p['observation_exception'] ?? null, 'default_packages' => $this->defaultPackages($account),
            'readiness' => $this->readiness($account)];
    }

    private function defaultPackages(CollectionAccount $account): array
    {
        $ids = array_values($account->policy['catalogue_price_defaults'] ?? []);
        if (! $ids) {
            return [];
        }
        $platforms = DB::table('collection_account_platforms')->where('account_id', $account->id)->pluck('platform_id')->all();

        return \App\Models\ProductPrice::with('product')->whereIn('id', $ids)->whereHas('product', fn ($q) => $q->whereIn('platform_id', $platforms))->get()
            ->map(fn ($price) => ['id' => $price->id, 'name' => $price->product->name, 'duration_days' => $price->duration_days,
                'amount' => $price->price, 'currency' => $price->currency, 'active' => (bool) ($price->is_active && $price->product->is_active && ! $price->product->is_archived)])->all();
    }

    public function readiness(CollectionAccount $account): array
    {
        $p = $account->policy ?? [];
        $checks = [
            ['key' => 'account', 'label' => 'Receiving account and SIM destination verified', 'ready' => (bool) $account->verified_at && ! $account->suspended_at],
            ['key' => 'limits', 'label' => 'Exposure limits approved', 'ready' => app(CollectionPolicyService::class)->configured($p)],
            ['key' => 'age', 'label' => 'Maximum payment age approved', 'ready' => app(CollectionAutomationEpoch::class)->validAge($p['max_auto_age_seconds'] ?? null)],
            ['key' => 'device', 'label' => 'Receiving phone has a fresh capture check-in', 'ready' => app(CollectionDeviceHealthService::class)->accountReady($account->id)],
        ];
        foreach (['independent_reconciliation' => 'Independent payment statement comparison accepted', 'held_out_parser' => 'Held-out payment message parsing accepted',
            'correction_acceptance' => 'Correction and recovery acceptance completed', 'device_capture' => 'Locked phone and restart capture accepted',
            'settlement_policy' => 'Settlement policy accepted', 'historical_reference_map' => 'Historical payment reference checks accepted'] as $key => $label) {
            $field = $key === 'correction_acceptance' ? 'correction_acceptance_at' : $key.'_accepted_at';
            $checks[] = ['key' => $field, 'label' => $label, 'ready' => ! empty($p[$field])];
        }
        $parser = config('collection.parser_versions.'.$account->provider_key);
        $checks[] = ['key' => 'parser', 'label' => 'Current parser version accepted', 'ready' => is_string($parser) && in_array($parser, $p['accepted_parser_versions'] ?? [], true)];
        $platforms = DB::table('collection_account_platforms')->where('account_id', $account->id)->whereNull('valid_until')->pluck('platform_id')->map(fn ($id) => (int) $id)->all();
        foreach ($platforms as $platformId) {
            if ($secondary = config('collection.subsidiary_platforms.'.$platformId)) {
                $platforms[] = (int) $secondary;
            }
        }
        $platforms = array_values(array_unique($platforms));
        $writers = $platforms && ! array_diff($platforms, config('collection.entitlement_platforms', []))
            && ! array_diff($platforms, config('collection.verified_writer_platforms', [])) && ! array_diff($platforms, config('collection.verified_correction_platforms', []));
        $checks[] = ['key' => 'writers', 'label' => 'Exact sites support activation, correction and all writer holds', 'ready' => (bool) $writers];
        $checks[] = ['key' => 'rollout', 'label' => 'This receiving account is included in the verified rollout', 'ready' => in_array($account->id, config('collection.automatic_accounts', []), true)];
        $reviewed = DB::table('collection_purchase_intents')->where('account_id', $account->id)->whereNull('revoked_at')
            ->where('valid_until', '>', now())->whereColumn('used_count', '<', 'max_renewals')->exists();
        $catalogue = ($p['purchase_matching'] ?? '') === 'exact_catalogue_or_reviewed' && ! empty($p['purchase_matching_approved_by'])
            && DB::table('product_prices as pp')->join('products as product', 'product.id', '=', 'pp.product_id')
                ->whereIn('product.platform_id', $platforms)->where('pp.currency', $account->currency)->where('pp.is_active', true)->where('product.is_active', true)->exists();
        $checks[] = ['key' => 'authority', 'label' => 'Exact package matching policy or reviewed purchase available', 'ready' => $reviewed || $catalogue];

        return $checks;
    }

    public function update(User $actor, string $accountId, array $input): array
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        app(CollectionAccessService::class)->account($actor, $accountId);
        if (array_diff(array_keys($input), [...self::FIELDS, 'expected_version', 'reason'])) {
            throw new CollectionException('unsupported_policy_fields', 422);
        }
        $v = Validator::make($input, ['expected_version' => 'required|integer|min:0', 'reason' => 'required|string|min:3|max:500',
            'automation_enabled' => 'sometimes|boolean', 'match_threshold' => 'sometimes|integer|min:0|max:100',
            'catalogue_price_defaults' => 'sometimes|array|max:50', 'catalogue_price_defaults.*' => 'required|integer|min:1', 'purchase_matching' => 'sometimes|in:reviewed_only,exact_catalogue_or_reviewed', 'max_auto_age_seconds' => 'sometimes|integer|min:60|max:86400', 'observation_pilot_hours' => 'sometimes|integer|in:24'])->validate();

        return DB::transaction(function () use ($actor, $accountId, $v) {
            $account = CollectionAccount::lockForUpdate()->findOrFail($accountId);
            $p = $account->policy ?? [];
            if ((int) ($p['version'] ?? 0) !== (int) $v['expected_version']) {
                throw new CollectionException('collection_policy_changed', 409);
            }
            $before = $this->present($account);
            $enabled = array_key_exists('automation_enabled', $v) ? (bool) $v['automation_enabled'] : $before['enabled'];
            $threshold = (int) ($v['match_threshold'] ?? $before['match_threshold']);
            $age = isset($v['max_auto_age_seconds']) ? (int) $v['max_auto_age_seconds'] : $before['max_auto_age_seconds'];
            $purchaseMatching = $v['purchase_matching'] ?? $before['purchase_matching'];
            $purchaseChanged = $purchaseMatching !== $before['purchase_matching'];
            $defaults = array_map('intval', $v['catalogue_price_defaults'] ?? $before['catalogue_price_defaults']);
            ksort($defaults);
            $existingDefaults = $before['catalogue_price_defaults'];
            ksort($existingDefaults);
            $defaultsChanged = $defaults !== $existingDefaults;
            if ($defaultsChanged) {
                $primaryPlatforms = DB::table('collection_account_platforms')->where('account_id', $accountId)->whereNull('valid_until')->pluck('platform_id')->all();
                foreach ($defaults as $key => $priceId) {
                    $price = \App\Models\ProductPrice::with('product')->find($priceId);
                    if (! $price || ! $price->is_active || ! $price->product?->is_active || $price->product->is_archived
                        || ! in_array((int) $price->product->platform_id, array_map('intval', $primaryPlatforms), true)
                        || $price->currency !== $account->currency
                        || $key !== $price->product->platform_id.':'.$price->currency.':'.app(TzMpesaParser::class)->minor((string) $price->price)) {
                        throw new CollectionException('invalid_automatic_default_package', 422);
                    }
                }
                $p['catalogue_price_defaults'] = $defaults;
                $p['catalogue_price_defaults_approved_by'] = $actor->id;
                $p['catalogue_price_defaults_approved_at'] = now()->toIso8601String();
            }
            if ($purchaseChanged) {
                $p['purchase_matching'] = $purchaseMatching;
                $p['purchase_matching_approved_by'] = $actor->id;
                $p['purchase_matching_approved_at'] = now()->toIso8601String();
            }
            $changed = $defaultsChanged || $purchaseChanged || $enabled !== $before['enabled'] || $threshold !== $before['match_threshold'] || $age !== $before['max_auto_age_seconds'];
            $p['automation_enabled'] = $enabled;
            $p['match_threshold'] = $threshold;
            $p['max_auto_age_seconds'] = $age;
            $p['include_backlog'] = false;
            if ($changed) {
                $p['automation_epoch'] = $enabled ? (string) Str::uuid() : null;
                $p['enabled_from'] = $enabled ? now()->toIso8601String() : null;
            }
            if (isset($v['observation_pilot_hours'])) {
                if (isset($p['observation_exception']) || ! $enabled || $before['enabled'] || $account->country !== 'TZ' || $account->provider_key !== 'vodacom_mpesa_tz') {
                    throw new CollectionException('observation_exception_requires_new_tz_enablement', 422);
                }
                $p['observation_exception'] = ['account_id' => $accountId, 'platform_ids' => $this->platforms($account),
                    'parser_version' => config('collection.parser_versions.'.$account->provider_key),
                    'waived_keys' => ['clean_observation_days', 'positive_receiving_credits'], 'reason' => $v['reason'],
                    'approved_by' => $actor->id, 'approved_at' => now()->toIso8601String(),
                    'starts_at' => now()->toIso8601String(), 'expires_at' => now()->addHours(24)->toIso8601String()];
            }
            if ($enabled) {
                if (! app(CollectionAutomationEpoch::class)->validAge($age)) {
                    throw new CollectionException('automatic_payment_age_policy_required');
                }
                if (! app(CollectionPolicyService::class)->configured($p)) {
                    throw new CollectionException('automatic_exposure_limits_required');
                }
                // Only the real acceptance facts/configuration can permit enablement.
                $account->policy = $p;
                $account->mode = 'auto';
                app(CollectionGate::class)->assertWrites($account, $this->platforms($account), true);
            }
            $p['version'] = (int) ($p['version'] ?? 0) + 1;
            $account->update(['policy' => $p]);
            DB::table('collection_audit')->insert(['account_id' => $accountId, 'actor_id' => $actor->id, 'action' => 'automation_policy_updated',
                'context' => json_encode(['before' => $before, 'after' => $this->present($account), 'version' => $p['version'],
                    'reason' => $v['reason'], 'eligibility_epoch_rotated' => $changed], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return app(CollectionPolicyService::class)->present($account);
        }, 3);
    }

    private function platforms(CollectionAccount $account): array
    {
        $ids = DB::table('collection_account_platforms')->where('account_id', $account->id)->where('valid_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhere('valid_until', '>', now()))->pluck('platform_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
        if (! $ids) {
            throw new CollectionException('destination_binding_missing');
        }

        foreach ($ids as $platformId) {
            if ($secondary = config('collection.subsidiary_platforms.'.$platformId)) {
                $ids[] = (int) $secondary;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
