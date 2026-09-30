<?php

namespace App\Services\Monetization;

use App\Models\Client;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentEvent;
use App\Models\PremiumContentOffer;
use App\Services\MonetizationSettingsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Staff-curated bundles over ready protected items. One escort → a same-escort bundle on
 * her profile; several escorts → an Exotic collection with no single owner. Items stay
 * individually for sale; the bundle is an additional offer.
 */
class AdminBundleService
{
    public function __construct(private MonetizationSettingsService $settings, private PurchaseAllocationService $allocations) {}

    public function candidates(Platform $platform, ?string $search = null): Collection
    {
        $assets = PremiumContentAsset::with('client:id,name,wp_post_id,phone')->where('platform_id', $platform->id)->where('status', 'ready')->whereNotNull('client_id')
            ->when($search, function ($q) use ($search) {
                $term = trim($search);
                $q->whereHas('client', fn ($c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")->orWhere('id', ctype_digit($term) ? (int) $term : 0));
            })->latest('id')->limit(120)->get();
        $prices = DB::table('premium_content_offer_items')->join('premium_content_offers', 'premium_content_offers.id', '=', 'premium_content_offer_items.offer_id')
            ->whereIn('asset_id', $assets->pluck('id'))->where('premium_content_offers.kind', 'single')->whereIn('premium_content_offers.status', ['live', 'paused', 'draft'])
            ->pluck('premium_content_offers.amount', 'asset_id');

        return $assets->map(fn ($a) => ['public_id' => $a->public_id, 'media_type' => $a->media_type, 'preview_url' => $a->preview_url, 'duration_seconds' => $a->duration_seconds, 'origin' => $a->origin, 'client_id' => $a->client_id, 'creator' => $a->client?->name, 'item_price' => isset($prices[$a->id]) ? number_format((float) $prices[$a->id], 2, '.', '') : null]);
    }

    /**
     * @return array{assets: Collection, scope: string, creator_ids: array<int>, suggested: int, allocation_preview: array}
     */
    public function preview(Platform $platform, array $assetIds, ?float $amount = null): array
    {
        $s = $this->settings->forPlatform($platform);
        $policy = $s->offer_policy_json;
        $ids = array_values(array_unique($assetIds));
        abort_if(count($ids) < 2, 422, 'Choose at least two ready items.');
        abort_if(count($ids) > (int) ($policy['bundle_max_items'] ?? 12), 422, 'This bundle has more items than the market allows.');
        $assets = PremiumContentAsset::where('platform_id', $platform->id)->whereIn('public_id', $ids)->get();
        abort_unless($assets->count() === count($ids), 403, 'Every item must belong to the selected market.');
        abort_unless($assets->every(fn ($a) => $a->status === 'ready' && $a->client_id), 409, 'An item is unavailable or held. Remove it and try again.');
        foreach ($assets as $asset) {
            abort_unless($policy[$asset->media_type === 'photo' ? 'photos_enabled' : 'videos_enabled'] ?? false, 422, 'This media type is disabled.');
        }
        $ordered = collect($ids)->map(fn ($id) => $assets->firstWhere('public_id', $id));
        $creatorIds = $assets->pluck('client_id')->unique()->sort()->values()->all();
        abort_unless(Client::where('platform_id', $platform->id)->whereIn('id', $creatorIds)->count() === count($creatorIds), 403, 'A creator belongs to another market.');
        $singles = DB::table('premium_content_offer_items')->join('premium_content_offers', 'premium_content_offers.id', '=', 'premium_content_offer_items.offer_id')
            ->whereIn('asset_id', $assets->pluck('id'))->where('premium_content_offers.kind', 'single')->whereIn('premium_content_offers.status', ['live', 'paused', 'draft'])->pluck('premium_content_offers.amount', 'asset_id');
        $suggested = (int) min((int) $policy['max_price'], max((int) $policy['min_price'], (int) round($assets->sum(fn ($a) => (float) ($singles[$a->id] ?? $policy['min_price'])))));
        $gross = $amount ?? $suggested;

        return ['assets' => $ordered, 'scope' => count($creatorIds) > 1 ? 'multi_creator' : 'single_creator', 'creator_ids' => $creatorIds, 'suggested' => $suggested,
            'allocation_preview' => array_map(fn ($row) => $row + ['amount' => number_format($row['amount_minor'] / 100, 2, '.', '')], $this->allocations->split((int) round($gross * 100), $creatorIds))];
    }

    public function create(Platform $platform, array $input, int $actor, ?string $attempt = null): PremiumContentOffer
    {
        $data = Validator::make($input, ['title' => 'required|string|min:2|max:120', 'asset_public_ids' => 'required|array|min:2|max:50', 'asset_public_ids.*' => 'required|uuid|distinct', 'amount' => 'required|integer|min:1', 'scope' => 'nullable|in:single_creator,multi_creator'])->validate();
        $s = $this->settings->forPlatform($platform);
        $policy = $s->offer_policy_json;
        abort_unless($policy['bundles_enabled'] ?? false, 422, 'Bundles are disabled in this market.');
        abort_unless($data['amount'] >= (int) $policy['min_price'] && $data['amount'] <= (int) $policy['max_price'], 422, 'Enter a whole-unit price within the market limits.');

        return DB::transaction(function () use ($platform, $data, $actor, $attempt, $s) {
            $hash = $attempt ? hash('sha256', 'admin-bundle:'.$platform->id.':'.$attempt) : null;
            if ($hash && ($old = PremiumContentOffer::where('creation_attempt_hash', $hash)->first())) {
                return $old->load('assets');
            }
            $preview = $this->preview($platform, $data['asset_public_ids'], (float) $data['amount']);
            abort_if(! empty($data['scope']) && $data['scope'] !== $preview['scope'], 422, $preview['scope'] === 'single_creator' ? 'These items all belong to one escort. Publish a same-escort bundle.' : 'These items belong to several escorts. Publish an Exotic collection.');
            $single = $preview['scope'] === 'single_creator';
            $offer = PremiumContentOffer::create([
                'public_id' => (string) Str::uuid(), 'platform_id' => $platform->id, 'client_id' => $single ? $preview['creator_ids'][0] : null, 'kind' => 'bundle', 'title' => $data['title'],
                'currency' => $s->currency, 'amount' => $data['amount'], 'status' => 'live', 'version' => 1, 'is_sandbox' => $s->rollout_mode === 'sandbox', 'published_at' => now(),
                'origin' => PremiumContentOffer::ORIGIN_ADMIN_BUNDLE, 'bundle_scope' => $preview['scope'], 'creation_attempt_hash' => $hash,
                'pricing_snapshot_json' => ['suggested' => $preview['suggested'], 'creator_count' => count($preview['creator_ids']), 'actor_id' => $actor],
            ]);
            $offer->assets()->sync($preview['assets']->values()->mapWithKeys(fn ($a, $i) => [$a->id => ['sort_order' => $i]])->all());
            PremiumContentEvent::create(['platform_id' => $platform->id, 'client_id' => $offer->client_id, 'actor_id' => $actor, 'kind' => 'admin_bundle_published', 'reason' => 'Published '.($single ? 'a same-escort bundle' : 'an Exotic collection').': '.$data['title'], 'metadata_json' => ['offer_id' => $offer->id, 'creators' => $preview['creator_ids'], 'allocation_preview' => $preview['allocation_preview']]]);

            return $offer->fresh('assets');
        }, 3);
    }

    /** An escort removes her items from an Exotic collection: new sales pause, buyers keep access. */
    public function withdraw(Client $client, PremiumContentOffer $offer): PremiumContentOffer
    {
        return DB::transaction(function () use ($client, $offer) {
            $offer = PremiumContentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless($offer->platform_id === $client->platform_id && $offer->isMultiCreator() && $offer->assets()->where('premium_content_assets.client_id', $client->id)->exists(), 403, 'This collection does not include your items.');
            $offer->update(['status' => 'paused', 'paused_by' => 'member:'.$client->id, 'version' => $offer->version + 1]);
            PremiumContentEvent::create(['platform_id' => $offer->platform_id, 'client_id' => $client->id, 'kind' => 'collection_member_withdrawn', 'reason' => 'An escort removed her items from '.$offer->title.'. Remove or replace them before republishing.', 'metadata_json' => ['offer_id' => $offer->id]]);

            return $offer->fresh('assets');
        });
    }

    /** Pause every Exotic collection containing an asset that is no longer sellable. */
    public function pauseCollectionsContaining(PremiumContentAsset $asset, string $reason): void
    {
        PremiumContentOffer::where('platform_id', $asset->platform_id)->where('bundle_scope', 'multi_creator')->where('status', 'live')
            ->whereHas('assets', fn ($q) => $q->where('premium_content_assets.id', $asset->id))->get()
            ->each(function ($offer) use ($asset, $reason) {
                $offer->update(['status' => 'paused', 'paused_by' => 'member_unavailable', 'version' => $offer->version + 1]);
                PremiumContentEvent::create(['platform_id' => $offer->platform_id, 'client_id' => $asset->client_id, 'kind' => 'collection_member_unavailable', 'reason' => $reason, 'metadata_json' => ['offer_id' => $offer->id, 'asset_id' => $asset->id]]);
            });
    }

    /** Staff edits: remove/replace members of a paused collection, or retire it. */
    public function update(PremiumContentOffer $offer, array $input, int $actor): PremiumContentOffer
    {
        $data = Validator::make($input, ['title' => 'required|string|min:2|max:120', 'asset_public_ids' => 'required|array|min:2|max:50', 'asset_public_ids.*' => 'required|uuid|distinct', 'amount' => 'required|integer|min:1', 'status' => 'required|in:live,paused,retired', 'version' => 'required|integer'])->validate();
        abort_unless($offer->origin === PremiumContentOffer::ORIGIN_ADMIN_BUNDLE, 422, 'Only Exotic-created bundles can be edited here.');
        $platform = Platform::findOrFail($offer->platform_id);
        $policy = $this->settings->forPlatform($platform)->offer_policy_json;
        abort_unless($data['amount'] >= (int) $policy['min_price'] && $data['amount'] <= (int) $policy['max_price'], 422, 'Enter a whole-unit price within the market limits.');

        return DB::transaction(function () use ($offer, $data, $actor, $platform) {
            $offer = PremiumContentOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $offer->version === (int) $data['version'], 409, 'This bundle changed. Reload it before saving.');
            if ($data['status'] === 'retired') {
                $offer->update(['status' => 'retired', 'paused_by' => 'staff', 'version' => $offer->version + 1]);
            } else {
                $preview = $this->preview($platform, $data['asset_public_ids'], (float) $data['amount']);
                abort_unless($preview['scope'] === $offer->bundle_scope, 422, 'Changing between a same-escort bundle and an Exotic collection needs a new bundle.');
                abort_if($offer->bundle_scope === 'single_creator' && $preview['creator_ids'][0] !== (int) $offer->client_id, 422, 'A same-escort bundle must keep the same escort.');
                $offer->update(['title' => $data['title'], 'amount' => $data['amount'], 'status' => $data['status'], 'paused_by' => $data['status'] === 'paused' ? 'staff' : null, 'version' => $offer->version + 1, 'published_at' => $data['status'] === 'live' ? now() : $offer->published_at]);
                $offer->assets()->sync($preview['assets']->values()->mapWithKeys(fn ($a, $i) => [$a->id => ['sort_order' => $i]])->all());
            }
            PremiumContentEvent::create(['platform_id' => $offer->platform_id, 'client_id' => $offer->client_id, 'actor_id' => $actor, 'kind' => 'admin_bundle_updated', 'reason' => 'Updated '.$offer->title.' ('.$data['status'].').', 'metadata_json' => ['offer_id' => $offer->id]]);

            return $offer->fresh('assets');
        }, 3);
    }
}
