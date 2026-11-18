<?php

namespace Tests\Feature\Collections;

use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionMarketScopeTest extends CollectionWorkflowTestCase
{
    private function ghanaEnvelope(string $body, string $id, array $extra): string
    {
        $data = json_decode($this->envelope($body, $id, $extra), true);
        $data['deviceId'] = 'fixture-gh-phone';

        return json_encode($data);
    }

    private function markets(): array
    {
        [$tz, $device, , , $admin] = $this->purchase(false, 6);
        $tz->update(['label' => 'TZ office wallet']);
        $tzReceipt = $this->received($device, 'TZONLY12345');
        $tzReceipt->update(['funds_state' => 'reversed']);
        $gh = $tz->replicate();
        $gh->id = (string) \Illuminate\Support\Str::uuid();
        $gh->account_fingerprint = app(\App\Services\Collections\CollectionPrivacy::class)->fingerprint('ghana-fixture');
        $gh->country = 'GH';
        $gh->save();
        $ghDevice = $device->replicate();
        $ghDevice->id = (string) \Illuminate\Support\Str::uuid();
        $ghDevice->external_device_id = 'fixture-gh-phone';
        $ghDevice->key_id = 'fixture-gh-key';
        $ghDevice->save();
        $binding = (array) DB::table('collection_sim_bindings')->where('device_id', $device->id)->first();
        unset($binding['id']);
        $binding['device_id'] = $ghDevice->id;
        $binding['account_id'] = $gh->id;
        DB::table('collection_sim_bindings')->insert($binding);
        $gh->update(['label' => 'Ghana office wallet', 'country' => 'GH', 'provider_key' => 'mtn_momo_gh', 'currency' => 'GHS']);
        Platform::factory()->create(['id' => 3, 'currency_code' => 'GHS', 'phone_prefix' => '233']);
        DB::table('collection_account_platforms')->insert(['account_id' => $gh->id, 'platform_id' => 3, 'destination_snapshot' => '{}', 'valid_from' => now()->subDay()]);
        $event = app(CollectionIngestService::class)->accept($ghDevice, $this->ghanaEnvelope('Payment received for GHS 380.00 from FIXTURE PAYER Current Balance: GHS 5523.86. Available Balance: GHS 5523.86. Reference: fixture. Transaction ID: 94000000001.', 'gh-scope', ['sender' => 'MobileMoney']));
        $ghReceipt = app(CollectionReceiptService::class)->process($event['event_id']);
        $ghReceipt->update(['funds_state' => 'disputed']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);

        return [$tz, $gh];
    }

    public function test_market_and_account_scope_cover_receipts_reversals_balances_and_policies(): void
    {
        [$tz, $gh] = $this->markets();
        $this->getJson('/api/crm/collections/receipts?platform_id=3')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'reversals')->assertJsonPath('reversals.0.reference', '94000000001')
            ->assertJsonPath('reversals.0.account_label', 'Ghana office wallet')
            ->assertJsonCount(1, 'wallet_balances')->assertJsonPath('wallet_balances.0.account_id', $gh->id);
        $this->getJson('/api/crm/collections/receipts?platform_id=6')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('reversals.0.reference', 'TZONLY12345')->assertJsonCount(1, 'wallet_balances')
            ->assertJsonPath('wallet_balances.0.account_id', $tz->id);
        $this->getJson('/api/crm/collections/receipts?account_id='.$gh->id)->assertOk()->assertJsonPath('total', 1)
            ->assertJsonCount(1, 'reversals')->assertJsonCount(1, 'wallet_balances');
        $this->getJson('/api/crm/collections/receipts?platform_id=3&account_id='.$tz->id)->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'reversals')->assertJsonCount(0, 'wallet_balances');
        $this->getJson('/api/crm/collections/receipts')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'reversals')->assertJsonCount(2, 'wallet_balances');
        $this->getJson('/api/crm/collections/account-policies?platform_id=3')->assertOk()->assertJsonCount(1, 'accounts')
            ->assertJsonPath('accounts.0.account_id', $gh->id);
        $this->getJson('/api/crm/collections/claim-checks?platform_id=6')->assertOk()->assertJsonCount(0, 'accounts');
    }

    public function test_market_filters_cannot_widen_staff_or_shared_account_access(): void
    {
        [$tz, $gh] = $this->markets();
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [3]]);
        Sanctum::actingAs($sales);
        $this->getJson('/api/crm/collections/receipts?platform_id=6')->assertForbidden();
        $this->getJson('/api/crm/collections/account-policies?platform_id=6')->assertForbidden();
        $this->getJson('/api/crm/collections/claim-checks?platform_id=6')->assertForbidden();
        $this->getJson('/api/crm/collections/receipts?account_id='.$tz->id)->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'reversals')->assertJsonCount(0, 'wallet_balances');
        DB::table('collection_account_platforms')->insert(['account_id' => $gh->id, 'platform_id' => 6, 'destination_snapshot' => '{}', 'valid_from' => now()]);
        $this->getJson('/api/crm/collections/receipts?platform_id=3')->assertOk()
            ->assertJsonPath('total', 0)->assertJsonCount(0, 'reversals')->assertJsonCount(0, 'wallet_balances');
    }
}
