<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Models\Collections\CollectionEvent;
use App\Models\Platform;
use App\Models\User;
use App\Services\Collections\CollectionEnrollmentService;
use App\Services\Collections\CollectionException;
use App\Services\Collections\CollectionIngestService;
use App\Services\Collections\CollectionParserRegistry;
use App\Services\Collections\CollectionPayerEvidenceService;
use App\Services\Collections\CollectionPrivacy;
use App\Services\Collections\CollectionReceiptService;
use App\Services\Collections\CollectionSimIdentityService;
use App\Services\Collections\CollectionWalletBalanceService;
use App\Services\Collections\UgMtnMomoPayParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Synthetic Uganda MTN MoMoPay Till layouts only; no private SMS. */
class UgandaMomoPayCaptureTest extends CollectionTestCase
{
    private function wallet(string $id = '45000000001', string $amount = '50000', string $name = 'FIXTURE PAYER', string $at = '2026-11-24 17:04:16'): string
    {
        return "You have received UGX $amount from $name at $at.  Fee: 500. Message: Till:632555. New balance is: UGX 900680. Transaction ID: $id. Download MoMo App http://bit.ly/x to get 500MBs.";
    }

    private function momoPay(string $id = '45000000001', string $amount = '50,000', string $name = 'FIXTURE PAYER', string $phone = '256780000001'): string
    {
        return "You have received UGX $amount from ($name) $phone. Your new MoMoPay balance: 900680.46. Transaction ID: $id. Download MoMo App http://bit.ly/x to get 500MBs.";
    }

    private function uganda(): array
    {
        $privacy = app(CollectionPrivacy::class);
        $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => 'Fixture UG Till', 'country' => 'UG', 'provider_key' => 'mtn_momopay_ug',
            'account_kind' => 'till', 'account_identifier' => 'ug-fixture', 'account_fingerprint' => $privacy->fingerprint('ug-fixture'),
            'currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'ownership' => 'company', 'mode' => 'shadow', 'verified_at' => now()]);
        $device = CollectionDevice::create(['id' => (string) Str::uuid(), 'label' => 'Fixture UG phone', 'external_device_id' => 'ug-phone', 'key_id' => 'ug-key',
            'signing_secret' => str_repeat('u', 32), 'cutover_at' => now()->subDays(3), 'inbox_cursor' => 'ug-cursor', 'last_seen_at' => now(), 'acceptance' => ['country' => 'UG']]);
        DB::table('collection_sim_bindings')->insert(['device_id' => $device->id, 'account_id' => $account->id, 'sim_number' => 1,
            'recipient_fingerprint' => $privacy->fingerprint('256770000099'), 'iccid_fingerprint' => $privacy->fingerprint('ug-iccid'),
            'carrier_name' => 'MTN', 'valid_from' => now()->subDays(30)]);
        $market = Platform::factory()->create(['id' => 305, 'currency_code' => 'UGX', 'phone_prefix' => '256']);
        DB::table('collection_account_platforms')->insert(['account_id' => $account->id, 'platform_id' => $market->id, 'destination_snapshot' => json_encode(['fixture' => true]), 'valid_from' => now()->subDays(30)]);

        return [$account, $device, $market];
    }

    private function receive(CollectionDevice $device, string $body, string $id, ?string $at = null, string $sender = 'MTNMobMoney')
    {
        $event = app(CollectionIngestService::class)->accept($device, json_encode(['deviceId' => 'ug-phone', 'id' => $id, 'event' => 'sms:received', 'payload' => [
            'message' => $body, 'sender' => $sender, 'recipient' => '256770000099', 'simNumber' => 1, 'receivedAt' => $at ?? now()->toIso8601String()]], JSON_THROW_ON_ERROR));

        return app(CollectionReceiptService::class)->process($event['event_id']);
    }

    /** Provider-local (Kampala, UTC+3) clock string for a moment N minutes ago. */
    private function kampala(int $minutesAgo): string
    {
        return now()->subMinutes($minutesAgo)->startOfSecond()->setTimezone('Africa/Kampala')->format('Y-m-d H:i:s');
    }

    public function test_parser_reads_both_till_views_and_rejects_the_rest(): void
    {
        $p = app(UgMtnMomoPayParser::class);
        $wallet = $p->parse('MTNMobMoney', $this->wallet('45331931063', '50000', 'FIXTURE PAYER', '2026-11-24 17:04:16'));
        $this->assertSame(['credit', '45331931063', 5000000, 'UGX', null, '2026-11-24 14:04:16', 'till_wallet'],
            [$wallet['kind'], $wallet['reference'], $wallet['principal_minor'], $wallet['currency'], $wallet['payer_phone'], $wallet['provider_at'], $wallet['family']]);
        $view = $p->parse('MTNMobMoney', $this->momoPay('45331931063'));
        $this->assertSame(['credit', '45331931063', 5000000, '256780000001', null, 'paired_view'],
            [$view['kind'], $view['reference'], $view['principal_minor'], $view['payer_phone'], $view['provider_at'], $view['time_source']]);
        $this->assertSame(5000000, $p->parse('MTNMobMoney', 'You have received UGX 50,000 from (X Y) 256780000001. Your new MoMoPay balance: UGX 1000.00. Transaction ID: 45000000009.')['principal_minor']);
        $this->assertSame('256390000001', $p->parse('MTNMobMoney', $this->momoPay('45000000008', '20,000', 'X Y', '256390000001'))['payer_phone']);
        $this->assertSame('5000000', (string) $p->parse('MTNMobMoney', str_replace('Till:632555', 'Till  632555', $this->wallet()))['principal_minor']);
        $old = $p->parse('MTNMobMoney', 'You have received 60000 UGX from FIXTURE PAYER (256770000002) on your mobile money account at 2025-08-05 20:45:03. Message from sender: Till:632555. Your new balance: 1410121 UGX. Coupon amount was . Fee was 900 UGX. Financial Transaction Id: 34436500280. External Transaction Id: -.');
        $this->assertSame(['credit', '34436500280', 6000000, '256770000002', 'till_wallet_v0'], [$old['kind'], $old['reference'], $old['principal_minor'], $old['payer_phone'], $old['family']]);
        $this->assertSame('credit', $p->parse('MTNMobMoney', 'You have received UGX 60,000 from (FIXTURE PAYER) 256780000003. Your new MoMoPayAccount balance: 1410120.82. ID: 34436500281.')['kind']);

        $this->assertSame(['FIXTURE PAYER', '256780000001'], array_values(array_intersect_key($p->payerDetails('MTNMobMoney', $this->momoPay()), array_flip(['name', 'phone']))));
        $this->assertSame(90068000, $p->balanceMinor($this->wallet()));
        $this->assertSame(90068046, $p->balanceMinor($this->momoPay()));

        $reversed = $p->parse('MTNMobMoney', 'The transaction from FIXTURE (256770000004) with 60000 UGX has been reversed at 2026-03-18 11:03:39. Your new balance is 1903665 UGX. Financial Transaction Id: 39299427597. External Transaction Id: -. .');
        $this->assertSame(['hold', null, 'reversal_completed', 6000000, '256770000004'],
            [$reversed['kind'], $reversed['reference'], $reversed['reason'], $reversed['principal_minor'], $reversed['payer_phone']]);
        $request = $p->parse('MTNMobMoney', 'FIXTURE, 256760000005 has initiated a reversal of 50000 wrongly sent to you. Please approve the reversal by dialling *165*8*3# and enter your PIN.');
        $this->assertSame(['hold', 'reversal_or_hold', 5000000], [$request['kind'], $request['reason'], $request['principal_minor']]);

        $this->assertSame('personal_wallet_credit', $p->parse('MTNMobMoney', 'You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. fee:0. Reason: Apps. New balance: UGX 387200. ID: 45298853873.')['reason']);
        $this->assertSame('personal_wallet_credit', $p->parse('MTNMobMoney', 'You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. Reason: 1. New balance: UGX 387200. ID: 45298853874.')['reason']);
        $this->assertFalse($p->looksLikeCredit('You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. fee:0. Reason: Apps.'));
        foreach (['You have sent UGX 10000 to FIXTURE, 256770000007 on 2026-11-20 10:00:00. fee: 500. Reason: family. New balance: 1.', 'You have bought UGX 1000 airtime. New MoMo balance: UGX 5.',
            'You have deposited UGX 5000 from FIXTURE on 2026-11-20 10:00:00. New balance: UGX 10.', "Y'ello! Money sent successfully."] as $body) {
            $this->assertSame('unsupported', $p->parse('MTNMobMoney', $body)['kind']);
        }
        $this->assertSame('unrecognized_credit', $p->parse('MTNMobMoney', 'You have received UGX 50000 in a changed layout')['reason']);
        $this->assertSame('sender_not_supported', $p->parse('MTNMoMo', $this->wallet())['reason']);

        $registry = app(CollectionParserRegistry::class);
        $this->assertSame('exotic-ug-v1', $registry->filterVersion('UG'));
        $this->assertSame('256770000001', $registry->phone('UG', '0770000001'));
        $this->assertSame('256770000001', $registry->anyPhone('+256 770 000001'));
        $this->assertSame('255700000001', $registry->anyPhone('0700000001'));
    }

    public function test_the_two_till_messages_merge_into_one_live_receipt_in_either_order(): void
    {
        [$account, $device] = $this->uganda();
        // Wallet view first: provider time, then the MoMoPay view adds the payer phone.
        $first = $this->receive($device, $this->wallet('45000000010', '60000', 'FIXTURE PAYER', $this->kampala(5)), 'ug-1a', now()->subMinutes(5)->addSeconds(6)->toIso8601String());
        $this->assertNull($first->payer_phone);
        $merged = $this->receive($device, $this->momoPay('45000000010', '60,000'), 'ug-1b', now()->subMinutes(5)->addSeconds(8)->toIso8601String());
        $this->assertSame($first->id, $merged->id);
        $this->assertSame([6000000, 'UGX', '256780000001', false, []], [$merged->principal_minor, $merged->currency, $merged->payer_phone, $merged->historical_only, $merged->conflicts]);
        $this->assertTrue($merged->provider_at->equalTo(now()->subMinutes(5)->startOfSecond()));
        $this->assertSame($merged->payer_fingerprint, app(CollectionPrivacy::class)->fingerprint('256780000001'));
        $this->assertSame(UgMtnMomoPayParser::VERSION, CollectionEvent::where('external_event_id', 'ug-1b')->value('parse_version'));

        // MoMoPay view first: no provider clock, but a live payment must never be classified historical.
        $early = $this->receive($device, $this->momoPay('45000000011', '50,000'), 'ug-2a', now()->subMinutes(2)->toIso8601String());
        $this->assertSame([false, null], [$early->historical_only, $early->provider_at]);
        $late = $this->receive($device, $this->wallet('45000000011', '50000', 'FIXTURE PAYER', $this->kampala(2)), 'ug-2b');
        $this->assertSame([false, []], [$late->historical_only, $late->conflicts]);
        $this->assertNotNull($late->provider_at);

        // A paid amount that disagrees between the two views is a conflict for staff.
        $this->receive($device, $this->wallet('45000000012', '50000', 'FIXTURE PAYER', $this->kampala(1)), 'ug-3a');
        $bad = $this->receive($device, $this->momoPay('45000000012', '5,000'), 'ug-3b');
        $this->assertContains('principal_minor_conflict', $bad->conflicts);
        $this->assertSame('needs_review', $bad->decision_state);

        $balances = app(CollectionWalletBalanceService::class)->forReceipts(collect([$merged->fresh()]));
        $this->assertSame('UGX', $balances[$merged->id]['currency']);
        $evidence = app(CollectionPayerEvidenceService::class)->forReceipt($merged->fresh());
        $this->assertSame('FIXTURE PAYER', $evidence['name']);
        $this->assertNull($account->fresh()->suspended_at);
    }

    public function test_personal_wallet_and_reversals_are_flagged_without_drift_but_layout_changes_suspend(): void
    {
        [$account, $device] = $this->uganda();
        $this->assertNull($this->receive($device, 'You have received UGX 90000 from FIXTURE, 256790000006 on 2026-11-23 11:52:26. fee:0. Reason: Apps. New balance: UGX 387200. ID: 45298853873.', 'ug-p2p'));
        $this->assertSame('personal_wallet_credit', CollectionEvent::where('external_event_id', 'ug-p2p')->value('quarantine_reason'));
        $this->assertNull($this->receive($device, 'The transaction from FIXTURE (256770000004) with 60000 UGX has been reversed at 2026-03-18 11:03:39. Your new balance is 1 UGX. Financial Transaction Id: 39299427597. External Transaction Id: -. .', 'ug-rev'));
        $this->assertTrue(DB::table('collection_audit')->where('action', 'unmatched_reversal_flagged')->exists());
        $this->assertNull($this->receive($device, '45000000099 Confirmed. You have received Tsh35,000.00 from 255700000001 - X on 30/10/26 at 14:59.', 'ug-mpesa', null, 'M-PESA'));
        $this->assertSame('sender_not_supported', CollectionEvent::where('external_event_id', 'ug-mpesa')->value('quarantine_reason'));
        $this->assertNull($account->fresh()->suspended_at);

        $this->assertNull($this->receive($device, 'You have received UGX 50000 in a changed layout', 'ug-drift'));
        $this->assertSame('receiving_parser_drift', $account->fresh()->suspension_reason);
    }

    public function test_tz_account_still_rejects_mtn_uganda_messages(): void
    {
        [, $device] = $this->inbox();
        $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->wallet(), 'tz-ug', ['sender' => 'MTNMobMoney']));
        $this->assertNull(app(CollectionReceiptService::class)->process($event['event_id']));
        $this->assertSame('sender_not_supported', CollectionEvent::find($event['event_id'])->quarantine_reason);
    }

    public function test_enrollment_accepts_uganda_only_with_its_own_filter_and_number(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $market = Platform::factory()->create(['id' => 306, 'currency_code' => 'UGX']);
        $input = ['account_label' => 'UG', 'country' => 'UG', 'provider_key' => 'mtn_momopay_ug', 'account_kind' => 'till', 'account_identifier' => 'ug-till',
            'currency' => 'UGX', 'timezone' => 'Africa/Kampala', 'ownership' => 'company', 'ownership_evidence_reference' => 'fixture', 'device_label' => 'UG phone',
            'external_device_id' => 'ug-enrol', 'signing_secret' => str_repeat('k', 32), 'inbox_cursor' => '1', 'sim_number' => 1, 'recipient' => '0770000001',
            'sim_identity_mode' => 'android_subscription_v1', 'android_subscription_id' => 1, 'filter_version' => 'exotic-ug-v1', 'carrier_name' => 'MTN',
            'apk_sha256' => str_repeat('a', 64), 'apk_version' => 'fixture-ug', 'platform_bindings' => [['platform_id' => $market->id, 'destination_snapshot' => ['fixture' => true]]]];
        foreach ([['filter_version' => 'exotic-gh-v1'], ['recipient' => '0541000001'], ['provider_key' => 'mtn_momo_gh']] as $bad) {
            try {
                app(CollectionEnrollmentService::class)->enroll($admin, array_merge($input, $bad));
                $this->fail('Mismatched Uganda enrollment accepted');
            } catch (CollectionException $e) {
                $this->assertSame('verified_enrollment_evidence_required', $e->reason);
            }
        }
        $result = app(CollectionEnrollmentService::class)->enroll($admin, $input);
        $device = CollectionDevice::findOrFail($result['device_id']);
        $this->assertSame('UG', $device->acceptance['country']);
        $this->assertSame('256770000001', app(CollectionSimIdentityService::class)->recipient($device, '+256 77 000 0001'));
        $this->assertSame('shadow', $result['mode']);
    }
}
