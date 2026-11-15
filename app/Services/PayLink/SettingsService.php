<?php

namespace App\Services\PayLink;

use App\Models\{BillingMarketProviderBinding, PayLinkSetting, Platform};
use App\Services\{AuditService, BillingModeService, WpSyncFactory};
use App\Billing\Support\BillingSurface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SettingsService
{
    public function forPlatform(Platform $platform): PayLinkSetting
    {
        return PayLinkSetting::firstOrCreate(['platform_id' => $platform->id], ['rollout_mode' => 'off', 'provider_order' => ['pawapay'], 'offers_json' => []]);
    }

    public function binding(Platform $p, string $environment): BillingMarketProviderBinding
    {
        $binding = BillingMarketProviderBinding::with('providerProfile')->where('market_id', $p->id)
            ->where('billing_surface', BillingSurface::PayLink->value)->where('enabled', true)->where('self_service_enabled', true)
            ->where('execution_mode', 'direct')->whereHas('providerProfile', fn ($q) => $q->where('provider_type_key', 'pawapay')->where('environment', $environment)->where('active', true))
            ->orderBy('priority')->orderBy('id')->first();
        abort_unless($binding && ($binding->providerProfile->market_id === null || (int) $binding->providerProfile->market_id === (int) $p->id), 409, 'Payment service is unavailable. Please contact support.');
        app(BillingModeService::class)->profileBackedProviderContext($p, $binding->providerProfile, $binding->id, false, $environment, BillingSurface::PayLink);

        return $binding;
    }

    public function save(Platform $p, array $input, int $actor): PayLinkSetting
    {
        $data = Validator::make($input, [
            'rollout_mode' => 'required|in:off,test,live', 'config_revision' => 'required|integer|min:1',
            'reason' => 'required|string|min:5|max:1000', 'offers_json' => 'required|array|max:30',
            'offers_json.*.product_price_id' => 'required|integer|distinct', 'offers_json.*.featured' => 'sometimes|boolean',
            'offers_json.*.badge' => 'nullable|string|max:40', 'test_client_ids' => 'present|array|max:100',
            'test_client_ids.*' => 'integer|distinct', 'support_whatsapp' => ['nullable', 'regex:/^\+?[1-9][0-9]{7,14}$/D'],
            'switch_package_while_live' => 'sometimes|declined', 'provider_order' => 'required|array|size:1', 'provider_order.0' => 'in:pawapay',
        ])->validate();
        foreach ($data['offers_json'] as $offer) app(EligibilityService::class)->price($p, (int) $offer['product_price_id']);
        foreach ($data['test_client_ids'] as $id) abort_unless(\App\Models\Client::where('platform_id', $p->id)->whereKey($id)->exists(), 422, 'A test client belongs to another market.');
        if ($data['rollout_mode'] !== 'off') {
            abort_if(empty($data['offers_json']), 422, 'Select at least one catalogue plan.');
            $this->binding($p, $data['rollout_mode'] === 'test' ? 'sandbox' : 'production');
            $ready = app(WpSyncFactory::class)->forPlatform($p->id)->payLinkCapabilities();
            abort_unless(($ready['version'] ?? 0) === 1 && ($ready['writers_ready'] ?? false), 409, 'Install and verify the matching WordPress pay-link writer release first.');
        }
        $this->forPlatform($p);

        return DB::transaction(function () use ($p, $data, $actor) {
            $s = PayLinkSetting::where('platform_id', $p->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $s->config_revision === (int) $data['config_revision'], 409, 'Settings changed. Reload before saving.');
            $before = $s->toArray();
            $s->fill($data)->forceFill(['switch_package_while_live' => false, 'config_revision' => $s->config_revision + 1, 'updated_by_user_id' => $actor])->save();
            abort_unless(app(AuditService::class)->record(['platform_id' => $p->id, 'entity_type' => 'pay_link_settings', 'entity_id' => $s->id, 'actor_id' => $actor, 'action' => 'updated', 'reason' => $data['reason'], 'before_state' => $before, 'after_state' => $s->toArray()]), 503, 'Could not record the settings audit.');

            return $s->fresh();
        });
    }
}
