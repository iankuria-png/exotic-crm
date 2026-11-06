<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionEvent;
use App\Models\Collections\CollectionReceipt;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionReceiptService;
use App\Services\Collections\TzMpesaParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CollectionCaptureTest extends CollectionTestCase
{
    public function test_signed_delivery_is_durable_encrypted_and_replayed_twenty_times_without_financial_writes(): void
    {
        [$a, $d] = $this->inbox();
        $body = $this->envelope($this->credit());
        $at = (string) now()->timestamp;
        for ($i = 0; $i < 20; $i++) {
            $this->call('POST', '/api/collection-events/v1/devices/'.$d->id, [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $at,
                'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body.$at, $d->signing_secret),
            ], $body)->assertStatus(202)->assertJsonPath('status', $i ? 'duplicate' : 'accepted');
        }
        $this->assertDatabaseCount('collection_events', 1);
        $this->assertDatabaseCount('collection_outbox', 1);
        $this->assertStringNotContainsString('FIXTURE PAYER', DB::table('collection_events')->first()->raw_body);
        $this->assertStringNotContainsString('FIXTURE PAYER', json_encode(CollectionEvent::first()));
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('deals', 0);
        Http::assertNothingSent();
    }

    public function test_native_signature_rejects_stale_modified_and_revoked_keys_and_default_gate(): void
    {
        [$a, $d] = $this->inbox();
        $body = $this->envelope($this->credit());
        $send = function ($at, $sig) use ($d, $body) {
            return $this->call('POST', '/api/collection-events/v1/devices/'.$d->id, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TIMESTAMP' => $at, 'HTTP_X_SIGNATURE' => $sig], $body);
        };
        $at = (string) now()->subMinutes(6)->timestamp;
        $send($at, hash_hmac('sha256', $body.$at, $d->signing_secret))->assertStatus(401);
        $send((string) now()->timestamp, str_repeat('a', 64))->assertStatus(401);
        $d->update(['revoked_at' => now()]);
        $at = (string) now()->timestamp;
        $send($at, hash_hmac('sha256', $body.$at, $d->signing_secret))->assertStatus(401);
        config(['collection.capture_enabled' => false]);
        $send($at, str_repeat('a', 64))->assertStatus(404);
        $this->assertDatabaseCount('collection_events', 0);
    }

    public function test_complementary_messages_merge_amount_phone_and_field_provenance(): void
    {
        [$a, $d] = $this->inbox();
        $ingest = app(CollectionIngestService::class);
        $process = app(CollectionReceiptService::class);
        $summary = 'ABCDEF12345 confirmed. You have received a payment of Tsh35,000.00 from 123456 - FIXTURE BANK on 30/10/26 at 14:59. New M-Pesa balance Tsh100,000.00';
        $first = $ingest->accept($d, $this->envelope($summary));
        $r = $process->process($first['event_id']);
        $this->assertNull($r->payer_phone);
        $this->assertFalse($r->historical_only);
        $detail = 'ABCDEF12345 imethibitishwa. Umepokea Tshs 35,000.00 kutoka BANK : 255700000001 - FIXTURE PAYER tarehe 30/10/2026 saa 14:59:30.';
        $second = $ingest->accept($d, $this->envelope($detail, 'detail'));
        $r = $process->process($second['event_id']);
        $process->process($second['event_id']);
        $this->assertDatabaseCount('collection_receipts', 1);
        $this->assertSame(3500000, $r->principal_minor);
        $this->assertSame('255700000001', $r->payer_phone);
        $this->assertSame([$second['event_id']], $r->field_provenance['payer_phone']);
        $this->assertCount(2, $r->field_provenance['principal_minor']);
        $this->assertSame([], $r->conflicts);
    }

    public function test_history_is_sticky_and_old_provider_time_is_historical_even_when_delivered_now(): void
    {
        [$a, $d] = $this->inbox();
        $i = app(CollectionIngestService::class);
        $p = app(CollectionReceiptService::class);
        $first = $i->accept($d, $this->envelope($this->credit()), true);
        $this->assertTrue($p->process($first['event_id'])->historical_only);
        $second = $i->accept($d, $this->envelope($this->credit(), 'late-live'));
        $this->assertTrue($p->process($second['event_id'])->historical_only);
        $old = $i->accept($d, $this->envelope(str_replace('30/10/26', '29/10/26', $this->credit('ZZZZZZ12345')), 'old-provider'));
        $this->assertTrue($p->process($old['event_id'])->historical_only);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_changed_transport_bytes_and_wrong_sim_are_quarantined_without_losing_original(): void
    {
        [$a, $d] = $this->inbox();
        $s = app(CollectionIngestService::class);
        $body = $this->envelope($this->credit());
        $s->accept($d, $body);
        $result = $s->accept($d, $this->envelope($this->credit('ABCDEF12345', '30,000')));
        $this->assertSame('quarantined', $result['status']);
        $this->assertSame($body, CollectionEvent::first()->raw_body);
        $this->assertDatabaseCount('collection_event_conflicts', 1);
        $this->assertNotNull($a->fresh()->suspended_at);
        $this->assertSame('quarantined', $s->accept($d, $this->envelope($this->credit(), 'sim-swap', ['simNumber' => 2]))['status']);
    }

    public function test_reversal_before_credit_and_amount_phone_time_conflicts_never_create_a_new_receipt(): void
    {
        [$a, $d] = $this->inbox();
        $s = app(CollectionIngestService::class);
        $p = app(CollectionReceiptService::class);
        foreach (['The transaction ABCDEF12345 has been successfully reversed.', $this->credit(),
            $this->credit('ABCDEF12345', '30,000', '255700000002'), str_replace('14:59', '14:50', $this->credit())] as $n => $body) {
            $event = $s->accept($d, $this->envelope($body, 'event-'.$n));
            $p->process($event['event_id']);
        }
        $this->assertDatabaseCount('collection_receipts', 1);
        $r = CollectionReceipt::first();
        $this->assertSame('reversed', $r->funds_state);
        $this->assertSame('needs_review', $r->decision_state);
        foreach (['principal_minor_conflict', 'payer_phone_conflict', 'provider_time_conflict'] as $reason) {
            $this->assertContains($reason, $r->conflicts);
        }
    }

    public function test_all_four_layouts_opaque_codes_and_negative_direction_cases(): void
    {
        $p = app(TzMpesaParser::class);
        $bodies = [$this->credit('ABCDEFGHIJ'), $this->credit(),
            'ABCDEFGHIJ Confirmed. On 30/10/26, 2:59 PM Receive Tsh35,000 from FIXTURE NAME and balance Tsh900,000.',
            'ABCDEFGHIJK imethibitishwa. Umepokea Tshs 35,000 kutoka FIXTURE NAME tarehe 30-10-2026 saa 14:59.'];
        foreach ($bodies as $body) {
            $result = $p->parse('M-PESA', $body);
            $this->assertSame('credit', $result['kind']);
            $this->assertSame(3500000, $result['principal_minor']);
            $this->assertSame('2026-10-30 11:59:00', $result['provider_at']);
        }
        foreach (['Your OTP is 12345', 'ABCDEFGHIJ Confirmed. You have sent Tsh35000 to FIXTURE.',
            'ABCDEFGHIJ Confirmed. You have received Tsh0 from FIXTURE on 30/10/26 at 14:59.',
            'ABCDEFGHIJ Confirmed. You have received Tsh35000 from FIXTURE on 31/02/26 at 14:59.'] as $body) {
            $this->assertSame('unsupported', $p->parse('M-PESA', $body)['kind']);
        }
        $this->assertSame('unsupported', $p->parse('BANK', $this->credit())['kind']);
    }
}
