<?php

namespace Tests\Feature\Monetization;

use App\Models\Client;
use App\Models\ClientMonetizationPass;
use App\Models\ContentMonetizationSetting;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\PremiumContentAsset;
use App\Models\VisitorContentPurchase;
use App\Models\WalletTransaction;
use App\Services\BillingModeService;
use App\Services\Monetization\AccessService;
use App\Services\Monetization\ListingEligibility;
use App\Services\Monetization\OfferService;
use App\Services\Monetization\PassService;
use App\Services\Monetization\SyncService;
use App\Services\MonetizationSettingsService;
use App\Services\PaymentCompletionService;
use App\Services\PaymentMatchingService;
use App\Services\WalletService;
use App\Services\WalletSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MonetizationContractTest extends TestCase
{
    use RefreshDatabase;

    private Platform $market;

    private Client $creator;

    private ContentMonetizationSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Queue::fake();
        $this->mock(WalletSyncService::class, fn ($m) => $m->shouldReceive('syncClientBalanceById')->andReturn(['status' => 'skipped']));
        $this->market = Platform::factory()->create(['currency_code' => 'KES', 'phone_prefix' => '254']);
        $this->creator = Client::factory()->create(['platform_id' => $this->market->id, 'wp_post_id' => 112541, 'wp_user_id' => 33167, 'profile_status' => 'publish', 'lifecycle_archived_at' => null, 'closed_at' => null, 'escort_expire' => now()->addMonth()->timestamp, 'is_high_risk' => false, 'wallet_currency' => 'KES']);
        $this->settings = app(MonetizationSettingsService::class)->forPlatform($this->market);
        $this->settings->update(['enabled' => true, 'rollout_mode' => 'sandbox', 'heartbeat_at' => now(), 'readiness_json' => ['ready' => true], 'test_client_ids' => [$this->creator->id]]);
        app(MonetizationSettingsService::class)->system()->update(['enabled' => true]);
        $this->settings->prices()->create(['duration_key' => '1_month', 'duration_label' => '1 Month', 'duration_days' => 30, 'currency' => 'KES', 'price' => 500, 'subsidy_mode' => 'fixed', 'subsidy_value' => 100]);
    }

    private function asset(): PremiumContentAsset
    {
        return PremiumContentAsset::create(['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'wp_post_id' => $this->creator->wp_post_id, 'wp_attachment_id' => random_int(100, 999999), 'media_type' => 'photo', 'preview_url' => 'https://example.test/blur.jpg', 'content_fingerprint' => str_repeat('a', 64)]);
    }

    private function purchase(bool $sandbox = false): VisitorContentPurchase
    {
        $a = $this->asset();
        $payment = Payment::create(['platform_id' => $this->market->id, 'amount' => 350, 'currency' => 'KES', 'purpose' => Payment::PURPOSE_PREMIUM_CONTENT_SALE, 'provider_key' => 'kopokopo', 'provider_environment' => $sandbox ? 'sandbox' : 'production', 'source' => 'website_private_content', 'status' => 'pending']);

        return VisitorContentPurchase::create(['public_id' => (string) Str::uuid(), 'platform_id' => $this->market->id, 'client_id' => $this->creator->id, 'payment_id' => $payment->id, 'status' => 'pending_payment', 'offer_kind' => 'single', 'offer_version' => 1, 'currency' => 'KES', 'gross_amount' => 350, 'entitlement_snapshot_json' => [['public_id' => $a->public_id, 'content_fingerprint' => $a->content_fingerprint, 'media_type' => 'photo', 'preview_url' => $a->preview_url]], 'visitor_phone_hash' => hash_hmac('sha256', '254711000111', $this->settings->device_pepper), 'visitor_phone_masked' => '*******0111', 'first_device_hash' => str_repeat('b', 64), 'public_token_hash' => str_repeat('c', 64), 'idempotency_key_hash' => hash('sha256', (string) Str::uuid()), 'is_sandbox' => $sandbox]);
    }

    public function test_kopokopo_lost_callback_reconciles_premium_sale_once(): void
    {
        $purchase = $this->purchase();
        $location = 'https://api.kopokopo.com/api/v1/incoming_payments/fixture-123';
        $purchase->payment->forceFill(['transaction_reference' => $location, 'updated_at' => now()->subHours(2), 'completed_at' => null])->save();
        $this->mock(BillingModeService::class, function ($mock) {
            $mock->shouldReceive('providerContext')->once()->withArgs(fn ($p, $provider, $enabled, $environment, $surface) => $provider === 'kopokopo' && $environment === 'production' && $surface === 'premium_content')->andReturn(['provider_direct_config' => ['base_url' => 'https://api.kopokopo.com']]);
        });
        $this->mock(\App\Services\KopokopoService::class, function ($mock) use ($purchase, $location) {
            $mock->shouldReceive('paymentStatus')->once()->with($location, ['base_url' => 'https://api.kopokopo.com'])->andReturn(['metadata' => ['payment_id' => $purchase->payment_id], 'status' => 'Success', 'resourceStatus' => 'Received', 'reference' => 'MPESA-FIXTURE', 'amount' => '350.00', 'currency' => 'KES']);
        });
        $this->artisan('crm:reconcile-pending-payments', ['--delay-ms' => 0])->assertExitCode(0);
        $this->assertSame('active', $purchase->fresh()->status);
        $this->assertSame('completed', $purchase->payment->fresh()->status);
        $this->assertSame('350.00', $purchase->fresh()->creator_credit_amount);
        $this->artisan('crm:reconcile-pending-payments', ['--delay-ms' => 0])->assertExitCode(0);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'premium_content_sale')->count());
    }

    public function test_kopokopo_mismatched_status_never_fulfils_purchase(): void
    {
        $purchase = $this->purchase();
        $this->mock(\App\Services\KopokopoService::class, fn ($mock) => $mock->shouldReceive('paymentStatus')->once()->andReturn(['metadata' => ['payment_id' => $purchase->payment_id + 1], 'status' => 'Success', 'amount' => 350, 'currency' => 'KES']));
        $this->expectException(\RuntimeException::class);
        app(\App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter::class)->verify($purchase->payment, [], 'https://api.kopokopo.com/api/v1/incoming_payments/fixture');
    }

    public function test_kopokopo_status_url_cannot_send_credentials_to_another_origin(): void
    {
        $service = app(\App\Services\KopokopoService::class);
        $this->expectException(\InvalidArgumentException::class);
        $service->paymentStatus('https://example.invalid/api/v1/incoming_payments/fixture', ['base_url' => 'https://api.kopokopo.com']);
    }

    public function test_wallet_attribution_keeps_currencies_and_spending_separate(): void
    {
        $purchase = $this->purchase();
        app(PaymentCompletionService::class)->complete($purchase->payment, ['amount' => 350, 'currency' => 'KES']);
        WalletTransaction::create(['client_id' => $this->creator->id, 'platform_id' => $this->market->id, 'currency_code' => 'KES', 'type' => 'debit', 'amount' => 100, 'balance_after' => 250, 'reference_type' => 'subscription', 'description' => 'QA subscription debit', 'idempotency_key' => 'stats-fixture']);
        $totals = app(\App\Services\Monetization\StatsService::class)->ledger(WalletTransaction::where('platform_id', $this->market->id));
        $this->assertSame([['currency' => 'KES', 'earned_outstanding' => '250.00', 'earned_spent' => '100.00', 'earned_reversed' => '0.00']], $totals);
    }

    public function test_provider_replay_credits_gross_once_and_never_provisions_a_listing(): void
    {
        $p = $this->purchase();
        $service = app(PaymentCompletionService::class);
        $service->complete($p->payment, ['amount' => 350, 'currency' => 'KES', 'fee' => 10]);
        $service->complete($p->payment->fresh(), ['amount' => 350, 'currency' => 'KES', 'fee' => 10]);
        $this->assertSame('active', $p->fresh()->status);
        $this->assertSame('350.00', $p->fresh()->creator_credit_amount);
        $this->assertSame('10.00', $p->fresh()->provider_fee);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'premium_content_sale')->count());
        $this->assertSame(350.0, app(WalletService::class)->balanceFor($this->creator, 'KES'));
        $this->assertSame(0, Deal::count());
        $this->assertNull($p->payment->fresh()->client_id);
    }

    public function test_missing_short_and_wrong_currency_settlement_do_not_grant_or_credit(): void
    {
        foreach ([[], ['amount' => 349, 'currency' => 'KES'], ['amount' => 350, 'currency' => 'USD']] as $payload) {
            $p = $this->purchase();
            app(PaymentCompletionService::class)->complete($p->payment, $payload);
            $this->assertSame('review', $p->fresh()->status);
        }
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_sandbox_and_classified_tests_never_credit_live_money(): void
    {
        foreach ([true, false] as $sandbox) {
            $p = $this->purchase($sandbox);
            if (! $sandbox) {
                $p->payment->update(['record_classification' => 'test']);
            }
            app(PaymentCompletionService::class)->complete($p->payment->fresh(), ['amount' => 350, 'currency' => 'KES']);
            $this->assertSame('active', $p->fresh()->status);
            $this->assertTrue($p->fresh()->is_sandbox);
        }
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_visitor_purposes_are_never_matched_or_subscription_revenue(): void
    {
        foreach ([Payment::PURPOSE_PREMIUM_CONTENT_SALE, Payment::PURPOSE_VISITOR_CONTACT_UNLOCK] as $purpose) {
            $p = $this->purchase()->payment;
            $p->update(['purpose' => $purpose]);
            $this->assertFalse(app(PaymentMatchingService::class)->matchPayment($p)['matched']);
            $this->assertFalse(app(PaymentMatchingService::class)->dryRunMatchPayment($p)['matched']);
        }
        $this->assertSame(0, Payment::excludingWalletTopups()->count());
        $this->assertSame(0, Payment::subscriptionRevenue()->count());
        $this->assertSame(0, Payment::excludingContactUnlocks()->count());
    }

    public function test_device_slots_eviction_restore_and_refund_preserve_the_contract(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $access = app(AccessService::class);
        foreach (['a', 'b', 'c'] as $ch) {
            $this->assertTrue($access->bind($p, str_repeat($ch, 64), 3));
        }
        $this->assertFalse($access->bind($p, str_repeat('d', 64), 3));
        DB::table('premium_content_purchase_devices')->where('device_hash', str_repeat('a', 64))->update(['last_used_at' => now()->subDays(91)]);
        $this->assertTrue($access->bind($p, str_repeat('d', 64), 3));
        $this->assertCount(1, $access->entitlements($this->market, str_repeat('d', 64)));
        $p->update(['status' => 'refunded']);
        $this->assertSame('refunded', $access->entitlements($this->market, str_repeat('d', 64))[0]['status']);
    }

    public function test_phone_restore_does_not_depend_on_market_enabled_or_wallet(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $this->settings->update(['enabled' => false, 'rollout_mode' => 'off']);
        $result = app(AccessService::class)->restore($this->market, '0711000111', str_repeat('e', 64), '127.0.0.1');
        $this->assertCount(1, $result['entitlements']);
        $this->assertCount(0, app(AccessService::class)->restore($this->market, '0711000222', str_repeat('f', 64), '127.0.0.2')['entitlements']);
    }

    public function test_legacy_listing_gate_and_trial_and_seo_subsidies_are_distinct(): void
    {
        $this->assertTrue(app(ListingEligibility::class)->facts($this->creator)['listing_active']);
        $this->assertSame('500.00', app(PassService::class)->quote($this->creator, '1_month')['payable_amount']);
        foreach ([['is_free_trial' => true, 'origin' => 'subscription', 'expected' => '500.00'], ['is_free_trial' => false, 'origin' => 'seo_boost', 'expected' => '500.00'], ['is_free_trial' => false, 'origin' => 'subscription', 'expected' => '400.00']] as $case) {
            Deal::query()->delete();
            Deal::factory()->create(['client_id' => $this->creator->id, 'platform_id' => $this->market->id, 'status' => 'active', 'expires_at' => now()->addDays(7), 'is_free_trial' => $case['is_free_trial'], 'origin' => $case['origin']]);
            $this->assertSame($case['expected'], app(PassService::class)->quote($this->creator, '1_month')['payable_amount']);
        }
        $this->creator->update(['profile_status' => 'private']);
        $this->assertFalse(app(ListingEligibility::class)->facts($this->creator)['listing_active']);
    }

    public function test_pass_activation_replay_cannot_become_a_second_renewal(): void
    {
        $this->mock(BillingModeService::class, fn ($m) => $m->shouldReceive('assertWalletAvailable')->andReturn(['environment' => 'sandbox']));
        $service = app(PassService::class);
        $q = $service->quote($this->creator, '1_month');
        $input = ['duration_key' => '1_month', 'intent' => 'activate', 'expected_current_pass_id' => null, 'expected_expires_at' => null, 'expected_amount' => $q['payable_amount'], 'config_revision' => $q['config_revision']];
        $pass = $service->activate($this->creator, $input, 'attempt-1');
        $this->assertSame($pass->id, $service->activate($this->creator, $input, 'attempt-1')->id);
        $this->assertSame(1, ClientMonetizationPass::count());
        $this->assertSame(0, WalletTransaction::count());
        try {
            $service->activate($this->creator, $input, 'attempt-2');
            $this->fail('Stale activation accepted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_profile_sync_does_not_invalidate_checkout_configuration(): void
    {
        $before = $this->settings->fresh()->config_revision;
        app(SyncService::class)->profile($this->creator);

        $this->assertSame($before, $this->settings->fresh()->config_revision);
        $this->assertSame(1, $this->creator->fresh()->paid_media_revision);
    }

    public function test_owner_identity_is_bound_to_both_market_and_profile(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ListingEligibility::class)->assertOwner($this->market->id, $this->creator->wp_post_id, 999);
    }

    public function test_grants_bind_asset_fingerprint_and_device_and_reject_refunds(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $access = app(AccessService::class);
        $hash = str_repeat('f', 64);
        $access->bind($p, $hash, 3);
        $a = PremiumContentAsset::where('public_id', $p->entitlement_snapshot_json[0]['public_id'])->firstOrFail();
        $grant = $access->grant($this->settings, $a, $p, $hash);
        parse_str(parse_url($grant['delivery_url'], PHP_URL_QUERY), $query);
        [$body,$sig] = explode('.', $query['grant']);
        $this->assertSame(hash_hmac('sha256', $body, $this->settings->grant_secret), $sig);
        $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
        $this->assertSame($hash, $claims['device']);
        $this->assertSame($a->content_fingerprint, $claims['fingerprint']);
        $p->update(['status' => 'refunded']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $access->grant($this->settings, $a, $p, $hash);
    }

    public function test_sold_asset_cannot_be_deleted_but_public_copy_preserves_access(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $id = $p->entitlement_snapshot_json[0]['public_id'];
        $service = app(\App\Services\Monetization\AssetLifecycleService::class);
        try {
            $service->change($this->creator, $id, 'delete', true);
            $this->fail('Sold original deleted');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        try {
            $service->change($this->creator, $id, 'public', false);
            $this->fail('Warning skipped');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $service->change($this->creator, $id, 'public', true);
        $a = PremiumContentAsset::where('public_id', $id)->first();
        $access = app(AccessService::class);
        $hash = str_repeat('a', 64);
        $access->bind($p, $hash, 3);
        $this->assertNotEmpty($access->grant($this->settings, $a, $p, $hash)['delivery_url']);
        $this->assertSame('active', $p->fresh()->status);
    }

    public function test_bundle_edits_keep_the_purchased_asset_snapshot_and_reject_stale_versions(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $c = $this->asset();
        $service = app(OfferService::class);
        $input = ['kind' => 'bundle', 'title' => 'QA bundle', 'amount' => 600, 'assets' => [$a->public_id, $b->public_id], 'status' => 'draft'];
        $offer = $service->save($this->creator, $input, null, 'offer-attempt');
        $this->assertSame($offer->id, $service->save($this->creator, $input, null, 'offer-attempt')->id);
        $p = $this->purchase();
        $p->update(['offer_id' => $offer->id, 'offer_kind' => 'bundle', 'entitlement_snapshot_json' => [['public_id' => $a->public_id], ['public_id' => $b->public_id]], 'status' => 'active']);
        $service->save($this->creator, array_merge($input, ['version' => 1, 'assets' => [$a->public_id, $c->public_id]]), $offer);
        $this->assertSame([$a->public_id, $b->public_id], array_column($p->fresh()->entitlement_snapshot_json, 'public_id'));
        $this->assertSame(2, $offer->fresh()->version);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->save($this->creator, $input + ['version' => 1], $offer);
    }

    public function test_live_bundle_minimum_blocks_visibility_change_and_larger_bundle_is_versioned(): void
    {
        $a = $this->asset();
        $b = $this->asset();
        $c = $this->asset();
        $offer = app(OfferService::class)->save($this->creator, ['kind' => 'bundle', 'amount' => 600, 'assets' => [$a->public_id, $b->public_id], 'status' => 'draft']);
        $offer->update(['status' => 'live']);
        $service = app(\App\Services\Monetization\AssetLifecycleService::class);
        try {
            $service->change($this->creator, $a->public_id, 'public', true);
            $this->fail('Below-minimum bundle allowed');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $offer->assets()->attach($c->id, ['sort_order' => 2]);
        $service->change($this->creator, $a->public_id, 'public', true);
        $this->assertSame(2, $offer->fresh()->version);
        $this->assertSame(2, $offer->assets()->count());
    }

    public function test_asset_hold_blocks_delivery_but_creator_selling_hold_does_not(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $asset = PremiumContentAsset::where('public_id', $p->entitlement_snapshot_json[0]['public_id'])->firstOrFail();
        $access = app(AccessService::class);
        $hash = str_repeat('d', 64);
        $access->bind($p, $hash, 3);
        $this->creator->update(['is_high_risk' => true]);
        $this->assertNotEmpty($access->grant($this->settings, $asset, $p, $hash));
        $asset->update(['status' => 'held']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $access->grant($this->settings, $asset, $p, $hash);
    }

    public function test_expiry_and_queued_pass_promotion_do_not_double_activate(): void
    {
        $this->mock(BillingModeService::class, fn ($m) => $m->shouldReceive('assertWalletAvailable')->andReturn(['environment' => 'sandbox']));
        $service = app(PassService::class);
        $q = $service->quote($this->creator, '1_month');
        $input = ['duration_key' => '1_month', 'intent' => 'activate', 'expected_current_pass_id' => null, 'expected_expires_at' => null, 'expected_amount' => $q['payable_amount'], 'config_revision' => $q['config_revision']];
        $first = $service->activate($this->creator, $input, 'first');
        $next = $service->activate($this->creator, array_merge($input, ['intent' => 'renew', 'expected_current_pass_id' => $first->id, 'expected_expires_at' => $first->expires_at->toIso8601String()]), 'renew');
        $this->assertSame('queued', $next->status);
        $this->assertTrue($next->starts_at->equalTo($first->expires_at));
        $this->travel(31)->days();
        $this->artisan('monetize:expire-passes')->assertSuccessful();
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame('active', $next->fresh()->status);
        $this->assertSame(1, ClientMonetizationPass::where('active_marker', 1)->count());
        $this->assertSame(1, \App\Models\PremiumContentEvent::where('kind', 'pass_expired')->count());
    }

    public function test_local_simulator_is_not_available_in_other_environments(): void
    {
        config(['monetization.local_simulator' => true]);
        $p = $this->purchase(true);
        $r = \Illuminate\Http\Request::create('/', 'POST', ['outcome' => 'success']);
        $r->attributes->set('wallet_platform', $this->market);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Controllers\Wp\PremiumContentController::class)->simulate($r, $p->public_id);
    }

    public function test_settings_require_matching_revision_and_hide_secrets(): void
    {
        $runtime = app(MonetizationSettingsService::class)->runtime($this->settings);
        $this->assertArrayNotHasKey('grant_secret', $runtime);
        $this->assertArrayNotHasKey('device_pepper', $runtime);
        $this->assertArrayNotHasKey('grant_secret', $this->settings->toArray());
        $this->settings->update(['heartbeat_at' => now()->subMinutes(16)]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MonetizationSettingsService::class)->assertCommerce($this->settings, 'checkout');
    }

    public function test_live_settings_name_a_disabled_checkout_provider_without_saving(): void
    {
        $this->mock(BillingModeService::class, function ($mock) {
            $mock->shouldReceive('providerContext')
                ->once()
                ->andThrow(new \InvalidArgumentException('Selected provider is disabled for this market.'));
        });

        try {
            app(MonetizationSettingsService::class)->save($this->market, [
                'reason' => 'Enable paid photos',
                'config_revision' => $this->settings->fresh()->config_revision,
                'currency' => 'KES',
                'enabled' => true,
                'rollout_mode' => 'live',
                'activation_kill_switch' => false,
                'checkout_kill_switch' => false,
                'prices' => [[
                    'duration_key' => '1_month',
                    'price' => 500,
                    'subsidy_mode' => 'fixed',
                    'subsidy_value' => 100,
                    'is_active' => true,
                ]],
                'offer_policy' => $this->settings->offer_policy_json,
                'surface_policy' => $this->settings->surface_policy_json,
                'checkout_policy' => ['allowed_providers' => ['pawapay'], 'device_slots' => 3, 'restore_per_hour' => 5],
                'delivery_policy' => ['grant_ttl' => 300],
                'test_client_ids' => [],
            ], 1);
            $this->fail('Disabled providers must prevent live configuration.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'PawaPay is not enabled for this market. Enable it in Settings → Wallet System, or remove it from Surfaces & checkout.',
                $exception->errors()['checkout_policy.allowed_providers'][0]
            );
        }

        $this->assertSame(1, $this->settings->fresh()->config_revision);
    }

    public function test_refund_replay_cannot_credit_again(): void
    {
        $p = $this->purchase();
        $service = app(PaymentCompletionService::class);
        $service->complete($p->payment, ['amount' => 350, 'currency' => 'KES']);
        $p->refresh()->update(['status' => 'refunded']);
        $service->complete($p->payment->fresh(), ['amount' => 350, 'currency' => 'KES']);
        $this->assertSame('refunded', $p->fresh()->status);
        $this->assertSame(1, WalletTransaction::where('reference_type', 'premium_content_sale')->count());
    }

    public function test_sales_cannot_refund_and_other_market_manager_cannot_view(): void
    {
        $p = $this->purchase();
        $p->update(['status' => 'active']);
        $sales = \App\Models\User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->market->id]]);
        $this->actingAs($sales, 'sanctum')->postJson('/api/crm/monetization/purchases/'.$p->id.'/refund', ['reason' => 'QA refund', 'provider_reference' => 'REF-1'])->assertForbidden();
        $manager = \App\Models\User::factory()->create(['role' => 'sub_admin', 'status' => 'active', 'assigned_market_ids' => []]);
        $this->actingAs($manager, 'sanctum')->getJson('/api/crm/monetization?platform_id='.$this->market->id)->assertForbidden();
    }

    public function test_staff_refund_is_audited_and_does_not_automatically_debit(): void
    {
        $p = $this->purchase();
        app(PaymentCompletionService::class)->complete($p->payment, ['amount' => 350, 'currency' => 'KES']);
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/monetization/purchases/'.$p->id.'/refund', ['reason' => 'QA provider refund recorded', 'provider_reference' => 'REF-1'])->assertOk()->assertJsonPath('purchase.status', 'refunded');
        $this->assertSame(0, WalletTransaction::where('type', 'debit')->count());
        $this->assertDatabaseHas('premium_content_events', ['kind' => 'refund', 'purchase_id' => $p->id, 'actor_id' => $admin->id]);
    }

    public function test_staff_comp_replay_does_not_debit_or_create_a_deal(): void
    {
        $admin = \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $payload = ['action' => 'comp', 'duration_key' => '1_month', 'reason' => 'QA complimentary access', 'attempt' => (string) Str::uuid()];
        $this->actingAs($admin, 'sanctum')->postJson('/api/crm/clients/'.$this->creator->id.'/monetization/pass', $payload)->assertOk();
        $this->postJson('/api/crm/clients/'.$this->creator->id.'/monetization/pass', $payload)->assertOk();
        $this->assertSame(1, ClientMonetizationPass::count());
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, Deal::count());
        $this->assertSame(0, WalletTransaction::count());
    }

    public function test_access_hmac_still_works_with_wallet_disabled_and_tampering_is_rejected(): void
    {
        $wallet = app(\App\Services\WalletSettingsService::class);
        $wallet->rotateWpCredentials($this->market, 'sandbox', 'both');
        $pair = $wallet->wpToCrmCredentialPair($this->market, 'sandbox');
        $path = '/api/wp-svc/premium-content/entitlements';
        $body = ['session_proof' => str_repeat('a', 64)];
        $key = (string) Str::uuid();
        $time = (string) now()->timestamp;
        $json = json_encode($body);
        $headers = ['Authorization' => 'Bearer '.$pair['bearer_key'], 'X-Exotic-Platform-Id' => (string) $this->market->id, 'X-Exotic-Timestamp' => $time, 'X-Idempotency-Key' => $key, 'X-Exotic-Signature' => hash_hmac('sha256', implode("\n", [$time, 'POST', $path, (string) $this->market->id, $key, hash('sha256', $json)]), $pair['hmac_secret'])];
        $this->postJson($path, $body, $headers)->assertOk()->assertJsonPath('entitlements', []);
        $this->postJson($path, ['session_proof' => str_repeat('b', 64)], $headers)->assertUnauthorized();
    }
}
