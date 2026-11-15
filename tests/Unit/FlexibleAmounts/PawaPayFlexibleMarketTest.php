<?php

namespace Tests\Unit\FlexibleAmounts;

use App\Billing\Providers\PawaPay\PawaPayDirectDepositAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PawaPayFlexibleMarketTest extends TestCase
{
    public function test_strict_active_configuration_and_predicted_network_for_each_pilot_country(): void
    {
        $http = app(\Illuminate\Http\Client\Factory::class);
        foreach ([['KE', 'KEN', 'KES', 'MPESA_KEN'], ['SN', 'SEN', 'XOF', 'FIXTURE_SEN'], ['UG', 'UGA', 'UGX', 'FIXTURE_UGA']] as [$alpha2,$alpha3,$currency,$provider]) {
            Cache::flush();
            $context = ['environment' => 'sandbox', 'provider_profile_id' => 991, 'provider_profile_country_code' => $alpha2, 'provider_credentials' => ['api_key' => 'fixture-only']];
            Http::swap(clone $http);
            Http::fake(function ($r) use ($alpha3, $currency, $provider) {
                if (str_ends_with($r->url(), '/active-conf')) {
                    return Http::response(['signatureConfiguration' => ['signedRequestsOnly' => false], 'countries' => [['country' => $alpha3, 'providers' => [['provider' => $provider, 'currencies' => [['currency' => $currency, 'operationTypes' => [['DEPOSIT' => ['authType' => 'PROVIDER_AUTH', 'pinPrompt' => 'AUTOMATIC', 'decimalsInAmount' => 'NONE', 'status' => 'OPERATIONAL', 'minTransactionLimit' => '1', 'maxTransactionLimit' => '999999']]]]]]]]]]);
                }

                return Http::response(['phoneNumber' => $r['phoneNumber'], 'country' => $alpha3, 'provider' => $provider]);
            });
            $a = app(PawaPayDirectDepositAdapter::class);
            $this->assertSame('OPERATIONAL', $a->configuration($context, '1000')['status']);
            $this->assertSame($provider, $a->predict($context, '254712345678')['provider']);
            try {
                $a->configuration($context, '1000', 'FOREIGN_NETWORK');
                $this->fail('Foreign network should fail.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    private function capturedConfiguration(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/PawaPay/active-conf-v2-kenya.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    private function kenyaContext(): array
    {
        return ['environment' => 'sandbox', 'provider_profile_id' => 992, 'provider_profile_country_code' => 'KE', 'provider_credentials' => ['api_key' => 'fixture-only']];
    }

    public function test_captured_v2_map_normalizes_limits_and_enforces_whole_unit_bounds(): void
    {
        Cache::flush();
        Http::preventStrayRequests();
        Http::fake(['https://api.sandbox.pawapay.io/v2/active-conf' => Http::response($this->capturedConfiguration())]);
        $adapter = app(PawaPayDirectDepositAdapter::class);
        $conf = $adapter->configuration($this->kenyaContext(), '1000', 'MPESA_KEN');
        $this->assertSame('1', $conf['minTransactionLimit']);
        $this->assertSame('250000', $conf['maxTransactionLimit']);
        foreach (['0', '250001', '660.50'] as $amount) {
            try {
                $adapter->configuration($this->kenyaContext(), $amount, 'MPESA_KEN');
                $this->fail('Unsupported amount must be rejected.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(422, $e->getStatusCode());
            }
        }
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_ends_with($request->url(), '/active-conf'));
    }

    public function test_current_limits_win_over_legacy_and_malformed_current_values_fail_closed(): void
    {
        $factory = app(\Illuminate\Http\Client\Factory::class);
        foreach ([false, true] as $malformed) {
            Cache::flush();
            Http::swap(clone $factory);
            $body = $this->capturedConfiguration();
            $deposit = &$body['countries'][0]['providers'][0]['currencies'][0]['operationTypes']['DEPOSIT'];
            $deposit['minTransactionLimit'] = '2000';
            $deposit['maxTransactionLimit'] = '5';
            if ($malformed) $deposit['minAmount'] = null;
            Http::fake(['https://api.sandbox.pawapay.io/v2/active-conf' => Http::response($body)]);
            try {
                $conf = app(PawaPayDirectDepositAdapter::class)->configuration($this->kenyaContext(), '1000', 'MPESA_KEN');
                $this->assertFalse($malformed);
                $this->assertSame('1', $conf['minTransactionLimit']);
                $this->assertSame('250000', $conf['maxTransactionLimit']);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertTrue($malformed);
                $this->assertSame(422, $e->getStatusCode());
            }
        }
    }

    public function test_current_map_does_not_relax_provider_readiness_guards(): void
    {
        $factory = app(\Illuminate\Http\Client\Factory::class);
        foreach (['closed', 'redirect', 'manual_pin', 'decimals', 'foreign_currency', 'foreign_network', 'signed_only'] as $scenario) {
            Cache::flush();
            Http::swap(clone $factory);
            $body = $this->capturedConfiguration();
            $deposit = &$body['countries'][0]['providers'][0]['currencies'][0]['operationTypes']['DEPOSIT'];
            if ($scenario === 'closed') $deposit['status'] = 'CLOSED';
            if ($scenario === 'redirect') $deposit['authType'] = 'REDIRECT_AUTH';
            if ($scenario === 'manual_pin') $deposit['pinPrompt'] = 'MANUAL';
            if ($scenario === 'decimals') $deposit['decimalsInAmount'] = 'TWO_PLACES';
            if ($scenario === 'foreign_currency') $body['countries'][0]['providers'][0]['currencies'][0]['currency'] = 'EUR';
            if ($scenario === 'foreign_network') $body['countries'][0]['providers'][0]['provider'] = 'OTHER_KEN';
            if ($scenario === 'signed_only') $body['signatureConfiguration']['signedRequestsOnly'] = true;
            Http::fake(['https://api.sandbox.pawapay.io/v2/active-conf' => Http::response($body)]);
            try {
                app(PawaPayDirectDepositAdapter::class)->configuration($this->kenyaContext(), '1000');
                $this->fail('Unsafe provider configuration accepted: '.$scenario);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode(), $scenario);
            }
        }
    }
}
