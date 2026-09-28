<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\ContentMonetizationSetting;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentOffer;
use App\Services\Kyc\KycSettingsService;
use App\Services\MonetizationSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OfferService
{
    public function __construct(private MonetizationSettingsService $settings, private ListingEligibility $eligibility, private PassService $passes) {}

    public function available(PremiumContentOffer $offer, ContentMonetizationSetting $s, bool $testDevice = false): bool
    {
        if (! $offer->client || $offer->status !== 'live' || ! $s->heartbeat_at?->gt(now()->subMinutes(15)) || data_get($s->readiness_json, 'ready') !== true) {
            return false;
        }
        $runtime = $this->settings->runtime($s);
        $facts = $this->eligibility->facts($offer->client);
        $pass = $this->passes->current($offer->client);

        return $runtime['enabled'] && ! $runtime['checkout_kill_switch'] && ! \App\Models\ClientMonetizationPass::where('client_id', $offer->client_id)->where('status', 'held')->where('expires_at', '>', now())->exists() && $facts['listing_active'] && ! $facts['held'] && $pass
            && ($s->rollout_mode === 'live' ? ! $offer->is_sandbox && ! $pass->is_sandbox : $testDevice && $offer->is_sandbox && $pass->is_sandbox)
            && $offer->assets->isNotEmpty() && $offer->assets->every(fn ($a) => $a->status === 'ready');
    }

    public function save(Client $client, array $input, ?PremiumContentOffer $offer = null, ?string $attempt = null): PremiumContentOffer
    {
        $data = Validator::make($input, ['kind' => 'required|in:single,bundle', 'title' => 'nullable|string|max:120', 'amount' => 'required|integer|min:1', 'assets' => 'required|array|min:1|max:50', 'assets.*' => 'required|uuid|distinct', 'version' => 'nullable|integer', 'status' => 'required|in:draft,live,paused'])->validate();

        return DB::transaction(function () use ($client, $data, $offer, $attempt) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $hash = $attempt ? hash('sha256', $client->platform_id.':'.$client->id.':'.$attempt) : null;
            if (! $offer && $hash && ($old = PremiumContentOffer::where('creation_attempt_hash', $hash)->first())) {
                return $old->load('assets');
            }
            $s = $this->settings->forPlatform($client->platform);
            $p = $s->offer_policy_json;
            abort_unless(($p[$data['kind'] === 'single' ? 'single_enabled' : 'bundles_enabled'] ?? false), 422, 'This offer type is disabled.');
            abort_unless($data['amount'] >= $p['min_price'] && $data['amount'] <= $p['max_price'], 422, 'Enter a whole-unit price within the market limits.');
            $count = count($data['assets']);
            abort_unless($data['kind'] === 'single' ? $count === 1 : ($count >= $p['bundle_min_items'] && $count <= $p['bundle_max_items']), 422, 'Choose the permitted number of items.');
            $assets = PremiumContentAsset::where('platform_id', $client->platform_id)->where('client_id', $client->id)->whereIn('public_id', $data['assets'])->where('status', 'ready')->get();
            abort_unless($assets->count() === $count, 409, 'An item is not ready or does not belong to you.');
            foreach ($assets as $asset) {
                abort_unless($p[$asset->media_type === 'photo' ? 'photos_enabled' : 'videos_enabled'] ?? false, 422, 'This media type is disabled.');
            }
            if ($data['status'] === 'live') {
                $this->settings->assertCommerce($s, 'checkout');
                $facts = $this->eligibility->facts($client);
                abort_unless($facts['listing_active'] && ! $facts['held'] && $this->passes->current($client), 403, 'An active listing and private content pass are required.');
                abort_if(app(KycSettingsService::class)->privateContentUploadDecision($client)['decision'] === 'block_with_verification_cta', 403, 'Verify your account before publishing private content.');
                abort_if(PremiumContentOffer::where('client_id', $client->id)->where('status', 'live')->when($offer, fn ($q) => $q->where('id', '!=', $offer->id))->count() >= $p['live_offer_limit'], 422, 'Your live offer limit has been reached.');
            }
            if ($offer) {
                $offer = PremiumContentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
                abort_unless($offer->client_id === $client->id && $offer->platform_id === $client->platform_id, 403);
                abort_unless($offer->version === ($data['version'] ?? null), 409, 'This offer changed. Reload it before saving.');
                abort_if($offer->paused_by === 'staff' || $offer->status === 'held', 409, 'This offer is held by support.');
                $offer->version++;
            } else {
                $offer = new PremiumContentOffer(['public_id' => Str::uuid(), 'platform_id' => $client->platform_id, 'client_id' => $client->id, 'version' => 1, 'creation_attempt_hash' => $hash, 'is_sandbox' => $s->rollout_mode === 'sandbox']);
            }
            $offer->fill(collect($data)->only(['kind', 'title', 'amount', 'status'])->all());
            $offer->currency = $s->currency;
            $offer->paused_by = $data['status'] === 'paused' ? 'owner' : null;
            if ($data['status'] === 'live') {
                $offer->published_at = now();
            }
            $offer->save();
            $membership = [];
            foreach ($data['assets'] as $index => $id) {
                $membership[$assets->firstWhere('public_id', $id)->id] = ['sort_order' => $index];
            }
            $offer->assets()->sync($membership);

            return $offer->fresh('assets');
        }, 3);
    }

    public function present(PremiumContentOffer $offer): array
    {
        return ['public_id' => $offer->public_id, 'kind' => $offer->kind, 'title' => $offer->title, 'amount' => $offer->amount, 'currency' => $offer->currency, 'status' => $offer->status, 'version' => $offer->version, 'item_count' => $offer->assets->count(), 'creator' => $offer->client?->name, 'wp_post_id' => $offer->client?->wp_post_id, 'assets' => $offer->assets->map(fn ($a) => ['public_id' => $a->public_id, 'media_type' => $a->media_type, 'preview_url' => $a->preview_url, 'duration_seconds' => $a->duration_seconds])->all()];
    }
}
