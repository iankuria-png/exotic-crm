<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ContactUnlockEvent;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use App\Services\ContactUnlockDemandDetailService;
use App\Services\ContactUnlockPulseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Demand drill-downs exist to explain the KPI cards, so every assertion here checks the
 * drawer's totals against the card's own number. If they ever drift, the drawer is lying.
 */
class ContactUnlockDemandDetailTest extends TestCase
{
    use RefreshDatabase;

    /** ProductFactory only has three unique names, so every payment and deal in a test shares one product. */
    private ?Product $product = null;

    public function test_renewed_after_demand_lists_the_clients_behind_the_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $platform = $this->market();
        $client = $this->client($platform, 'Mitchell');

        $this->paidUnlock($platform, $client, ['created_at' => Carbon::parse('2026-09-02 09:00:00')]);
        $this->paidUnlock($platform, $client, ['created_at' => Carbon::parse('2026-09-03 09:00:00')]);
        // After the renewal — real demand, but it cannot have caused the renewal.
        $this->paidUnlock($platform, $client, ['created_at' => Carbon::parse('2026-09-08 09:00:00')]);

        $renewalPayment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'product_id' => (int) $this->product($platform)->id,
            'client_id' => (int) $client->id,
            'amount' => 1500,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'completed_at' => Carbon::parse('2026-09-05 10:00:00'),
        ]);

        $this->deal($platform, $client, [
            'activated_at' => Carbon::parse('2026-08-06 10:00:00'),
            'expires_at' => Carbon::parse('2026-09-06 10:00:00'),
        ]);
        $this->deal($platform, $client, [
            'payment_id' => (int) $renewalPayment->id,
            'activated_at' => Carbon::parse('2026-09-05 10:00:00'),
            'expires_at' => Carbon::parse('2026-10-05 10:00:00'),
        ]);

        $detail = $this->detail(ContactUnlockDemandDetailService::METRIC_RENEWED, $platform);
        $kpi = $this->kpis($platform);

        $this->assertCount(1, $detail['rows']);
        $this->assertSame($kpi['renewed_after_paid_demand'], $detail['totals']['clients']);

        $row = $detail['rows'][0];
        $this->assertSame('Mitchell', $row['client_name']);
        $this->assertSame(2, $row['unlocks_before_renewal'], 'Only demand that preceded the renewal counts.');
        $this->assertSame(3, $row['unlocks_in_window']);
        $this->assertSame(3.0, $row['days_to_renewal']);
        $this->assertSame(1500.0, $row['amount']);
        $this->assertStringStartsWith('2026-09-06', $row['previous_expiry']);
        $this->assertStringStartsWith('2026-10-05', $row['current_expiry']);
        $this->assertSame(1.0, $row['days_before_expiry'], 'Renewed one day before the standing expiry.');
        $this->assertSame(29.0, $row['days_extended']);
    }

    public function test_renewed_after_demand_flags_a_win_back_after_the_profile_lapsed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $platform = $this->market();
        $client = $this->client($platform, 'Lenilyn');

        $this->paidUnlock($platform, $client, ['created_at' => Carbon::parse('2026-09-02 09:00:00')]);

        $renewalPayment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'product_id' => (int) $this->product($platform)->id,
            'client_id' => (int) $client->id,
            'amount' => 1500,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'completed_at' => Carbon::parse('2026-09-04 10:00:00'),
        ]);

        $this->deal($platform, $client, [
            'activated_at' => Carbon::parse('2026-07-30 10:00:00'),
            'expires_at' => Carbon::parse('2026-08-30 10:00:00'),
        ]);
        $this->deal($platform, $client, [
            'payment_id' => (int) $renewalPayment->id,
            'activated_at' => Carbon::parse('2026-09-04 10:00:00'),
            'expires_at' => Carbon::parse('2026-10-04 10:00:00'),
        ]);

        $row = $this->detail(ContactUnlockDemandDetailService::METRIC_RENEWED, $platform)['rows'][0];

        $this->assertSame(-5.0, $row['days_before_expiry'], 'Negative means the profile had already lapsed.');
    }

    public function test_single_profile_rows_pair_each_visitor_with_the_client_they_bought(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $platform = $this->market();
        $client = $this->client($platform, 'Melisa');

        $first = $this->paidUnlock($platform, $client, ['visitor_phone' => '254722000111']);
        $this->paidUnlock($platform, $client, ['visitor_phone' => '254722000111']);
        $this->paidUnlock($platform, $client, ['visitor_phone' => '254733000222']);

        ContactUnlockEvent::query()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => (int) $client->id,
            'visitor_contact_unlock_id' => (int) $first->id,
            'event_type' => ContactUnlockEvent::TYPE_CHECKOUT_START,
            'session_hash' => str_repeat('a', 64),
            'event_id_hash' => str_repeat('b', 64),
            'traffic_source' => 'referral',
            'occurred_at' => Carbon::now(),
        ]);

        $detail = $this->detail(ContactUnlockDemandDetailService::METRIC_SINGLE, $platform);
        $kpi = $this->kpis($platform);

        $this->assertSame($kpi['single_profile_purchases'], $detail['totals']['purchases']);
        $this->assertCount(3, $detail['rows']);
        $this->assertSame(2, $detail['totals']['distinct_visitors']);
        $this->assertSame(1, $detail['totals']['distinct_clients']);
        $this->assertSame(2, $detail['totals']['repeat_buyers'], 'Both rows from the repeat phone are flagged.');

        $row = collect($detail['rows'])->firstWhere('unlock_id', (int) $first->id);
        $this->assertSame('Melisa', $row['client']['name']);
        $this->assertSame('254*****111', $row['visitor']['phone_masked']);
        $this->assertStringStartsWith('V-', $row['visitor']['reference']);
        $this->assertTrue($row['visitor']['is_repeat_buyer']);
        $this->assertSame('referral', $row['traffic_source']);
    }

    public function test_full_access_rows_name_the_profiles_the_visitor_actually_revealed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $platform = $this->market();
        $revealedOne = $this->client($platform, 'Hanan');
        $revealedTwo = $this->client($platform, 'Ava');

        // Market-wide access carries no client_id — the reveal trail is the only link.
        $unlock = $this->paidUnlock($platform, null, [
            'scope' => VisitorContactUnlock::SCOPE_MARKET_INACTIVE_PROFILES,
            'amount' => 999,
        ]);

        foreach ([[$revealedOne, 2], [$revealedTwo, 1]] as [$client, $times]) {
            for ($index = 0; $index < $times; $index++) {
                ContactUnlockEvent::query()->create([
                    'platform_id' => (int) $platform->id,
                    'client_id' => (int) $client->id,
                    'visitor_contact_unlock_id' => (int) $unlock->id,
                    'event_type' => ContactUnlockEvent::TYPE_UNLOCK_REVEAL,
                    'session_hash' => str_repeat('c', 64),
                    'event_id_hash' => hash('sha256', 'reveal-'.$client->id.'-'.$index),
                    'occurred_at' => Carbon::now(),
                ]);
            }
        }

        $detail = $this->detail(ContactUnlockDemandDetailService::METRIC_FULL_ACCESS, $platform);
        $kpi = $this->kpis($platform);

        $this->assertSame($kpi['full_access_purchases'], $detail['totals']['purchases']);
        $row = $detail['rows'][0];
        $this->assertSame('', $row['client']['name'], 'A market-wide purchase has no single advertiser.');
        $this->assertSame(2, $row['revealed_profile_count']);
        $this->assertSame('Hanan', $row['revealed_profiles'][0]['name']);
        $this->assertSame(2, $row['revealed_profiles'][0]['reveals']);
    }

    public function test_endpoint_rejects_an_unknown_metric_and_serves_a_known_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 12:00:00'));
        $platform = $this->market();
        $client = $this->client($platform, 'Bella');
        $this->paidUnlock($platform, $client);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin', 'status' => 'active']));

        $this->getJson('/api/crm/settings/billing/contact-unlock/demand-detail?metric=made_up&range=30d')
            ->assertStatus(422);

        $this->getJson('/api/crm/settings/billing/contact-unlock/demand-detail?metric=single_profile_purchases&range=30d')
            ->assertOk()
            ->assertJsonPath('metric', 'single_profile_purchases')
            ->assertJsonPath('totals.purchases', 1);
    }

    private function detail(string $metric, Platform $platform): array
    {
        return app(ContactUnlockDemandDetailService::class)->detail(
            $metric,
            [(int) $platform->id],
            '30d',
            'UTC'
        );
    }

    private function kpis(Platform $platform): array
    {
        return app(ContactUnlockPulseService::class)
            ->summary([(int) $platform->id], '30d', 'UTC')['kpis'];
    }

    private function market(): Platform
    {
        return Platform::factory()->create([
            'name' => 'Kenya',
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'phone_prefix' => '254',
        ]);
    }

    private function client(Platform $platform, string $name): Client
    {
        return Client::factory()->create([
            'platform_id' => (int) $platform->id,
            'name' => $name,
            'city' => 'Nairobi',
            'wp_post_id' => random_int(40000, 49999),
            'profile_status' => 'publish',
        ]);
    }

    private function product(Platform $platform): Product
    {
        return $this->product ??= Product::factory()->create(['platform_id' => (int) $platform->id]);
    }

    private function deal(Platform $platform, Client $client, array $overrides = []): Deal
    {
        return Deal::query()->create(array_merge([
            'platform_id' => (int) $platform->id,
            'client_id' => (int) $client->id,
            'product_id' => (int) $this->product($platform)->id,
            'plan_type' => 'basic',
            'amount' => 1500,
            'currency' => 'KES',
            'duration' => 'monthly',
            'status' => 'active',
        ], $overrides));
    }

    private function paidUnlock(Platform $platform, ?Client $client, array $overrides = []): VisitorContactUnlock
    {
        $amount = (float) ($overrides['amount'] ?? 199);
        $createdAt = $overrides['created_at'] ?? Carbon::now()->subDay();
        $token = uniqid('token-', true);

        $payment = Payment::factory()->create([
            'platform_id' => (int) $platform->id,
            'product_id' => (int) $this->product($platform)->id,
            'client_id' => $client ? (int) $client->id : null,
            'amount' => $amount,
            'currency' => 'KES',
            'status' => 'completed',
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'completed_at' => $createdAt,
            'reference_number' => 'CU-'.strtoupper(substr(hash('sha1', $token), 0, 8)),
        ]);

        $unlock = VisitorContactUnlock::query()->create([
            'platform_id' => (int) $platform->id,
            'client_id' => $client ? (int) $client->id : null,
            'wp_post_id' => $client?->wp_post_id,
            'payment_id' => (int) $payment->id,
            'scope' => (string) ($overrides['scope'] ?? VisitorContactUnlock::SCOPE_SINGLE_PROFILE),
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => $amount,
            'credit_amount' => 0,
            'amount_due' => $amount,
            'visitor_phone_hash' => hash('sha256', (string) ($overrides['visitor_phone'] ?? '254722000111')),
            'visitor_phone_masked' => '254*****'.substr((string) ($overrides['visitor_phone'] ?? '254722000111'), -3),
            'idempotency_key_hash' => hash('sha256', 'idem-'.$token),
            'session_token_hash' => hash('sha256', 'session-'.$token),
            'public_token_hash' => hash('sha256', 'public-'.$token),
        ]);

        // created_at is guarded, so backdating has to happen after the insert.
        $unlock->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $unlock->refresh();
    }
}
