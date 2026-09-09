<?php

namespace Tests\Feature;

use App\Models\ContactUnlockEvent;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactUnlockAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_buckets_unlock_revenue_by_day_and_splits_it_by_market(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya');
        $ghana = $this->platform('Accra', 'Ghana');
        Sanctum::actingAs($this->admin());

        $today = now()->startOfDay()->addHours(9);
        $yesterday = now()->subDay()->startOfDay()->addHours(9);

        $this->unlock($kenya, $today, 300.0);
        $this->unlock($kenya, $yesterday, 100.0);
        $this->unlock($ghana, $today, 100.0);

        // Subscription revenue must never leak into the visitor books.
        Payment::factory()->create([
            'platform_id' => $kenya->id,
            'product_id' => null,
            'client_id' => null,
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'status' => 'completed',
            'amount' => 9999,
            'currency' => 'USD',
            'completed_at' => $today,
            'created_at' => $today,
            'updated_at' => $today,
        ]);

        $from = now()->subDay()->toDateString();
        $to = now()->toDateString();
        $response = $this->getJson("/api/crm/settings/billing/contact-unlock/analytics?range=custom&from={$from}&to={$to}&reporting_currency=USD&bucket=day")
            ->assertOk()
            ->json();

        $this->assertSame('day', $response['window']['bucket']);
        $this->assertSame(500.0, (float) $response['totals']['normalized_total']);
        $this->assertSame(3, (int) $response['totals']['payments_count']);
        $this->assertSame(3, (int) $response['totals']['unlocks_count']);

        $points = collect($response['points'])->keyBy('label');
        $this->assertSame(100.0, (float) $points[now()->subDay()->toDateString()]['value']);
        $this->assertSame(400.0, (float) $points[now()->toDateString()]['value']);
        $this->assertSame(2, (int) $points[now()->toDateString()]['payments_count']);
        $this->assertSame(200.0, (float) $points[now()->toDateString()]['average_ticket']);

        $markets = collect($response['markets'])->keyBy('name');
        $this->assertSame(400.0, (float) $markets['Nairobi']['value']);
        $this->assertSame(80.0, (float) $markets['Nairobi']['share_percent']);
        $this->assertSame(100.0, (float) $markets['Accra']['value']);
        $this->assertSame(20.0, (float) $markets['Accra']['share_percent']);
        // Leading market sorts first so the pie legend and the ranked list agree.
        $this->assertSame('Nairobi', $response['markets'][0]['name']);
    }

    public function test_analytics_reports_per_market_funnel_counts_and_scopes_to_one_market(): void
    {
        $kenya = $this->platform('Nairobi', 'Kenya');
        $ghana = $this->platform('Accra', 'Ghana');
        Sanctum::actingAs($this->admin());

        $this->unlock($kenya, now()->startOfDay()->addHours(9), 250.0);
        $this->unlock($ghana, now()->startOfDay()->addHours(9), 250.0);
        $this->event($kenya, ContactUnlockEvent::TYPE_ELIGIBLE_VIEW);
        $this->event($kenya, ContactUnlockEvent::TYPE_ELIGIBLE_VIEW);
        $this->event($kenya, ContactUnlockEvent::TYPE_CTA_CLICK);

        $today = now()->toDateString();
        $all = $this->getJson("/api/crm/settings/billing/contact-unlock/analytics?range=custom&from={$today}&to={$today}&reporting_currency=USD")
            ->assertOk()
            ->json();

        $kenyaRow = collect($all['markets'])->firstWhere('platform_id', $kenya->id);
        $this->assertSame(2, (int) $kenyaRow['eligible_views']);
        $this->assertSame(1, (int) $kenyaRow['cta_clicks']);
        $this->assertSame(1, (int) $kenyaRow['checkout_starts']);
        $this->assertSame(100.0, (float) $kenyaRow['conversion_percent']);

        $scoped = $this->getJson("/api/crm/settings/billing/contact-unlock/analytics?range=custom&from={$today}&to={$today}&reporting_currency=USD&platform_id={$kenya->id}")
            ->assertOk()
            ->json();

        $this->assertCount(1, $scoped['markets']);
        $this->assertSame($kenya->id, (int) $scoped['markets'][0]['platform_id']);
        $this->assertSame(250.0, (float) $scoped['totals']['normalized_total']);
    }

    public function test_analytics_requires_authentication(): void
    {
        $this->getJson('/api/crm/settings/billing/contact-unlock/analytics')->assertUnauthorized();
    }

    private function unlock(Platform $platform, $at, float $amount): VisitorContactUnlock
    {
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => null,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => 'completed',
            'amount' => $amount,
            'currency' => 'USD',
            'reference_number' => 'UNLOCK-'.Str::upper(Str::random(6)),
            'created_at' => $at,
            'updated_at' => $at,
            'completed_at' => $at,
        ]);

        $unlock = VisitorContactUnlock::query()->create([
            'platform_id' => $platform->id,
            'payment_id' => $payment->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'gross_amount' => $amount,
            'credit_amount' => 0,
            'amount_due' => $amount,
            'visitor_phone_hash' => hash('sha256', Str::random(16)),
            'visitor_phone_masked' => '254*****111',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => hash('sha256', Str::random(16)),
            'public_token_hash' => hash('sha256', Str::random(16)),
        ]);
        $unlock->forceFill(['created_at' => $at, 'updated_at' => $at])->save();

        return $unlock;
    }

    private function event(Platform $platform, string $type): void
    {
        ContactUnlockEvent::query()->create([
            'platform_id' => $platform->id,
            'event_type' => $type,
            'session_hash' => hash('sha256', Str::random(16)),
            'event_id_hash' => hash('sha256', Str::random(16)),
            'occurred_at' => now()->startOfDay()->addHours(9),
        ]);
    }

    private function platform(string $name, string $country): Platform
    {
        return Platform::factory()->create([
            'name' => $name,
            'country' => $country,
            'currency_code' => 'USD',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }
}
