<?php

namespace App\Services;

use App\Models\ContentMonetizationSetting;
use App\Models\ContentMonetizationSystemSetting;
use App\Models\Platform;
use App\Models\PremiumContentEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MonetizationSettingsService
{
    public function system(): ContentMonetizationSystemSetting
    {
        return ContentMonetizationSystemSetting::firstOrCreate(['id' => 1]);
    }

    public function forPlatform(Platform $platform): ContentMonetizationSetting
    {
        return ContentMonetizationSetting::firstOrCreate(['platform_id' => $platform->id], [
            'currency' => app(WalletSettingsService::class)->runtimeWalletCurrencyCode($platform),
            'grant_secret' => bin2hex(random_bytes(32)), 'device_pepper' => bin2hex(random_bytes(32)),
            'offer_policy_json' => ['photos_enabled' => true, 'videos_enabled' => true, 'single_enabled' => true, 'bundles_enabled' => true, 'min_price' => 100, 'max_price' => 5000, 'bundle_min_items' => 2, 'bundle_max_items' => 12, 'live_offer_limit' => 50, 'upload_max_bytes' => 52428800, 'max_video_seconds' => 600],
            'surface_policy_json' => ['profile_section' => true, 'home_private_content' => true, 'videos_private_filter' => true, 'show_prices' => true],
            'checkout_policy_json' => ['allowed_providers' => ['kopokopo'], 'device_slots' => 3, 'restore_per_hour' => 5, 'wallet_sale_credit' => 'gross'],
            'delivery_policy_json' => ['grant_ttl' => 300], 'test_client_ids' => [],
        ])->load('prices');
    }

    public function runtime(ContentMonetizationSetting $s): array
    {
        $system = $this->system();

        return [
            'enabled' => $system->enabled && $s->enabled && $s->rollout_mode !== 'off',
            'rollout_mode' => $s->rollout_mode, 'currency' => $s->currency,
            'revision' => (int) $s->config_revision, 'system_revision' => (int) $system->config_revision,
            'activation_kill_switch' => $system->activation_kill_switch || $s->activation_kill_switch,
            'checkout_kill_switch' => $system->checkout_kill_switch || $s->checkout_kill_switch,
            'offer_policy' => $s->offer_policy_json, 'surface_policy' => $s->surface_policy_json,
            'checkout_policy' => $s->checkout_policy_json, 'delivery_policy' => $s->delivery_policy_json,
            'prices' => $s->prices()->where('is_active', true)->orderBy('sort_order')->get()->toArray(),
        ];
    }

    public function assertCommerce(ContentMonetizationSetting $s, string $kind): void
    {
        $p = $this->runtime($s);
        abort_unless($p['enabled'], 409, 'Private content is not enabled in this market.');
        abort_if($p[$kind === 'activation' ? 'activation_kill_switch' : 'checkout_kill_switch'], 409, 'New sales are paused on Exotic right now.');
        abort_unless($s->heartbeat_at && $s->heartbeat_at->gt(now()->subMinutes(15)) && data_get($s->readiness_json, 'ready') === true, 409, 'Protected delivery needs a fresh successful readiness check.');
        app(BillingModeService::class)->assertWalletAvailable($s->platform);
    }

    public function save(Platform $platform, array $input, int $actor): ContentMonetizationSetting
    {
        $data = Validator::make($input, [
            'reason' => 'required|string|min:5|max:1000', 'config_revision' => 'required|integer',
            'currency' => 'sometimes|string|size:3',
            'enabled' => 'required|boolean', 'rollout_mode' => ['required', Rule::in(['off', 'sandbox', 'live'])],
            'activation_kill_switch' => 'required|boolean', 'checkout_kill_switch' => 'required|boolean',
            'prices' => 'required|array|max:2', 'prices.*.duration_key' => ['required', 'distinct', Rule::in(['2_weeks', '1_month'])],
            'prices.*.price' => 'required|numeric|min:0|max:1000000',
            'prices.*.subsidy_mode' => ['required', Rule::in(['fixed', 'percentage'])],
            'prices.*.subsidy_value' => 'required|numeric|min:0', 'prices.*.is_active' => 'required|boolean',
            'offer_policy' => 'required|array', 'offer_policy.photos_enabled' => 'required|boolean', 'offer_policy.videos_enabled' => 'required|boolean',
            'offer_policy.single_enabled' => 'required|boolean', 'offer_policy.bundles_enabled' => 'required|boolean',
            'offer_policy.min_price' => 'required|integer|min:1', 'offer_policy.max_price' => 'required|integer|gte:offer_policy.min_price|max:1000000',
            'offer_policy.bundle_min_items' => 'required|integer|min:2|max:50', 'offer_policy.bundle_max_items' => 'required|integer|gte:offer_policy.bundle_min_items|max:50',
            'offer_policy.live_offer_limit' => 'required|integer|min:1|max:200', 'offer_policy.upload_max_bytes' => 'required|integer|min:1024|max:104857600',
            'offer_policy.max_video_seconds' => 'required|integer|min:1|max:1800',
            'surface_policy' => 'required|array', 'surface_policy.*' => 'boolean',
            'checkout_policy' => 'required|array', 'checkout_policy.allowed_providers' => 'required|array|min:1',
            'checkout_policy.allowed_providers.*' => [Rule::in(['kopokopo', 'pawapay'])],
            'checkout_policy.device_slots' => 'required|integer|min:1|max:3', 'checkout_policy.restore_per_hour' => 'required|integer|min:1|max:10',
            'delivery_policy.grant_ttl' => 'required|integer|min:60|max:300',
            'test_client_ids' => 'array', 'test_client_ids.*' => 'integer',
        ])->validate();
        foreach ($data['prices'] as $price) {
            abort_if((float) $price['subsidy_value'] > ($price['subsidy_mode'] === 'percentage' ? 100 : (float) $price['price']), 422, 'Subsidy cannot exceed the pass price.');
        }
        abort_if(data_get($data, 'surface_policy.videos_private_filter') && ! data_get($data, 'offer_policy.videos_enabled'), 422, 'Enable videos before the Videos surface.');
        abort_if((data_get($data, 'surface_policy.profile_section') || data_get($data, 'surface_policy.home_private_content')) && ! data_get($data, 'offer_policy.photos_enabled') && ! data_get($data, 'offer_policy.videos_enabled'), 422, 'Enable an asset type before discovery.');
        foreach ($data['test_client_ids'] ?? [] as $id) {
            abort_unless(\App\Models\Client::where('platform_id', $platform->id)->whereKey($id)->exists(), 422, 'Test creator belongs to another market.');
        }

        return DB::transaction(function () use ($platform, $data, $actor) {
            $s = $this->forPlatform($platform);
            $s = ContentMonetizationSetting::whereKey($s->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $s->config_revision === (int) $data['config_revision'], 409, 'Settings changed. Reload before saving.');
            $before = $s->toArray();
            if (isset($data['currency']) && $data['currency'] !== $s->currency) {
                $currencies = app(WalletSettingsService::class)->runtimePlatformConfig($platform)['supported_currencies'] ?? [$platform->currency_code];
                abort_unless(in_array($data['currency'], $currencies, true), 422, 'Choose a currency enabled for this market wallet.');
                abort_if(\App\Models\PremiumContentOffer::where('platform_id', $platform->id)->exists() || \App\Models\ClientMonetizationPass::where('platform_id', $platform->id)->exists(), 422, 'Currency is fixed once passes or offers exist. Existing purchase and wallet history must retain their currency.');
                $s->currency = $data['currency'];
            }
            if ($s->rollout_mode === 'sandbox' && $data['rollout_mode'] === 'live') {
                \App\Models\ClientMonetizationPass::where('platform_id', $platform->id)->where('is_sandbox', true)->update(['status' => 'expired', 'active_marker' => null]);
            }
            if ($data['rollout_mode'] === 'live') {
                foreach ($data['checkout_policy']['allowed_providers'] as $provider) {
                    app(BillingModeService::class)->providerContext($platform, $provider, true, 'production', 'premium_content');
                }
            }
            if ($data['rollout_mode'] === 'live') {
                abort_unless((! data_get($data, 'offer_policy.videos_enabled') || data_get($s->readiness_json, 'checks.video_processing') === true) && data_get($s->readiness_json, 'ready') === true && $s->heartbeat_at?->gt(now()->subMinutes(15)), 422, 'Run readiness checks before enabling live sales.');
            }
            $s->fill(collect($data)->only(['enabled', 'rollout_mode', 'activation_kill_switch', 'checkout_kill_switch', 'test_client_ids'])->all());
            if ($data['rollout_mode'] !== 'off') {
                $s->premium_access_environment = $data['rollout_mode'] === 'live' ? 'production' : 'sandbox';
            }
            foreach (['offer', 'surface', 'checkout', 'delivery'] as $key) {
                $s->{$key.'_policy_json'} = $data[$key.'_policy'];
            }
            $s->checkout_policy_json = array_merge($s->checkout_policy_json, ['wallet_sale_credit' => 'gross']);
            $s->config_revision++;
            $s->save();
            $s->prices()->whereNotIn('duration_key', array_column($data['prices'], 'duration_key'))->update(['is_active' => false]);
            foreach ($data['prices'] as $index => $price) {
                $key = $price['duration_key'];
                $s->prices()->updateOrCreate(['duration_key' => $key], [
                    'duration_label' => $key === '2_weeks' ? '2 Weeks' : '1 Month', 'duration_days' => $key === '2_weeks' ? 14 : 30,
                    'currency' => $s->currency, 'price' => $price['price'], 'subsidy_mode' => $price['subsidy_mode'],
                    'subsidy_value' => $price['subsidy_value'], 'is_active' => $price['is_active'], 'sort_order' => $index,
                ]);
            }
            PremiumContentEvent::create(['platform_id' => $platform->id, 'actor_id' => $actor, 'kind' => 'settings_changed', 'reason' => $data['reason'], 'metadata_json' => ['before' => $before, 'after' => $s->fresh('prices')->toArray()]]);

            return $s->fresh('prices');
        });
    }
}
