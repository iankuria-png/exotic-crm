<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use App\Services\ReportingCurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingCurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalizes_payment_query_to_usd_without_mutating_native_amounts(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $platform = Platform::factory()->create(['currency_code' => 'KES']);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 1000,
            'currency' => 'KES',
            'status' => 'completed',
            'completed_at' => '2026-04-20 10:00:00',
            'created_at' => '2026-04-20 09:00:00',
        ]);

        ReportingFxRate::query()->create([
            'provider' => 'currencyapi',
            'source_currency' => 'KES',
            'target_currency' => 'USD',
            'rate_date' => '2026-04-20',
            'rate' => '0.0077000000',
            'fetched_at' => now(),
        ]);

        $payload = app(ReportingCurrencyService::class)->normalizePaymentQuery(
            Payment::query()->whereKey($payment->id),
            'USD'
        );

        $this->assertSame(['KES' => 1000.0], $payload['source_breakdown']);
        $this->assertSame(7.7, $payload['normalized_total']);
        $this->assertSame('USD', $payload['normalized_currency']);
        $this->assertFalse($payload['normalization_meta']['partial']);

        $payment->refresh();
        $this->assertSame('KES', $payment->currency);
        $this->assertSame(1000.0, (float) $payment->amount);
    }

    public function test_missing_rate_marks_normalization_partial(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $payload = app(ReportingCurrencyService::class)->normalizeBreakdown(
            ['GHS' => 250, 'USD' => 10],
            now()->parse('2026-04-20'),
            'USD'
        );

        $this->assertNull($payload['normalized_total']);
        $this->assertTrue($payload['normalization_meta']['partial']);
        $this->assertSame(1, $payload['normalization_meta']['missing_rate_count']);
        $this->assertSame(['GHS'], $payload['normalization_meta']['missing_currencies']);
    }

    public function test_contextual_cfa_is_resolved_without_mutating_native_payment_currency(): void
    {
        config(['services.reporting_fx.enabled' => true]);

        $platform = Platform::factory()->create([
            'name' => 'Benin',
            'country' => 'Benin Republic',
            'currency_code' => 'CFA',
        ]);
        $payment = Payment::factory()->create([
            'platform_id' => $platform->id,
            'amount' => 1000,
            'currency' => 'CFA',
            'status' => 'completed',
            'completed_at' => '2026-04-23 10:00:00',
            'created_at' => '2026-04-23 09:00:00',
        ]);

        ReportingFxRate::query()->create([
            'provider' => 'currencyapi',
            'source_currency' => 'XOF',
            'target_currency' => 'USD',
            'rate_date' => '2026-04-23',
            'rate' => '0.0016500000',
            'fetched_at' => now(),
        ]);

        $payload = app(ReportingCurrencyService::class)->normalizePaymentQuery(
            Payment::query()->whereKey($payment->id),
            'USD'
        );

        $this->assertSame(['CFA' => 1000.0], $payload['source_breakdown']);
        $this->assertSame(1.65, $payload['normalized_total']);
        $this->assertFalse($payload['normalization_meta']['partial']);
        $this->assertSame('CFA', $payload['normalization_meta']['currency_aliases'][0]['source_currency']);
        $this->assertSame('XOF', $payload['normalization_meta']['currency_aliases'][0]['canonical_currency']);

        $payment->refresh();
        $this->assertSame('CFA', $payment->currency);
        $this->assertSame(1000.0, (float) $payment->amount);
    }

    public function test_ambiguous_cfa_marks_partial_without_calling_fx_provider(): void
    {
        config([
            'services.reporting_fx.enabled' => true,
            'services.reporting_fx.api_key' => 'test-key',
        ]);
        Http::fake();

        $payload = app(ReportingCurrencyService::class)->normalizeBreakdown(
            ['CFA' => 1000],
            now()->parse('2026-04-23'),
            'USD'
        );

        $this->assertNull($payload['normalized_total']);
        $this->assertTrue($payload['normalization_meta']['partial']);
        $this->assertSame(['CFA'], $payload['normalization_meta']['missing_currencies']);
        $this->assertStringContainsString('ambiguous', $payload['normalization_meta']['missing_currency_reasons']['CFA']);
        Http::assertNothingSent();
    }

    public function test_reporting_currency_settings_endpoint_defaults_to_usd(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'email' => Str::random(8) . '@example.test',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/reporting-currency')
            ->assertOk()
            ->assertJsonPath('settings.target_currency', 'USD')
            ->assertJsonPath('recommended_defaults.all_market_management', 'flat')
            ->assertJsonPath('recommended_defaults.payments_rows', 'native');

        $this->patchJson('/api/crm/settings/reporting-currency', [
            'enabled' => true,
            'target_currency' => 'usd',
            'provider' => 'currencyapi',
            'allow_user_override' => true,
            'stale_days' => 3,
        ])->assertOk()
            ->assertJsonPath('settings.enabled', true)
            ->assertJsonPath('settings.target_currency', 'USD')
            ->assertJsonPath('settings.stale_days', 3);

        $this->assertSame('USD', data_get(
            IntegrationSetting::query()->where('key', ReportingCurrencyService::SETTINGS_KEY)->value('value'),
            'target_currency'
        ));
    }
}
