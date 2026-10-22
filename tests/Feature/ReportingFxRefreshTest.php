<?php

namespace Tests\Feature;

use App\Models\IntegrationSetting;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use App\Services\ReportingCurrencyService;
use App\Services\ReportingFxRefreshService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportingFxRefreshTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 01:42:00');
        Sleep::fake();
        config(['services.reporting_fx.api_key' => 'test-key']);
        IntegrationSetting::query()->create([
            'key' => ReportingCurrencyService::SETTINGS_KEY,
            'value' => ['enabled' => true, 'provider' => 'currencyapi', 'target_currency' => 'USD', 'stale_days' => 2],
        ]);
        Platform::factory()->create(['name' => 'Kenya', 'country' => 'Kenya', 'currency_code' => 'KES']);
        Platform::factory()->create(['name' => 'Senegal', 'country' => 'Senegal', 'currency_code' => 'CFA']);
        Platform::factory()->create(['name' => 'Zimbabwe', 'country' => 'Zimbabwe', 'currency_code' => 'USD']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fakeProvider(int $historicalStatus = 200): void
    {
        Http::fake([
            '*/status' => Http::response(['quotas' => ['month' => ['used' => 40, 'total' => 300, 'remaining' => 260]]]),
            '*/historical*' => fn (Request $request) => $historicalStatus === 200
                ? Http::response([
                    'meta' => ['last_updated_at' => $request['date'].'T23:59:59Z'],
                    'data' => [
                        'KES' => ['code' => 'KES', 'value' => 129.5],
                        'XOF' => ['code' => 'XOF', 'value' => 570.0],
                    ],
                ])
                : Http::response(['message' => 'Too Many Requests'], $historicalStatus),
        ]);
    }

    public function test_fetches_each_missing_closed_day_with_one_usd_base_call_and_stores_inverse_rates(): void
    {
        $this->fakeProvider();

        $result = app(ReportingFxRefreshService::class)->refresh();

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['KES', 'XOF'], $result['currencies']);
        $this->assertSame(['2026-10-21', '2026-10-20'], $result['dates_fetched']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/historical')
            && $request['date'] === '2026-10-21'
            && $request['base_currency'] === 'USD'
            && $request['currencies'] === 'KES,XOF');

        $kes = ReportingFxRate::query()->where('source_currency', 'KES')->whereDate('rate_date', '2026-10-21')->firstOrFail();
        $this->assertEqualsWithDelta(1 / 129.5, (float) $kes->rate, 1e-9);
        $this->assertSame('scheduled_daily_close', $kes->metadata['source']);

        $status = IntegrationSetting::query()->where('key', ReportingFxRefreshService::STATUS_KEY)->value('value');
        $this->assertSame('ok', $status['status']);
        $this->assertNotNull($status['last_success_at']);
    }

    public function test_replaces_provisional_intraday_rates_but_keeps_final_ones(): void
    {
        $this->fakeProvider();
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi', 'source_currency' => 'KES', 'target_currency' => 'USD',
            'rate_date' => '2026-10-21', 'rate' => '0.0080000000', 'fetched_at' => '2026-10-21 09:00:00',
        ]);
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi', 'source_currency' => 'XOF', 'target_currency' => 'USD',
            'rate_date' => '2026-10-21', 'rate' => '0.0017000000', 'fetched_at' => '2026-10-22 00:30:00',
        ]);

        app(ReportingFxRefreshService::class)->refresh();

        $this->assertEqualsWithDelta(1 / 129.5, (float) ReportingFxRate::query()->where('source_currency', 'KES')->whereDate('rate_date', '2026-10-21')->value('rate'), 1e-9);
        $this->assertSame('0.0017000000', (string) ReportingFxRate::query()->where('source_currency', 'XOF')->whereDate('rate_date', '2026-10-21')->value('rate'));
    }

    public function test_provider_failure_is_recorded_and_logged_as_an_error(): void
    {
        $this->fakeProvider(429);
        IntegrationSetting::query()->create([
            'key' => ReportingFxRefreshService::STATUS_KEY,
            'value' => ['last_success_at' => '2026-10-20T01:42:00+00:00', 'status' => 'ok'],
        ]);
        Log::spy();

        $result = app(ReportingFxRefreshService::class)->refresh();

        $this->assertSame('failed', $result['status']);
        $this->assertSame(0, ReportingFxRate::query()->count());
        $status = IntegrationSetting::query()->where('key', ReportingFxRefreshService::STATUS_KEY)->value('value');
        $this->assertSame('failed', $status['status']);
        $this->assertSame('2026-10-20T01:42:00+00:00', $status['last_success_at']);
        Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === ReportingFxRefreshService::FAILURE_EVENT)->once();
    }

    public function test_dry_run_does_not_call_the_provider_for_rates_or_write(): void
    {
        $this->fakeProvider();

        $this->artisan('crm:refresh-fx-rates --dry-run')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/historical'));
        $this->assertSame(0, ReportingFxRate::query()->count());
        $this->assertNull(IntegrationSetting::query()->where('key', ReportingFxRefreshService::STATUS_KEY)->value('value'));
    }

    public function test_settings_endpoint_reports_per_currency_freshness(): void
    {
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi', 'source_currency' => 'KES', 'target_currency' => 'USD',
            'rate_date' => '2026-10-21', 'rate' => '0.0077000000', 'fetched_at' => '2026-10-22 01:42:00',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/crm/settings/reporting-currency')->assertOk();

        $this->assertSame('never_run', $response->json('refresh.status'));
        $this->assertSame('current', $response->json('refresh.currencies.KES.state'));
        $this->assertSame('outdated', $response->json('refresh.currencies.XOF.state'));
        $this->assertSame(1, $response->json('refresh.outdated_count'));
    }

    public function test_refresh_is_scheduled_daily_in_the_background(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains((string) $event->command, 'crm:refresh-fx-rates'));

        $this->assertNotNull($event);
        $this->assertSame('42 1 * * *', $event->expression);
        $this->assertTrue($event->runInBackground);
        $this->assertTrue($event->withoutOverlapping);
    }
}
