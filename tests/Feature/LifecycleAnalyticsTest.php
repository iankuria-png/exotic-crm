<?php

namespace Tests\Feature;

use App\Models\BillingProxySession;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\TimelineEvent;
use App\Services\LifecycleAnalyticsService;
use App\Services\LifecycleSmsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LifecycleAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-24 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(Client $client, string $flow, ?int $paymentId, Carbon $at): TimelineEvent
    {
        return TimelineEvent::create([
            'platform_id' => $client->platform_id,
            'entity_type' => 'client',
            'entity_id' => $client->id,
            'event_type' => LifecycleSmsService::TIMELINE_EVENT_TYPE,
            'actor_id' => null,
            'content' => ['flow' => $flow, 'status' => 'sent', 'reference' => $flow, 'payment_id' => $paymentId],
            'created_at' => $at,
        ]);
    }

    public function test_funnel_direct_and_assisted_attribution(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);

        // Client A: onboarding link sent, opened, and the SAME link payment completes → DIRECT.
        $clientA = Client::factory()->create(['platform_id' => $platform->id]);
        $linkPayment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $clientA->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'USD',
            'completed_at' => now()->subDays(4),
            'raw_payload' => ['source' => 'crm_lifecycle', 'lifecycle_flow' => 'onboarding'],
        ]);
        $this->send($clientA, 'onboarding', $linkPayment->id, now()->subDays(5));
        BillingProxySession::create([
            'payment_id' => $linkPayment->id,
            'provider_type_key' => 'pawapay',
            'environment' => 'production',
            'token_hash' => 'h1',
            'token_expires_at' => now()->addDay(),
            'opened_at' => now()->subDays(4),
            'open_count' => 2,
            'state' => 'opened',
        ]);

        // Client B: win-back sent; client pays via a DIFFERENT payment in-window → ASSISTED.
        $clientB = Client::factory()->create(['platform_id' => $platform->id]);
        $this->send($clientB, 'reactivation', 999999, now()->subDays(3));
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $clientB->id,
            'status' => 'completed',
            'amount' => 50,
            'currency' => 'USD',
            'completed_at' => now()->subDays(2),
        ]);

        // Client C: sent, never converted.
        $clientC = Client::factory()->create(['platform_id' => $platform->id]);
        $this->send($clientC, 'onboarding', null, now()->subDays(6));

        $overview = app(LifecycleAnalyticsService::class)->overview([
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
            'window_days' => 7,
        ]);

        $this->assertSame(3, $overview['funnel']['sent']);
        $this->assertSame(1, $overview['funnel']['opened']);
        $this->assertSame(2, $overview['funnel']['converted']);
        $this->assertSame(1, $overview['funnel']['direct']);
        $this->assertSame(1, $overview['funnel']['assisted']);
        $this->assertEqualsWithDelta(150.0, $overview['attributed_revenue_usd'], 0.5);
    }

    public function test_funnel_resolves_cfa_alias_from_market_context(): void
    {
        $platform = Platform::factory()->create([
            'name' => 'Senegal',
            'country' => 'Senegal',
            'currency_code' => 'XOF',
        ]);
        ReportingFxRate::query()->create([
            'provider' => 'manual',
            'source_currency' => 'XOF',
            'target_currency' => 'USD',
            'rate_date' => now()->subDays(2)->toDateString(),
            'rate' => 0.002,
            'fetched_at' => now(),
        ]);

        $client = Client::factory()->create(['platform_id' => $platform->id]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'status' => 'completed',
            'amount' => 1000,
            'currency' => 'CFA',
            'completed_at' => now()->subDays(2),
            'raw_payload' => ['source' => 'crm_lifecycle', 'lifecycle_flow' => 'onboarding'],
        ]);
        $this->send($client, 'onboarding', $payment->id, now()->subDays(3));

        $overview = app(LifecycleAnalyticsService::class)->overview([
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
            'window_days' => 7,
        ]);

        $this->assertSame(1, $overview['funnel']['converted']);
        $this->assertEqualsWithDelta(2.0, $overview['attributed_revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(2.0, $overview['by_market'][0]['revenue_usd'], 0.001);
        $this->assertEqualsWithDelta(2.0, $overview['payments']['completed']['value_usd'], 0.001);
    }

    public function test_messages_drill_annotates_and_filters_rows(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $client = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Joy']);

        // Converted onboarding send (direct).
        $paid = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'USD',
            'completed_at' => now()->subDays(2),
        ]);
        $s1 = $this->send($client, 'onboarding', $paid->id, now()->subDays(3));
        $s1->forceFill(['content' => array_merge($s1->content, ['body' => 'Welcome Joy, tap to pay: link'])])->save();

        // Non-converted win-back send.
        $s2 = $this->send($client, 'reactivation', null, now()->subDay());
        $s2->forceFill(['content' => array_merge($s2->content, ['body' => 'We miss you Joy'])])->save();

        $service = app(LifecycleAnalyticsService::class);
        $filters = ['from' => now()->subDays(30)->toDateString(), 'to' => now()->toDateString(), 'window_days' => 7];

        $all = $service->messages($filters, 1, 25);
        $this->assertSame(2, $all['total']);
        $direct = collect($all['data'])->firstWhere('flow', 'onboarding');
        $this->assertTrue($direct['converted']);
        $this->assertSame('direct', $direct['conversion_type']);
        $this->assertStringContainsString('Welcome Joy', $direct['body']);

        // Outcome filter: only converted rows.
        $converted = $service->messages(array_merge($filters, ['outcome' => 'converted']), 1, 25);
        $this->assertSame(1, $converted['total']);
        $this->assertSame('onboarding', $converted['data'][0]['flow']);

        // Flow filter.
        $winback = $service->messages(array_merge($filters, ['flow' => 'reactivation']), 1, 25);
        $this->assertSame(1, $winback['total']);
        $this->assertFalse($winback['data'][0]['converted']);
    }

    public function test_conversion_outside_window_is_not_attributed(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $this->send($client, 'onboarding', null, now()->subDays(20));
        // Paid 10 days after the send — outside a 7-day window.
        Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'status' => 'completed',
            'amount' => 100,
            'currency' => 'USD',
            'completed_at' => now()->subDays(10),
        ]);

        $overview = app(LifecycleAnalyticsService::class)->overview([
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
            'window_days' => 7,
        ]);

        $this->assertSame(1, $overview['funnel']['sent']);
        $this->assertSame(0, $overview['funnel']['converted']);
    }

    public function test_funnel_counts_proof_submitted_stage(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        $this->send($client, 'onboarding', null, now()->subDays(2));

        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'product_id' => null,
            'client_id' => $client->id,
            'status' => 'initiated',
            'amount' => 50,
            'currency' => 'USD',
        ]);
        \App\Models\PaymentManualSubmission::create([
            'payment_id' => $payment->id,
            'client_id' => $client->id,
            'platform_id' => $platform->id,
            'duration_key' => '1_week',
            'manual_method_key' => 'till',
            'sender_name' => 'Client',
            'transaction_reference' => 'ABC123',
            'proof_path' => 'proofs/x.jpg',
            'proof_mime' => 'image/jpeg',
            'created_at' => now()->subDay(),
        ]);

        $overview = app(LifecycleAnalyticsService::class)->overview([
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
            'window_days' => 7,
        ]);

        $this->assertSame(1, $overview['funnel']['submitted']);
        $this->assertSame(0, $overview['funnel']['converted']); // proof in review, not yet activated
    }

    public function test_payment_rollup_groups_lifecycle_payments_by_status(): void
    {
        $platform = Platform::factory()->create(['currency_code' => 'USD']);
        $client = Client::factory()->create(['platform_id' => $platform->id]);

        foreach ([['completed', 100], ['initiated', 40], ['pending', 40], ['failed', 30]] as [$status, $amount]) {
            Payment::factory()->create([
                'platform_id' => $platform->id,
                'product_id' => null,
                'client_id' => $client->id,
                'status' => $status,
                'amount' => $amount,
                'currency' => 'USD',
                'completed_at' => $status === 'completed' ? now()->subDay() : null,
                'raw_payload' => ['source' => 'crm_lifecycle', 'lifecycle_flow' => 'onboarding'],
            ]);
        }

        $overview = app(LifecycleAnalyticsService::class)->overview([
            'from' => now()->subDays(30)->toDateString(),
            'to' => now()->toDateString(),
        ]);

        $this->assertSame(1, $overview['payments']['completed']['count']);
        $this->assertSame(2, $overview['payments']['pending']['count']); // initiated + pending
        $this->assertSame(1, $overview['payments']['failed']['count']);
        $this->assertSame(4, $overview['payments']['total']['count']);
    }
}
