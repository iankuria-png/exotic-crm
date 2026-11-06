<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAllocation;
use App\Models\User;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionFulfillmentService;
use App\Services\Collections\CollectionPolicyService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

class CollectionPolicyTest extends CollectionWorkflowTestCase
{
    private function unlimited(int $version): array
    {
        return array_fill_keys(CollectionPolicyService::CAPS, null) + ['expected_version' => $version];
    }

    public function test_approved_unlimited_caps_still_reserve_and_do_not_reset_counters_or_acceptance(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $before = $account->policy;
        $this->putJson('/api/crm/collections/accounts/'.$account->id.'/policy', $this->unlimited(1))
            ->assertOk()->assertJsonPath('configured', true)->assertJsonPath('version', 2)->assertJsonPath('limits.single_cap_minor', null);
        $after = $account->fresh()->policy;
        foreach (array_diff(array_keys($before), [...CollectionPolicyService::CAPS, 'version']) as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
        $this->assertSame($admin->id, $after['limits_approved_by']);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->fakeWordPress();
        app(CollectionFulfillmentService::class)->resume(CollectionAllocation::firstOrFail()->id);
        $this->assertDatabaseCount('collection_exposure_reservations', 1);
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('bucket', 'outstanding')->value('unreviewed_minor'));
        $this->putJson('/api/crm/collections/accounts/'.$account->id.'/policy', array_merge($this->unlimited(2), ['daily_cap_minor' => 0]))->assertOk();
        $this->assertSame(3500000, (int) DB::table('collection_exposure_buckets')->where('bucket', '!=', 'outstanding')->value('daily_minor'));
        $next = $this->received($device, 'SECOND12345');
        app(CollectionDecisionService::class)->evaluate($next->id);
        $this->assertContains('automatic_exposure_limit', $next->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 1);
        $this->assertSame(2, DB::table('collection_audit')->where('action', 'exposure_limits_updated')->count());
    }

    public function test_missing_or_unapproved_null_caps_are_not_unlimited(): void
    {
        $service = app(CollectionPolicyService::class);
        $policy = array_fill_keys(CollectionPolicyService::CAPS, null) + ['version' => 1];
        $this->assertFalse($service->configured($policy));
        $policy += ['limits_approved_by' => 1, 'limits_approved_at' => now()->toIso8601String()];
        $this->assertTrue($service->configured($policy));
        unset($policy['daily_cap_minor']);
        $this->assertFalse($service->configured($policy));
    }

    public function test_caps_only_manager_updates_reject_missing_fields_injected_acceptance_and_stale_version(): void
    {
        [$account, $device, $client, $intent, $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $url = '/api/crm/collections/accounts/'.$account->id.'/policy';
        $this->putJson($url, $this->unlimited(1) + ['clean_observation_days' => 100])->assertStatus(422)->assertJsonPath('reason', 'unsupported_policy_fields');
        $missing = $this->unlimited(1);
        unset($missing['daily_cap_minor']);
        $this->putJson($url, $missing)->assertStatus(422);
        $this->putJson($url, $this->unlimited(0))->assertConflict()->assertJsonPath('reason', 'collection_policy_changed');
        $this->assertSame(1, $account->fresh()->policy['version']);
        $this->assertDatabaseCount('collection_exposure_reservations', 0);
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$client->platform_id]]);
        Sanctum::actingAs($sales);
        $this->putJson($url, $this->unlimited(1))->assertForbidden();
        $this->getJson('/api/crm/collections/account-policies')->assertOk()->assertJsonCount(1, 'accounts')->assertJsonMissingPath('accounts.0.clean_observation_days');
    }
}
