<?php

namespace Tests\Feature\Collections;

use App\Models\ContentMonetizationSetting;
use App\Models\ContentMonetizationSystemSetting;
use App\Services\Collections\CollectionEntitlementPolicy;
use App\Services\Collections\CollectionException;
use App\Services\Monetization\SyncService;
use App\Services\SubscriptionProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CollectionExactContractTest extends CollectionWorkflowTestCase
{
    public function test_canonical_key_ignores_stale_auxiliary_expiry_in_target_and_cached_profile(): void
    {
        [, , $client] = $this->purchase(false);
        $snapshot = $this->snapshot(77, 55, now()->addDays(3)->timestamp, 'vip');
        $snapshot['post_type'] = 'custom_profile_slug';
        $snapshot['meta']['agency_expire'] = (string) now()->addDays(80)->timestamp;
        $policy = app(CollectionEntitlementPolicy::class);
        $target = $policy->target($snapshot, ['wp_post_id' => 77, 'wp_user_id' => 55, 'tier' => 'vip', 'duration_days' => 7]);
        $this->assertSame((int) $snapshot['meta']['escort_expire'] + 7 * 86400, $target['target_expiry']);
        app(SubscriptionProvisioningService::class)->cacheVerifiedCollectionSnapshot($client->id, $snapshot);
        $this->assertSame((int) $snapshot['meta']['escort_expire'], (int) $client->fresh()->escort_expire);
    }

    public function test_uncertain_or_mismatched_canonical_key_is_refused(): void
    {
        $snapshot = $this->snapshot(77, 55);
        $snapshot['expiry_meta_key'] = 'agency_expire';
        $this->expectException(CollectionException::class);
        $this->expectExceptionMessage('canonical subscription snapshot required');
        app(CollectionEntitlementPolicy::class)->expiry($snapshot);
    }

    public function test_indefinite_active_tier_cannot_be_replaced_by_finite_coverage(): void
    {
        $snapshot = $this->snapshot(77, 55, 0, 'premium');
        $snapshot['post_status'] = 'publish';
        $this->expectException(CollectionException::class);
        $this->expectExceptionMessage('indefinite active coverage requires review');
        app(CollectionEntitlementPolicy::class)->target($snapshot, ['wp_post_id' => 77, 'wp_user_id' => 55, 'tier' => 'premium', 'duration_days' => 7]);
    }

    public function test_managed_visitor_and_agency_restricted_profiles_are_held(): void
    {
        foreach (['exotic_tour_visitor', 'needs_ag_payment'] as $key) {
            $snapshot = $this->snapshot(77, 55);
            $snapshot['meta'][$key] = '1';
            try {
                app(CollectionEntitlementPolicy::class)->target($snapshot, ['wp_post_id' => 77, 'wp_user_id' => 55, 'tier' => 'vip', 'duration_days' => 7]);
                $this->fail('Managed ownership was not refused.');
            } catch (CollectionException $e) {
                $this->assertSame('subscription_ownership_requires_review', $e->reason);
            }
        }
    }

    public function test_configured_sync_never_creates_absent_global_or_market_settings(): void
    {
        [, , $client] = $this->purchase(false);
        Http::fake();
        DB::table('content_monetization_system_settings')->delete();
        $before = ContentMonetizationSetting::count();
        $this->assertSame('skipped', app(SyncService::class)->configuredSubscriptionProfile($client)['status']);
        $this->assertSame(0, ContentMonetizationSystemSetting::count());
        $this->assertSame($before, ContentMonetizationSetting::count());
        ContentMonetizationSystemSetting::create(['id' => 1, 'enabled' => true]);
        $this->assertSame('skipped', app(SyncService::class)->configuredSubscriptionProfile($client)['status']);
        $this->assertSame($before, ContentMonetizationSetting::count());
        Http::assertNothingSent();
    }

    public function test_returned_failed_monetization_sync_remains_pending_and_retries_after_confirmation(): void
    {
        [$account, $device, $client, , $actor, $price] = $this->purchase(false);
        $account->update(['mode' => 'shadow']);
        $receipt = $this->received($device);
        config(['collection_sales.verified_accounts' => [$account->id], 'collection_sales.verified_platforms' => [101]]);
        $this->fakeWordPress();
        $input = ['receipt_version' => $receipt->version, 'session_version' => 0, 'request_key' => (string) \Illuminate\Support\Str::uuid(),
            'items' => [['client_id' => $client->id, 'product_price_id' => $price->id]]];
        $input['preview_token'] = app(\App\Services\Collections\CollectionSalesService::class)->preview($receipt, $actor, $input)['preview_token'];
        $fulfillment = app(\App\Services\Collections\CollectionSalesFulfillmentService::class);
        $fulfillment->prepare($receipt, $actor, $input);
        $item = \App\Models\Collections\CollectionSalesItem::firstOrFail();
        $fulfillment->resume($item->id);
        ContentMonetizationSystemSetting::updateOrCreate(['id' => 1], ['enabled' => true]);
        ContentMonetizationSetting::create(['platform_id' => 101, 'enabled' => true, 'rollout_mode' => 'production', 'currency' => 'TZS', 'grant_secret' => str_repeat('g',32), 'device_pepper' => str_repeat('d',32), 'free_pass_policy_json' => ['new_subscriptions_enabled' => false]]);
        $sync = \Mockery::mock(SyncService::class);
        $sync->shouldReceive('configuredSubscriptionProfile')->twice()->andReturn(['status' => 'failed'], ['status' => 'synced']);
        $this->app->instance(SyncService::class, $sync);
        DB::table('collection_outbox')->where('kind', '!=', 'sales_benefits')->update(['completed_at' => now()]);
        $worker = app(\App\Services\Collections\CollectionOutboxWorker::class);
        $this->assertSame(1, $worker->run()['held']);
        $row = DB::table('collection_outbox')->where('kind', 'sales_benefits')->sole();
        $this->assertNull($row->completed_at);
        $this->assertSame('sale_monetization_sync_pending', $row->last_error_code);
        DB::table('collection_outbox')->where('id', $row->id)->update(['available_at' => now()]);
        $this->assertSame(1, $worker->run()['done']);
        $this->assertNotNull(DB::table('collection_outbox')->where('id', $row->id)->value('completed_at'));
        $this->assertSame(1, $this->posts);
    }
}
