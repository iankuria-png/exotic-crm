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

    private function withoutSignoffs($account): void
    {
        $policy = $account->fresh()->policy;
        foreach (array_keys(\App\Services\Collections\CollectionAutomationPolicy::SIGNOFFS) as $key) {
            unset($policy[$key]);
        }
        $policy['accepted_parser_versions'] = [];
        $account->update(['mode' => 'shadow', 'policy' => array_replace($policy, ['automation_enabled' => false, 'automation_epoch' => null, 'enabled_from' => null,
            'clean_observation_days' => 0, 'positive_receiving_credits' => 0, 'limits_approved_by' => 1, 'limits_approved_at' => now()->toIso8601String()])]);
    }

    public function test_manager_records_attributed_signoffs_with_parser_version_audit_and_withdrawal(): void
    {
        [$account, , $client, , $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        $this->withoutSignoffs($account);
        Sanctum::actingAs($admin);
        $url = '/api/crm/collections/accounts/'.$account->id.'/signoffs';
        $this->postJson($url, ['key' => 'independent_reconciliation_accepted_at', 'expected_version' => 1, 'accepted_by' => 'Finance — Grace', 'note' => 'Statement 1–8 Nov agrees'])
            ->assertOk()->assertJsonPath('version', 2);
        $check = collect($this->getJson('/api/crm/collections/account-policies')->json('accounts.0.automation.readiness'))->firstWhere('key', 'independent_reconciliation_accepted_at');
        $this->assertTrue($check['ready']);
        $this->assertSame('finance', $check['group']);
        $this->assertSame('Finance — Grace', $check['evidence']['accepted_by']);
        $this->assertSame($admin->name, $check['evidence']['recorded_by_name']);
        $this->postJson($url, ['key' => 'held_out_parser_accepted_at', 'expected_version' => 2, 'accepted_by' => 'Ian', 'note' => 'Held-out sample read correctly'])->assertOk();
        $this->assertSame(['tz-mpesa-v1'], $account->fresh()->policy['accepted_parser_versions']);
        $this->postJson($url, ['key' => 'held_out_parser_accepted_at', 'expected_version' => 2, 'accepted_by' => 'Ian', 'note' => 'stale'])->assertConflict();
        $this->postJson($url, ['key' => 'clean_observation_days', 'expected_version' => 3, 'accepted_by' => 'Ian', 'note' => 'Not a sign-off'])->assertStatus(422);
        $this->postJson($url, ['key' => 'held_out_parser_accepted_at', 'expected_version' => 3, 'accepted_by' => 'Ian', 'note' => 'x', 'positive_receiving_credits' => 100])->assertStatus(422);
        $this->postJson($url, ['key' => 'held_out_parser_accepted_at', 'expected_version' => 3, 'withdraw' => true, 'note' => 'Re-test after parser change'])->assertOk();
        $policy = $account->fresh()->policy;
        $this->assertArrayNotHasKey('held_out_parser_accepted_at', $policy);
        $this->assertSame([], $policy['accepted_parser_versions']);
        $this->assertSame(0, (int) ($policy['positive_receiving_credits'] ?? 0));
        $this->assertSame(2, DB::table('collection_audit')->where('action', 'automation_signoff_recorded')->count());
        $this->assertSame(1, DB::table('collection_audit')->where('action', 'automation_signoff_withdrawn')->count());
        $sales = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$client->platform_id]]);
        Sanctum::actingAs($sales);
        $this->postJson($url, ['key' => 'settlement_policy_accepted_at', 'expected_version' => 4, 'accepted_by' => 'Me', 'note' => 'Not allowed'])->assertForbidden();
    }

    public function test_switch_on_requires_every_signoff_then_starts_one_attributed_pilot_and_reports_live_status(): void
    {
        [$account, , , , $admin] = $this->purchase();
        config(['collection.workspace_enabled' => true]);
        $this->withoutSignoffs($account);
        Sanctum::actingAs($admin);
        $enable = fn (int $version) => $this->putJson('/api/crm/collections/accounts/'.$account->id.'/policy', ['expected_version' => $version, 'automation_enabled' => true,
            'match_threshold' => 80, 'max_auto_age_seconds' => 3600, 'purchase_matching' => 'reviewed_only', 'observation_pilot_hours' => 24, 'reason' => 'Finance accepted TZ go-live']);
        $enable(1)->assertConflict()->assertJsonPath('reason', 'automatic_account_acceptance_required');
        $status = $this->getJson('/api/crm/collections/account-policies')->json('accounts.0.automation.status');
        $this->assertSame('paused', $status['live_state']);
        $this->assertFalse($status['can_enable']);
        $this->assertTrue($status['pilot']['available']);
        $version = 1;
        foreach (array_keys(\App\Services\Collections\CollectionAutomationPolicy::SIGNOFFS) as $key) {
            $version = $this->postJson('/api/crm/collections/accounts/'.$account->id.'/signoffs', ['key' => $key, 'expected_version' => $version, 'accepted_by' => 'Finance and operations', 'note' => 'Accepted 9 Nov'])
                ->assertOk()->json('version');
        }
        $this->assertTrue($this->getJson('/api/crm/collections/account-policies')->json('accounts.0.automation.status.can_enable'));
        $enable($version)->assertOk()->assertJsonPath('automation.enabled', true)->assertJsonPath('mode', 'auto')
            ->assertJsonPath('automation.status.live_state', 'live')->assertJsonPath('automation.status.pilot.state', 'active')
            ->assertJsonPath('automation.status.activity.automatic', 0)->assertJsonPath('automation.status.last_change.reason', 'Finance accepted TZ go-live');
        $this->travel(25)->hours();
        $this->captureCheckIn(\App\Models\Collections\CollectionDevice::firstOrFail());
        $status = $this->getJson('/api/crm/collections/account-policies')->json('accounts.0.automation.status');
        $this->assertSame('pilot_ended', $status['live_state']);
        $this->assertSame('expired', $status['pilot']['state']);
    }
}
