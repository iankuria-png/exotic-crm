<?php

namespace Tests\Feature;

use App\Jobs\RefreshReportingFxRatesJob;
use App\Jobs\SendFxRefreshAlertJob;
use App\Models\IntegrationSetting;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\ReportingFxRate;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ReportingCurrencyService;
use App\Services\ReportingFxRefreshService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;
use Mockery;
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

    public function test_settings_endpoint_separates_currencies_in_use_from_dormant_markets(): void
    {
        $kenya = Platform::query()->where('name', 'Kenya')->firstOrFail();
        Payment::factory()->create(['platform_id' => $kenya->id, 'currency' => 'KES', 'amount' => 500, 'status' => 'completed']);
        ReportingFxRate::query()->create([
            'provider' => 'currencyapi', 'source_currency' => 'KES', 'target_currency' => 'USD',
            'rate_date' => '2026-10-21', 'rate' => '0.0077000000', 'fetched_at' => '2026-10-22 01:42:00',
        ]);
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $response = $this->getJson('/api/crm/settings/reporting-currency')->assertOk();

        $this->assertSame('never_run', $response->json('refresh.status'));
        $this->assertSame('current', $response->json('refresh.currencies.KES.state'));
        $this->assertTrue($response->json('refresh.currencies.KES.in_use'));
        $this->assertFalse($response->json('refresh.currencies.XOF.in_use'));
        $this->assertSame(1, $response->json('refresh.in_use_count'));
        // XOF has no rate, but no Senegal payments either, so it is not an incident.
        $this->assertSame(0, $response->json('refresh.outdated_count'));
        $this->assertSame('2026-10-23T01:42:00+00:00', $response->json('refresh.next_run_at'));
    }

    public function test_only_currencies_in_use_raise_an_outside_window_issue(): void
    {
        Http::fake([
            '*/status' => Http::response(['quotas' => ['month' => ['used' => 40, 'total' => 300, 'remaining' => 260]]]),
            '*/historical*' => Http::response(['data' => ['KES' => ['code' => 'KES', 'value' => 129.5]]]),
        ]);
        $senegal = Platform::query()->where('name', 'Senegal')->firstOrFail();
        Payment::factory()->create(['platform_id' => $senegal->id, 'currency' => 'CFA', 'amount' => 5000, 'status' => 'completed']);

        $result = app(ReportingFxRefreshService::class)->refresh();

        $this->assertSame('degraded', $result['status']);
        $this->assertContains('CurrencyAPI returned no rate for: XOF.', $result['issues']);
        $this->assertStringContainsString('window for: XOF;', implode(' ', $result['issues']));
        $this->assertStringNotContainsString('KES;', implode(' ', $result['issues']));
    }

    public function test_manual_refresh_is_queued_once_and_reported_as_in_progress(): void
    {
        Bus::fake([RefreshReportingFxRatesJob::class]);
        Sanctum::actingAs($admin = User::factory()->create(['role' => 'admin']));

        $this->postJson('/api/crm/settings/reporting-currency/refresh')
            ->assertStatus(202)
            ->assertJsonPath('refresh.queued_at', now()->toIso8601String());
        $this->postJson('/api/crm/settings/reporting-currency/refresh')->assertStatus(202);

        Bus::assertDispatchedTimes(RefreshReportingFxRatesJob::class, 1);
        Bus::assertDispatched(RefreshReportingFxRatesJob::class, fn ($job) => $job->userId === $admin->id);
    }

    public function test_sales_users_cannot_trigger_a_refresh(): void
    {
        Bus::fake([RefreshReportingFxRatesJob::class]);
        Sanctum::actingAs(User::factory()->create(['role' => 'sales']));

        $this->postJson('/api/crm/settings/reporting-currency/refresh')->assertForbidden();

        Bus::assertNotDispatched(RefreshReportingFxRatesJob::class);
    }

    public function test_manual_run_clears_the_queued_state_and_records_its_trigger(): void
    {
        $this->fakeProvider();
        $service = app(ReportingFxRefreshService::class);
        $service->markQueued(7);
        $this->assertNotNull($service->status()['queued_at']);

        Carbon::setTestNow('2026-10-22 01:43:00');
        (new RefreshReportingFxRatesJob(7))->handle($service);

        $status = $service->status();
        $this->assertNull($status['queued_at']);
        $this->assertSame('manual', $status['last_trigger']);
        $this->assertSame('ok', $status['status']);
    }

    public function test_failures_alert_opted_in_admins_once_per_issue(): void
    {
        Bus::fake([SendFxRefreshAlertJob::class]);
        $this->fakeProvider(429);

        app(ReportingFxRefreshService::class)->refresh();
        Bus::assertDispatchedTimes(SendFxRefreshAlertJob::class, 1);

        // A manual retry an hour later with the same failure does not page again.
        Carbon::setTestNow('2026-10-22 02:42:00');
        app(ReportingFxRefreshService::class)->refresh();
        Bus::assertDispatchedTimes(SendFxRefreshAlertJob::class, 1);

        // The next day's run with the same unresolved failure pages once more.
        Carbon::setTestNow('2026-10-23 01:42:00');
        app(ReportingFxRefreshService::class)->refresh();
        Bus::assertDispatchedTimes(SendFxRefreshAlertJob::class, 2);
    }

    public function test_alert_sms_goes_only_to_active_admins_who_opted_in(): void
    {
        $optedIn = User::factory()->create(['role' => 'admin', 'status' => 'active', 'phone' => '0712000001', 'notification_prefs' => ['fx_refresh_sms' => ['enabled' => true]]]);
        User::factory()->create(['role' => 'admin', 'status' => 'active', 'phone' => '0712000002', 'notification_prefs' => ['market_down_sms' => ['enabled' => true]]]);
        User::factory()->create(['role' => 'sales', 'status' => 'active', 'phone' => '0712000003', 'notification_prefs' => ['fx_refresh_sms' => ['enabled' => true]]]);
        User::factory()->create(['role' => 'sub_admin', 'status' => 'inactive', 'phone' => '0712000004', 'notification_prefs' => ['fx_refresh_sms' => ['enabled' => true]]]);

        $sent = [];
        $notifications = Mockery::mock(NotificationService::class);
        $notifications->shouldReceive('sendSms')->andReturnUsing(function ($phone, $message) use (&$sent) {
            $sent[] = [$phone, $message];

            return ['success' => true];
        });

        (new SendFxRefreshAlertJob('failed', ['2026-10-21: CurrencyAPI responded with HTTP 429.', 'second issue']))->handle($notifications);

        $this->assertSame([$optedIn->phone], array_column($sent, 0));
        $this->assertStringContainsString('FX rate refresh failed. 2026-10-21: CurrencyAPI responded with HTTP 429. (+1 more)', $sent[0][1]);
    }

    public function test_admin_can_save_the_fx_alert_opt_in(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $target = User::factory()->create(['role' => 'sub_admin', 'status' => 'active']);

        $this->patchJson("/api/crm/settings/roles/{$target->id}", [
            'role' => 'sub_admin',
            'status' => 'active',
            'notification_prefs' => ['fx_refresh_sms' => ['enabled' => true]],
        ])->assertOk();

        $this->assertTrue($target->fresh()->fxRefreshSmsEnabled());
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
