<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionDeviceHealthService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionGate;
use App\Services\Collections\CollectionIngestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class CollectionDeviceHealthTest extends CollectionWorkflowTestCase
{
    public function test_fresh_capture_recovers_stale_alert_and_automatic_gate_but_general_contact_does_not(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        $health = app(CollectionDeviceHealthService::class);
        $this->assertTrue($health->accountReady($account->id));
        $this->travel(301)->seconds();
        $this->assertFalse($health->accountReady($account->id));
        $this->assertSame('no_recent_contact', $health->inspect($device->fresh())['state']);
        app(CollectionIngestService::class)->accept($device, json_encode(['id' => 'general-ping', 'deviceId' => $device->external_device_id, 'event' => 'system:ping', 'payload' => ['status' => 'pass']]));
        $this->assertSame('capture_unverified', $health->inspect($device->fresh())['state']);
        try {
            app(CollectionGate::class)->assertWrites($account, [$client->platform_id], true);
            $this->fail('General contact must not authorize new writes.');
        } catch (CollectionException $e) {
            $this->assertSame('receiving_device_offline_or_unbound', $e->reason);
        }
        $this->captureCheckIn($device);
        $this->assertTrue($health->accountReady($account->id));
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/collections/device-health')->assertOk()->assertJsonPath('needs_attention', 0)->assertJsonPath('devices.0.state', 'ready');
        $this->travel(301)->seconds();
        $this->getJson('/api/crm/collections/arrivals')->assertOk()->assertJsonPath('device_needs_attention', 1);
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public function test_queued_historical_future_and_degraded_heartbeats_never_clear_capture_alert(): void
    {
        [$account, $device] = $this->purchase();
        $health = app(CollectionDeviceHealthService::class);
        foreach ([['observedAt' => now()->subMinutes(6)->toIso8601String()], ['observedAt' => now()->addMinutes(2)->toIso8601String()],
            ['observedAt' => 'bad'], ['running' => false], ['smsPermission' => false], ['filterVersion' => 'unknown'],
            ['simVerified' => false], ['formatDrift' => true]] as $capture) {
            $this->captureCheckIn($device, $capture);
            $this->assertFalse($health->accountReady($account->id), json_encode($capture));
        }
        $this->captureCheckIn($device, [], 'historical');
        $this->assertFalse($health->accountReady($account->id));
        $this->captureCheckIn($device);
        $this->assertTrue($health->accountReady($account->id));
        $device->update(['revoked_at' => now()]);
        $this->assertFalse($health->accountReady($account->id));
        $this->assertSame('revoked', $health->inspect($device->fresh())['state']);
    }

    public function test_device_health_is_scoped_across_all_shared_accounts_and_never_contains_secrets(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/collections/device-health')->assertNotFound();
        config(['collection.workspace_enabled' => true]);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$client->platform_id]]);
        Sanctum::actingAs($sales);
        $response = $this->getJson('/api/crm/collections/device-health')->assertOk()->assertJsonCount(1, 'devices');
        foreach (['fixture-phone', str_repeat('s', 32), '255700000099', 'fixture-iccid', 'fixture-cursor'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $other = Platform::factory()->create();
        $shared = CollectionAccount::create(array_merge($account->getAttributes(), ['id' => (string) Str::uuid(), 'account_fingerprint' => str_repeat('1', 64), 'label' => 'Private market']));
        DB::table('collection_account_platforms')->insert(['account_id' => $shared->id, 'platform_id' => $other->id, 'destination_snapshot' => '{}', 'valid_from' => now()]);
        $binding = DB::table('collection_sim_bindings')->where('device_id', $device->id)->first();
        $copy = (array) $binding;
        unset($copy['id']);
        DB::table('collection_sim_bindings')->insert(array_merge($copy, ['account_id' => $shared->id, 'sim_number' => 2]));
        $this->getJson('/api/crm/collections/device-health')->assertOk()->assertJsonCount(0, 'devices')->assertDontSee('Private market');
        $this->getJson('/api/crm/collections/device-health?platform_id='.$other->id)->assertForbidden();
        Sanctum::actingAs($admin);
        $account->update(['suspended_at' => now()]);
        $this->getJson('/api/crm/collections/device-health')->assertOk()->assertJsonPath('devices.0.state', 'account_review');
    }
}
