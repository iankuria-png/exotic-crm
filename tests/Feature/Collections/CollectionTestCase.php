<?php

namespace Tests\Feature\Collections;

use App\Models\Collections\CollectionAccount;
use App\Models\Collections\CollectionDevice;
use App\Services\Collections\CollectionPrivacy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class CollectionTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        config(['collection.payer_fingerprint_key' => str_repeat('f', 32), 'collection.capture_enabled' => true]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    protected function inbox(): array
    {
        $privacy = app(CollectionPrivacy::class);
        $account = CollectionAccount::create(['id' => (string) Str::uuid(), 'label' => 'Fixture TZ', 'country' => 'TZ', 'provider_key' => 'vodacom_mpesa_tz',
            'account_kind' => 'merchant', 'account_identifier' => 'fixture-account', 'account_fingerprint' => $privacy->fingerprint('fixture-account'),
            'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'ownership' => 'company', 'mode' => 'shadow', 'verified_at' => now()]);
        $device = CollectionDevice::create(['id' => (string) Str::uuid(), 'label' => 'Fixture phone', 'external_device_id' => 'fixture-phone', 'key_id' => 'fixture-key',
            'signing_secret' => str_repeat('s', 32), 'cutover_at' => now()->subHour(), 'inbox_cursor' => 'fixture-cursor', 'last_seen_at' => now(), 'acceptance' => []]);
        DB::table('collection_sim_bindings')->insert(['device_id' => $device->id, 'account_id' => $account->id, 'sim_number' => 1,
            'recipient_fingerprint' => $privacy->fingerprint('255700000099'), 'iccid_fingerprint' => $privacy->fingerprint('fixture-iccid'),
            'carrier_name' => 'Vodacom', 'valid_from' => now()->subDays(900)]);

        return [$account, $device];
    }

    protected function envelope(string $message, string $id = 'fixture-event', array $extra = []): string
    {
        return json_encode(['deviceId' => 'fixture-phone', 'id' => $id, 'event' => 'sms:received', 'payload' => array_merge([
            'message' => $message, 'sender' => 'M-PESA', 'recipient' => '+255700000099', 'simNumber' => 1, 'receivedAt' => now()->toIso8601String(),
        ], $extra)], JSON_THROW_ON_ERROR);
    }

    protected function credit(string $reference = 'ABCDEF12345', string $amount = '35,000.00', string $phone = '255700000001'): string
    {
        return "$reference Confirmed. You have received Tsh$amount from $phone - FIXTURE PAYER on 30/10/26 at 14:59. New M-Pesa balance Tsh900,000.00";
    }
}
