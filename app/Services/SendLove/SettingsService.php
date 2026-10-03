<?php

namespace App\Services\SendLove;

use App\Models\Client;
use App\Models\LoveGift;
use App\Models\Platform;
use App\Models\PremiumContentEvent;
use App\Models\SendLoveSetting;
use App\Services\BillingModeService;
use App\Services\MonetizationSettingsService;
use App\Services\WalletSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SettingsService
{
    public function forPlatform(Platform $p): SendLoveSetting
    {
        return SendLoveSetting::firstOrCreate(['platform_id' => $p->id], [
            'currency' => app(WalletSettingsService::class)->runtimeWalletCurrencyCode($p), 'device_pepper' => bin2hex(random_bytes(32)),
            'presets_json' => [500, 1000, 5000], 'allowed_providers_json' => ['kopokopo'], 'copy_policy_json' => ['playful_copy' => true],
            'message_policy_json' => ['enabled' => true, 'max_len' => 140, 'sender_name' => true, 'block_contacts' => true],
            'eligibility_json' => ['require_active_listing' => true], 'limits_json' => ['per_phone_daily_amount' => 50000, 'attempts_per_10min' => 5], 'test_client_ids' => [],
        ])->fresh();
    }

    public function runtime(SendLoveSetting $s): array
    {
        return ['enabled' => $s->enabled && $s->rollout_mode !== 'off', 'rollout_mode' => $s->rollout_mode,
            'kill_switch' => $s->kill_switch || app(MonetizationSettingsService::class)->system()->send_love_kill_switch,
            'currency' => $s->currency, 'revision' => (int) $s->config_revision, 'presets' => $s->presets_json, 'default_preset' => $s->default_preset,
            'custom_min' => $s->custom_min, 'custom_max' => $s->custom_max, 'allowed_providers' => $s->allowed_providers_json,
            'copy_policy' => $s->copy_policy_json, 'message_policy' => $s->message_policy_json, 'phone_prefix' => (string) $s->platform->phone_prefix,
            'test_post_ids' => Client::where('platform_id', $s->platform_id)->whereIn('id', $s->test_client_ids ?? [])->pluck('wp_post_id')->all()];
    }

    public function assertCommerce(SendLoveSetting $s): void
    {
        $runtime = $this->runtime($s);
        abort_unless($runtime['enabled'] && ! $runtime['kill_switch'], 409, 'Send love is paused right now.');
        app(BillingModeService::class)->assertWalletAvailable($s->platform);
    }

    public function save(Platform $p, array $input, int $actor): SendLoveSetting
    {
        $d = Validator::make($input, [
            'reason' => 'required|string|min:5|max:1000', 'config_revision' => 'required|integer', 'currency' => 'required|string|size:3',
            'enabled' => 'required|boolean', 'rollout_mode' => 'required|in:off,sandbox,live', 'kill_switch' => 'required|boolean',
            'presets_json' => 'required|array|size:3', 'presets_json.*' => 'required|integer|distinct|min:1', 'default_preset' => 'required|integer',
            'custom_min' => 'required|integer|min:1', 'custom_max' => 'required|integer|gte:custom_min|max:1000000',
            'allowed_providers_json' => 'required|array|min:1', 'allowed_providers_json.*' => 'required|distinct|in:kopokopo,pawapay',
            'creator_share_bps' => 'required|integer|min:0|max:10000', 'copy_policy_json.playful_copy' => 'required|boolean',
            'message_policy_json.enabled' => 'required|boolean', 'message_policy_json.max_len' => 'required|integer|min:1|max:140',
            'message_policy_json.sender_name' => 'required|boolean', 'message_policy_json.block_contacts' => 'required|accepted',
            'eligibility_json.require_active_listing' => 'required|accepted',
            'limits_json.per_phone_daily_amount' => 'required|integer|min:1|max:1000000', 'limits_json.attempts_per_10min' => 'required|integer|min:1|max:20',
            'test_client_ids' => 'present|array', 'test_client_ids.*' => 'integer|distinct',
        ])->validate();
        abort_unless(in_array($d['default_preset'], $d['presets_json'], true), 422, 'Default amount must be a preset.');
        foreach ($d['presets_json'] as $amount) {
            abort_unless($amount >= $d['custom_min'] && $amount <= $d['custom_max'], 422, 'Presets must stay inside the amount limits.');
        }
        foreach ($d['test_client_ids'] as $id) {
            abort_unless(Client::where('platform_id', $p->id)->whereKey($id)->exists(), 422, 'Test creator belongs to another market.');
        }
        if ($d['enabled'] && $d['rollout_mode'] !== 'off') {
            foreach ($d['allowed_providers_json'] as $provider) {
                try {
                    app(BillingModeService::class)->providerContext($p, $provider, true, $d['rollout_mode'] === 'live' ? 'production' : 'sandbox', 'send_love');
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['allowed_providers_json' => [$provider.' is not enabled for this market. Enable it in Settings → Wallet System, or remove it from Send love.']]);
                }
            }
        }
        $this->forPlatform($p);

        return DB::transaction(function () use ($p, $d, $actor) {
            $reason = $d['reason'];
            $s = SendLoveSetting::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $d['config_revision'] === (int) $s->config_revision, 409, 'Settings changed. Reload before saving.');
            $before = $s->toArray();
            if ($s->currency !== $d['currency']) {
                abort_if(LoveGift::where('platform_id', $p->id)->exists(), 422, 'Currency is fixed once a gift exists.');
                abort_unless(in_array($d['currency'], app(WalletSettingsService::class)->runtimePlatformConfig($p)['supported_currencies'] ?? [$p->currency_code], true), 422, 'Choose a currency enabled for this market wallet.');
            }
            unset($d['reason'],$d['config_revision']);
            $s->fill($d);
            $s->config_revision++;
            $s->save();
            PremiumContentEvent::create(['platform_id' => $p->id, 'actor_id' => $actor, 'kind' => 'send_love_settings_changed', 'reason' => $reason, 'metadata_json' => ['before' => $before, 'after' => $s->toArray()]]);

            return $s;
        });
    }
}
