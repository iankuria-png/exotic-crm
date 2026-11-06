<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionDecisionService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionReceiptService;
use App\Services\Collections\CollectionSettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CollectionSettlementEnrollmentTest extends CollectionTestCase
{
    public function test_statement_only_history_and_discrepancies_never_activate_or_overwrite_received_principal(): void
    {
        [$account, $device] = $this->inbox();
        $actor = User::factory()->create(['role' => 'admin']);
        $row = ['reference' => 'ABCDEF12345', 'amount_minor' => 3500000, 'currency' => 'TZS', 'provider_at' => now()->subDay(),
            'artifact_sha256' => str_repeat('a', 64), 'evidence_reference' => 'fixture-statement-row'];
        $r = app(CollectionSettlementService::class)->statement($account, $actor, $row);
        $this->assertTrue($r->historical_only);
        app(CollectionDecisionService::class)->evaluate($r->id);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('collection_allocations', 0);
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit('SECOND12345')));
        $second = app(CollectionReceiptService::class)->process($event['event_id']);
        $conflict = app(CollectionSettlementService::class)->statement($account, $actor, array_merge($row, ['reference' => 'SECOND12345', 'amount_minor' => 3000000, 'evidence_reference' => 'different-statement-row']));
        $this->assertSame(3500000, $conflict->principal_minor);
        $this->assertContains('statement_principal_or_currency_conflict', $conflict->conflicts);
        $this->assertSame('needs_review', $conflict->decision_state);
        Http::assertNothingSent();
    }

    public function test_reference_alias_requires_review_and_cannot_move_to_another_receipt(): void
    {
        [$account, $device] = $this->inbox();
        $actor = User::factory()->create(['role' => 'admin']);
        $market = Platform::factory()->create();
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $market->id, 'destination_snapshot' => '{}', 'valid_from' => now()->subDay()]);
        $receipts = [];
        foreach (['ABCDEF12345', 'SECOND12345'] as $ref) {
            $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit($ref), $ref));
            $receipts[] = app(CollectionReceiptService::class)->process($event['event_id']);
        }
        $service = app(CollectionSettlementService::class);
        $service->alias($receipts[0], $actor, 'BANK-FIXTURE-123', 'Reviewed paired provider statement');
        $service->alias($receipts[0], $actor, 'BANK-FIXTURE-123', 'Reviewed paired provider statement');
        $this->assertDatabaseCount('collection_receipt_aliases', 1);
        $this->expectException(CollectionException::class);
        $service->alias($receipts[1], $actor, 'BANK-FIXTURE-123', 'Cannot reuse the bank reference');
    }

    public function test_remittance_is_a_separate_idempotent_evidence_entry_with_no_money_movement(): void
    {
        [$account, $device] = $this->inbox();
        $actor = User::factory()->create(['role' => 'admin']);
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit()));
        app(CollectionReceiptService::class)->process($event['event_id']);
        $row = ['kind' => 'remittance', 'amount_minor' => 1000000, 'currency' => 'TZS', 'artifact_sha256' => str_repeat('b', 64),
            'evidence_reference' => 'fixture-company-credit', 'idempotency_key' => (string) Str::uuid(), 'posted_at' => now()];
        $service = app(CollectionSettlementService::class);
        $service->adjustment($account, $actor, $row);
        $service->adjustment($account, $actor, $row);
        $service->adjustment($account, $actor, array_merge($row, ['idempotency_key' => (string) Str::uuid()]));
        $summary = $service->summary($account);
        $this->assertSame(3500000, $summary['gross_received_minor']);
        $this->assertSame(1000000, $summary['remitted_minor']);
        $this->assertSame(2500000, $summary['closing_due_minor']);
        $this->assertDatabaseCount('collection_settlement_entries', 1);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_enrollment_defaults_to_preview_and_explicit_apply_creates_shadow_only(): void
    {
        $actor = User::factory()->create(['role' => 'admin']);
        $market = Platform::factory()->create();
        $file = tempnam(sys_get_temp_dir(), 'collection-enrollment-');
        chmod($file, 0600);
        $input = ['account_label' => 'Fixture account', 'country' => 'TZ', 'provider_key' => 'vodacom_mpesa_tz', 'account_kind' => 'merchant',
            'account_identifier' => 'fixture-account', 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'ownership' => 'company', 'ownership_evidence_reference' => 'fixture-reviewed-owner',
            'device_label' => 'Fixture phone', 'external_device_id' => 'fixture-device', 'signing_secret' => str_repeat('s', 32), 'inbox_cursor' => 'fixture-inbox-cursor',
            'sim_number' => 1, 'recipient' => '255700000099', 'iccid' => 'fixture-iccid', 'carrier_name' => 'Vodacom', 'apk_sha256' => str_repeat('c', 64), 'apk_version' => 'fixture-only',
            'platform_bindings' => [['platform_id' => $market->id, 'destination_snapshot' => ['method_key' => 'fixture']]]];
        file_put_contents($file, json_encode($input));
        try {
            $this->artisan('collections:enroll', ['private_config' => $file, '--actor' => $actor->id])->assertSuccessful();
            $this->assertDatabaseCount('collection_devices', 0);
            $this->artisan('collections:enroll', ['private_config' => $file, '--actor' => $actor->id, '--apply' => true])->assertSuccessful();
            $this->assertSame('shadow', CollectionAccount::first()->mode);
            $this->assertEquals(now()->timestamp, CollectionDevice::first()->cutover_at->timestamp);
            $this->assertStringNotContainsString($input['signing_secret'], DB::table('collection_devices')->first()->signing_secret);
            $this->assertDatabaseCount('payments', 0);
            $this->assertDatabaseCount('collection_allocations', 0);
        } finally {
            unlink($file);
        }
    }
}
