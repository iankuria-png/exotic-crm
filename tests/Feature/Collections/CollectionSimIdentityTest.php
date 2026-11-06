<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Services\Collections\CollectionDeviceHealthService;
use App\Services\Collections\CollectionIngestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CollectionSimIdentityTest extends CollectionWorkflowTestCase
{
    private function androidDevice($device): void
    {
        $device->update(['acceptance' => array_merge($device->acceptance ?? [], ['sim_identity_mode' => 'android_subscription_v1',
            'filter_version' => 'exotic-tz-v1', 'android_subscription_ids' => ['1' => 9]])]);
    }

    private function card(array $extra = []): array
    {
        return array_merge(['simNumber' => 1, 'subscriptionId' => 9, 'carrierName' => 'Vodacom', 'phoneNumber' => null, 'iccid' => null], $extra);
    }

    public function test_modern_binding_accepts_actual_subscription_id_and_explicit_enrolled_recipient_without_iccid(): void
    {
        [$account, $device] = $this->inbox();
        $this->androidDevice($device);
        $service = app(CollectionIngestService::class);
        $start = $service->accept($device, json_encode(['id' => 'start-android', 'deviceId' => $device->external_device_id, 'event' => 'app:started', 'payload' => ['simCards' => [$this->card()]]], JSON_THROW_ON_ERROR));
        $this->assertSame('accepted', $start['status']);
        $event = $service->accept($device, $this->envelope($this->credit(), 'enrolled-recipient', [
            'subscriptionId' => 9, 'carrierName' => 'Vodacom', 'recipientSource' => 'enrollment', 'measuredRecipient' => null,
        ]));
        $this->assertSame('accepted', $event['status']);
        $measured = $service->accept($device, $this->envelope($this->credit(), 'measured-recipient', [
            'recipient' => '0700000099', 'subscriptionId' => 9, 'carrierName' => 'Vodacom',
            'recipientSource' => 'device', 'measuredRecipient' => '+255700000099',
        ]));
        $this->assertSame('accepted', $measured['status']);
        $this->assertNull($account->fresh()->suspended_at);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_changed_blank_or_unproven_subscription_identity_is_quarantined_and_cannot_clear_account_suspension(): void
    {
        [$account, $device] = $this->inbox();
        $this->androidDevice($device);
        $base = ['subscriptionId' => 9, 'carrierName' => 'Vodacom', 'recipientSource' => 'enrollment', 'measuredRecipient' => null];
        foreach ([['subscriptionId' => 10], ['subscriptionId' => null], ['subscriptionId' => '9'], ['simNumber' => 2],
            ['carrierName' => 'Changed'], ['recipientSource' => null], ['recipientSource' => 'device'],
            ['measuredRecipient' => '+255700000000']] as $i => $changed) {
            $event = app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit(), 'changed-'.$i, array_merge($base, $changed)));
            $this->assertSame('quarantined', $event['status']);
        }
        $this->assertNotNull($account->fresh()->suspended_at);
        $this->captureCheckIn($device, ['simBindings' => [$this->card()]]);
        $this->assertNotNull($account->fresh()->suspended_at, 'A later ping cannot silently clear a SIM incident.');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_missing_identity_mode_keeps_strict_iccid_behavior_and_unknown_mode_never_matches(): void
    {
        [$account, $device] = $this->inbox();
        $start = ['id' => 'legacy-no-iccid', 'deviceId' => $device->external_device_id, 'event' => 'app:started', 'payload' => ['simCards' => [$this->card(['phoneNumber' => '255700000099'])]]];
        $this->assertSame('quarantined', app(CollectionIngestService::class)->accept($device, json_encode($start))['status']);
        $device->update(['acceptance' => ['sim_identity_mode' => 'unknown']]);
        $this->assertSame('quarantined', app(CollectionIngestService::class)->accept($device, $this->envelope($this->credit(), 'unknown-mode'))['status']);
    }

    public function test_all_shared_phone_bindings_must_match_current_measured_inventory(): void
    {
        [$account, $device] = $this->inbox();
        $this->androidDevice($device);
        $other = CollectionAccount::create(array_merge($account->getAttributes(), ['id' => (string) Str::uuid(), 'account_fingerprint' => str_repeat('b', 64)]));
        $binding = (array) DB::table('collection_sim_bindings')->where('device_id', $device->id)->first();
        unset($binding['id']);
        DB::table('collection_sim_bindings')->insert(array_merge($binding, ['account_id' => $other->id, 'sim_number' => 2]));
        $device->update(['acceptance' => array_merge($device->acceptance, ['android_subscription_ids' => ['1' => 9, '2' => 10]])]);
        $this->captureCheckIn($device, ['simBindings' => [$this->card()]]);
        $health = app(CollectionDeviceHealthService::class);
        $this->assertFalse($health->accountReady($account->id));
        $this->assertFalse($health->accountReady($other->id));
        $this->assertNotNull($account->fresh()->suspended_at);
        $this->assertNotNull($other->fresh()->suspended_at);
        $this->captureCheckIn($device, ['simBindings' => [$this->card(), $this->card(['simNumber' => 2, 'subscriptionId' => 10])]]);
        $this->assertTrue($health->accountReady($account->id));
        $this->assertNotNull($account->fresh()->suspended_at);
    }
}
