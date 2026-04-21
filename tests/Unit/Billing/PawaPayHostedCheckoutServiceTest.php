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

    private function makePayment(): Payment
    {
        $platform = Platform::factory()->create([
            'country' => 'Kenya',
            'currency_code' => 'KES',
        ]);

        return Payment::factory()->create([
            'platform_id' => $platform->id,
            'provider_key' => 'pawapay',
            'provider_environment' => 'sandbox',
            'phone' => '254700000111',
            'amount' => '900.00',
            'currency' => 'KES',
            'transaction_uuid' => 'a4bc54eb-08e8-4e29-8d6f-4823d3b23d0c',
            'reference_number' => 'PAWAPAY-UNIT-001',
            'transaction_reference' => 'PAWAPAY-UNIT-001',
        ]);
    }

    private function context(): array
    {
        return [
            'environment' => 'sandbox',
            'provider_credentials' => [
                'api_key' => 'pawapay-sandbox-key',
                'base_url' => 'https://api.sandbox.pawapay.io',
            ],
        ];
    }
}
