<?php

namespace Tests\Feature\Collections;

use App\Models\Client;
use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Collections\CollectionEvent;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionClaimCheckService;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionParserRegistry;
use App\Services\Collections\CollectionPrivacy;
use App\Services\Collections\CollectionReceiptService;
use App\Services\Collections\GhMtnMomoParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/** Synthetic Ghana MTN MoMo layouts only; no private SMS. */
class GhanaMomoCaptureTest extends CollectionTestCase
{
    private ?\App\Models\Product $product = null;

    private function momo(string $id = '94000000001', string $amount = '380.00', string $name = 'FIXTURE PAYER', string $reference = '1'): string
    {
        return "Payment received for GHS $amount from $name  Current Balance: GHS 2353.86 . Available Balance: GHS 2353.86. Reference: $reference. Transaction ID: $id. TRANSACTION FEE: 0.00";
    }

    private function ghana(): array
    {
        $privacy = app(CollectionPrivacy::class);
        $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => 'Fixture GH MoMo', 'country' => 'GH', 'provider_key' => 'mtn_momo_gh',
            'account_kind' => 'wallet', 'account_identifier' => 'gh-fixture', 'account_fingerprint' => $privacy->fingerprint('gh-fixture'),
            'currency' => 'GHS', 'timezone' => 'Africa/Accra', 'ownership' => 'company', 'mode' => 'shadow', 'verified_at' => now()]);
        $device = CollectionDevice::create(['id' => (string) Str::uuid(), 'label' => 'Fixture GH phone', 'external_device_id' => 'gh-phone', 'key_id' => 'gh-key',
            'signing_secret' => str_repeat('g', 32), 'cutover_at' => now()->subDays(3), 'inbox_cursor' => 'gh-cursor', 'last_seen_at' => now(), 'acceptance' => ['country' => 'GH']]);
        DB::table('collection_sim_bindings')->insert(['device_id' => $device->id, 'account_id' => $account->id, 'sim_number' => 1,
            'recipient_fingerprint' => $privacy->fingerprint('233540000099'), 'iccid_fingerprint' => $privacy->fingerprint('gh-iccid'),
            'carrier_name' => 'MTN', 'valid_from' => now()->subDays(30)]);
        $market = Platform::factory()->create(['id' => 303, 'currency_code' => 'GHS', 'phone_prefix' => '233']);
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $market->id, 'destination_snapshot' => json_encode(['fixture' => true]), 'valid_from' => now()->subDays(30)]);

        return [$account, $device, $market];
    }

    private function receive(CollectionDevice $device, string $body, string $id, ?string $at = null, string $sender = 'MobileMoney')
    {
        $event = app(CollectionIngestService::class)->accept($device, json_encode(['deviceId' => 'gh-phone', 'id' => $id, 'event' => 'sms:received', 'payload' => [
            'message' => $body, 'sender' => $sender, 'recipient' => '233540000099', 'simNumber' => 1, 'receivedAt' => $at ?? now()->toIso8601String()]], JSON_THROW_ON_ERROR));

        return app(CollectionReceiptService::class)->process($event['event_id']);
    }

    private function claim(Platform $market, string $reference, string $amount, string $at): Payment
    {
        // The product factory only yields three unique names per test, so claims share one product.
        $this->product ??= \App\Models\Product::factory()->create(['platform_id' => $market->id, 'currency' => 'GHS']);
        $client = Client::factory()->create(['platform_id' => $market->id, 'name' => 'Claimant '.Str::random(4)]);
        $deal = Deal::factory()->create(['platform_id' => $market->id, 'client_id' => $client->id, 'status' => 'active', 'product_id' => $this->product->id]);
        $payment = Payment::factory()->create(['platform_id' => $market->id, 'product_id' => $this->product->id, 'client_id' => $client->id, 'deal_id' => $deal->id, 'amount' => $amount, 'currency' => 'GHS',
            'status' => 'completed', 'source' => 'gateway', 'provider_key' => null, 'transaction_reference' => $reference, 'reference_number' => $reference]);
        $payment->forceFill(['created_at' => $at])->saveQuietly();

        return $payment;
    }

    public function test_parser_reads_reviewed_ghana_layouts_and_rejects_the_rest(): void
    {
        $p = app(GhMtnMomoParser::class);
        $credit = $p->parse('MobileMoney', $this->momo('94083222320', '230.00', 'FIXTURE PAYER', 'FIXTURE PAYER ,233209000001,1 from VODAFONE'));
        $this->assertSame(['credit', '94083222320', 23000, 'GHS', '233209000001', 'payment_received'],
            [$credit['kind'], $credit['reference'], $credit['principal_minor'], $credit['currency'], $credit['payer_phone'], $credit['family']]);
        $this->assertNull($credit['provider_at']);
        $this->assertSame('device_received', $credit['time_source']);
        $airtel = $p->parse('MobileMoney', $this->momo('94000000002', '380.00', 'FIXTURE', 'FIXTURE ,579000001,Funds transfer wallet to wallet from AIRTEL'));
        $this->assertSame('233579000001', $airtel['payer_phone']);
        $this->assertNull($p->parse('MobileMoney', $this->momo())['payer_phone']);
        $cash = $p->parse('MobileMoney', 'Cash In received for GHS 380.00 from FIXTURE 82 VENTURES. Current Balance GHS 15223.86 Available Balance GHS 15223.86. Transaction ID: 94041226655. Fee charged: GHS 0. Cash in (Deposit) is a free transaction on MTN Mobile Money.');
        $this->assertSame(['credit', 'cash_in', 38000], [$cash['kind'], $cash['family'], $cash['principal_minor']]);
        $this->assertNull($p->payerDetails('MobileMoney', 'Cash In received for GHS 380.00 from FIXTURE 82 VENTURES. Current Balance GHS 1.00 Available Balance GHS 1.00. Transaction ID: 94041226655.')['name']);
        $this->assertSame('FIXTURE PAYER', $p->payerDetails('MobileMoney', $this->momo())['name']);
        $this->assertSame(235386, $p->balanceMinor($this->momo()));
        $this->assertSame(1500000, $p->parse('MobileMoney', $this->momo('94000000003', '15,000.00'))['principal_minor']);

        $hold = $p->parse('MobileMoney', "Y'ello Valued customer, the transfer of GHS 380.00 from 233597000001 has been withheld and is pending reversal within 15 days. Thank you.");
        $this->assertSame(['hold', null, 'reversal_or_hold', 38000, '233597000001'], [$hold['kind'], $hold['reference'], $hold['reason'], $hold['principal_minor'], $hold['payer_phone']]);
        $done = $p->parse('MobileMoney', "Y'ello Valued Customer, the transfer of GHS 230.00 from 233594000001 has been reversed on 2026-11-15 12:52:23, with transaction ID:93569919264. Your new balance is GHS 6410.12. Transaction fee is GHS0.");
        $this->assertSame(['hold', null, 'reversal_completed'], [$done['kind'], $done['reference'], $done['reason']]);
        $this->assertSame(['unsupported', 'reversal_revoked', '93286766075'], array_values(array_intersect_key($p->parse('MobileMoney', 'The reversal of the transfer 93286766075 has been revoked. Note: si'), array_flip(['kind', 'reason', 'reference']))));
        foreach (['Payment made for GHS 15000.00 to FIXTURE Current Balance: GHS 893.82 . Available Balance: GHS 893.82. Reference: x. Transaction ID: 92188137001.',
            'Your payment of GHS 100.00 to MTN AIRTIME has been completed at 2026-11-17 10:05:34.', 'Go cashless with MoMoPay.',
            'The reversal request has been rejected for the transaction to FIXTURE (233550000001) with 255.00 GHS.'] as $body) {
            $this->assertSame('unsupported', $p->parse('MobileMoney', $body)['kind']);
        }
        $this->assertSame('unrecognized_credit', $p->parse('MobileMoney', 'Payment received for GHS 380.00 in a changed layout')['reason']);
        $this->assertSame('sender_not_supported', $p->parse('MTN', $this->momo())['reason']);
        $this->assertSame('exotic-gh-v1', app(CollectionParserRegistry::class)->filterVersion('GH'));
        $this->assertSame('233541000001', app(CollectionParserRegistry::class)->anyPhone('0541000001'));
        $this->assertSame('255700000001', app(CollectionParserRegistry::class)->anyPhone('0700000001'));
    }

    public function test_ghana_account_ingests_momo_with_device_time_and_never_accepts_m_pesa(): void
    {
        [$account, $device] = $this->ghana();
        $at = now()->subMinutes(5)->toIso8601String();
        $receipt = $this->receive($device, $this->momo('94000000010', '380.00', 'FIXTURE', 'FIXTURE ,233209000001,1 from VODAFONE'), 'gh-1', $at);
        $this->assertSame(['94000000010', 38000, 'GHS', false], [$receipt->reference_norm, $receipt->principal_minor, $receipt->currency, $receipt->historical_only]);
        $this->assertSame('233209000001', $receipt->payer_phone);
        $this->assertTrue($receipt->provider_at->equalTo(now()->subMinutes(5)->startOfSecond()));
        $this->assertSame([], $receipt->conflicts);
        $this->assertSame(GhMtnMomoParser::VERSION, CollectionEvent::where('external_event_id', 'gh-1')->value('parse_version'));
        // A repeat delivery of the same credit merges into the one receipt.
        $this->assertSame($receipt->id, $this->receive($device, $this->momo('94000000010', '380.00', 'FIXTURE', 'FIXTURE ,233209000001,1 from VODAFONE'), 'gh-1b', $at)->id);

        $this->assertNull($this->receive($device, '94000000011 Confirmed. You have received Tsh35,000.00 from 255700000001 - X on 30/10/26 at 14:59.', 'gh-2', null, 'M-PESA'));
        $this->assertSame('sender_not_supported', CollectionEvent::where('external_event_id', 'gh-2')->value('quarantine_reason'));

        $this->assertNull($this->receive($device, "Y'ello Valued customer, the transfer of GHS 380.00 from 233597000001 has been withheld and is pending reversal within 15 days. Thank you.", 'gh-3'));
        $this->assertTrue(DB::table('collection_audit')->where('action', 'unmatched_reversal_flagged')->exists());
        $this->assertNull($this->receive($device, 'The reversal of the transfer 94000000010 has been revoked. Note: si', 'gh-4'));
        $this->assertTrue(DB::table('collection_audit')->where('action', 'reversal_revoked_flagged')->exists());
        $this->assertNull($account->fresh()->suspended_at);

        $this->assertNull($this->receive($device, 'Payment received for GHS 380.00 in a changed layout', 'gh-5'));
        $this->assertSame('receiving_parser_drift', $account->fresh()->suspension_reason);
    }

    public function test_tz_account_still_rejects_mobile_money(): void
    {
        [, $device] = $this->inbox();
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->momo(), 'tz-momo', ['sender' => 'MobileMoney']));
        $this->assertNull(app(CollectionReceiptService::class)->process($event['event_id']));
        $this->assertSame('sender_not_supported', CollectionEvent::find($event['event_id'])->quarantine_reason);
    }

    public function test_claim_checks_flag_without_changing_subscriptions(): void
    {
        [$account, $device, $market] = $this->ghana();
        $base = now()->subHours(6);
        $this->receive($device, $this->momo('94000000101', '380.00', 'EXACT PAYER'), 'c1', $base->toIso8601String());
        $this->receive($device, $this->momo('94000000102', '230.00', 'SHORT PAYER'), 'c2', $base->toIso8601String());
        $this->receive($device, $this->momo('94000000103', '380.00', 'SHARED PAYER'), 'c3', $base->toIso8601String());
        $this->receive($device, $this->momo('94069791412', '230.00', 'TYPO PAYER'), 'c4', $base->toIso8601String());
        $this->receive($device, $this->momo('94000000105', '380.00', 'ISAAC BANAFO'), 'c5', $base->toIso8601String());

        $exact = $this->claim($market, '94000000101', '380.00', $base->copy()->addMinutes(10));
        $short = $this->claim($market, '94000000102', '380.00', $base->copy()->addMinutes(10));
        $shareA = $this->claim($market, '94000000103', '380.00', $base->copy()->addMinutes(10));
        $shareB = $this->claim($market, '94000000103', '380.00', $base->copy()->addMinutes(20));
        $typo = $this->claim($market, '9406979141', '230.00', $base->copy()->addMinutes(12));
        $named = $this->claim($market, 'ISAAC BANAFO', '380.00', $base->copy()->addMinutes(14));
        $fake = $this->claim($market, '2580', '10000.00', $base->copy()->addMinutes(15));
        $recent = $this->claim($market, '94099999999', '380.00', now()->subMinutes(5));
        $before = Deal::orderBy('id')->get(['id', 'status'])->toArray();

        $result = app(CollectionClaimCheckService::class)->forAccount($account, 14, false);
        $status = collect($result['claims'])->pluck('status', 'payment_id');
        $this->assertSame('verified', $status[$exact->id]);
        $this->assertSame('short_paid', $status[$short->id]);
        $this->assertSame('receipt_reused', $status[$shareA->id]);
        $this->assertSame('receipt_reused', $status[$shareB->id]);
        $this->assertSame('no_receipt', $status[$typo->id]);
        $this->assertSame('no_receipt', $status[$named->id]);
        $this->assertSame('no_receipt', $status[$fake->id]);
        $this->assertSame('pending', $status[$recent->id]);
        $rows = collect($result['claims'])->keyBy('payment_id');
        $this->assertSame('94069791412', $rows[$typo->id]['suggestion']['receipt']['reference']);
        $this->assertContains('transaction_id_one_digit_off', $rows[$typo->id]['suggestion']['reasons']);
        $this->assertSame('94000000105', $rows[$named->id]['suggestion']['receipt']['reference']);
        $this->assertSame(['payer_name_typed', 'same_amount'], $rows[$named->id]['suggestion']['reasons']);
        $this->assertNull($rows[$fake->id]['suggestion']);
        $this->assertSame(['verified' => 1, 'pending' => 1, 'no_receipt' => 3, 'short_paid' => 1, 'receipt_reused' => 2, 'reversed' => 0], $result['counts']);
        $this->assertSame($before, Deal::orderBy('id')->get(['id', 'status'])->toArray());

        // A manager-reviewed alias resolves the typo claim through the existing receipt alias.
        $admin = User::factory()->create(['role' => 'admin']);
        config(['collection.workspace_enabled' => true]);
        Sanctum::actingAs($admin);
        $this->postJson('/api/crm/collections/receipts/'.$rows[$typo->id]['suggestion']['receipt']['id'].'/aliases', ['reference' => '9406979141', 'evidence_reference' => 'claim-check payment '.$typo->id])->assertOk();
        $flagged = $this->getJson('/api/crm/collections/claim-checks')->assertOk();
        $this->assertSame('Fixture GH MoMo', $flagged->json('accounts.0.label'));
        $ids = collect($flagged->json('accounts.0.claims'))->pluck('payment_id')->all();
        $this->assertNotContains($typo->id, $ids);
        $this->assertNotContains($exact->id, $ids);
        $this->assertContains($fake->id, $ids);
    }

    public function test_enrollment_accepts_ghana_only_with_its_own_filter_and_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $market = Platform::factory()->create(['id' => 304, 'currency_code' => 'GHS']);
        $input = ['account_label' => 'GH', 'country' => 'GH', 'provider_key' => 'mtn_momo_gh', 'account_kind' => 'wallet', 'account_identifier' => 'gh-wallet',
            'currency' => 'GHS', 'timezone' => 'Africa/Accra', 'ownership' => 'company', 'ownership_evidence_reference' => 'fixture', 'device_label' => 'GH phone',
            'external_device_id' => 'gh-enrol', 'signing_secret' => str_repeat('k', 32), 'inbox_cursor' => '1', 'sim_number' => 1, 'recipient' => '0541000001',
            'sim_identity_mode' => 'android_subscription_v1', 'android_subscription_id' => 1, 'filter_version' => 'exotic-gh-v1', 'carrier_name' => 'MTN',
            'apk_sha256' => str_repeat('a', 64), 'apk_version' => 'fixture-gh', 'platform_bindings' => [['platform_id' => $market->id, 'destination_snapshot' => ['fixture' => true]]]];
        foreach ([['filter_version' => 'exotic-tz-v1'], ['recipient' => '0700000001'], ['provider_key' => 'vodacom_mpesa_tz']] as $bad) {
            try {
                app(\App\Services\Collections\CollectionEnrollmentService::class)->enroll($admin, array_merge($input, $bad));
                $this->fail('Mismatched Ghana enrollment accepted');
            } catch (\App\Services\Collections\CollectionException $e) {
                $this->assertSame('verified_enrollment_evidence_required', $e->reason);
            }
        }
        $result = app(\App\Services\Collections\CollectionEnrollmentService::class)->enroll($admin, $input);
        $device = CollectionDevice::findOrFail($result['device_id']);
        $this->assertSame('GH', $device->acceptance['country']);
        $this->assertSame('233541000001', app(\App\Services\Collections\CollectionSimIdentityService::class)->recipient($device, '+233 54 100 0001'));
        $this->assertSame('shadow', $result['mode']);
    }
}
