<?php

namespace Tests\Feature;

use App\Models\ReportingFxRate;
use App\Services\ReportingCurrencyService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportingFxDateBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_market_tomorrow_uses_latest_with_actual_provider_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-10 23:53:18', 'UTC'));
        config(['services.reporting_fx.api_key' => 'test-key']);
        Http::fake(['api.currencyapi.com/v3/latest*' => Http::response(['meta' => ['last_updated_at' => '2026-10-09T23:59:59Z'], 'data' => ['USD' => ['value' => 0.0077]]])]);
        try {
            $method = new \ReflectionMethod(ReportingCurrencyService::class, 'fetchAndCacheLiveRate');
            $rate = $method->invoke(app(ReportingCurrencyService::class), 'KES', 'USD', '2026-10-11');
            $this->assertNotNull($rate);
            $this->assertSame('2026-10-09', $rate->rate_date->format('Y-m-d'));
            $this->assertSame(0, ReportingFxRate::whereDate('rate_date', '2026-10-11')->count());
            Http::assertSent(fn ($request) => str_contains($request->url(), '/latest') && ! str_contains($request->url(), 'date='));
        } finally {
            Carbon::setTestNow();
        }
    }
}
