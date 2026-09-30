<?php

namespace Tests\Feature\Monetization;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\ContentMonetizationSetting;
use App\Models\Deal;
use App\Models\MonetizationAutomationItem;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\PremiumContentOffer;
use App\Models\VisitorContentPurchase;
use App\Models\VisitorContentPurchaseAllocation;
use App\Models\WalletTransaction;
use App\Services\BillingModeService;
use App\Services\Monetization\AdminBundleService;
use App\Services\Monetization\ComplimentaryPassService;
use App\Services\Monetization\ExpiryAutomationService;
use App\Services\Monetization\OfferService;
use App\Services\Monetization\PurchaseAllocationService;
use App\Services\Monetization\SyncService;
use App\Services\MonetizationSettingsService;
use App\Services\PaymentCompletionService;
use App\Services\WalletSyncService;
use App\Support\ClientLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpiryAutomationAndPassesTest extends TestCase
{
    use RefreshDatabase;

    private Platform $market;

    private Client $creator;

    private ContentMonetizationSetting $settings;

    private array $wpCalls = [];

    private array $wpConvert = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById')->andReturn(['status' => 'skipped']));
        $this->mock(BillingModeService::class, fn ($m) => $m->shouldReceive('assertWalletAvailable')->andReturn(['environment' => 'production']));
        $this->partialMock(SyncService::class, function ($m) {
            $m->shouldReceive('send')->andReturnUsing(function ($s, $route, $payload) {
                $this->wpCalls[] = [$route, $payload];

                return $route === '/premium-content/expiry-media-convert' ? ['status' => 'synced', 'response' => $this->wpConvert] : ['status' => 'synced', 'response' => []];
            });
        });
        $this->market = Platform::factory()->create(['currency_code' => 'KES', 'phone_prefix' => '254']);
        $this->creator = $this->creator('Beki');
        $this->settings = app(MonetizationSettingsService::class)->forPlatform($this->market);
        $this->settings->update(['enabled' => true, 'rollout_mode' => 'live', 'heartbeat_at' => now(), 'readiness_json' => ['ready' => true]]);
        app(MonetizationSettingsService::class)->system()->update(['enabled' => true]);
        $this->settings->prices()->create(['duration_key' => '1_month', 'duration_label' => '1 Month', 'duration_days' => 30, 'currency' => 'KES', 'price' => 500, 'subsidy_mode' => 'fixed', 'subsidy_value' => 100]);
        $this->settings->prices()->create(['duration_key' => '2_weeks', 'duration_label' => '2 Weeks', 'duration_days' => 14, 'currency' => 'KES', 'price' => 300, 'subsidy_mode' => 'fixed', 'subsidy_value' => 0]);
    }

    private function creator(string $name, string $lifecycle = ClientLifecycleState::EXPIRED): Client
    {
        return Client::factory()->create(['platform_id' => $this->market->id, 'name' => $name, 'client_type' => 'escort', 'wp_post_id' => random_int(1000, 999999), 'wp_user_id' => random_int(1000, 999999), 'profile_status' => 'publish', 'lifecycle_state' => $lifecycle, 'lifecycle_expired_at' => now()->subDay(), 'lifecycle_archived_at' => null, 'closed_at' => null, 'escort_expire' => now()->subDay()->timestamp, 'is_high_risk' => false, 'wallet_currency' => 'KES']);
    }

    private function policy(array $overrides = []): array
    {
        return array_replace_recursive(['enabled' => true, 'apply_to_future_expiries' => true, 'media_types' => ['video'], 'max_per_type' => ['video' => 2, 'photo' => 0], 'video_duration' => ['mode' => 'between', 'min_seconds' => 30, 'max_seconds' => 180], 'pricing' => ['mode' => 'duration_scale', 'fixed_amount' => null, 'min_amount' => 500, 'max_amount' => 1000, 'round_increment' => 50, 'photo_amount' => null]], $overrides);
    }

    private function asset(Client $client, string $origin = 'expiry_automation', string $type = 'video'): PremiumContentAsset
    {
        return PremiumContentAsset::create(['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $client->id, 'wp_post_id' => $client->wp_post_id, 'wp_attachment_id' => random_int(100, 999999), 'media_type' => $type, 'preview_url' => 'https://example.test/blur.jpg', 'duration_seconds' => 60, 'content_fingerprint' => str_repeat('a', 64), 'origin' => $origin]);
    }

    private function purchaseFor(PremiumContentOffer $offer): VisitorContentPurchase
    {
        $payment = Payment::create(['platform_id' => $this->market->id, 'amount' => $offer->amount, 'currency' => 'KES', 'purpose' => Payment::PURPOSE_PREMIUM_CONTENT_SALE, 'provider_key' => 'kopokopo', 'provider_environment' => 'production', 'source' => 'website_private_content', 'status' => 'pending']);
        $purchase = VisitorContentPurchase::create(['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $offer->client_id, 'offer_id' => $offer->id, 'payment_id' => $payment->id, 'status' => 'pending_payment', 'offer_kind' => $offer->kind, 'offer_version' => $offer->version, 'currency' => 'KES', 'gross_amount' => $offer->amount, 'entitlement_snapshot_json' => $offer->assets->map(fn ($a) => ['public_id' => $a->public_id, 'content_fingerprint' => $a->content_fingerprint, 'media_type' => $a->media_type, 'preview_url' => $a->preview_url])->all(), 'visitor_phone_hash' => str_repeat('d', 64), 'visitor_phone_masked' => '*******0111', 'first_device_hash' => str_repeat('b', 64), 'public_token_hash' => str_repeat('c', 64), 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()), 'is_sandbox' => false]);
        app(PurchaseAllocationService::class)->freeze($purchase, $offer);

        return $purchase;
    }

    public function test_fixed_and_length_scaled_prices_follow_the_rule(): void
    {
        $service = app(ExpiryAutomationService::class);
        $this->settings->update(['expiry_video_policy_json' => $this->policy()]);
        $rule = $service->rule($this->settings->fresh());
        foreach ([30 => 500, 105 => 750, 180 => 1000, 10 => 500, 999 => 1000, 70 => 650] as $seconds => $expected) {
            $this->assertSame($expected, $service->price($rule, 'video', $seconds)['amount'], "{$seconds}s");
        }
        $this->assertSame('duration_unavailable', $service->price($rule, 'video', null)['reason']);
        $fixed = $service->rule(tap($this->settings->fresh())->update(['expiry_video_policy_json' => $this->policy(['video_duration' => ['mode' => 'any'], 'pricing' => ['mode' => 'fixed', 'fixed_amount' => 450]])]));
        $this->assertFalse($fixed['duration_required']);
        $this->assertSame(450, $service->price($fixed, 'video', null)['amount']);
    }

    public function test_settings_validate_media_duration_and_price_rules(): void
    {
        $settings = app(MonetizationSettingsService::class);
        $offerPolicy = $this->settings->offer_policy_json;
        $this->assertSame(['video'], $settings->expiryPolicy($this->settings)['media_types']);
        $this->assertFalse($settings->expiryPolicy($this->settings)['enabled']);
        foreach ([
            $this->policy(['video_duration' => ['min_seconds' => 180, 'max_seconds' => 30]]),
            $this->policy(['pricing' => ['min_amount' => 1000, 'max_amount' => 500]]),
            $this->policy(['pricing' => ['max_amount' => 999999]]),
            array_merge($this->policy(), ['media_types' => []]),
        ] as $invalid) {
            try {
                $settings->validateExpiryPolicy($invalid, $offerPolicy);
                $this->fail('Invalid expiry rule accepted');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $clean = $settings->validateExpiryPolicy($this->policy(['max_per_type' => ['photo' => 4]]), $offerPolicy);
        $this->assertSame(0, $clean['max_per_type']['photo'], 'Unselected photos must not keep a stale cap');
    }

    public function test_expiry_conversion_publishes_priced_offers_once_and_sells_without_a_pass(): void
    {
        $this->settings->update(['expiry_video_policy_json' => $this->policy()]);
        $this->wpConvert = ['converted' => [
            ['public_id' => (string) Str::uuid(), 'wp_attachment_id' => 7781, 'media_type' => 'video', 'duration_seconds' => 30, 'preview_url' => 'https://example.test/p1.jpg', 'content_fingerprint' => str_repeat('e', 64)],
            ['public_id' => (string) Str::uuid(), 'wp_attachment_id' => 7782, 'media_type' => 'video', 'duration_seconds' => 180, 'preview_url' => 'https://example.test/p2.jpg', 'content_fingerprint' => str_repeat('f', 64)],
        ], 'skipped' => [['wp_attachment_id' => 7783, 'reason' => 'duration_outside_rule']]];
        $service = app(ExpiryAutomationService::class);
        $item = $service->queueNaturalExpiry($this->creator->fresh());
        $this->assertNotNull($item);
        $service->process($item);
        $this->assertSame('succeeded', $item->fresh()->status);
        $offers = PremiumContentOffer::where('client_id', $this->creator->id)->orderBy('amount')->get();
        $this->assertSame(['500.00', '1000.00'], $offers->pluck('amount')->all());
        $this->assertTrue($offers->every(fn ($o) => $o->origin === 'expiry_automation' && $o->status === 'live' && $o->pricing_snapshot_json['mode'] === 'duration_scale'));
        $this->assertNull(app(\App\Services\Monetization\PassService::class)->current($this->creator));
        $this->assertTrue(app(OfferService::class)->available($offers[0]->load('assets', 'client'), $this->settings->fresh()));
        [$route, $payload] = collect($this->wpCalls)->firstWhere(0, '/premium-content/expiry-media-convert');
        $this->assertSame(['video'], $payload['media_types']);
        $this->assertSame(['mode' => 'between', 'min_seconds' => 30, 'max_seconds' => 180], $payload['video_duration']);

        // A replay of the same WordPress result never duplicates offers or assets.
        $replay = MonetizationAutomationItem::create(['platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'kind' => 'expiry_media', 'operation_key' => 'replay', 'rule_json' => $item->rule_json]);
        $service->process($replay);
        $this->assertSame(2, PremiumContentOffer::where('client_id', $this->creator->id)->count());
        $this->assertSame(2, PremiumContentAsset::where('client_id', $this->creator->id)->count());

        // A renewed or private profile is not automated.
        $this->creator->update(['lifecycle_state' => ClientLifecycleState::ACTIVE]);
        $again = MonetizationAutomationItem::create(['platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'kind' => 'expiry_media', 'operation_key' => 'renewed', 'rule_json' => $item->rule_json]);
        $service->process($again);
        $this->assertSame('profile_not_restricted', $again->fresh()->result_code);
    }

    public function test_owner_price_edit_and_opt_out_are_respected_by_later_runs(): void
    {
        $this->settings->update(['expiry_video_policy_json' => $this->policy()]);
        $asset = $this->asset($this->creator);
        $offer = PremiumContentOffer::create(['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'kind' => 'single', 'currency' => 'KES', 'amount' => 500, 'status' => 'live', 'origin' => 'expiry_automation', 'origin_key' => 'expiry:'.$this->creator->wp_post_id.':'.$asset->wp_attachment_id]);
        $offer->assets()->sync([$asset->id => ['sort_order' => 0]]);
        $service = app(OfferService::class);
        $edited = $service->save($this->creator, ['kind' => 'single', 'amount' => 800, 'assets' => [$asset->public_id], 'status' => 'live', 'version' => 1], $offer);
        $this->assertSame('800.00', $edited->amount);
        $removed = $service->save($this->creator, ['kind' => 'single', 'amount' => 800, 'assets' => [$asset->public_id], 'status' => 'paused', 'version' => 2], $edited);
        $this->assertNotNull($removed->owner_opted_out_at);

        $this->wpConvert = ['converted' => [['public_id' => $asset->public_id, 'wp_attachment_id' => $asset->wp_attachment_id, 'media_type' => 'video', 'duration_seconds' => 60, 'preview_url' => 'https://example.test/p.jpg', 'content_fingerprint' => $asset->content_fingerprint]], 'skipped' => []];
        $item = MonetizationAutomationItem::create(['platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'kind' => 'expiry_media', 'operation_key' => 'later', 'rule_json' => app(ExpiryAutomationService::class)->rule($this->settings->fresh())]);
        app(ExpiryAutomationService::class)->process($item);
        [, $payload] = collect($this->wpCalls)->firstWhere(0, '/premium-content/expiry-media-convert');
        $this->assertSame([$asset->wp_attachment_id], $payload['exclude_attachment_ids']);
        $this->assertSame('paused', $offer->fresh()->status);
        $this->assertSame(1, PremiumContentOffer::where('client_id', $this->creator->id)->count());
    }

    public function test_multi_escort_collection_credits_exact_equal_shares_once(): void
    {
        $zari = $this->creator('Zari');
        $kelly = $this->creator('Kelly');
        $assets = [$this->asset($kelly), $this->asset($kelly), $this->asset($zari), $this->asset($this->creator)];
        $offer = app(AdminBundleService::class)->create($this->market, ['title' => 'After Dark Collection', 'asset_public_ids' => collect($assets)->pluck('public_id')->all(), 'amount' => 1000, 'scope' => 'multi_creator'], 1);
        $this->assertNull($offer->client_id);
        $this->assertTrue(app(OfferService::class)->available($offer->load('assets'), $this->settings->fresh()));
        $purchase = $this->purchaseFor($offer);
        app(PaymentCompletionService::class)->complete($purchase->payment, ['amount' => 1000, 'currency' => 'KES']);
        app(PaymentCompletionService::class)->complete($purchase->payment->fresh(), ['amount' => 1000, 'currency' => 'KES']);
        $ids = collect([$this->creator->id, $zari->id, $kelly->id])->sort()->values();
        $rows = VisitorContentPurchaseAllocation::where('purchase_id', $purchase->id)->orderBy('client_id')->get();
        $this->assertSame($ids->all(), $rows->pluck('client_id')->all());
        $this->assertSame([33334, 33333, 33333], $rows->pluck('amount_minor')->all());
        $this->assertSame(['credited'], $rows->pluck('status')->unique()->values()->all());
        $this->assertSame(3, WalletTransaction::where('reference_type', 'premium_content_sale')->count());
        $this->assertSame('1000.00', number_format((float) WalletTransaction::where('reference_type', 'premium_content_sale')->sum('amount'), 2, '.', ''));
        $this->assertSame('active', $purchase->fresh()->status);
        $this->assertSame('1000.00', $purchase->fresh()->gross_amount);
        $this->assertSame('333.34', app(\App\Services\Monetization\StatsService::class)->owner(Client::find($ids[0]))['month_sales']);

        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/monetization/purchases/'.$purchase->id.'/refund', ['reason' => 'QA provider refund recorded', 'provider_reference' => 'REF-9'])->assertOk();
        $this->assertSame(['reversed'], VisitorContentPurchaseAllocation::where('purchase_id', $purchase->id)->pluck('status')->unique()->values()->all());
        $this->assertSame([33334, 33333, 33333], VisitorContentPurchaseAllocation::where('purchase_id', $purchase->id)->orderBy('client_id')->pluck('amount_minor')->all());
    }

    public function test_same_escort_admin_bundle_credits_full_amount_and_owner_may_only_remove_it(): void
    {
        $assets = [$this->asset($this->creator), $this->asset($this->creator)];
        $this->expectsWrongScope($assets);
        $offer = app(AdminBundleService::class)->create($this->market, ['title' => 'Beki selection', 'asset_public_ids' => collect($assets)->pluck('public_id')->all(), 'amount' => 900], 1);
        $this->assertSame('single_creator', $offer->bundle_scope);
        $this->assertSame($this->creator->id, $offer->client_id);
        $purchase = $this->purchaseFor($offer->load('assets'));
        app(PaymentCompletionService::class)->complete($purchase->payment, ['amount' => 900, 'currency' => 'KES']);
        $this->assertSame([90000], VisitorContentPurchaseAllocation::where('purchase_id', $purchase->id)->pluck('amount_minor')->all());
        try {
            app(OfferService::class)->save($this->creator, ['kind' => 'bundle', 'title' => 'Mine now', 'amount' => 100, 'assets' => collect($assets)->pluck('public_id')->all(), 'status' => 'live', 'version' => 1], $offer);
            $this->fail('Owner edited an Exotic bundle');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $removed = app(OfferService::class)->save($this->creator, ['kind' => 'bundle', 'amount' => 900, 'assets' => collect($assets)->pluck('public_id')->all(), 'status' => 'paused', 'version' => 1], $offer);
        $this->assertSame('paused', $removed->status);
    }

    private function expectsWrongScope(array $assets): void
    {
        try {
            app(AdminBundleService::class)->create($this->market, ['title' => 'Wrong', 'asset_public_ids' => collect($assets)->pluck('public_id')->all(), 'amount' => 900, 'scope' => 'multi_creator'], 1);
            $this->fail('Wrong bundle scope accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
    }

    public function test_held_or_withdrawn_member_pauses_the_collection_without_revoking_buyers(): void
    {
        $zari = $this->creator('Zari');
        $mine = $this->asset($this->creator);
        $offer = app(AdminBundleService::class)->create($this->market, ['title' => 'Pair', 'asset_public_ids' => [$mine->public_id, $this->asset($zari)->public_id], 'amount' => 800], 1);
        $purchase = $this->purchaseFor($offer->load('assets'));
        app(PaymentCompletionService::class)->complete($purchase->payment, ['amount' => 800, 'currency' => 'KES']);
        app(AdminBundleService::class)->withdraw($this->creator, $offer);
        $this->assertSame('paused', $offer->fresh()->status);
        $this->assertFalse(app(OfferService::class)->available($offer->fresh()->load('assets'), $this->settings->fresh()));
        $this->assertSame('active', $purchase->fresh()->status);

        $offer->fresh()->update(['status' => 'live', 'paused_by' => null]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/monetization/assets/'.$mine->id.'/hold', ['reason' => 'QA hold review', 'held' => true])->assertOk();
        $this->assertSame('member_unavailable', $offer->fresh()->paused_by);
        $this->assertTrue(collect($this->app->make(\App\Http\Controllers\CRM\MonetizationController::class)->index(tap(\Illuminate\Http\Request::create('/api/crm/monetization', 'GET', ['platform_id' => $this->market->id]))->setUserResolver(fn () => $admin))->getData(true)['content'])->firstWhere('id', $offer->id)['needs_attention']);
    }

    public function test_free_pass_waits_to_be_claimed_then_queues_after_paid_time(): void
    {
        $this->creator->update(['lifecycle_state' => ClientLifecycleState::ACTIVE, 'escort_expire' => now()->addMonth()->timestamp]);
        $paid = ClientMonetizationPass::create(['client_id' => $this->creator->id, 'platform_id' => $this->market->id, 'status' => 'active', 'active_marker' => 1, 'starts_at' => now()->subDays(4), 'expires_at' => now()->addDays(10), 'duration_key' => '2_weeks', 'duration_days' => 14, 'currency' => 'KES', 'list_amount' => 300, 'subsidy_amount' => 0, 'paid_amount' => 300, 'eligibility_snapshot_json' => [], 'idempotency_key_hash' => str_repeat('1', 64)]);
        $service = app(ComplimentaryPassService::class);
        $passes = app(\App\Services\Monetization\PassService::class);
        $first = $service->grant($this->creator, '1_month', 'campaign_selected', 'fixture');
        $this->assertSame('granted', $first['status']);
        $this->assertSame('granted', $first['pass']->status);
        $this->assertSame($paid->id, $passes->current($this->creator)->id, 'An unclaimed grant never becomes the current pass');
        $this->assertSame($first['pass']->id, $passes->claimable($this->creator)->id);
        $this->assertSame('already_granted', $service->grant($this->creator, '1_month', 'campaign_selected', 'fixture')['status']);

        $claimed = $passes->claim($this->creator, 'claim-1');
        $this->assertSame('queued', $claimed->status);
        $this->assertTrue($claimed->starts_at->equalTo($paid->fresh()->expires_at));
        $this->assertTrue($claimed->expires_at->equalTo($paid->fresh()->expires_at->copy()->addDays(30)));
        $this->assertNotNull($claimed->claimed_at);
        $this->assertSame('0.00', $claimed->paid_amount);
        $this->assertSame('300.00', $paid->fresh()->paid_amount);
        $this->assertSame($claimed->id, $passes->claim($this->creator, 'claim-1')->id, 'A replayed claim returns the same pass');
        $this->assertNull($passes->claimable($this->creator));
        try {
            $passes->claim($this->creator, 'claim-2');
            $this->fail('Claimed a pass that was not granted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(2, ClientMonetizationPass::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_claim_starts_the_term_now_and_needs_an_active_listing(): void
    {
        $service = app(ComplimentaryPassService::class);
        $passes = app(\App\Services\Monetization\PassService::class);
        $service->grant($this->creator, '2_weeks', 'campaign_selected', 'expired-escort');
        try {
            $passes->claim($this->creator, 'too-early');
            $this->fail('An expired listing claimed a free pass');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame('granted', $passes->claimable($this->creator)->status);
        $this->creator->update(['lifecycle_state' => ClientLifecycleState::ACTIVE, 'escort_expire' => now()->addMonth()->timestamp]);
        $this->travel(5)->days();
        $claimed = $passes->claim($this->creator, 'after-renewal');
        $this->assertSame('active', $claimed->status);
        $this->assertSame(1, $claimed->active_marker);
        $this->assertTrue($claimed->starts_at->isSameMinute(now()));
        $this->assertTrue($claimed->expires_at->isSameMinute(now()->addDays(14)), 'Days waiting before the claim are not lost');
    }

    public function test_new_subscription_grants_once_and_renewal_or_trial_does_not(): void
    {
        $this->settings->update(['free_pass_policy_json' => ['new_subscriptions_enabled' => true, 'duration_key' => '2_weeks', 'effective_from' => now()->subHour()->toIso8601String()]]);
        $this->creator->update(['lifecycle_state' => ClientLifecycleState::ACTIVE]);
        $product = \App\Models\Product::factory()->create(['platform_id' => $this->market->id]);
        $deal = fn (array $attrs) => Deal::factory()->create($attrs + ['product_id' => $product->id, 'client_id' => $this->creator->id, 'platform_id' => $this->market->id, 'status' => 'active', 'activated_at' => now(), 'expires_at' => now()->addMonth(), 'is_free_trial' => false, 'origin' => 'subscription']);
        $new = $deal(['subscription_lifecycle' => 'new']);
        $service = app(ComplimentaryPassService::class);
        $this->assertSame('granted', $service->grantForNewSubscription($new->id));
        $this->assertSame('already_granted', $service->grantForNewSubscription($new->id));
        $this->assertNull($service->grantForNewSubscription($deal(['subscription_lifecycle' => 'renewal'])->id));
        $this->assertNull($service->grantForNewSubscription($deal(['subscription_lifecycle' => 'new', 'is_free_trial' => true])->id));
        $this->assertNull($service->grantForNewSubscription($deal(['subscription_lifecycle' => 'new', 'activated_at' => now()->subDays(2)])->id));
        $pass = ClientMonetizationPass::sole();
        $this->assertSame('granted', $pass->status);
        $this->assertSame('policy_new_subscription', $pass->grant_source);
        $this->assertSame($new->id, $pass->source_deal_id);
        $this->assertTrue($pass->isComplimentary());
    }

    public function test_selected_grants_respect_market_scope_and_backfill_runs_track_retries(): void
    {
        $other = Client::factory()->create(['platform_id' => Platform::factory()->create()->id]);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/free-passes', ['scope' => 'selected', 'duration_key' => '1_month', 'client_ids' => [$other->id]])->assertForbidden();
        $this->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/free-passes', ['scope' => 'selected', 'duration_key' => '1_month', 'client_ids' => [$this->creator->id]])->assertOk()->assertJsonPath('estimated_recipients', 1);

        $this->settings->update(['expiry_video_policy_json' => $this->policy()]);
        $this->creator('Zari');
        $this->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/expired-content', ['scope' => 'currently_expired', 'settings_revision' => 999])->assertStatus(409);
        $response = $this->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/expired-content', ['scope' => 'currently_expired', 'settings_revision' => $this->settings->fresh()->config_revision])->assertOk()->assertJsonPath('estimated_profiles', 2);
        $run = \App\Models\MonetizationAutomationRun::where('public_id', $response->json('run_id'))->firstOrFail();
        $service = app(ExpiryAutomationService::class);
        [$failing, $passing] = $run->items()->orderBy('id')->get()->all();
        $service->finish($failing, 'failed', 'wordpress_unavailable');
        $this->wpConvert = ['converted' => [], 'skipped' => [['wp_attachment_id' => 1, 'reason' => 'no_eligible_media']]];
        $service->process($passing);
        $this->assertSame('completed_with_errors', $run->fresh()->status);
        $this->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/runs/'.$run->public_id.'/retry')->assertOk()->assertJsonPath('retried', 1);
        $this->assertSame(['failed' => 0, 'skipped' => 1, 'status' => 'running'], ['failed' => $run->fresh()->failed_count, 'skipped' => $run->fresh()->skipped_count, 'status' => $run->fresh()->status]);
    }

    public function test_active_subscription_passes_grant_in_batches_without_overlap(): void
    {
        $active = collect(range(1, 52))->map(fn ($i) => tap($this->creator('Active '.$i, ClientLifecycleState::ACTIVE))->update(['escort_expire' => now()->addMonth()->timestamp]));
        $expired = $this->creator('Lapsed');
        $service = app(ComplimentaryPassService::class);
        $this->assertSame(52, $service->activeSubscriptionsRemaining($this->market)->count());

        $first = $service->startCampaign($this->market, 'active_subscriptions', '1_month', [], 1, 50);
        $this->assertSame(50, $first->total_count);
        $this->assertSame($active->pluck('id')->sort()->take(50)->values()->all(), $first->items()->orderBy('client_id')->pluck('client_id')->all());
        $this->assertSame(50, $first->rule_json['batch_size']);
        // Escorts waiting in a running batch are not picked again.
        $this->assertSame(2, $service->activeSubscriptionsRemaining($this->market)->count());
        foreach ($first->items as $item) {
            $service->processItem($item);
        }
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame(50, ClientMonetizationPass::where('grant_source', 'campaign_active_subscription')->count());

        $second = $service->startCampaign($this->market, 'active_subscriptions', '1_month', [], 1, 100);
        $this->assertSame(2, $second->total_count);
        $this->assertNotContains($expired->id, $second->items()->pluck('client_id')->all());
        foreach ($second->items as $item) {
            $service->processItem($item);
        }
        $this->assertSame(0, $service->activeSubscriptionsRemaining($this->market)->count());
        $this->assertSame('already_granted', $service->grant($active->first(), '1_month', 'campaign_active_subscription', 'another-run')['status']);
        $this->assertSame(52, ClientMonetizationPass::where('grant_source', 'campaign_active_subscription')->count());
        try {
            $service->startCampaign($this->market, 'active_subscriptions', '1_month', [], 1, 50);
            $this->fail('An empty batch started');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/settings/monetization/markets/'.$this->market->id.'/automations/free-passes', ['scope' => 'active_subscriptions', 'duration_key' => '1_month', 'batch_size' => 75])->assertUnprocessable();
    }

    public function test_claim_migration_returns_unused_grants_and_keeps_used_or_paid_passes(): void
    {
        $make = fn (Client $client, string $source, float $paid, string $key) => ClientMonetizationPass::create(['client_id' => $client->id, 'platform_id' => $this->market->id, 'status' => 'active', 'active_marker' => 1, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDays(20), 'duration_key' => '1_month', 'duration_days' => 30, 'currency' => 'KES', 'list_amount' => 500, 'subsidy_amount' => 500 - $paid, 'paid_amount' => $paid, 'eligibility_snapshot_json' => [], 'idempotency_key_hash' => str_repeat($key, 64), 'grant_source' => $source]);
        $unused = $make($this->creator, 'campaign_active_subscription', 0, '2');
        $user = $this->creator('Uploader', ClientLifecycleState::ACTIVE);
        $this->asset($user, 'creator', 'photo');
        $used = $make($user, 'campaign_selected', 0, '3');
        $payer = $this->creator('Payer', ClientLifecycleState::ACTIVE);
        $paid = $make($payer, 'wallet', 400, '4');
        DB::table('client_monetization_passes')->update(['claimed_at' => null]);
        (require database_path('migrations/2026_10_01_100000_add_claim_step_to_complimentary_passes.php'))->up();
        $this->assertSame(['granted', null, null], [$unused->fresh()->status, $unused->fresh()->active_marker, $unused->fresh()->claimed_at]);
        $this->assertSame('active', $used->fresh()->status);
        $this->assertNotNull($used->fresh()->claimed_at);
        $this->assertSame(['active', null], [$paid->fresh()->status, $paid->fresh()->claimed_at]);
    }
}
