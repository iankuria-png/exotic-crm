<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionPayerLink;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionMatchExclusionService;
use App\Services\Collections\CollectionMatchService;
use Illuminate\Support\Facades\DB;

class CollectionMatchPolicyTest extends CollectionWorkflowTestCase
{
    public function test_strict_threshold_and_ambiguous_or_invalid_inputs(): void
    {
        foreach ([[80, 80, null], [81, 80, 42], [95, 95, null], [95, 94, 42], [0, 0, null], [1, 0, 42], [100, 100, null]] as [$score, $threshold, $expected]) {
            $this->assertSame($expected, CollectionMatchService::select([['id' => 42, 'score' => $score]], false, $threshold));
        }
        $this->assertNull(CollectionMatchService::select([['id' => 42, 'score' => 95]], true, 80));
        $this->assertNull(CollectionMatchService::select([['id' => 42, 'score' => 95], ['id' => 43, 'score' => 95]], false, 80));
        $this->assertNull(CollectionMatchService::select([['id' => 42, 'score' => 95]], false, '80'));
    }

    public function test_worker_rechecks_real_match_against_manager_threshold(): void
    {
        [$account, $device] = $this->purchase();
        $account->update(['policy' => array_replace($account->policy, ['match_threshold' => 95])]);
        $r = $this->received($device);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->assertSame('needs_review', $r->fresh()->decision_state);
        $this->assertContains('client_match_below_threshold_or_conflicting', $r->fresh()->candidate_snapshot['reasons']);
        $this->assertDatabaseCount('collection_allocations', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejected_identity_revokes_only_its_authority_and_old_history_cannot_restore_it(): void
    {
        [$account, $device, $client, $intent, $actor] = $this->purchase();
        $r = $this->received($device);
        $sibling = CollectionPayerLink::firstOrFail()->replicate();
        $sibling->id = (string) \Illuminate\Support\Str::uuid();
        $sibling->payer_fingerprint = str_repeat('a', 64);
        $sibling->save();
        DB::transaction(fn () => app(CollectionMatchExclusionService::class)->record($r, $client->id, null, 'test-correction:1', $actor->id, 'Wrong advertiser'));
        $this->assertNotNull($intent->fresh()->revoked_at);
        $this->assertNull($sibling->fresh()->revoked_at);
        $future = $this->received($device, 'FUTURE12345');
        $history = ['scope' => ['platform_ids' => [$client->platform_id]], 'client_counts' => [$client->id => 18], 'complete' => true, 'identity_conflict' => false];
        $result = app(CollectionMatchService::class)->evidence($future, $history, ['complete' => true], []);
        $this->assertNull($result['recommended_client_id']);
        $this->assertSame(0, $result['suggestions'][0]['score']);
        $this->assertNull(CollectionMatchService::select($result['suggestions'], $result['needs_choice'], 0));
        DB::transaction(fn () => app(CollectionMatchExclusionService::class)->record($r, $client->id, null, 'test-correction:1', $actor->id, 'Wrong advertiser'));
        $this->assertDatabaseCount('collection_match_exclusions', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_package_only_change_does_not_mark_the_identity_wrong(): void
    {
        [$account, $device, $client, $intent, $actor] = $this->purchase();
        $r = $this->received($device);
        DB::transaction(fn () => app(CollectionMatchExclusionService::class)->record($r, $client->id, $client->id, 'test-package:1', $actor->id, 'Choose eight days'));
        $this->assertNull($intent->fresh()->revoked_at);
        $this->assertDatabaseCount('collection_match_exclusions', 0);
    }
}
