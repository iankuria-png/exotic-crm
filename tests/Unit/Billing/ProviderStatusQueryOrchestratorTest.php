<?php

namespace Tests\Unit\Billing;

use App\Billing\Providers\PawaPay\PawaPayCompatibilityAdapter;
use App\Billing\Providers\Pesapal\PesapalCompatibilityAdapter;
use App\Models\BillingRoutingDecision;
use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\HostedCheckoutService;
use App\Services\ProviderStatusQueryOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProviderStatusQueryOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_pinned_inactive_profiles_keep_each_providers_original_verification_context(): void
    {
        foreach (['paystack', 'pesapal', 'pawapay', 'kopokopo'] as $provider) {
            $payment = $this->makePayment(['provider_key' => $provider, 'product_id' => null,
                'transaction_reference' => '6f3ae557-334e-48bb-bd73-ff04767b224f']);
            $credentials = ['public_key' => 'pk_original', 'secret_key' => 'sk_original',
                'consumer_key' => 'original', 'consumer_secret' => 'original', 'ipn_id' => 'original',
                'api_key' => 'original', 'client_id' => 'original', 'client_secret' => 'original',
                'base_url' => 'https://sandbox.kopokopo.com', 'till_number' => 'original'];
            $profile = \App\Models\BillingProviderProfile::create(['provider_type_key' => $provider,
                'profile_name' => 'Original', 'environment' => 'production', 'market_id' => $payment->platform_id,
                'active' => false, 'config_json' => $credentials, 'secrets_json' => []]);
            $surface = $provider === 'kopokopo' ? 'subscription_push' : 'subscription_link';
            BillingRoutingDecision::create(['payment_id' => $payment->id, 'market_id' => $payment->platform_id,
                'provider_profile_id' => $profile->id, 'provider_type_key' => $provider, 'environment' => 'production',
                'billing_surface' => $surface, 'execution_mode' => 'direct', 'decision_version' => 1,
                'immutable_until_terminal_state' => true, 'snapshot_json' => [], 'decision_json' => [], 'created_at' => now()]);
            $billing = app(BillingModeService::class);
            $context = $billing->profileBackedProviderContext($payment->platform, $profile, null, false, 'production', $surface);
            $this->assertSame($profile->id, $context['provider_profile_id']);
            if ($provider === 'kopokopo') $this->assertSame('original', $context['provider_direct_config']['client_secret']);
            else $this->assertSame('original', $context['provider_credentials']['api_key']);
            $hosted = Mockery::mock(HostedCheckoutService::class);
            $pesapal = Mockery::mock(PesapalCompatibilityAdapter::class);
            $pawa = Mockery::mock(PawaPayCompatibilityAdapter::class);
            $kopo = Mockery::mock(\App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter::class);
            $adapter = match ($provider) { 'paystack' => $hosted, 'pesapal' => $pesapal, 'pawapay' => $pawa, 'kopokopo' => $kopo };
            $adapter->shouldReceive($provider === 'paystack' ? 'verifyPaystackTransaction' : 'verify')
                ->once()->withArgs(fn ($p, $ctx, $ref) => $p->id === $payment->id && $ctx['provider_profile_id'] === $profile->id)
                ->andReturn(['status' => 'pending', 'data' => []]);
            $orchestrator = new ProviderStatusQueryOrchestrator($billing, $hosted, $pesapal, $pawa, $kopo);
            $this->assertSame('pending', $orchestrator->verify($payment)['status']);
        }
    }

    public function test_loaded_decisions_ignore_mutable_rows_and_select_the_latest_immutable_id(): void
    {
        $payment = $this->makePayment();
        $older = new BillingRoutingDecision(['immutable_until_terminal_state' => true, 'provider_type_key' => 'paystack', 'created_at' => now()]);
        $older->id = 10;
        $newer = new BillingRoutingDecision(['immutable_until_terminal_state' => true, 'provider_type_key' => 'pawapay', 'created_at' => now()]);
        $newer->id = 11;
        $mutable = new BillingRoutingDecision(['immutable_until_terminal_state' => false, 'provider_type_key' => 'pesapal', 'created_at' => now()]);
        $mutable->id = 12;
        $payment->setRelation('routingDecisions', collect([$mutable, $older, $newer]));
        $this->assertSame('pawapay', $this->makeOrchestrator()->resolveProviderType($payment));
    }

    public function test_completed_payments_ignore_late_failed_provider_signals(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $decision = $orchestrator->decideMutation($payment, [
            'provider' => 'paystack',
            'provider_environment' => 'production',
            'provider_reference' => 'PSTK-STATUS-001',
            'status' => 'failed',
        ]);

        $this->assertSame('noop_terminal', $decision['decision']);
        $this->assertSame('completed', $decision['winning_status']);
        $this->assertTrue($decision['late_signal']);
    }

    public function test_failed_payments_can_recover_on_late_success_for_the_same_routed_contract(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'status' => 'failed',
            'failure_reason' => 'Timed out',
        ]);

        $decision = $orchestrator->decideMutation($payment, [
            'provider' => 'paystack',
            'provider_environment' => 'production',
            'provider_reference' => 'PSTK-STATUS-001',
            'status' => 'completed',
        ]);

        $this->assertSame('apply_completed', $decision['decision']);
        $this->assertSame('completed', $decision['winning_status']);
        $this->assertTrue($decision['late_signal']);
    }

    public function test_pending_payments_with_legacy_completed_at_still_accept_verified_success(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'status' => 'pending',
            'completed_at' => now(),
        ]);

        $decision = $orchestrator->decideMutation($payment, [
            'provider' => 'paystack',
            'provider_environment' => 'production',
            'provider_reference' => 'PSTK-STATUS-001',
            'status' => 'completed',
        ]);

        $this->assertSame('apply_completed', $decision['decision']);
        $this->assertSame('completed', $decision['winning_status']);
        $this->assertFalse($decision['late_signal']);
    }

    public function test_mismatched_provider_contracts_are_treated_as_superseded(): void
    {
        $orchestrator = $this->makeOrchestrator();
        $payment = $this->makePayment([
            'provider_key' => 'paystack_checkout',
            'provider_environment' => 'sandbox',
            'reference_number' => 'PSTK-STATUS-001',
        ]);

        BillingRoutingDecision::query()->create([
            'payment_id' => $payment->id,
            'market_id' => $payment->platform_id,
            'billing_surface' => 'payment_link',
            'provider_type_key' => 'paystack',
            'execution_mode' => 'proxy',
            'environment' => 'sandbox',
            'decision_version' => 1,
            'snapshot_json' => [
                'provider_key' => 'paystack_checkout',
                'provider_type_key' => 'paystack',
                'environment' => 'sandbox',
            ],
            'decision_json' => [
                'source' => 'test',
            ],
            'immutable_until_terminal_state' => true,
            'created_at' => now(),
        ]);

        $decision = $orchestrator->decideMutation($payment->fresh('routingDecisions'), [
            'provider' => 'paystack',
            'provider_environment' => 'sandbox',
            'provider_reference' => 'WRONG-REFERENCE',
            'status' => 'completed',
        ]);

        $this->assertSame('noop_superseded', $decision['decision']);
        $this->assertTrue($decision['superseded']);
        $this->assertSame('pending', $decision['winning_status']);
    }

    public function test_verify_uses_pesapal_compatibility_adapter_for_pesapal_payments(): void
    {
        $platform = Platform::factory()->create();
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'provider_key' => 'pesapal',
            'provider_environment' => 'production',
            'reference_number' => 'PESAPAL-001',
            'transaction_reference' => 'TRACK-123',
        ]);

        $billingModeService = Mockery::mock(BillingModeService::class);
        $billingModeService->shouldReceive('providerContext')
            ->once()
            ->with(
                Mockery::on(fn ($resolvedPlatform) => $resolvedPlatform instanceof Platform && $resolvedPlatform->is($platform)),
                'pesapal',
                false,
                'production',
                'subscription_link'
            )
            ->andReturn([
                'environment' => 'production',
                'provider_credentials' => ['consumer_key' => 'key', 'consumer_secret' => 'secret'],
            ]);

        $pesapalAdapter = Mockery::mock(PesapalCompatibilityAdapter::class);
        $pesapalAdapter->shouldReceive('verify')
            ->once()
            ->with(Mockery::on(fn (Payment $resolved) => $resolved->is($payment)), Mockery::type('array'), 'TRACK-123')
            ->andReturn([
                'status' => 'completed',
                'message' => 'Completed',
                'data' => ['tracking_id' => 'TRACK-123'],
            ]);

        $orchestrator = new ProviderStatusQueryOrchestrator(
            $billingModeService,
            Mockery::mock(HostedCheckoutService::class),
            $pesapalAdapter,
            Mockery::mock(PawaPayCompatibilityAdapter::class),
            Mockery::mock(\App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter::class)
        );

        $verification = $orchestrator->verify($payment);

        $this->assertSame('pesapal', $verification['provider']);
        $this->assertSame('completed', $verification['status']);
        $this->assertSame('TRACK-123', $verification['provider_reference']);
    }

    public function test_verify_uses_pawapay_compatibility_adapter_for_pawapay_payments(): void
    {
        $platform = Platform::factory()->create();
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'provider_key' => 'pawapay',
            'provider_environment' => 'sandbox',
            'reference_number' => 'WTU-PAWA-001',
            'transaction_reference' => '6f3ae557-334e-48bb-bd73-ff04767b224f',
        ]);

        $billingModeService = Mockery::mock(BillingModeService::class);
        $billingModeService->shouldReceive('providerContext')
            ->once()
            ->with(
                Mockery::on(fn ($resolvedPlatform) => $resolvedPlatform instanceof Platform && $resolvedPlatform->is($platform)),
                'pawapay',
                false,
                'sandbox',
                'subscription_link'
            )
            ->andReturn([
                'environment' => 'sandbox',
                'provider_credentials' => ['api_key' => 'pawapay-key'],
            ]);

        $pawaPayAdapter = Mockery::mock(PawaPayCompatibilityAdapter::class);
        $pawaPayAdapter->shouldReceive('verify')
            ->once()
            ->with(
                Mockery::on(fn (Payment $resolved) => $resolved->is($payment)),
                Mockery::type('array'),
                '6f3ae557-334e-48bb-bd73-ff04767b224f'
            )
            ->andReturn([
                'status' => 'completed',
                'message' => 'Completed',
                'data' => ['depositId' => '6f3ae557-334e-48bb-bd73-ff04767b224f'],
            ]);

        $orchestrator = new ProviderStatusQueryOrchestrator(
            $billingModeService,
            Mockery::mock(HostedCheckoutService::class),
            Mockery::mock(PesapalCompatibilityAdapter::class),
            $pawaPayAdapter,
            Mockery::mock(\App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter::class)
        );

        $verification = $orchestrator->verify($payment);

        $this->assertSame('pawapay', $verification['provider']);
        $this->assertSame('completed', $verification['status']);
        $this->assertSame('6f3ae557-334e-48bb-bd73-ff04767b224f', $verification['provider_reference']);
    }

    private function makeOrchestrator(): ProviderStatusQueryOrchestrator
    {
        return new ProviderStatusQueryOrchestrator(
            Mockery::mock(BillingModeService::class),
            Mockery::mock(HostedCheckoutService::class),
            Mockery::mock(PesapalCompatibilityAdapter::class),
            Mockery::mock(PawaPayCompatibilityAdapter::class),
            Mockery::mock(\App\Billing\Providers\KopoKopo\KopoKopoCompatibilityAdapter::class)
        );
    }

    private function makePayment(array $overrides = []): Payment
    {
        $platform = Platform::factory()->create();

        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'provider_key' => 'paystack',
            'provider_environment' => 'production',
            'reference_number' => 'PSTK-STATUS-001',
            'transaction_reference' => 'PSTK-STATUS-001',
            'status' => 'pending',
            'completed_at' => null,
            'wallet_transaction_id' => null,
        ], $overrides));
    }
}
