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
            'checkout_policy_json' => ['allowed_providers' => [], 'device_slots' => 3, 'restore_per_hour' => 5, 'wallet_sale_credit' => 'gross'],
            'delivery_policy_json' => ['grant_ttl' => 300], 'test_client_ids' => [],
        ])->refresh()->load('prices');
    }

    public const EXPIRY_POLICY_DEFAULTS = [
        'enabled' => false, 'apply_to_future_expiries' => false, 'media_types' => ['video'],
        'max_per_type' => ['video' => 2, 'photo' => 0],
        'video_duration' => ['mode' => 'any', 'min_seconds' => null, 'max_seconds' => null],
        'pricing' => ['mode' => 'fixed', 'fixed_amount' => 500, 'min_amount' => 500, 'max_amount' => 1000, 'round_increment' => 50, 'photo_amount' => 300],
    ];

    public const FREE_PASS_DEFAULTS = ['new_subscriptions_enabled' => false, 'duration_key' => '1_month', 'effective_from' => null];

    /** Card previews: a blurred looping clip per private video. Strengths match the WordPress encoder. */
    public const TEASER_STRENGTHS = ['colours', 'shapes', 'outlines'];

    public const TEASER_DEFAULTS = ['enabled' => true, 'strength' => 'shapes'];

    /** The saved expiry rule merged over neutral defaults, so older rows load unchanged. */
    public function expiryPolicy(ContentMonetizationSetting $s): array
    {
        $saved = $s->expiry_video_policy_json ?? [];
        $policy = array_replace(self::EXPIRY_POLICY_DEFAULTS, $saved);
        foreach (['max_per_type', 'video_duration', 'pricing'] as $group) {
            $policy[$group] = array_replace(self::EXPIRY_POLICY_DEFAULTS[$group], $saved[$group] ?? []);
        }

        return $policy;
    }

    public function freePassPolicy(ContentMonetizationSetting $s): array
    {
        return array_replace(self::FREE_PASS_DEFAULTS, $s->free_pass_policy_json ?? []);
    }

    public function teaserPolicy(ContentMonetizationSetting $s): array
    {
        $policy = array_replace(self::TEASER_DEFAULTS, array_intersect_key($s->teaser_policy_json ?? [], self::TEASER_DEFAULTS));
        if (! in_array($policy['strength'], self::TEASER_STRENGTHS, true)) {
            $policy['strength'] = self::TEASER_DEFAULTS['strength'];
        }
        $policy['enabled'] = (bool) $policy['enabled'];

        return $policy;
    }

    /** Validate an expiry media/count/duration/price rule against the market's offer policy. */
    public function validateExpiryPolicy(array $input, array $offerPolicy): array
    {
        $data = Validator::make($input, [
            'enabled' => 'required|boolean', 'apply_to_future_expiries' => 'required|boolean',
            'media_types' => 'present|array', 'media_types.*' => ['distinct', Rule::in(['photo', 'video'])],
            'max_per_type.video' => 'required|integer|min:0|max:20', 'max_per_type.photo' => 'required|integer|min:0|max:20',
            'video_duration.mode' => ['required', Rule::in(['any', 'up_to', 'at_least', 'between'])],
            'video_duration.min_seconds' => 'nullable|required_if:video_duration.mode,at_least,between|integer|min:0|max:1800',
            'video_duration.max_seconds' => 'nullable|required_if:video_duration.mode,up_to,between|integer|min:1|max:1800',
            'pricing.mode' => ['required', Rule::in(['fixed', 'duration_scale'])],
            'pricing.fixed_amount' => 'required_if:pricing.mode,fixed|nullable|integer|min:1',
            'pricing.min_amount' => 'required_if:pricing.mode,duration_scale|nullable|integer|min:1',
            'pricing.max_amount' => 'required_if:pricing.mode,duration_scale|nullable|integer|min:1',
            'pricing.round_increment' => 'required|integer|min:1|max:100000',
            'pricing.photo_amount' => 'nullable|integer|min:1',
        ], [], ['video_duration.min_seconds' => 'minimum length', 'video_duration.max_seconds' => 'maximum length'])->validate();
        $types = array_values($data['media_types']);
        $mode = $data['video_duration']['mode'];
        $min = in_array($mode, ['at_least', 'between'], true) ? (int) $data['video_duration']['min_seconds'] : null;
        $max = in_array($mode, ['up_to', 'between'], true) ? (int) $data['video_duration']['max_seconds'] : null;
        // A disabled rule is stored as drafted; it is checked in full when it is switched on,
        // so unrelated saves (pass pricing, surfaces) never fail on untouched defaults.
        $fail = fn (string $key, string $message) => $data['enabled'] ? throw \Illuminate\Validation\ValidationException::withMessages([$key => [$message]]) : null;
        if ($data['enabled'] && ! $types) {
            $fail('media_types', 'Choose Videos, Photos or both before enabling automation.');
        }
        foreach ($types as $type) {
            if (! ($offerPolicy[$type === 'photo' ? 'photos_enabled' : 'videos_enabled'] ?? false)) {
                $fail('media_types', 'Enable private '.$type.'s in Offers & limits first.');
            }
            if ((int) $data['max_per_type'][$type] < 1) {
                $fail('max_per_type.'.$type, 'Choose at least one '.$type.' per profile.');
            }
        }
        if ($mode === 'between' && $min >= $max) {
            $fail('video_duration.max_seconds', 'The maximum length must be longer than the minimum length.');
        }
        $marketMax = (int) ($offerPolicy['max_video_seconds'] ?? 1800);
        if (($min !== null && $min > $marketMax) || ($max !== null && $max > $marketMax)) {
            $fail('video_duration', 'Video length cannot exceed the market maximum of '.$marketMax.' seconds.');
        }
        $pricing = $data['pricing'];
        $inLimits = fn ($amount) => $amount === null || ((int) $amount >= (int) $offerPolicy['min_price'] && (int) $amount <= (int) $offerPolicy['max_price']);
        $amounts = $pricing['mode'] === 'fixed' ? ['pricing.fixed_amount' => $pricing['fixed_amount']] : ['pricing.min_amount' => $pricing['min_amount'], 'pricing.max_amount' => $pricing['max_amount']];
        if (in_array('photo', $types, true)) {
            $amounts['pricing.photo_amount'] = $pricing['photo_amount'] ?? null;
            if (empty($pricing['photo_amount'])) {
                $fail('pricing.photo_amount', 'Set the fixed photo price.');
            }
        }
        foreach ($amounts as $key => $amount) {
            if (! $inLimits($amount)) {
                $fail($key, 'Prices must stay within '.$offerPolicy['min_price'].'–'.$offerPolicy['max_price'].'.');
            }
        }
        if ($pricing['mode'] === 'duration_scale') {
            if ((int) $pricing['min_amount'] > (int) $pricing['max_amount']) {
                $fail('pricing.max_amount', 'The highest price must be at least the lowest price.');
            }
            [$from, $to] = $this->scaleBounds(['video_duration' => ['mode' => $mode, 'min_seconds' => $min, 'max_seconds' => $max]], $marketMax);
            if ($from >= $to) {
                $fail('video_duration', 'Length-scaled pricing needs a length range.');
            }
            if (in_array('video', $types, true) === false) {
                $fail('pricing.mode', 'Length-scaled pricing applies to videos. Select Videos or use a fixed price.');
            }
        }

        return [
            'enabled' => (bool) $data['enabled'], 'apply_to_future_expiries' => (bool) $data['apply_to_future_expiries'], 'media_types' => $types,
            'max_per_type' => ['video' => in_array('video', $types, true) ? (int) $data['max_per_type']['video'] : 0, 'photo' => in_array('photo', $types, true) ? (int) $data['max_per_type']['photo'] : 0],
            'video_duration' => ['mode' => $mode, 'min_seconds' => $min, 'max_seconds' => $max],
            'pricing' => ['mode' => $pricing['mode'], 'fixed_amount' => isset($pricing['fixed_amount']) ? (int) $pricing['fixed_amount'] : null, 'min_amount' => isset($pricing['min_amount']) ? (int) $pricing['min_amount'] : null, 'max_amount' => isset($pricing['max_amount']) ? (int) $pricing['max_amount'] : null, 'round_increment' => (int) $pricing['round_increment'], 'photo_amount' => isset($pricing['photo_amount']) ? (int) $pricing['photo_amount'] : null],
        ];
    }

    /** Length range used by length-scaled pricing: open-ended filters use 0 or the market maximum. */
    public function scaleBounds(array $policy, int $marketMax): array
    {
        $duration = $policy['video_duration'];

        return [
            in_array($duration['mode'], ['at_least', 'between'], true) ? (int) $duration['min_seconds'] : 0,
            in_array($duration['mode'], ['up_to', 'between'], true) ? (int) $duration['max_seconds'] : $marketMax,
        ];
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
            'teaser_policy' => $this->teaserPolicy($s),
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
            'premium_access_environment' => ['sometimes', Rule::in(['production', 'sandbox'])],
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
            'checkout_policy' => 'required|array', 'checkout_policy.allowed_providers' => 'present|array',
            'checkout_policy.allowed_providers.*' => [Rule::in(['kopokopo', 'pawapay'])],
            'checkout_policy.device_slots' => 'required|integer|min:1|max:3', 'checkout_policy.restore_per_hour' => 'required|integer|min:1|max:10',
            'delivery_policy.grant_ttl' => 'required|integer|min:60|max:300',
            'test_client_ids' => 'array', 'test_client_ids.*' => 'integer',
            'expiry_video_policy' => 'sometimes|array', 'free_pass_policy' => 'sometimes|array',
            'free_pass_policy.new_subscriptions_enabled' => 'required_with:free_pass_policy|boolean',
            'free_pass_policy.duration_key' => ['required_with:free_pass_policy', Rule::in(['2_weeks', '1_month'])],
            'teaser_policy' => 'sometimes|array', 'teaser_policy.enabled' => 'required_with:teaser_policy|boolean',
            'teaser_policy.strength' => ['required_with:teaser_policy', Rule::in(self::TEASER_STRENGTHS)],
        ])->validate();
        if (isset($data['expiry_video_policy'])) {
            $data['expiry_video_policy'] = $this->validateExpiryPolicy($data['expiry_video_policy'], $data['offer_policy']);
        }
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
            $environment = $data['rollout_mode'] === 'live' ? 'production' : ($data['premium_access_environment'] ?? ($data['rollout_mode'] === 'sandbox' ? 'sandbox' : $s->premium_access_environment));
            foreach ($data['checkout_policy']['allowed_providers'] as $provider) {
                try {
                    app(BillingModeService::class)->providerContext($platform, $provider, true, $environment, 'premium_content');
                } catch (\InvalidArgumentException $exception) {
                    $label = $provider === 'kopokopo' ? 'M-Pesa · KopoKopo' : 'PawaPay';

                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'checkout_policy.allowed_providers' => [
                            $exception->getMessage() === 'Selected provider is disabled for this market.' ? "$label is not enabled for this market. Enable it in Settings → Wallet System, or remove it from Surfaces & checkout." : "$label cannot accept payments for {$platform->name} $environment. Configure the wallet and provider credentials in Settings → Wallet System, then choose it again.",
                        ],
                    ]);
                }
            }
            if ($data['rollout_mode'] === 'live') {
                abort_unless($s->rollout_mode === 'live' && $s->enabled, 422, 'Use Guided setup → Enable live market after completing the required checks.');
                abort_unless(count($data['checkout_policy']['allowed_providers']) > 0, 422, 'Choose an available production provider before saving a live market.');
                abort_unless((! data_get($data, 'offer_policy.videos_enabled') || data_get($s->readiness_json, 'checks.video_processing') === true) && data_get($s->readiness_json, 'ready') === true && $s->heartbeat_at?->gt(now()->subMinutes(15)), 422, 'Run readiness checks before enabling live sales.');
            }
            $s->fill(collect($data)->only(['enabled', 'rollout_mode', 'activation_kill_switch', 'checkout_kill_switch', 'test_client_ids'])->all());
            $s->premium_access_environment = $environment;
            $s->setup_json = null;
            foreach (['offer', 'surface', 'checkout', 'delivery'] as $key) {
                $s->{$key.'_policy_json'} = $data[$key.'_policy'];
            }
            $s->checkout_policy_json = array_merge($s->checkout_policy_json, ['wallet_sale_credit' => 'gross']);
            if (isset($data['expiry_video_policy'])) {
                $s->expiry_video_policy_json = $data['expiry_video_policy'];
            }
            if (isset($data['free_pass_policy'])) {
                $previous = $this->freePassPolicy($s);
                $enabled = (bool) $data['free_pass_policy']['new_subscriptions_enabled'];
                // Only subscriptions activated after the policy is switched on qualify.
                $s->free_pass_policy_json = ['new_subscriptions_enabled' => $enabled, 'duration_key' => $data['free_pass_policy']['duration_key'], 'effective_from' => $enabled ? ($previous['new_subscriptions_enabled'] && $previous['effective_from'] ? $previous['effective_from'] : now()->toIso8601String()) : null];
            }
            if (isset($data['teaser_policy'])) {
                $s->teaser_policy_json = ['enabled' => (bool) $data['teaser_policy']['enabled'], 'strength' => $data['teaser_policy']['strength']];
            }
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
