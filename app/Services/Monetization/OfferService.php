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
        if ($offer->status !== 'live' || (! $offer->client && ! $offer->isMultiCreator()) || ! $s->heartbeat_at?->gt(now()->subMinutes(15)) || data_get($s->readiness_json, 'ready') !== true) {
            return false;
        }
        $runtime = $this->settings->runtime($s);
        if (! $runtime['enabled'] || $runtime['checkout_kill_switch'] || $offer->assets->isEmpty() || ! $offer->assets->every(fn ($a) => $a->status === 'ready')) {
            return false;
        }
        if ($s->rollout_mode === 'live' ? $offer->is_sandbox : ! ($testDevice && $offer->is_sandbox)) {
            return false;
        }
        // Every earning escort must independently be able to sell the items she contributes.
        $byCreator = $offer->assets->groupBy('client_id');
        $creatorIds = $byCreator->keys()->map(fn ($id) => (int) $id)->filter()->values();
        if ($offer->isMultiCreator() ? $creatorIds->count() < 2 : $creatorIds->all() !== [(int) $offer->client_id]) {
            return false;
        }
        foreach ($byCreator as $clientId => $assets) {
            $client = $offer->client && (int) $offer->client->id === (int) $clientId ? $offer->client : \App\Models\Client::find($clientId);
            if (! $client || ! $this->creatorCanSell($client, $s, $assets->every(fn ($a) => $a->origin === PremiumContentOffer::ORIGIN_EXPIRY))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Creator-posted items need an active listing and a current selling pass. Items Exotic
     * moved when a listing expired stay for sale on any publicly reachable profile.
     */
    public function creatorCanSell(\App\Models\Client $client, ContentMonetizationSetting $s, bool $automatedOnly): bool
    {
        if (\App\Models\ClientMonetizationPass::where('client_id', $client->id)->where('status', 'held')->where('expires_at', '>', now())->exists()) {
            return false;
        }
        if ($automatedOnly) {
            return $client->profile_status === 'publish' && ! $client->closed_at && ! $client->is_high_risk;
        }
        $facts = $this->eligibility->facts($client);
        $pass = $this->passes->current($client);

        return $facts['listing_active'] && ! $facts['held'] && $pass && ($s->rollout_mode === 'live' ? ! $pass->is_sandbox : $pass->is_sandbox);
    }

    public function save(Client $client, array $input, ?PremiumContentOffer $offer = null, ?string $attempt = null): PremiumContentOffer
    {
        $data = Validator::make($input, ['kind' => 'required|in:single,bundle', 'title' => 'nullable|string|max:120', 'amount' => 'required|integer|min:1', 'assets' => 'required|array|min:1|max:50', 'assets.*' => 'required|uuid|distinct', 'version' => 'nullable|integer', 'status' => 'required|in:draft,live,paused'])->validate();

        if ($offer && $offer->origin !== PremiumContentOffer::ORIGIN_CREATOR) {
            return $this->saveManaged($client, $data, $offer);
        }

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
                abort_if(PremiumContentOffer::where('client_id', $client->id)->where('origin', PremiumContentOffer::ORIGIN_CREATOR)->where('status', 'live')->when($offer, fn ($q) => $q->where('id', '!=', $offer->id))->count() >= $p['live_offer_limit'], 422, 'Your live offer limit has been reached.');
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

    /**
     * Owner edits of offers Exotic created. Automated items: the escort may change the price
     * within market limits (the accepted default), take the item off sale (recorded as an
     * opt-out so later expiries do not re-sell it) or put it back on sale. Same-escort admin
     * bundles: she may only remove them from sale.
     */
    private function saveManaged(Client $client, array $data, PremiumContentOffer $offer): PremiumContentOffer
    {
        return DB::transaction(function () use ($client, $data, $offer) {
            Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $offer = PremiumContentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($offer->client_id === $client->id && $offer->platform_id === $client->platform_id, 403);
            abort_unless($offer->version === ($data['version'] ?? null), 409, 'This offer changed. Reload it before saving.');
            abort_if($offer->paused_by === 'staff' || $offer->status === 'held', 409, 'This offer is held by support.');
            if ($offer->origin === PremiumContentOffer::ORIGIN_ADMIN_BUNDLE) {
                abort_unless($data['status'] === 'paused', 422, 'Bundles created by Exotic can only be removed from sale.');
                $offer->update(['status' => 'paused', 'paused_by' => 'owner', 'version' => $offer->version + 1]);

                return $offer->fresh('assets');
            }
            $p = $this->settings->forPlatform($client->platform)->offer_policy_json;
            abort_unless($data['kind'] === 'single' && $data['assets'] === $offer->assets->pluck('public_id')->all(), 422, 'Automatically protected items are sold individually.');
            abort_unless($data['amount'] >= $p['min_price'] && $data['amount'] <= $p['max_price'], 422, 'Enter a whole-unit price within the market limits.');
            $live = $data['status'] === 'live';
            if ($live) {
                abort_unless($client->profile_status === 'publish' && ! $client->closed_at, 403, 'Your profile must be public to sell this item.');
            }
            $offer->update(['amount' => $data['amount'], 'status' => $live ? 'live' : 'paused', 'paused_by' => $live ? null : 'owner', 'owner_opted_out_at' => $live ? null : ($offer->owner_opted_out_at ?? now()), 'version' => $offer->version + 1, 'published_at' => $live ? now() : $offer->published_at]);
            if (! $live) {
                foreach ($offer->assets as $asset) {
                    app(AdminBundleService::class)->pauseCollectionsContaining($asset, 'An escort removed an item that is part of this collection.');
                }
            }

            return $offer->fresh('assets');
        }, 3);
    }

    public function present(PremiumContentOffer $offer): array
    {
        $multi = $offer->isMultiCreator();
        $creators = $multi ? \App\Models\Client::whereIn('id', $offer->assets->pluck('client_id')->unique())->get(['id', 'name', 'wp_post_id']) : collect();

        return ['public_id' => $offer->public_id, 'kind' => $offer->kind, 'title' => $offer->title, 'amount' => $offer->amount, 'currency' => $offer->currency, 'status' => $offer->status, 'version' => $offer->version, 'item_count' => $offer->assets->count(),
            'creator' => $multi ? null : $offer->client?->name, 'wp_post_id' => $multi ? null : $offer->client?->wp_post_id,
            'origin' => $offer->origin, 'bundle_scope' => $offer->bundle_scope, 'owner_opted_out_at' => $offer->owner_opted_out_at?->toIso8601String(), 'published_at' => $offer->published_at?->toIso8601String(),
            'creator_count' => $multi ? $creators->count() : 1, 'member_wp_post_ids' => $multi ? $creators->pluck('wp_post_id')->map(fn ($id) => (int) $id)->values()->all() : null,
            'assets' => $offer->assets->map(fn ($a) => ['public_id' => $a->public_id, 'media_type' => $a->media_type, 'preview_url' => $a->preview_url, 'duration_seconds' => $a->duration_seconds])->all()];
    }
}
