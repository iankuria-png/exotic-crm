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

    public function test_initialize_pawapay_prefers_profile_country_code_for_rwanda(): void
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

                TestCase::assertSame('RWA', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/rwanda-profile',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context([
            'provider_profile_country_code' => 'RW',
        ]), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/rwanda-profile', $result['url']);
    }

    public function test_initialize_pawapay_maps_rwanda_platform_country_variants_to_rwa(): void
    {
        $payment = $this->makePayment([
            'country' => 'Rwanda',
            'currency_code' => 'RWF',
            'phone_prefix' => '250',
        ], [
            'currency' => 'RWF',
            'phone' => '250791494431',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame('RWA', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/rwanda-platform',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/rwanda-platform', $result['url']);
    }

    public function test_initialize_pawapay_uses_rwf_and_250_as_rwanda_fallback(): void
    {
        $payment = $this->makePayment([
            'country' => 'Unsupported',
            'currency_code' => 'RWF',
            'phone_prefix' => '250',
        ], [
            'currency' => 'RWF',
            'phone' => '250791494431',
        ]);
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));

        Http::fake([
            'https://api.sandbox.pawapay.io/v2/paymentpage' => function ($request) {
                $payload = json_decode($request->body(), true);

                TestCase::assertSame('RWA', $payload['country'] ?? null);
                TestCase::assertArrayNotHasKey('phoneNumber', $payload);

                return Http::response([
                    'depositId' => $payload['depositId'] ?? null,
                    'redirectUrl' => 'https://checkout.pawapay.test/rwanda-fallback',
                ], 200);
            },
        ]);

        $result = $service->initializePawaPay($payment, $this->context(), [
            'callback_url' => 'https://merchant.example.test/billing/complete?payment=' . urlencode((string) $payment->transaction_uuid),
        ]);

        $this->assertSame('https://checkout.pawapay.test/rwanda-fallback', $result['url']);
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


    public function test_pawapay_country_code_supports_all_official_profile_country_codes(): void
    {
        foreach ($this->officialPawaPayMarkets() as $expectedCountry => $market) {
            $this->assertSame($expectedCountry, $this->invokePawaPayCountryCode($market['alpha2']));
        }
    }

    public function test_pawapay_country_code_supports_all_official_market_names(): void
    {
        foreach ($this->officialPawaPayMarkets() as $expectedCountry => $market) {
            $this->assertSame($expectedCountry, $this->invokePawaPayCountryCode($market['country']));
        }
    }

    public function test_pawapay_country_code_from_market_hints_supports_official_currency_prefix_pairs(): void
    {
        foreach ($this->officialPawaPayMarkets() as $expectedCountry => $market) {
            foreach ((array) ($market['currencies'] ?? [$market['currency']]) as $currency) {
                $this->assertSame(
                    $expectedCountry,
                    $this->invokePawaPayCountryCodeFromMarketHints($currency, $market['phone_prefix'])
                );
            }
        }
    }

    public function test_pawapay_country_code_distinguishes_the_two_congo_markets(): void
    {
        $this->assertSame('COG', $this->invokePawaPayCountryCode('Republic of the Congo'));
        $this->assertSame('COD', $this->invokePawaPayCountryCode('Democratic Republic of the Congo'));
        $this->assertNull($this->invokePawaPayCountryCode('Congo'));
    }

    private function invokePawaPayCountryCode(string $value): ?string
    {
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));
        $method = new \ReflectionMethod($service, 'pawaPayCountryCode');

        return $method->invoke($service, $value);
    }

    private function invokePawaPayCountryCodeFromMarketHints(string $currency, string $phonePrefix): ?string
    {
        $service = new HostedCheckoutService(Mockery::mock(BillingModeService::class));
        $method = new \ReflectionMethod($service, 'pawaPayCountryCodeFromMarketHints');

        return $method->invoke($service, $currency, $phonePrefix);
    }

    private function officialPawaPayMarkets(): array
    {
        return [
            'BEN' => ['alpha2' => 'BJ', 'country' => 'Benin', 'currency' => 'XOF', 'currencies' => ['XOF'], 'phone_prefix' => '229'],
            'BFA' => ['alpha2' => 'BF', 'country' => 'Burkina Faso', 'currency' => 'XOF', 'currencies' => ['XOF'], 'phone_prefix' => '226'],
            'CMR' => ['alpha2' => 'CM', 'country' => 'Cameroon', 'currency' => 'XAF', 'currencies' => ['XAF'], 'phone_prefix' => '237'],
            'CIV' => ['alpha2' => 'CI', 'country' => "Côte d'Ivoire", 'currency' => 'XOF', 'currencies' => ['XOF'], 'phone_prefix' => '225'],
            'COD' => ['alpha2' => 'CD', 'country' => 'Democratic Republic of the Congo', 'currency' => 'CDF', 'currencies' => ['CDF', 'USD'], 'phone_prefix' => '243'],
            'ETH' => ['alpha2' => 'ET', 'country' => 'Ethiopia', 'currency' => 'ETB', 'currencies' => ['ETB'], 'phone_prefix' => '251'],
            'GAB' => ['alpha2' => 'GA', 'country' => 'Gabon', 'currency' => 'XAF', 'currencies' => ['XAF'], 'phone_prefix' => '241'],
            'GHA' => ['alpha2' => 'GH', 'country' => 'Ghana', 'currency' => 'GHS', 'currencies' => ['GHS'], 'phone_prefix' => '233'],
            'KEN' => ['alpha2' => 'KE', 'country' => 'Kenya', 'currency' => 'KES', 'currencies' => ['KES'], 'phone_prefix' => '254'],
            'LSO' => ['alpha2' => 'LS', 'country' => 'Lesotho', 'currency' => 'LSL', 'currencies' => ['LSL'], 'phone_prefix' => '266'],
            'MWI' => ['alpha2' => 'MW', 'country' => 'Malawi', 'currency' => 'MWK', 'currencies' => ['MWK'], 'phone_prefix' => '265'],
            'MOZ' => ['alpha2' => 'MZ', 'country' => 'Mozambique', 'currency' => 'MZN', 'currencies' => ['MZN'], 'phone_prefix' => '258'],
            'NGA' => ['alpha2' => 'NG', 'country' => 'Nigeria', 'currency' => 'NGN', 'currencies' => ['NGN'], 'phone_prefix' => '234'],
            'COG' => ['alpha2' => 'CG', 'country' => 'Republic of the Congo', 'currency' => 'XAF', 'currencies' => ['XAF'], 'phone_prefix' => '242'],
            'RWA' => ['alpha2' => 'RW', 'country' => 'Rwanda', 'currency' => 'RWF', 'currencies' => ['RWF'], 'phone_prefix' => '250'],
            'SEN' => ['alpha2' => 'SN', 'country' => 'Senegal', 'currency' => 'XOF', 'currencies' => ['XOF'], 'phone_prefix' => '221'],
            'SLE' => ['alpha2' => 'SL', 'country' => 'Sierra Leone', 'currency' => 'SLE', 'currencies' => ['SLE'], 'phone_prefix' => '232'],
            'TZA' => ['alpha2' => 'TZ', 'country' => 'Tanzania', 'currency' => 'TZS', 'currencies' => ['TZS'], 'phone_prefix' => '255'],
            'UGA' => ['alpha2' => 'UG', 'country' => 'Uganda', 'currency' => 'UGX', 'currencies' => ['UGX'], 'phone_prefix' => '256'],
            'ZMB' => ['alpha2' => 'ZM', 'country' => 'Zambia', 'currency' => 'ZMW', 'currencies' => ['ZMW'], 'phone_prefix' => '260'],
        ];
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
