<?php

namespace Tests\Unit\Billing;

use App\Models\Payment;
use App\Models\Platform;
use App\Services\BillingModeService;
use App\Services\HostedCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class PawaPayHostedCheckoutServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_initialize_pawapay_omits_phone_number_by_default(): void
    {
        $payment = $this->makePayment();
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertArrayNotHasKey('phoneNumber', $payload);
                TestCase::assertSame('KEN', $payload['country'] ?? null);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/default',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('redirect', $result['type']);
        $this->assertSame('https://checkout.pawapay.test/default', $result['url']);
    }

    public function test_initialize_pawapay_includes_phone_number_when_prefill_is_explicitly_enabled(): void
    {
        $payment = $this->makePayment();
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) use ($payment) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame($payment->phone, $payload['phoneNumber'] ?? null);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/prefilled',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
            'prefill_phone' => true,
        ]);

        $this->assertSame('redirect', $result['type']);
        $this->assertSame('https://checkout.pawapay.test/prefilled', $result['url']);
    }

    public function test_initialize_pawapay_prefers_profile_country_code_for_drc(): void
    {
        $payment = $this->makePayment([
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'phone_prefix' => '254',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame('COD', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/drc-profile',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context([
            'provider_profile_country_code' => 'CD',
        ]), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/drc-profile', $result['url']);
    }

    public function test_initialize_pawapay_maps_drc_platform_country_variants_to_cod(): void
    {
        $payment = $this->makePayment([
            'country' => 'Democratic Republic of the Congo',
            'currency_code' => 'CDF',
            'phone_prefix' => '243',
        ], [
            'currency' => 'CDF',
            'phone' => '243810000111',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame('COD', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/drc-platform',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/drc-platform', $result['url']);
    }

    public function test_initialize_pawapay_uses_cdf_and_243_as_drc_fallback(): void
    {
        $payment = $this->makePayment([
            'country' => 'Unsupported',
            'currency_code' => 'CDF',
            'phone_prefix' => '243',
        ], [
            'currency' => 'CDF',
            'phone' => '243810000111',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame('COD', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/drc-fallback',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/drc-fallback', $result['url']);
    }

    public function test_initialize_pawapay_falls_back_to_phone_when_country_cannot_be_resolved(): void
    {
        $payment = $this->makePayment([
            'country' => 'Unsupported',
            'currency_code' => 'USD',
            'phone_prefix' => '999',
        ], [
            'currency' => 'USD',
            'phone' => '999700000111',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) use ($payment) {
                $payload = json_decode($request->body(), true);

                TestCase::assertArrayNotHasKey('country', $payload);
                TestCase::assertSame($payment->phone, $payload['phoneNumber'] ?? null);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/phone-fallback',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/phone-fallback', $result['url']);
    }

    private function makePayment(array $platformOverrides = [], array $paymentOverrides = []): Payment
    {
        $platform = Platform::factory()->create(array_merge([
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ], $platformOverrides));

        return Payment::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'provider_key' => 'pawapay',
            'provider_environment' => 'sandbox',
            'phone' => '254700000111',
            'amount' => '900.00',
            'currency' => 'KES',
            'transaction_uuid' => 'a4bc54eb-08e8-4e29-8d6f-4823d3b23d0c',
            'reference_number' => 'PAWAPAY-UNIT-001',
            'transaction_reference' => 'PAWAPAY-UNIT-001',
        ], $paymentOverrides));
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'environment' => 'sandbox',
            'provider_credentials' => [
                'api_key' => 'pawapay-sandbox-key',
                'base_url' => 'https://api.sandbox.pawapay.io',
            ],
        ], $overrides);
    }
}
