<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionEvent;
use App\Services\Collections\CollectionAutomationEpoch;
use App\Services\Collections\CollectionAutomationPolicy;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionReceiptService;
use Illuminate\Support\Facades\DB;

class CollectionAutomationEpochTest extends CollectionWorkflowTestCase
{
    public function test_first_server_ingest_is_retained_and_one_hour_boundary_is_inclusive(): void
    {
        [$account, $device] = $this->purchase();
        $r = $this->received($device);
        $account->update(['policy' => array_replace($account->policy, ['max_auto_age_seconds' => 3600, 'enabled_from' => now()->subHours(2)->toIso8601String()])]);
        $r->unsetRelation('account');
        $this->assertSame(now()->timestamp, $r->first_ingested_at->timestamp);
        $this->assertTrue($r->automation_eligible_at_ingest);
        $this->assertSame($account->policy['automation_epoch'], $r->automation_epoch);
        $r->provider_at = now()->subHour();
        app(CollectionAutomationEpoch::class)->assertReceipt($r);
        $r->provider_at = now()->subHour()->subSecond();
        $this->expectException(CollectionException::class);
        $this->expectExceptionMessage('receipt outside automatic age');
        app(CollectionAutomationEpoch::class)->assertReceipt($r);
    }

    public function test_missing_original_provenance_is_held_instead_of_assigned_now(): void
    {
        [$account, $device] = $this->purchase();
        $r = $this->received($device);
        $r->first_ingested_at = null;
        $this->expectException(CollectionException::class);
        $this->expectExceptionMessage('receipt before automatic cutover');
        app(CollectionAutomationEpoch::class)->assertReceipt($r);
    }

    /** @dataProvider terms */
    public function test_delayed_parse_and_existing_receipt_cannot_cross_policy_changes(array $change): void
    {
        [$account, $device, , , $actor] = $this->purchase();
        $existing = $this->received($device);
        $accepted = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit('DELAYED1234'), 'delayed'));
        $epoch = $account->policy['automation_epoch'];
        app(CollectionAutomationPolicy::class)->update($actor, $account->id, $change + ['expected_version' => 1, 'reason' => 'Reviewed policy change']);
        $this->assertNotSame($epoch, $account->fresh()->policy['automation_epoch']);
        $later = app(CollectionReceiptService::class)->process($accepted['event_id']);
        foreach ([$existing, $later] as $r) {
            $this->assertSame($epoch, $r->automation_epoch);
            try {
                app(CollectionAutomationEpoch::class)->assertReceipt($r->fresh());
                $this->fail('Earlier intake must stay held');
            } catch (CollectionException $e) {
                $this->assertSame('receipt_outside_automation_epoch', $e->reason);
            }
        }
        $this->assertDatabaseCount('collection_allocations', 0);
    }

    public static function terms(): array
    {
        return ['threshold' => [['match_threshold' => 90]], 'age' => [['max_auto_age_seconds' => 3600]]];
    }

    public function test_pause_ingest_remains_manual_after_reenable_and_complementary_messages(): void
    {
        [$account, $device, , , $actor] = $this->purchase();
        $policy = app(CollectionAutomationPolicy::class);
        $policy->update($actor, $account->id, ['expected_version' => 1, 'automation_enabled' => false, 'reason' => 'Pause for check']);
        $accepted = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit(), 'paused'));
        $this->assertFalse(CollectionEvent::findOrFail($accepted['event_id'])->automation_eligible_at_ingest);
        $policy->update($actor, $account->id, ['expected_version' => 2, 'automation_enabled' => true, 'reason' => 'Resume after check']);
        $r = app(CollectionReceiptService::class)->process($accepted['event_id']);
        $this->travel(5)->seconds();
        $r = $this->received($device);
        $this->assertNull($r->automation_epoch);
        $this->assertFalse($r->automation_eligible_at_ingest);
        $this->expectException(CollectionException::class);
        app(CollectionAutomationEpoch::class)->assertReceipt($r);
    }

    public function test_out_of_order_parser_cannot_make_later_delivery_the_first_intake(): void
    {
        [$account, $device] = $this->purchase();
        $ingest = app(CollectionIngestService::class);
        $first = $ingest->accept($device, $this->envelope($this->credit(), 'first'));
        $second = $ingest->accept($device, $this->envelope($this->credit(), 'second'));
        try {
            app(CollectionReceiptService::class)->process($second['event_id']);
            $this->fail('Parse ordering must preserve original intake');
        } catch (CollectionException $e) {
            $this->assertSame('earlier_intake_parse_pending', $e->reason);
        }
        app(CollectionReceiptService::class)->process($first['event_id']);
        $r = app(CollectionReceiptService::class)->process($second['event_id']);
        $this->assertSame(CollectionEvent::findOrFail($first['event_id'])->ingested_at->timestamp, $r->first_ingested_at->timestamp);
    }

    public function test_pilot_waives_only_observations_and_expires_without_renewal_from_term_edits(): void
    {
        [$account, $device, $client, , $actor] = $this->purchase();
        $account->update(['policy' => array_replace($account->policy, ['automation_enabled' => false, 'automation_epoch' => null,
            'clean_observation_days' => 0, 'positive_receiving_credits' => 0])]);
        $policy = app(CollectionAutomationPolicy::class);
        $policy->update($actor, $account->id, ['expected_version' => 1, 'automation_enabled' => true,
            'observation_pilot_hours' => 24, 'reason' => 'Ian approved bounded observation exception']);
        $exception = $account->fresh()->policy['observation_exception'];
        $this->assertSame(['clean_observation_days', 'positive_receiving_credits'], $exception['waived_keys']);
        $policy->update($actor, $account->id, ['expected_version' => 2, 'match_threshold' => 85, 'reason' => 'Raise threshold']);
        $this->assertSame($exception, $account->fresh()->policy['observation_exception']);
        $this->assertSame(2, DB::table('collection_audit')->where('action', 'automation_policy_updated')->count());
        $account->update(['policy' => array_replace($account->fresh()->policy, ['independent_reconciliation_accepted_at' => null])]);
        $this->expectException(CollectionException::class);
        $policy->update($actor, $account->id, ['expected_version' => 3, 'match_threshold' => 86, 'reason' => 'Cannot bypass missing reconciliation']);
    }
}
