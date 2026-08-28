<?php

namespace Tests\Feature;

use App\Billing\Support\BillingSurface;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Models\VisitorContactUnlock;
use App\Services\ClientChurnStamper;
use App\Services\ClientFunnelService;
use App\Services\ProviderStatusQueryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisitorRevenueSeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_queue_default_excludes_unlocks_but_contact_unlock_filter_can_include_them(): void
    {
        $platform = $this->platform();
        $admin = $this->user('admin');
        $subscription = $this->payment($platform, ['purpose' => Payment::PURPOSE_SUBSCRIPTION, 'reference_number' => 'SUB-001']);
        $this->payment($platform, ['purpose' => Payment::PURPOSE_WALLET_TOPUP, 'reference_number' => 'WALLET-001']);
        $unlock = $this->payment($platform, ['purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK, 'reference_number' => 'UNLOCK-001']);

        Sanctum::actingAs($admin);

        $default = $this->getJson('/api/crm/payments?platform_id='.$platform->id);
        $default->assertOk()->assertJsonPath('stats.confirmed', 1);
        $defaultRefs = collect($default->json('data'))->pluck('reference_number')->all();
        $this->assertContains($subscription->reference_number, $defaultRefs);
        $this->assertNotContains($unlock->reference_number, $defaultRefs);

        $filtered = $this->getJson('/api/crm/payments?platform_id='.$platform->id.'&purpose=contact_unlock');
        $filtered->assertOk()->assertJsonPath('stats.confirmed', 1);
        $this->assertSame(['UNLOCK-001'], collect($filtered->json('data'))->pluck('reference_number')->values()->all());
    }

    public function test_dashboard_unmatched_kpis_and_review_queue_exclude_clientless_unlocks(): void
    {
        $platform = $this->platform();
        $admin = $this->user('admin');
        $this->payment($platform, ['purpose' => Payment::PURPOSE_SUBSCRIPTION, 'client_id' => null, 'reference_number' => 'UNMATCHED-SUB']);
        $this->payment($platform, ['purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK, 'client_id' => null, 'reference_number' => 'UNMATCHED-UNLOCK']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/crm/dashboard?platform_id='.$platform->id.'&from='.now()->subDay()->toDateString().'&to='.now()->toDateString());

        $response->assertOk()
            ->assertJsonPath('kpis.unmatched_payments_window', 1)
            ->assertJsonPath('kpis.payment_recovery_unmatched', 1);

        $references = collect($response->json('payment_review_queue'))->pluck('reference_number')->all();
        $this->assertContains('UNMATCHED-SUB', $references);
        $this->assertNotContains('UNMATCHED-UNLOCK', $references);
    }

    public function test_ai_reporting_market_revenue_view_excludes_unlocks_on_mysql(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQL view assertion is MySQL-only.');
        }

        $platform = $this->platform();
        $this->payment($platform, ['purpose' => Payment::PURPOSE_SUBSCRIPTION, 'amount' => 1000]);
        $this->payment($platform, ['purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK, 'amount' => 299]);

        $total = DB::table('vw_market_revenue')->where('platform_id', $platform->id)->sum('revenue_usd');

        $this->assertSame(1000.0, (float) $total);
    }

    public function test_provider_surface_reports_contact_unlock(): void
    {
        $platform = $this->platform();
        $payment = $this->payment($platform, ['purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK]);

        $this->assertSame(BillingSurface::ContactUnlock->value, app(ProviderStatusQueryOrchestrator::class)->resolveSurface($payment));
    }

    public function test_unlock_payment_does_not_create_advertiser_paid_history_or_activation(): void
    {
        $platform = $this->platform();
        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $this->payment($platform, [
            'client_id' => $client->id,
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'completed_at' => now()->subDay(),
        ]);

        $hasPaidHistory = ClientFunnelService::applyPaidHistory(Client::query()->whereKey($client->id))->exists();
        $noPaidHistory = ClientFunnelService::applyNoPaidHistory(Client::query()->whereKey($client->id))->exists();

        $this->assertFalse($hasPaidHistory);
        $this->assertTrue($noPaidHistory);
        $this->assertNull(app(ClientChurnStamper::class)->firstActivationAt($client->fresh()));
    }

    public function test_unlock_summary_and_pulse_share_successful_status_definition(): void
    {
        $platform = $this->platform();
        $admin = $this->user('admin');
        $completed = $this->unlock($platform, ['status' => 'completed', 'amount' => 299]);
        $expired = $this->unlock($platform, ['status' => 'expired', 'amount' => 199]);
        $this->assertNotNull($completed);
        $this->assertNotNull($expired);

        Sanctum::actingAs($admin);

        $index = $this->getJson('/api/crm/settings/billing/contact-unlock?platform_id='.$platform->id.'&from='.now()->toDateString().'&to='.now()->toDateString());
        $pulse = $this->getJson('/api/crm/settings/billing/contact-unlock/pulse?range=custom&platform_id='.$platform->id.'&from='.now()->toDateString().'&to='.now()->toDateString());

        $index->assertOk()->assertJsonPath('summary.completed_payments', 2);
        $pulse->assertOk()->assertJsonPath('kpis.successful_payments', 2);
    }

    private function unlock(Platform $platform, array $overrides = []): VisitorContactUnlock
    {
        $payment = $this->payment($platform, [
            'purpose' => Payment::PURPOSE_VISITOR_CONTACT_UNLOCK,
            'status' => $overrides['status'] ?? 'completed',
            'amount' => $overrides['amount'] ?? 299,
        ]);

        return VisitorContactUnlock::query()->create([
            'platform_id' => $platform->id,
            'client_id' => null,
            'wp_post_id' => random_int(1000, 9999),
            'payment_id' => $payment->id,
            'scope' => VisitorContactUnlock::SCOPE_SINGLE_PROFILE,
            'status' => VisitorContactUnlock::STATUS_ACTIVE,
            'amount_due' => $payment->amount,
            'gross_amount' => $payment->amount,
            'credit_amount' => 0,
            'visitor_phone_hash' => hash('sha256', Str::random(16)),
            'visitor_phone_masked' => '254*****123',
            'idempotency_key_hash' => hash('sha256', Str::random(16)),
            'session_token_hash' => hash('sha256', Str::random(16)),
            'public_token_hash' => hash('sha256', Str::random(16)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(Platform $platform, array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => null,
            'status' => 'completed',
            'amount' => 1000,
            'currency' => 'KES',
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
            'reconciliation_state' => 'open',
            'resolution_code' => null,
        ], $overrides));
    }

    private function platform(): Platform
    {
        return Platform::factory()->create(['currency_code' => 'KES']);
    }

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'assigned_market_ids' => []]);
    }
}
