<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionProviderEvent;
use App\Models\Collections\CollectionProviderResource;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionDeviceHealthService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionPayerEvidenceService;
use App\Services\Collections\Kopokopo\KopokopoFeedService;
use App\Services\Collections\Kopokopo\KopokopoIngestService;
use Illuminate\Support\Facades\DB;

class KopokopoCaptureTest extends KopokopoCollectionTestCase
{
    public function test_signed_receipt_has_protected_provider_identity_and_no_new_device_or_payment(): void
    {
        [$feed] = $this->kenya();
        $devices = DB::table('collection_devices')->count();
        $payments = DB::table('payments')->count();
        $accepted = $this->receive($feed);
        $r = CollectionReceipt::findOrFail($accepted['receipt_id']);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame([150000, 'KES', 'provider', false], [$r->principal_minor, $r->currency, $r->evidence_level, $r->historical_only]);
        $payer = app(CollectionPayerEvidenceService::class)->forReceipt($r);
        $this->assertSame('Fixture Payer', $payer['name']);
        $this->assertTrue($payer['protected_identity']);
        $this->assertNull($payer['phone']);
        $this->assertSame($devices, DB::table('collection_devices')->count());
        $this->assertSame($payments, DB::table('payments')->count());
        $this->assertStringNotContainsString('Fixture', DB::table('collection_provider_events')->value('raw_body'));
    }

    public function test_signature_uses_exact_raw_bytes_and_api_key(): void
    {
        [$feed] = $this->kenya();
        $body = $this->providerBody();
        try {
            app(KopokopoIngestService::class)->webhook($feed, $body.' ', hash_hmac('sha256', $body, 'fixture-api-signing-key'));
            $this->fail('Changed bytes accepted');
        } catch (CollectionException $e) {
            $this->assertSame('provider_signature_invalid', $e->reason);
        }
        $this->assertSame(0, CollectionProviderEvent::count());
    }

    public function test_retries_and_resource_reference_conflicts_are_durable(): void
    {
        [$feed] = $this->kenya();
        $first = $this->receive($feed);
        $this->assertSame('duplicate', $this->receive($feed)['status']);
        $this->assertSame(1, CollectionReceipt::count());
        $changed = $this->receive($feed, $this->providerBody(['amount' => '3000.00']));
        $this->assertSame('quarantined', $changed['status']);
        $this->assertContains('provider_event_id_content_conflict', CollectionReceipt::find($first['receipt_id'])->conflicts);
        $conflict = $this->receive($feed, $this->providerBody(['reference' => 'UKXFIX0002'], 'fixture-event-2'));
        $this->assertSame('quarantined', $conflict['status']);
        $this->assertSame(1, CollectionProviderResource::count());
        $this->assertSame(1, CollectionReceipt::count());
        $this->assertSame(150000, CollectionReceipt::first()->principal_minor);
    }

    public function test_wrong_till_stk_till_and_bad_money_are_quarantined_without_receipts(): void
    {
        [$feed] = $this->kenya();
        foreach (['K317087', '999999'] as $i => $till) {
            $r = $this->receive($feed, $this->providerBody(['till_number' => $till], 'wrong-till-'.$i));
            $this->assertSame('quarantined', $r['status']);
        }
        foreach (['-1', '1e3', '12.345', '0', []] as $i => $amount) {
            $this->assertSame('quarantined', $this->receive($feed, $this->providerBody(['amount' => $amount], 'bad-money-'.$i))['status']);
        }
        $this->assertSame(0, CollectionReceipt::count());
        $this->assertSame(7, CollectionProviderEvent::count());
    }

    public function test_phone_enrichment_retains_hash_and_new_hash_does_not_transfer_reviewed_links(): void
    {
        [$feed] = $this->kenya();
        $first = $this->receive($feed);
        $r = CollectionReceipt::find($first['receipt_id']);
        $fingerprint = $r->payer_fingerprint;
        $this->receive($feed, $this->providerBody(['sender_phone_number' => '+254700000001'], 'phone-enrichment'));
        $this->assertSame($fingerprint, $r->fresh()->payer_fingerprint);
        $this->assertSame('254700000001', $r->fresh()->payer_phone);
        $other = $this->receive($feed, $this->providerBody(['hashed_sender_phone' => null, 'sender_phone_number' => '+254700000002', 'id' => 'fixture-resource-2', 'reference' => 'UKXFIX0002'], 'phone-first'));
        $r2 = CollectionReceipt::find($other['receipt_id']);
        $before = $r2->payer_fingerprint;
        $this->receive($feed, $this->providerBody(['hashed_sender_phone' => str_repeat('b', 64), 'sender_phone_number' => '+254700000002', 'id' => 'fixture-resource-2', 'reference' => 'UKXFIX0002'], 'hash-later'));
        $this->assertNotSame($before, $r2->fresh()->payer_fingerprint);
        $this->assertSame(0, DB::table('collection_payer_links')->where('payer_fingerprint', $r2->fresh()->payer_fingerprint)->count());
        $this->assertDatabaseHas('collection_audit', ['action' => 'provider_payer_identity_enriched', 'account_id' => $feed->account_id]);
    }

    public function test_reversal_before_credit_is_sticky_and_does_not_suspend_other_receipts(): void
    {
        [$feed] = $this->kenya();
        $reverse = $this->receive($feed, $this->providerBody([], 'reverse-first', 'buygoods_transaction_reversed'));
        $this->receive($feed, $this->providerBody([], 'credit-later'));
        $this->assertSame('reversed', CollectionReceipt::find($reverse['receipt_id'])->funds_state);
        $this->assertNull($feed->account->fresh()->suspended_at);
        $this->assertSame('accepted', $this->receive($feed, $this->providerBody(['id' => 'resource-other', 'reference' => 'UKXFIX0002'], 'event-other'))['status']);
    }

    public function test_quiet_till_health_depends_on_poll_coverage_and_not_customer_payment_frequency(): void
    {
        [$feed] = $this->kenya();
        $this->assertTrue(app(CollectionDeviceHealthService::class)->accountReady($feed->account_id));
        $feed->update(['reconciled_until' => now()->subHour()]);
        $this->assertFalse(app(KopokopoFeedService::class)->health($feed->fresh())['capture_ready']);
        $this->receive($feed);
        $this->assertFalse(app(KopokopoFeedService::class)->health($feed->fresh())['capture_ready']);
    }

    public function test_provider_timestamp_requires_offset_and_historical_receipt_cannot_dispatch(): void
    {
        [$feed] = $this->kenya();
        $this->assertSame('quarantined', $this->receive($feed, $this->providerBody(['origination_time' => now()->toDateTimeString()]))['status']);
        $old = $this->receive($feed, $this->providerBody(['origination_time' => now()->subDays(2)->toIso8601String()], 'old-receipt'));
        $r = CollectionReceipt::find($old['receipt_id']);
        $this->assertTrue($r->historical_only);
        $this->assertFalse($r->automation_eligible_at_ingest);
        $this->assertNull($r->automation_epoch);
    }
}
