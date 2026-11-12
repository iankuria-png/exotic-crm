<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionPayerLink;
use App\Models\Collections\CollectionPurchaseIntent;
use App\Models\Collections\CollectionReceipt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\User;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionPrivacy;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class CollectionWorkflowTestCase extends CollectionTestCase
{
    protected array $remote = [];

    protected array $operations = [];

    protected array $siteOwners = [];

    protected int $posts = 0;

    protected bool $loseResponse = false;

    protected bool $compensationV2 = false;

    protected ?int $unavailablePost = null;

    protected function purchase(bool $enable = true, ?int $platformId = null): array
    {
        [$account, $device] = $this->inbox();
        $user = User::factory()->create(['role' => 'admin']);
        $market = Platform::factory()->create(array_filter(['id' => $platformId ?? 101, 'currency_code' => 'TZS', 'phone_prefix' => '255',
            'wp_api_url' => 'https://collection-fixture.test/wp-json/exotic-crm-sync/v1', 'wp_api_user' => 'fixture', 'wp_api_password' => 'fixture']));
        $client = Client::factory()->create(['platform_id' => $market->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'is_high_risk' => false, 'assigned_to' => $user->id]);
        $product = Product::factory()->create(['platform_id' => $market->id, 'tier' => 'vip', 'name' => 'VIP', 'currency' => 'TZS', 'is_active' => true, 'is_archived' => false]);
        $price = ProductPrice::create(['product_id' => $product->id, 'duration_key' => 'custom_8', 'duration_label' => '8 days', 'duration_days' => 8, 'price' => '35000.00', 'currency' => 'TZS', 'is_active' => true]);
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $market->id, 'destination_snapshot' => json_encode(['fixture' => true]), 'valid_from' => now()->subDays(100)]);
        $link = CollectionPayerLink::create(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'payer_phone' => '255700000001',
            'payer_fingerprint' => app(CollectionPrivacy::class)->fingerprint('255700000001'), 'client_id' => $client->id, 'wp_post_id' => 77, 'wp_user_id' => 55,
            'auto_allowed' => true, 'evidence_receipt_ids' => [], 'reviewed_by' => $user->id, 'reviewed_at' => now()->subDays(3), 'valid_until' => now()->addMonth()]);
        $snapshot = ['client_id' => $client->id, 'platform_id' => $market->id, 'wp_post_id' => 77, 'wp_user_id' => 55, 'product_id' => $product->id, 'product_price_id' => $price->id,
            'tier' => 'vip', 'duration_days' => 8, 'duration_key' => 'custom_8', 'duration_label' => '8 days', 'amount_minor' => 3500000, 'catalogue_minor' => 3500000, 'currency' => 'TZS',
            'discount_approved_by' => null, 'discount_source' => null, 'original_amount' => null, 'discount_percentage' => null, 'stacking' => 'same_tier', 'identity_authority' => 'reviewed_payer', 'subsidiary' => null];
        $intent = CollectionPurchaseIntent::create(['id' => (string) Str::uuid(), 'account_id' => $account->id, 'payer_link_id' => $link->id,
            'client_id' => $client->id, 'platform_id' => $market->id, 'authority' => 'standing_agreement', 'purchase_snapshot' => $snapshot,
            'approved_by' => $user->id, 'approved_at' => now()->subDays(2), 'valid_from' => now()->subDays(2), 'valid_until' => now()->addMonth(), 'max_renewals' => 10]);
        $account->update(['mode' => 'auto', 'policy' => ['automation_enabled' => true, 'automation_epoch' => (string) Str::uuid(), 'enabled_from' => now()->subHour()->toIso8601String(), 'match_threshold' => 80, 'version' => 1, 'max_auto_age_seconds' => 86400, 'single_cap_minor' => 4000000, 'daily_cap_minor' => 7000000, 'unreviewed_cap_minor' => 7000000, 'unverified_cap_minor' => 7000000,
            'clean_observation_days' => 7, 'positive_receiving_credits' => 100, 'independent_reconciliation_accepted_at' => now()->toIso8601String(),
            'held_out_parser_accepted_at' => now()->toIso8601String(), 'correction_acceptance_at' => now()->toIso8601String(), 'device_capture_accepted_at' => now()->toIso8601String(),
            'settlement_policy_accepted_at' => now()->toIso8601String(), 'historical_reference_map_accepted_at' => now()->toIso8601String(),
            'accepted_parser_versions' => ['tz-mpesa-v1'], 'subsidiary_grant_approved_by' => $user->id, 'subsidiary_grant_valid_until' => now()->addMonth()->toIso8601String()]]);
        if ($enable) {
            config(['collection.entitlement_platforms' => [$market->id], 'collection.verified_writer_platforms' => [$market->id], 'collection.verified_correction_platforms' => [$market->id], 'collection.automatic_accounts' => [$account->id]]);
        }
        $this->remote[77] = $this->snapshot(77, 55);
        $this->captureCheckIn($device);

        return [$account, $device, $client, $intent, $user, $price];
    }

    protected function captureCheckIn(\App\Models\Collections\CollectionDevice $device, array $capture = [], string $source = 'live'): void
    {
        $device->update(['last_seen_at' => now(), 'acceptance' => array_merge($device->acceptance ?? [], ['filter_version' => 'exotic-tz-v1'])]);
        app(CollectionIngestService::class)->accept($device, json_encode(['id' => (string) Str::uuid(), 'deviceId' => $device->external_device_id,
            'event' => 'system:ping', 'payload' => ['capture' => array_merge(['observedAt' => now()->toIso8601String(), 'running' => true,
                'smsPermission' => true, 'filterVersion' => 'exotic-tz-v1', 'simVerified' => true], $capture)]], JSON_THROW_ON_ERROR), $source === 'historical');
    }

    protected function snapshot(int $post, int $user, int $expiry = 0, string $tier = 'basic', int $deal = 0): array
    {
        $state = ['post_id' => $post, 'wp_user_id' => $user, 'post_status' => $expiry > 0 ? 'publish' : 'private', 'post_type' => 'escort',
            'canonical_type' => 'escort', 'expiry_meta_key' => 'escort_expire',
            'meta' => ['escort_expire' => (string) $expiry, 'agency_expire' => '', 'premium' => in_array($tier, ['premium', 'vip', 'vvip']) ? '1' : '0', 'featured' => in_array($tier, ['vip', 'vvip']) ? '1' : '0', 'vvip' => $tier === 'vvip' ? '1' : '0', 'crm_deal_id' => (string) $deal]];
        $state['meta'] += ['premium_expire' => in_array($tier, ['premium', 'vip', 'vvip']) ? (string) $expiry : '',
            'featured_expire' => in_array($tier, ['vip', 'vvip']) ? (string) $expiry : '', 'vvip_expire' => $tier === 'vvip' ? (string) $expiry : '',
            'needs_payment' => '', 'notactive' => '', 'crm_lifecycle_state' => ''];
        $state['meta_present'] = array_keys($state['meta']);
        $state['fingerprint'] = hash('sha256', json_encode($state));

        return $state;
    }

    protected function fakeWordPress(): void
    {
        Http::fake(function ($request) {
            if (preg_match('#/clients/(\d+)/subscription-correction-owner#', $request->url(), $ownerMatch)) {
                $post = (int) $ownerMatch[1];
                if ($request->method() === 'GET') {
                    return Http::response(['version' => 1, 'owner' => $this->siteOwners[$post] ?? null]);
                }
                $owner = $this->siteOwners[$post] ?? null;
                if ($request['action'] === 'acquire') {
                    $owner = ['correction_id' => $request['correction_id'], 'generation' => $request['owner_generation'], 'state' => 'owned', 'initial_fingerprint' => $request['expected_fingerprint']];
                } elseif (! $owner || $owner['correction_id'] !== $request['correction_id'] || (int) $owner['generation'] !== (int) $request['owner_generation']) {
                    return Http::response(['reason' => 'owner_conflict'], 409);
                } elseif ($request['action'] === 'release') {
                    $owner['state'] = 'released';
                }
                $this->siteOwners[$post] = $owner;

                return Http::response(['version' => 1, 'owner' => $owner]);
            }
            preg_match('#/clients/(\d+)/subscription-operation#', $request->url(), $match);
            $post = (int) ($match[1] ?? 0);
            $this->assertArrayHasKey($post, $this->remote);
            if ($post === $this->unavailablePost) {
                throw new \Illuminate\Http\Client\ConnectionException('Fixture site unavailable');
            }
            if ($request->method() === 'GET') {
                parse_str(parse_url($request->url(), PHP_URL_QUERY) ?: '', $query);

                return Http::response(['version' => 1, 'capabilities' => ['compensate' => $this->compensationV2 ? 2 : 0, 'durable_correction_owner' => $this->compensationV2 ? 1 : 0], 'operation' => $this->operations[$query['operation_id'] ?? ''] ?? null, 'snapshot' => $this->remote[$post]]);
            }
            $payloadHash = hash('sha256', json_encode($request->data()));
            if ($saved = ($this->operations[$request['operation_id']] ?? null)) {
                return Http::response($saved, ($saved['_fixture_payload_hash'] ?? null) === $payloadHash ? 200 : 409);
            }
            if (($request->data()['operation_kind'] ?? '') === 'compensate') {
                $source = $this->operations[$request['source_operation_id']] ?? null;
                $digest = fn ($value) => app(\App\Services\Collections\CollectionSalesService::class)->digest($value);
                if (! $source || $digest($request['restore_snapshot']) !== $digest($source['before_state'])) {
                    return Http::response(['code' => 'subscription_review', 'message' => 'Compensation differs from the immutable original preimage.'], 409);
                }
            }
            $this->assertSame($this->remote[$post]['fingerprint'], $request['expected_fingerprint']);
            $beforeState = $this->remote[$post];
            $this->posts++;
            $this->remote[$post] = $this->snapshot($post, (int) $request['wp_user_id'], (int) $request['target_expiry'], $request['product_type'], (int) $request['crm_deal_id']);
            if (isset($request['restore_snapshot'])) {
                $this->remote[$post] = $request['restore_snapshot'];
            }
            $result = ['_fixture_payload_hash' => $payloadHash, 'contract_version' => 2, 'before_state' => $beforeState, 'correction_id' => $request->data()['correction_id'] ?? null, 'owner_generation' => $request->data()['owner_generation'] ?? null, 'state' => 'succeeded', 'operation_id' => $request['operation_id'], 'post_id' => $post, 'escort_expire' => $request['target_expiry'],
                'product_type' => $request['product_type'], 'applied_at' => now()->toIso8601String(), 'snapshot' => $this->remote[$post]];
            $this->operations[$request['operation_id']] = $result;
            if ($this->loseResponse) {
                $this->loseResponse = false;
                throw new \Illuminate\Http\Client\ConnectionException('Fixture response lost after commit');
            }

            return Http::response($result);
        });
    }

    protected function received($device, string $reference = 'ABCDEF12345', bool $historical = false): CollectionReceipt
    {
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit($reference), 'event-'.$reference), $historical);

        return app(CollectionReceiptService::class)->process($event['event_id']);
    }
}
