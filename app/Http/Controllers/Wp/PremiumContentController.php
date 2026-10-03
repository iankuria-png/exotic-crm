<?php

namespace App\Http\Controllers\Wp;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentEvent;
use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use App\Services\Kyc\KycSettingsService;
use App\Services\Monetization\AccessService;
use App\Services\Monetization\CheckoutService;
use App\Services\Monetization\ListingEligibility;
use App\Services\Monetization\OfferService;
use App\Services\Monetization\PassService;
use App\Services\Monetization\StatsService;
use App\Services\Monetization\SyncService;
use App\Services\MonetizationSettingsService;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PremiumContentController extends Controller
{
    public function __construct(private MonetizationSettingsService $settings, private AccessService $access, private PassService $passes, private OfferService $offers) {}

    private function platform(Request $r)
    {
        return $r->attributes->get('wallet_platform');
    }

    private function setting(Request $r)
    {
        return $this->settings->forPlatform($this->platform($r));
    }

    private function owner(Request $r): Client
    {
        return app(ListingEligibility::class)->assertOwner($this->platform($r)->id, (int) $r->input('wp_post_id'), (int) $r->input('wp_user_id'));
    }

    private function device(Request $r): string
    {
        return $this->access->device($this->setting($r), (string) $r->input('session_proof'));
    }

    public function config(Request $r)
    {
        $s = $this->setting($r);
        $capabilities = $r->input('capabilities', []);
        $missing = array_values(array_diff(['protected_storage', 'range_streaming', 'previews', 'media_guards'], $capabilities));
        $ready = ! $missing && version_compare((string) $r->input('plugin_version', '0'), '1.3.15', '>=') && (! data_get($s->offer_policy_json, 'videos_enabled') || $r->boolean('storage.ffmpeg')) && $r->boolean('storage_ready') && data_get($s->readiness_json, 'ready') === true;
        $report = array_merge($s->readiness_json ?? [], ['ready' => $ready, 'missing' => $missing, 'storage' => $r->input('storage'), 'checked_at' => now()->toIso8601String()]);
        $s->update(['heartbeat_at' => now(), 'wp_revision' => (int) $r->input('cached_revision', 0), 'readiness_json' => $report]);

        return response()->json(['effective' => $this->settings->runtime($s), 'readiness' => $s->readiness_json]);
    }

    public function ownerState(Request $r)
    {
        $client = $this->owner($r);
        $s = $this->setting($r);
        $decision = app(KycSettingsService::class)->privateContentUploadDecision($client);
        $facts = app(ListingEligibility::class)->facts($client);
        $pass = $this->passes->current($client);

        return response()->json(['policy' => $this->settings->runtime($s), 'listing' => $facts, 'pass' => $pass, 'free_pass' => $this->passes->claimable($client)?->only(['id', 'duration_key', 'duration_days', 'grant_source', 'created_at']), 'quotes' => $s->prices->where('is_active', true)->map(fn ($p) => $this->passes->quote($client, $p->duration_key))->values(), 'wallet' => app(WalletService::class)->summary($client), 'kyc' => $decision, 'content_permissions' => ['upload_private' => $decision['decision'], 'publish_offer' => $decision['decision']], 'assets' => PremiumContentAsset::where('client_id', $client->id)->whereNotIn('status', ['deleted', 'public'])->get(['public_id', 'wp_attachment_id', 'media_type', 'preview_url', 'status', 'origin', 'duration_seconds']), 'offers' => PremiumContentOffer::where('client_id', $client->id)->with('assets')->get()->map(fn ($o) => $this->offers->present($o)), 'notices' => PremiumContentEvent::where('client_id', $client->id)->whereIn('kind', ['sale_notice', 'pass_expiring', 'pass_expired', 'free_pass_granted', 'expiry_media_moved'])->latest()->limit(5)->get(['kind', 'reason', 'created_at']), 'stats' => app(StatsService::class)->owner($client), 'automation' => $this->automationState($client), 'collections' => $this->collections($client)]);
    }

    /** Items Exotic moved when the listing expired, for the owner's one-time explanation. */
    private function automationState(Client $client): array
    {
        $offers = PremiumContentOffer::where('client_id', $client->id)->where('origin', PremiumContentOffer::ORIGIN_EXPIRY)->whereIn('status', ['live', 'paused'])->get(['id', 'published_at', 'created_at', 'status']);

        return ['moved_count' => $offers->count(), 'live_count' => $offers->where('status', 'live')->count(), 'moved_at' => $offers->max('created_at')?->toIso8601String(), 'listing_expired' => app(\App\Services\Monetization\ExpiryAutomationService::class)->publiclyRestricted($client)];
    }

    /** Exotic collections that include this escort's items: her items, share and credited earnings only. */
    private function collections(Client $client): array
    {
        return PremiumContentOffer::where('platform_id', $client->platform_id)->where('bundle_scope', 'multi_creator')->whereIn('status', ['live', 'paused'])
            ->whereHas('assets', fn ($q) => $q->where('premium_content_assets.client_id', $client->id))->with('assets')->latest('id')->limit(20)->get()
            ->map(function ($offer) use ($client) {
                $mine = $offer->assets->where('client_id', $client->id);
                $creators = $offer->assets->pluck('client_id')->unique()->count();
                $earned = \App\Models\VisitorContentPurchaseAllocation::where('client_id', $client->id)->where('status', 'credited')->whereHas('purchase', fn ($p) => $p->where('offer_id', $offer->id)->where('is_sandbox', false))->sum('amount_minor');
                $share = app(\App\Services\Monetization\PurchaseAllocationService::class)->preview($offer, (float) $offer->amount);

                return ['public_id' => $offer->public_id, 'title' => $offer->title, 'status' => $offer->status, 'version' => $offer->version, 'amount' => $offer->amount, 'currency' => $offer->currency, 'creator_count' => $creators,
                    'your_share' => collect($share)->firstWhere('client_id', $client->id)['amount'] ?? '0.00', 'credited' => number_format($earned / 100, 2, '.', ''),
                    'your_items' => $mine->map(fn ($a) => ['public_id' => $a->public_id, 'media_type' => $a->media_type, 'preview_url' => $a->preview_url])->values()->all()];
            })->all();
    }

    public function quote(Request $r)
    {
        return response()->json(['quote' => $this->passes->quote($this->owner($r), (string) $r->input('duration_key'))]);
    }

    public function activate(Request $r)
    {
        $r->validate(['intent' => 'required|in:activate,renew,claim', 'duration_key' => 'required_unless:intent,claim|nullable|in:2_weeks,1_month']);
        $client = $this->owner($r);
        // A granted free pass is claimed through the same signed activation call; no wallet debit.
        $pass = $r->input('intent') === 'claim'
            ? $this->passes->claim($client, (string) $r->attributes->get('wallet_idempotency_key'))
            : $this->passes->activate($client, $r->all(), $r->attributes->get('wallet_idempotency_key'));
        app(SyncService::class)->profile($client);

        return response()->json(['pass' => $pass, 'wallet' => app(WalletService::class)->summary($client)]);
    }

    public function registerAsset(Request $r)
    {
        $client = $this->owner($r);
        $s = $this->setting($r);
        $this->settings->assertCommerce($s, 'checkout');
        $facts = app(ListingEligibility::class)->facts($client);
        abort_unless($facts['listing_active'] && ! $facts['held'] && $this->passes->current($client), 403, 'An active listing and pass are required.');
        abort_if(app(KycSettingsService::class)->privateContentUploadDecision($client)['decision'] === 'block_with_verification_cta', 403, 'Verify your account before uploading private content.');
        $data = $r->validate(['public_id' => 'required|uuid', 'wp_attachment_id' => 'required|integer|min:1', 'media_type' => 'required|in:photo,video', 'preview_url' => 'required|url|max:2048', 'content_fingerprint' => 'required|regex:/^[a-f0-9]{64}$/', 'duration_seconds' => 'nullable|integer|min:0|max:1800', 'teaser_url' => 'nullable|url|max:2048', 'teaser_strength' => ['nullable', \Illuminate\Validation\Rule::in(MonetizationSettingsService::TEASER_STRENGTHS)]]);
        abort_unless(data_get($s->offer_policy_json, $data['media_type'] === 'photo' ? 'photos_enabled' : 'videos_enabled'), 422);
        $hasTeaser = $data['media_type'] === 'video' && ! empty($data['teaser_url']);
        $data = array_merge($data, ['teaser_url' => $hasTeaser ? $data['teaser_url'] : null, 'teaser_strength' => $hasTeaser ? ($data['teaser_strength'] ?? null) : null, 'teaser_generated_at' => $hasTeaser ? now() : null]);
        $asset = PremiumContentAsset::firstOrCreate(['platform_id' => $client->platform_id, 'wp_attachment_id' => $data['wp_attachment_id']], $data + ['client_id' => $client->id, 'wp_post_id' => $client->wp_post_id]);
        abort_unless($asset->client_id === $client->id && $asset->content_fingerprint === $data['content_fingerprint'], 409);

        return response()->json(['asset' => $asset]);
    }

    public function saveOffer(Request $r, ?string $id = null)
    {
        $client = $this->owner($r);
        $offer = $id ? PremiumContentOffer::where('platform_id', $client->platform_id)->where('public_id', $id)->firstOrFail() : null;
        if ($offer && $offer->isMultiCreator()) {
            abort_unless($r->input('status') === 'paused', 422, 'You can remove your items from this collection.');
            $saved = app(\App\Services\Monetization\AdminBundleService::class)->withdraw($client, $offer);

            return response()->json(['offer' => $this->offers->present($saved)]);
        }
        $saved = $this->offers->save($client, $r->all(), $offer, $r->attributes->get('wallet_idempotency_key'));
        app(SyncService::class)->profile($client);

        return response()->json(['offer' => $this->offers->present($saved)]);
    }

    public function catalog(Request $r)
    {
        $s = $this->setting($r);
        $surface = $r->input('surface', 'profile');
        if (! data_get($s->surface_policy_json, ['videos' => 'videos_private_filter', 'collections' => 'home_private_content', 'profiles' => 'home_private_content'][$surface] ?? 'profile_section')) {
            return response()->json(['offers' => [], 'profiles' => []]);
        }
        if ($surface === 'profiles') {
            return response()->json($this->profilePreviews($r, $s) + ['offers' => []]);
        }
        $query = PremiumContentOffer::where('platform_id', $s->platform_id)->where('status', 'live')->with(['assets', 'client']);
        if ($surface === 'collections') {
            $query->where('bundle_scope', 'multi_creator');
        } elseif ($surface === 'videos') {
            $query->whereHas('assets', fn ($q) => $q->where('media_type', 'video'));
        } else {
            $query->whereHas('client', fn ($q) => $q->where('wp_post_id', (int) $r->input('wp_post_id')));
        }
        $page = max(1, min(10000, (int) $r->input('page', 1)));
        $perPage = $surface === 'profile' ? 200 : 12;
        $total = 0;
        $rows = [];
        $offset = ($page - 1) * $perPage;
        // Bounded batches avoid loading a market's full media registry into memory.
        foreach ($query->orderByDesc('id')->lazy(100) as $offer) {
            if (! $this->offers->available($offer, $s, $r->boolean('test_device'))) {
                continue;
            }
            if ($total >= $offset && count($rows) < $perPage) {
                $rows[] = $this->offers->present($offer);
            }
            $total++;
        }

        return response()->json(['enabled' => true, 'total' => $total, 'page' => $page, 'offers' => $rows]);
    }

    /**
     * Homepage Private content cards in one request: per profile, what is for sale, the lowest
     * price and one preview (her newest video with a card teaser, else any video, else a photo).
     */
    private function profilePreviews(Request $r, $s): array
    {
        $ids = collect((array) $r->input('wp_post_ids'))->map(fn ($id) => (int) $id)->filter()->unique()->take(60)->values();
        $clients = $ids->isEmpty() ? collect() : Client::where('platform_id', $s->platform_id)->whereIn('wp_post_id', $ids)->pluck('wp_post_id', 'id');
        $groups = [];
        if ($clients->isNotEmpty()) {
            foreach (PremiumContentOffer::where('platform_id', $s->platform_id)->where('status', 'live')->whereIn('client_id', $clients->keys())->with(['assets', 'client'])->orderByDesc('id')->get() as $offer) {
                if ($this->offers->available($offer, $s, $r->boolean('test_device'))) {
                    $groups[(int) $clients[$offer->client_id]][] = $offer;
                }
            }
        }
        $profiles = [];
        foreach ($groups as $wpPostId => $list) {
            $list = collect($list);
            $singles = $list->filter(fn ($o) => $o->kind !== 'bundle');
            $videos = $singles->filter(fn ($o) => $o->assets->first()?->media_type === 'video')->count();
            $assets = $list->flatMap(fn ($o) => $o->assets)->unique('id')->values();
            $preview = $assets->first(fn ($a) => $a->media_type === 'video' && $a->teaser_url) ?? $assets->first(fn ($a) => $a->media_type === 'video') ?? $assets->first();
            $cheapest = $list->sortBy(fn ($o) => (float) $o->amount)->first();
            $profiles[] = ['wp_post_id' => $wpPostId, 'photos' => $singles->count() - $videos, 'videos' => $videos, 'bundles' => $list->count() - $singles->count(), 'item_count' => $assets->count(),
                'from_amount' => $cheapest->amount, 'currency' => $cheapest->currency, 'preview' => $preview ? $this->offers->presentAsset($preview) : null];
        }

        return ['enabled' => true, 'profiles' => $profiles];
    }

    public function intent(Request $r)
    {
        $input = $r->validate(['offer_public_id' => 'required|uuid', 'provider_key' => 'required|string', 'visitor_phone' => 'required|string|max:30', 'session_proof' => 'required|string', 'test_device' => 'boolean']);

        return response()->json(app(CheckoutService::class)->intent($this->platform($r), $input, $r->attributes->get('wallet_idempotency_key'), $r));
    }

    public function entitlements(Request $r)
    {
        return response()->json(['entitlements' => $this->access->entitlements($this->platform($r), $this->device($r))]);
    }

    public function restore(Request $r)
    {
        $r->validate(['visitor_phone' => 'required|string|max:30']);

        return response()->json($this->access->restore($this->platform($r), $r->input('visitor_phone'), $this->device($r), (string) $r->input('visitor_ip', $r->ip())));
    }

    public function forget(Request $r)
    {
        DB::table('premium_content_purchase_devices')->where('device_hash', $this->device($r))->whereIn('purchase_id', VisitorContentPurchase::where('platform_id', $this->platform($r)->id)->select('id'))->delete();

        return response()->json(['forgotten' => true]);
    }

    public function status(Request $r, string $ref)
    {
        $p = VisitorContentPurchase::where('platform_id', $this->platform($r)->id)->where('public_id', $ref)->firstOrFail();
        $this->access->assertDevice($p, $this->device($r));
        if ($p->status === 'pending_payment' && $p->payment?->status === 'failed') {
            $p->update(['status' => 'failed']);
        }

        return response()->json($this->access->present($p));
    }

    public function grant(Request $r)
    {
        $p = VisitorContentPurchase::where('platform_id', $this->platform($r)->id)->where('public_id', $r->input('purchase_public_id'))->firstOrFail();
        $asset = PremiumContentAsset::where('platform_id', $this->platform($r)->id)->where('public_id', $r->input('asset_public_id'))->firstOrFail();

        return response()->json($this->access->grant($this->setting($r), $asset, $p, $this->device($r)));
    }

    public function report(Request $r)
    {
        $r->validate(['purchase_public_id' => 'required|uuid', 'reason' => 'required|string|min:5|max:1000']);
        $p = VisitorContentPurchase::where('platform_id', $this->platform($r)->id)->where('public_id', $r->input('purchase_public_id'))->firstOrFail();
        $this->access->assertDevice($p, $this->device($r));
        PremiumContentEvent::create(['platform_id' => $p->platform_id, 'client_id' => $p->client_id, 'purchase_id' => $p->id, 'kind' => 'buyer_report', 'reason' => $r->input('reason')]);

        return response()->json(['reported' => true]);
    }

    public function simulate(Request $r, string $ref)
    {
        abort_unless(app()->environment('local') && config('monetization.local_simulator'), 404);
        $p = VisitorContentPurchase::where('platform_id', $this->platform($r)->id)->where('public_id', $ref)->firstOrFail();
        abort_unless($p->is_sandbox && $p->payment?->isSandboxTest(), 403);
        $this->access->assertDevice($p, $this->device($r));
        $r->validate(['outcome' => 'required|in:success,failed,review']);
        if ($r->input('outcome') === 'failed') {
            abort_unless($p->status === 'pending_payment', 409);
            $p->payment->update(['status' => 'failed']);
            $p->update(['status' => 'failed']);
        } else {
            app(\App\Services\PaymentCompletionService::class)->complete($p->payment, $r->input('outcome') === 'success' ? ['amount' => $p->gross_amount, 'currency' => $p->currency] : []);
        }

        return response()->json($this->access->present($p->fresh()));
    }

    public function visibility(Request $r, string $id)
    {
        $r->validate(['action' => 'required|in:public,delete', 'confirmed' => 'boolean']);
        $client = $this->owner($r);
        $result = app(\App\Services\Monetization\AssetLifecycleService::class)->change($client, $id, $r->input('action'), $r->boolean('confirmed'));
        app(SyncService::class)->profile($client);

        return response()->json($result);
    }

    public function lifecycle(Request $r)
    {
        $client = Client::where('platform_id', $this->platform($r)->id)->where('wp_post_id', $r->input('wp_post_id'))->firstOrFail();
        $r->validate(['status' => 'required|in:publish,private,draft,trash,deleted,pending']);
        $client->update(['profile_status' => $r->input('status')]);
        if ($r->input('status') === 'deleted') {
            PremiumContentEvent::create(['platform_id' => $client->platform_id, 'client_id' => $client->id, 'kind' => 'profile_deleted', 'reason' => 'Protected purchases retained.']);
        }
        app(SyncService::class)->profile($client);

        return response()->json(['saved' => true]);
    }
}
