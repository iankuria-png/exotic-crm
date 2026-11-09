<?php

namespace App\Services\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\User;
use App\Services\MarketAuthorizationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CollectionAutomationPolicy
{
    public const FIELDS = ['automation_enabled', 'match_threshold', 'max_auto_age_seconds', 'observation_pilot_hours', 'purchase_matching', 'catalogue_price_defaults'];

    /** Human acceptance facts the automatic gate requires; each is recorded by a manager with who accepted it and why. */
    public const SIGNOFFS = [
        'independent_reconciliation_accepted_at' => ['group' => 'finance', 'title' => 'Statement comparison',
            'label' => 'Independent payment statement comparison accepted', 'detail' => 'Captured receipts agree with the receiving-account statement.'],
        'settlement_policy_accepted_at' => ['group' => 'finance', 'title' => 'Settlement policy',
            'label' => 'Settlement policy accepted', 'detail' => 'How collected money is settled and remitted to Exotic is agreed.'],
        'held_out_parser_accepted_at' => ['group' => 'operations', 'title' => 'Payment SMS reading',
            'label' => 'Held-out payment message parsing accepted', 'detail' => 'Payment messages outside the build set were read correctly. Also accepts the current reader version.'],
        'correction_acceptance_at' => ['group' => 'operations', 'title' => 'Corrections and recovery',
            'label' => 'Correction and recovery acceptance completed', 'detail' => 'Undo, change-client and site-confirmation recovery behave as expected.'],
        'device_capture_accepted_at' => ['group' => 'operations', 'title' => 'Locked phone and restart capture',
            'label' => 'Locked phone and restart capture accepted', 'detail' => 'The office phone keeps forwarding payment SMS while locked and after a restart.'],
        'historical_reference_map_accepted_at' => ['group' => 'operations', 'title' => 'Already-recorded payment codes',
            'label' => 'Historical payment reference checks accepted', 'detail' => 'Codes already recorded in CRM are recognised, so one payment cannot activate twice.'],
    ];

    private const SYSTEM_ACTIONS = [
        'account' => 'An administrator must verify the receiving account and SIM.',
        'limits' => 'Approve the exposure limits in this panel.',
        'age' => 'Choose the maximum payment age under Rules.',
        'device' => 'Unlock the office phone and open the receiving app until it checks in.',
        'parser' => 'Recorded with the Payment SMS reading sign-off.',
        'writers' => 'Released by engineering for the exact sites.',
        'rollout' => 'Released by engineering for this receiving account.',
        'authority' => 'Turn on exact package matching under Rules.',
    ];

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
        foreach (self::SIGNOFFS as $field => $meta) {
            $evidence = $p['acceptance_evidence'][$field] ?? null;
            $checks[] = ['key' => $field, 'label' => $meta['label'], 'ready' => ! empty($p[$field]), 'kind' => 'signoff', 'group' => $meta['group'],
                'title' => $meta['title'], 'detail' => $meta['detail'], 'accepted_at' => $p[$field] ?? null,
                'evidence' => is_array($evidence) ? array_intersect_key($evidence, array_flip(['accepted_by', 'note', 'recorded_by_name', 'recorded_at'])) : null];
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

        return array_map(fn ($check) => ($check['kind'] ?? null) === 'signoff' ? $check
            : $check + ['kind' => 'system', 'group' => 'system', 'action' => self::SYSTEM_ACTIONS[$check['key']] ?? null], $checks);
    }

    /** Read model for staff: live state, pilot clock, activity since switch-on and the last recorded change. */
    public function status(CollectionAccount $account, array $presented): array
    {
        $p = $account->policy ?? [];
        $pilot = $this->pilot($account);
        $blocking = array_values(array_map(fn ($c) => $c['key'], array_filter($presented['readiness'], fn ($c) => ! $c['ready'])));
        $observed = (int) ($p['clean_observation_days'] ?? 0) >= 7 && (int) ($p['positive_receiving_credits'] ?? 0) >= 100;
        $state = ! $presented['enabled'] ? 'paused' : ($blocking ? 'held' : (! $observed && $pilot['state'] !== 'active' ? 'pilot_ended' : 'live'));
        $activity = null;
        if (! empty($p['enabled_from'])) {
            $from = \Illuminate\Support\Carbon::parse($p['enabled_from']);
            $activity = ['since' => $p['enabled_from'],
                'received' => DB::table('collection_receipts')->where('account_id', $account->id)->where('historical_only', false)->where('created_at', '>=', $from)->count(),
                'automatic' => DB::table('collection_allocations as a')->join('collection_receipts as r', 'r.id', '=', 'a.receipt_id')
                    ->where('r.account_id', $account->id)->where('a.decision_source', 'automatic')->where('a.created_at', '>=', $from)->count()];
        }
        $last = DB::table('collection_audit as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->where('a.account_id', $account->id)
            ->whereIn('a.action', ['automation_policy_updated', 'automation_signoff_recorded', 'automation_signoff_withdrawn'])
            ->orderByDesc('a.id')->first(['a.action', 'a.context', 'a.created_at', 'u.name']);
        $context = $last ? json_decode((string) $last->context, true) : null;

        return ['live_state' => $state, 'blocking' => $blocking, 'pilot' => $pilot, 'observation_met' => $observed,
            'can_enable' => ! $presented['enabled'] && ! array_diff($blocking, ['age']) && ($observed || $pilot['state'] === 'active' || $pilot['available']),
            'activity' => $activity,
            'last_change' => $last ? ['action' => $last->action, 'actor' => $last->name ?: 'CRM staff', 'at' => \Illuminate\Support\Carbon::parse($last->created_at)->toIso8601String(),
                'reason' => $context['reason'] ?? $context['note'] ?? null] : null];
    }

    private function pilot(CollectionAccount $account): array
    {
        $exception = data_get($account->policy, 'observation_exception');
        $eligible = $account->country === 'TZ' && $account->provider_key === 'vodacom_mpesa_tz';
        if (! is_array($exception) || empty($exception['starts_at']) || empty($exception['expires_at'])) {
            return ['state' => 'none', 'available' => $eligible, 'starts_at' => null, 'expires_at' => null, 'hours' => 24];
        }
        $active = now()->gte(\Illuminate\Support\Carbon::parse($exception['starts_at'])) && now()->lt(\Illuminate\Support\Carbon::parse($exception['expires_at']));

        return ['state' => $active ? 'active' : 'expired', 'available' => false, 'starts_at' => $exception['starts_at'], 'expires_at' => $exception['expires_at'], 'hours' => 24];
    }

    public function recordSignoff(User $actor, string $accountId, array $input): array
    {
        app(MarketAuthorizationService::class)->ensureManager($actor);
        app(CollectionAccessService::class)->account($actor, $accountId);
        if (array_diff(array_keys($input), ['key', 'expected_version', 'withdraw', 'accepted_by', 'note'])) {
            throw new CollectionException('unsupported_signoff_fields', 422);
        }
        $v = Validator::make($input, ['key' => ['required', 'string', Rule::in(array_keys(self::SIGNOFFS))], 'expected_version' => 'required|integer|min:0',
            'withdraw' => 'sometimes|boolean', 'accepted_by' => 'required_unless:withdraw,true|nullable|string|min:2|max:120',
            'note' => 'required|string|min:3|max:1000'])->validate();
        $withdraw = (bool) ($v['withdraw'] ?? false);

        return DB::transaction(function () use ($actor, $accountId, $v, $withdraw) {
            $account = CollectionAccount::lockForUpdate()->findOrFail($accountId);
            $p = $account->policy ?? [];
            if ((int) ($p['version'] ?? 0) !== (int) $v['expected_version']) {
                throw new CollectionException('collection_policy_changed', 409);
            }
            $key = $v['key'];
            $parser = config('collection.parser_versions.'.$account->provider_key);
            $evidence = $p['acceptance_evidence'] ?? [];
            if ($withdraw) {
                unset($p[$key], $evidence[$key]);
                if ($key === 'held_out_parser_accepted_at' && is_string($parser)) {
                    $p['accepted_parser_versions'] = array_values(array_diff($p['accepted_parser_versions'] ?? [], [$parser]));
                }
            } else {
                $p[$key] = now()->toIso8601String();
                $evidence[$key] = ['accepted_by' => trim($v['accepted_by']), 'note' => trim($v['note']), 'recorded_by' => $actor->id,
                    'recorded_by_name' => $actor->name, 'recorded_at' => $p[$key]];
                if ($key === 'held_out_parser_accepted_at' && is_string($parser)) {
                    $p['accepted_parser_versions'] = array_values(array_unique([...($p['accepted_parser_versions'] ?? []), $parser]));
                }
            }
            $p['acceptance_evidence'] = $evidence;
            $p['version'] = (int) ($p['version'] ?? 0) + 1;
            $account->update(['policy' => $p]);
            DB::table('collection_audit')->insert(['account_id' => $accountId, 'actor_id' => $actor->id,
                'action' => $withdraw ? 'automation_signoff_withdrawn' : 'automation_signoff_recorded',
                'context' => json_encode(['key' => $key, 'accepted_by' => $withdraw ? null : trim($v['accepted_by']), 'note' => trim($v['note']),
                    'parser_version' => $key === 'held_out_parser_accepted_at' ? $parser : null, 'version' => $p['version'],
                    'automation_enabled' => ($p['automation_enabled'] ?? false) === true], JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return app(CollectionPolicyService::class)->present($account);
        }, 3);
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
