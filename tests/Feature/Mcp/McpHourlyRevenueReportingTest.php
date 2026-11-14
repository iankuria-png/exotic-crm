<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use App\Services\Ai\SqlSafetyValidator;
use App\Services\Ai\SqlValidationPolicy;
use App\Services\Mcp\McpServer;
use App\Services\Mcp\RevenueReportingQuery;
use App\Services\Mcp\RevenueReportingService;
use App\Services\Mcp\RevenueReportingWindow;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Disposable in-memory schema: no application/Local database is migrated or reset. */
class McpHourlyRevenueReportingTest extends TestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        class_exists(McpServer::class); // Existing protocol exception is declared by the server.
        config(['database.default' => 'hourly_test', 'database.connections.hourly_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'mcp.enabled' => true, 'mcp.waves.contracts' => true]);
        DB::purge('hourly_test');
        Http::preventStrayRequests();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-13T09:00:00Z'));
        Schema::create('platforms', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('country');
            $t->string('currency_code');
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('platform_id');
            $t->decimal('amount', 14, 2);
            $t->string('currency')->nullable();
            foreach (['status', 'purpose', 'source', 'provider_key', 'provider_environment', 'record_classification', 'reconciliation_state', 'resolution_code', 'subscription_lifecycle', 'reference_root', 'transaction_reference', 'reference_number'] as $name) {
                $t->string($name)->nullable();
            }
            foreach (['manual_payment_bundle_id', 'wallet_transaction_id', 'reference_sequence'] as $name) {
                $t->unsignedBigInteger($name)->nullable();
            }
            foreach (['completed_at', 'confirmed_at', 'created_at'] as $name) {
                $t->dateTime($name)->nullable();
            }
            $t->json('payment_data')->nullable();
            $t->json('raw_payload')->nullable();
        });
        Schema::create('manual_payment_bundles', function (Blueprint $t) {
            $t->id();
            $t->string('status');
        });
        Schema::create('collection_sales_sessions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('bundle_id');
        });
        Schema::create('collection_sales_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payment_id');
            $t->string('state');
            $t->dateTime('verified_at')->nullable();
        });
        Schema::create('integration_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key');
            $t->json('value')->nullable();
        });
        Schema::create('reporting_fx_rates', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('source_currency');
            $t->string('target_currency');
            $t->date('rate_date');
            $t->decimal('rate', 20, 10);
            $t->dateTime('fetched_at')->nullable();
            $t->json('metadata')->nullable();
        });
        Schema::create('user_platforms', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('platform_id');
        });
        DB::table('platforms')->insert([
            ['id' => 5, 'name' => 'Uganda', 'country' => 'Uganda', 'currency_code' => 'UGX'],
            ['id' => 16, 'name' => 'Second Uganda', 'country' => 'Uganda', 'currency_code' => 'UGX'],
            ['id' => 1, 'name' => 'Kenya', 'country' => 'Kenya', 'currency_code' => 'KES'],
        ]);
        $this->admin = new User(['role' => 'admin', 'status' => 'active']);
        $this->admin->id = 999;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        DB::disconnect('hourly_test');
        parent::tearDown();
    }

    private function payment(array $attributes = []): int
    {
        static $sequence = 0;

        return DB::table('payments')->insertGetId(array_merge([
            'platform_id' => 5, 'amount' => 100, 'currency' => 'USD', 'status' => 'completed', 'purpose' => 'subscription',
            'source' => 'gateway', 'provider_key' => 'test_provider', 'provider_environment' => 'live', 'record_classification' => 'live',
            'completed_at' => '2026-11-12 17:00:00', 'created_at' => '2026-11-12 16:00:00',
            'transaction_reference' => 'RECEIPT-'.(++$sequence), 'payment_data' => '{}', 'raw_payload' => '{}',
        ], $attributes));
    }

    private function arguments(array $extra = []): array
    {
        return $extra + ['from' => '2026-11-12T20:00:00+03:00', 'to' => '2026-11-13T05:09:00+03:00'];
    }

    private function rpc(string $name, array $arguments, string $protocol = '2026-07-28', ?User $user = null): array
    {
        $request = Request::create('/api/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => $protocol], json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments, '_meta' => ['io.modelcontextprotocol/protocolVersion' => $protocol]],
        ]));
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $user ?? $this->admin);
        $response = app(McpServer::class)->handle($request);

        return [$response->getStatusCode(), $response->getData(true)];
    }

    private function resultData(array $payload): array
    {
        return $payload['result']['structuredContent']['data'] ?? json_decode($payload['result']['content'][0]['text'], true);
    }

    public function test_august_to_november_monthly_history_agrees_across_tools_and_protocols_without_custom_flag(): void
    {
        foreach (['2026-08-15' => 10, '2026-09-15' => 20, '2026-10-15' => 30, '2026-11-12' => 40] as $date => $amount) {
            $this->payment(['amount' => $amount, 'completed_at' => $date.' 09:00:00']);
        }
        $this->payment(['amount' => 900, 'completed_at' => '2026-07-31 20:59:59']);
        $this->payment(['amount' => 800, 'completed_at' => '2026-11-14 21:00:00']);
        $arguments = ['from' => '2026-08-01', 'to' => '2026-11-14', 'bucket' => 'month'];
        foreach (['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'] as $protocol) {
            foreach (['exotic_revenue_summary', 'exotic_revenue_trend', 'exotic_market_breakdown', 'exotic_render_revenue_dashboard'] as $tool) {
                $toolArguments = $arguments;
                if (in_array($tool, ['exotic_revenue_summary', 'exotic_market_breakdown'], true)) {
                    unset($toolArguments['bucket']);
                }
                [$status, $payload] = $this->rpc($tool, $toolArguments, $protocol);
                $this->assertSame(200, $status, json_encode($payload));
                $data = $this->resultData($payload);
                if ($tool === 'exotic_render_revenue_dashboard') {
                    $this->assertSame($data['summary']['window'], $data['trend']['window']);
                    $this->assertSame($data['summary']['window'], $data['market_breakdown']['window']);
                    $this->assertEquals(100, $data['summary']['collections']['new_cash']['normalized_total']);
                    $data = $data['trend'];
                }
                $this->assertSame('2026-08-01T00:00:00+03:00', $data['window']['from_local']);
                $this->assertSame('2026-11-15T00:00:00+03:00', $data['window']['to_local']);
                if (isset($data['points'])) {
                    $this->assertEquals([10, 20, 30, 40], array_column($data['points'], 'value'));
                } elseif (isset($data['collections'])) {
                    $this->assertEquals(100, $data['collections']['new_cash']['normalized_total']);
                } else {
                    $this->assertEquals(100, $data['total']);
                }
            }
        }
    }

    public function test_multi_year_history_is_available_and_excessive_bucket_counts_are_explicit_errors(): void
    {
        $this->payment(['amount' => 25, 'completed_at' => '2025-01-10 10:00:00']);
        $this->payment(['amount' => 50, 'completed_at' => '2026-11-12 10:00:00']);
        $args = ['from' => '2025-01-01', 'to' => '2026-12-31', 'bucket' => 'month'];
        [$status, $payload] = $this->rpc('exotic_revenue_trend', $args);
        $this->assertSame(200, $status);
        $data = $this->resultData($payload);
        $this->assertCount(24, $data['points']);
        $this->assertEquals(75, array_sum(array_column($data['points'], 'value')));
        [$status, $payload] = $this->rpc('exotic_revenue_trend', array_merge($args, ['bucket' => 'hour']));
        $this->assertSame(422, $status);
        $this->assertStringContainsString('coarser bucket', json_encode($payload));
        $this->assertArrayNotHasKey('result', $payload);
    }

    public function test_unknown_presets_and_incomplete_custom_bounds_never_fall_back_to_thirty_days(): void
    {
        foreach (['exotic_revenue_summary', 'exotic_revenue_trend', 'exotic_market_breakdown', 'exotic_render_revenue_dashboard', 'exotic_ceo_dashboard'] as $tool) {
            foreach ([['window' => '4m'], ['window' => 'custom'], ['from' => '2026-08-01'], ['to' => '2026-11-14']] as $args) {
                [$status, $payload] = $this->rpc($tool, $args);
                $this->assertSame(422, $status, $tool.' '.json_encode($payload));
                $this->assertArrayNotHasKey('result', $payload);
            }
        }
    }

    public function test_legacy_presets_keep_dashboard_dispatch_and_ceo_dates_infer_custom_calendar_window(): void
    {
        $dashboard = \Mockery::mock(\App\Services\CeoDashboardDataService::class);
        foreach (['today', 'day', '7d', '30d', '90d', 'ytd'] as $preset) {
            $dashboard->shouldReceive('summary')->once()->withArgs(fn ($request) => $request->query('horizon') === $preset)
                ->andReturn(['window' => ['horizon' => $preset], 'metrics' => [], 'customer_mix' => [], 'visitor_revenue' => [], 'insights' => []]);
        }
        $this->app->instance(\App\Services\CeoDashboardDataService::class, $dashboard);
        foreach (['today', 'day', '7d', '30d', '90d', 'ytd'] as $preset) {
            [$status, $payload] = $this->rpc('exotic_revenue_summary', ['window' => $preset]);
            $this->assertSame(200, $status);
            $this->assertSame($preset, $this->resultData($payload)['window']['horizon']);
        }
        $request = app(\App\Services\Mcp\McpRequestAdapter::class)->build(['from' => '2026-08-01', 'to' => '2026-11-14'], $this->admin);
        $this->assertSame('custom', $request->query('horizon'));
        $this->app->forgetInstance(\App\Services\CeoDashboardDataService::class);
        $context = app(\App\Services\CeoDashboardDataService::class)->context($request);
        $this->assertSame('2026-08-01', $context['from']->toDateString());
        $this->assertSame('2026-11-14', $context['to']->toDateString());
        $this->assertSame(106, $context['days']);
    }

    public function test_catalogue_distinguishes_client_history_from_scoped_payment_retention(): void
    {
        Schema::create('clients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('platform_id');
            $t->dateTime('created_at');
        });
        DB::table('clients')->insert(['platform_id' => 1, 'created_at' => '2026-03-18 10:00:00']);
        $this->payment(['platform_id' => 1, 'completed_at' => '2026-08-10 10:00:00']);
        $this->payment(['platform_id' => 5, 'completed_at' => '2026-01-05 10:00:00']);
        $this->payment(['platform_id' => 1, 'completed_at' => '2025-01-01 10:00:00', 'status' => 'failed']);
        $user = new User(['role' => 'sub_admin', 'status' => 'active', 'assigned_market_ids' => [1]]);
        $user->id = 7;
        [$status, $payload] = $this->rpc('exotic_catalog', [], user: $user);
        $this->assertSame(200, $status, json_encode($payload));
        $data = $this->resultData($payload);
        $this->assertSame('client_record_creation', $data['coverage']['basis']);
        $this->assertSame('2026-03-18 10:00:00', $data['coverage']['from']);
        $this->assertSame('2026-08-10 10:00:00', $data['payment_retention']['from']);
        $this->assertSame('2026-08-10 10:00:00', $data['payment_retention']['to']);
        $this->assertStringContainsString('does not prove', $data['payment_retention']['caveat']);
        $user->role = 'sales';
        [$status, $payload] = $this->rpc('exotic_catalog', [], user: $user);
        $this->assertSame(200, $status);
        $this->assertSame('unavailable', $this->resultData($payload)['payment_retention']['availability']);
        $this->assertArrayNotHasKey('from', $this->resultData($payload)['payment_retention']);
    }

    public function test_ceo_dashboard_passes_complete_historical_bounds_to_every_existing_calculation(): void
    {
        $dashboard = \Mockery::mock(\App\Services\CeoDashboardDataService::class);
        foreach (['summary', 'revenueTrend', 'marketPie', 'peakHours', 'agentPerformance', 'context'] as $method) {
            $dashboard->shouldReceive($method)->times(4)->withArgs(fn ($request) => $request->query('horizon') === 'custom'
                && $request->query('from') === '2026-08-01' && $request->query('to') === '2026-11-14')->andReturn([]);
        }
        $this->app->instance(\App\Services\CeoDashboardDataService::class, $dashboard);
        foreach (['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'] as $protocol) {
            [$status, $payload] = $this->rpc('exotic_ceo_dashboard', ['from' => '2026-08-01', 'to' => '2026-11-14'], $protocol);
            $this->assertSame(200, $status, json_encode($payload));
            $this->assertSame('CeoDashboardDataService', $this->resultData($payload)['source']);
        }
    }

    public function test_exact_offset_boundaries_and_multiple_sites_country_category_funding_sums(): void
    {
        $this->payment(['amount' => 900, 'completed_at' => '2026-11-12 16:59:59']);
        $this->payment(['amount' => 10]);
        $this->payment(['amount' => 20, 'platform_id' => 16, 'subscription_lifecycle' => 'renewal']);
        $this->payment(['amount' => 30, 'purpose' => 'visitor_contact_unlock', 'completed_at' => '2026-11-13 02:08:59']);
        $this->payment(['amount' => 40, 'source' => 'wallet']);
        $this->payment(['amount' => 50, 'purpose' => 'send_love']);
        $this->payment(['amount' => 800, 'completed_at' => '2026-11-13 02:09:00']);
        $data = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $this->assertSame('2026-11-12T17:00:00+00:00', $data['window']['from']);
        $this->assertSame('2026-11-13T02:09:00+00:00', $data['window']['to']);
        $this->assertEquals(110, $data['collections']['new_cash']['normalized_total']);
        $this->assertEquals(150, $data['collections']['earned_services']['normalized_total']);
        $this->assertEquals(40, $data['collections']['wallet_redemptions']['normalized_total']);
        $this->assertEquals(70, $data['metrics']['collected_revenue']['value']['normalized_total']);
        $this->assertEquals(30, $data['visitor_revenue']['normalized_total']);
        $country = collect($data['collections']['countries'])->firstWhere('country', 'Uganda');
        $this->assertCount(2, $country['platforms']);
        $this->assertEquals(110, $country['new_cash']['normalized_total']);
        $this->assertEquals(110, collect($data['collections']['categories'])->sum('new_cash.normalized_total'));
        $this->assertEquals(110, collect($data['collections']['platforms'])->sum('new_cash.normalized_total'));
        $this->assertSame('unavailable', $data['customer_mix']['availability']);
    }

    public function test_historical_uganda_case_preserves_15_hour_start_and_date_only_inclusive_end(): void
    {
        $this->payment(['amount' => 100, 'completed_at' => '2026-10-31 11:59:59']);
        $this->payment(['amount' => 200, 'completed_at' => '2026-10-31 12:00:00']);
        $this->payment(['amount' => 300, 'platform_id' => 16, 'completed_at' => '2026-11-06 20:59:59']);
        $this->payment(['amount' => 400, 'completed_at' => '2026-11-06 21:00:00']);
        $data = app(RevenueReportingService::class)->summary(['from' => '2026-10-31T15:00:00+03:00', 'to' => '2026-11-06', 'country' => 'uganda'], $this->admin);
        $this->assertEquals(500, $data['collections']['new_cash']['normalized_total']);
        $this->assertSame('2026-11-07T00:00:00+03:00', $data['window']['to_local']);
    }

    public function test_real_hourly_buckets_across_midnight_keep_distinct_dates_and_partial_end(): void
    {
        $this->payment(['amount' => 10]);
        $this->payment(['amount' => 20, 'completed_at' => '2026-11-12 21:01:00']);
        $this->payment(['amount' => 30, 'completed_at' => '2026-11-13 02:08:59']);
        $data = app(RevenueReportingService::class)->trend($this->arguments(['bucket' => 'hour']), $this->admin);
        $this->assertSame('hour', $data['bucket']);
        $this->assertCount(10, $data['points']);
        $this->assertSame('2026-11-12T20:00:00+03:00', $data['points'][0]['label']);
        $this->assertSame('2026-11-13T00:00:00+03:00', $data['points'][4]['label']);
        $this->assertEquals(60, collect($data['points'])->sum('value'));
    }

    public function test_business_visibility_exclusions_and_aggregate_view_have_identical_eligibility(): void
    {
        $this->payment(['amount' => 10]);
        foreach ([['status' => 'pending'], ['status' => 'failed'], ['status' => 'refunded'], ['status' => 'reversed'],
            ['record_classification' => 'test'], ['provider_environment' => 'sandbox'], ['resolution_code' => 'reversed'],
            ['resolution_code' => 'invalid_reference'], ['reconciliation_state' => 'manual_review'], ['purpose' => 'wallet_topup'],
            ['payment_data' => '{"test_mode":true}'], ['amount' => 0]] as $bad) {
            $this->payment($bad + ['amount' => 1000]);
        }
        DB::table('manual_payment_bundles')->insert([['id' => 1, 'status' => 'committing'], ['id' => 2, 'status' => 'committed']]);
        $this->payment(['manual_payment_bundle_id' => 1]);
        $id = $this->payment(['manual_payment_bundle_id' => 2, 'reference_sequence' => 1]);
        DB::table('collection_sales_sessions')->insert(['bundle_id' => 2]);
        DB::table('collection_sales_items')->insert(['payment_id' => $id, 'state' => 'pending', 'verified_at' => null]);
        $data = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $this->assertEquals(10, $data['collections']['new_cash']['normalized_total']);
        (require database_path('migrations/2026_11_13_180000_create_mcp_payment_collections_view.php'))->up();
        $this->assertEquals(10, DB::table(RevenueReportingQuery::VIEW)->where('disposition', 'included')->sum('amount'));
        $columns = Schema::getColumnListing(RevenueReportingQuery::VIEW);
        foreach (['payment_id', 'client_id', 'name', 'phone', 'receipt_key', 'account_key'] as $private) {
            $this->assertNotContains($private, $columns);
        }
        (require database_path('migrations/2026_11_13_180000_create_mcp_payment_collections_view.php'))->down();
        $this->assertFalse(Schema::hasTable(RevenueReportingQuery::VIEW));
    }

    public function test_duplicate_outside_window_is_not_counted_but_split_bundle_and_receiving_accounts_are_preserved(): void
    {
        $this->payment(['transaction_reference' => 'DUP', 'amount' => 20]);
        $this->payment(['transaction_reference' => 'DUP', 'amount' => 20, 'completed_at' => '2026-11-12 16:00:00']);
        DB::table('manual_payment_bundles')->insert(['id' => 3, 'status' => 'committed']);
        $this->payment(['transaction_reference' => 'SPLIT', 'reference_root' => 'SPLIT', 'reference_sequence' => 1, 'manual_payment_bundle_id' => 3, 'amount' => 30]);
        $this->payment(['transaction_reference' => 'SPLIT', 'reference_root' => 'SPLIT', 'reference_sequence' => 2, 'manual_payment_bundle_id' => 3, 'amount' => 40]);
        $this->payment(['transaction_reference' => 'SAME-REF', 'payment_data' => '{"collection":{"account_id":"account-a"}}', 'amount' => 50]);
        $this->payment(['transaction_reference' => 'SAME-REF', 'payment_data' => '{"collection":{"account_id":"account-b"}}', 'amount' => 60]);
        $data = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $this->assertEquals(180, $data['collections']['new_cash']['normalized_total']);
        $this->assertEquals(20, $data['collections']['coverage']['ambiguous_receipt_records']['source_breakdown']['USD']);
        $this->assertSame(1, $data['collections']['coverage']['ambiguous_receipt_records']['payments_count']);
        $this->assertSame('partial', $data['_report_meta']['result_state']);
    }

    public function test_wallet_variants_unknown_funding_and_missing_cached_fx_are_not_new_cash_or_fake_usd(): void
    {
        $this->payment(['amount' => 10, 'raw_payload' => '{"method":"wallet"}']);
        $this->payment(['amount' => 20, 'wallet_transaction_id' => 77]);
        $this->payment(['amount' => 30, 'source' => null, 'provider_key' => null]);
        $this->payment(['amount' => 100, 'currency' => 'UGX']);
        $before = DB::table('reporting_fx_rates')->count();
        $data = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $this->assertEquals(30, $data['collections']['wallet_redemptions']['normalized_total']);
        $this->assertEquals(30, $data['collections']['unknown_funding']['normalized_total']);
        $this->assertNull($data['collections']['new_cash']['normalized_total']);
        $this->assertEquals(['UGX' => 100], $data['collections']['new_cash']['source_breakdown']);
        $this->assertSame($before, DB::table('reporting_fx_rates')->count());
        Http::assertNothingSent();
    }

    public function test_cached_historical_fx_and_timestamp_fallback_are_reported_without_external_fetch(): void
    {
        DB::table('reporting_fx_rates')->insert(['provider' => 'currencyapi', 'source_currency' => 'UGX', 'target_currency' => 'USD', 'rate_date' => '2026-11-12', 'rate' => 0.00025]);
        $this->payment(['currency' => 'UGX', 'amount' => 4000, 'completed_at' => null, 'confirmed_at' => '2026-11-12 17:02:00']);
        $data = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $this->assertEquals(1, $data['collections']['new_cash']['normalized_total']);
        $this->assertSame(['confirmed_at' => 1], $data['collections']['coverage']['clock_sources']);
        Http::assertNothingSent();
    }

    public function test_invalid_ranges_and_unscoped_country_are_refused_instead_of_defaulting(): void
    {
        foreach ([['from' => '2026-11-12'], ['from' => '2026-11-14', 'to' => '2026-11-12'],
            ['from' => '2026-11-12T20:00:00', 'to' => '2026-11-13T05:09:00'],
            ['from' => '2026-02-30', 'to' => '2026-03-03']] as $bad) {
            try {
                RevenueReportingWindow::fromArguments($bad);
                $this->fail('Invalid interval accepted');
            } catch (\App\Services\Mcp\McpProtocolException $e) {
                $this->assertSame(422, $e->httpStatus);
            }
        }
        $user = new User(['role' => 'sub_admin', 'assigned_market_ids' => [1]]);
        $user->id = 7;
        $this->expectException(\App\Services\Mcp\McpProtocolException::class);
        app(RevenueReportingService::class)->summary($this->arguments(['country' => 'Uganda']), $user);
    }

    public function test_equivalent_offsets_and_subsecond_bounds_select_the_same_instant(): void
    {
        $this->payment(['amount' => 10]);
        $a = app(RevenueReportingService::class)->summary($this->arguments(), $this->admin);
        $b = app(RevenueReportingService::class)->summary(['from' => '2026-11-12T17:00:00Z', 'to' => '2026-11-13T02:09:00Z'], $this->admin);
        $this->assertSame($a['collections']['new_cash'], $b['collections']['new_cash']);
        $c = app(RevenueReportingService::class)->summary(['from' => '2026-11-12T17:00:00.001Z', 'to' => '2026-11-13T02:09:00Z'], $this->admin);
        $this->assertSame(0, $c['collections']['new_cash']['payments_count']);
    }

    public function test_view_default_amount_is_cash_while_earned_value_is_explicit_and_ambiguous_rows_are_absent(): void
    {
        $this->payment(['amount' => 10]);
        $this->payment(['amount' => 20, 'source' => 'wallet']);
        $this->payment(['amount' => 30, 'source' => null, 'provider_key' => null]);
        $this->payment(['amount' => 40, 'transaction_reference' => 'DOUBLE']);
        $this->payment(['amount' => 40, 'transaction_reference' => 'DOUBLE']);
        (require database_path('migrations/2026_11_13_180000_create_mcp_payment_collections_view.php'))->up();
        $this->assertEquals(10, DB::table(RevenueReportingQuery::VIEW)->sum('amount'));
        $this->assertEquals(60, DB::table(RevenueReportingQuery::VIEW)->sum('earned_service_amount'));
        $this->assertEquals(1, DB::table(RevenueReportingQuery::VIEW)->sum('payments_count'));
        $this->assertEquals(3, DB::table(RevenueReportingQuery::VIEW)->sum('service_payment_count'));
        $this->assertSame(0, DB::table(RevenueReportingQuery::VIEW)->where('disposition', 'ambiguous_receipt')->count());
    }

    public function test_exact_market_rows_retain_app_contract_and_requested_currency_is_not_falsely_echoed(): void
    {
        $this->payment(['amount' => 10]);
        $data = app(RevenueReportingService::class)->marketBreakdown($this->arguments(['reporting_currency' => 'KES']), $this->admin);
        $site = collect($data['markets'])->firstWhere('platform_id', 5);
        $this->assertEquals(10, $site['value']);
        $this->assertEquals(100, $site['share_percent']);
        $this->assertSame('USD', $site['normalized_currency']);
        $this->assertSame('USD', $data['window']['reporting_currency']);
        $this->assertSame('KES', $data['_report_meta']['filters']['requested_reporting_currency']);
    }

    public function test_exact_render_dashboard_uses_one_interval_and_preserves_partial_fx_through_envelope(): void
    {
        $this->payment(['amount' => 100, 'currency' => 'UGX']);
        $request = Request::create('/api/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => '2026-07-28'], json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'exotic_render_revenue_dashboard', 'arguments' => $this->arguments(['bucket' => 'hour']), '_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28']],
        ]));
        $this->app->instance('request', $request);
        $request->setUserResolver(fn () => $this->admin);
        $response = app(McpServer::class)->handle($request);
        $payload = $response->getData(true);
        $this->assertSame(200, $response->getStatusCode(), json_encode($payload));
        $data = $payload['result']['structuredContent']['data'];
        $this->assertSame($data['summary']['window'], $data['trend']['window']);
        $this->assertSame($data['summary']['window'], $data['market_breakdown']['window']);
        $this->assertNull($data['summary']['collections']['new_cash']['normalized_total']);
        $this->assertNull($data['market_breakdown']['total']);
        $this->assertSame('partial', $payload['result']['structuredContent']['meta']['result_state']);
        $this->assertArrayNotHasKey('_report_meta', $data['summary']);
        $this->assertNotEmpty($payload['result']['structuredContent']['meta']['coverage']);
    }

    public function test_all_mcp_protocol_adapters_preserve_exact_values_metadata_and_sql_safety(): void
    {
        $this->payment(['amount' => 15]);
        foreach (['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'] as $protocol) {
            $request = Request::create('/api/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => $protocol], json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'exotic_revenue_summary', 'arguments' => $this->arguments(), '_meta' => ['io.modelcontextprotocol/protocolVersion' => $protocol]],
            ]));
            $this->app->instance('request', $request);
            $request->setUserResolver(fn () => $this->admin);
            $response = app(McpServer::class)->handle($request);
            $payload = $response->getData(true);
            $this->assertSame(200, $response->getStatusCode(), json_encode($payload));
            $data = $payload['result']['structuredContent']['data'] ?? json_decode($payload['result']['content'][0]['text'], true);
            $this->assertEquals(15, $data['collections']['new_cash']['normalized_total']);
            $this->assertSame('partial', $payload['result']['_meta']['exotic/resultType']);
            $this->assertArrayNotHasKey('_report_meta', $data);
            if (isset($payload['result']['structuredContent'])) {
                $this->assertNotEmpty($payload['result']['structuredContent']['meta']['caveats']);
            }
        }
        $policy = new SqlValidationPolicy([RevenueReportingQuery::VIEW], 50, 500, forbiddenColumns: ['payment_id', 'phone']);
        $sql = app(SqlSafetyValidator::class)->validateWithPolicy('SELECT country, SUM(amount) AS total FROM '.RevenueReportingQuery::VIEW." WHERE occurred_at >= '2026-11-12 17:00:00' AND occurred_at < '2026-11-13 02:09:00' AND disposition = 'included' GROUP BY country", null, $policy);
        $this->assertSame([RevenueReportingQuery::VIEW], $sql['views']);
        $this->expectException(\App\Services\Ai\Exceptions\SqlValidationException::class);
        app(SqlSafetyValidator::class)->validateWithPolicy('SELECT * FROM payments', null, $policy);
    }

    public function test_visitor_country_arguments_and_unavailable_coverage_survive_all_protocols(): void
    {
        // Transport integration only; real country scoping and ingestion are exercised
        // by ContactUnlockCountryDemandTest on its disposable schema.
        $arguments = ['country' => 'Tanzania', 'range' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-13', 'timezone' => 'Africa/Nairobi'];
        $analytics = \Mockery::mock(\App\Services\Mcp\McpAnalyticsService::class);
        $analytics->shouldReceive('visitorDemand')->times(4)->withArgs(fn ($actual, $auth) => $actual === $arguments && $auth->user->id === $this->admin->id)
            ->andReturn(['overview' => ['demand' => ['totals' => ['coverage' => 'unavailable'], 'profile_locations' => []]],
                'analytics' => [], '_report_meta' => ['result_state' => 'unavailable', 'caveats' => ['Missing collection is not zero demand.']]]);
        $this->app->instance(\App\Services\Mcp\McpAnalyticsService::class, $analytics);
        foreach (['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28'] as $protocol) {
            $request = Request::create('/api/mcp', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream', 'HTTP_MCP_PROTOCOL_VERSION' => $protocol], json_encode([
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'exotic_visitor_demand', 'arguments' => $arguments, '_meta' => ['io.modelcontextprotocol/protocolVersion' => $protocol]],
            ]));
            $this->app->instance('request', $request);
            $request->setUserResolver(fn () => $this->admin);
            $response = app(McpServer::class)->handle($request);
            $payload = $response->getData(true);
            $this->assertSame(200, $response->getStatusCode(), json_encode($payload));
            $data = $payload['result']['structuredContent']['data'] ?? json_decode($payload['result']['content'][0]['text'], true);
            $this->assertSame('unavailable', data_get($data, 'overview.demand.totals.coverage'));
            $this->assertSame('unavailable', $payload['result']['_meta']['exotic/resultType']);
            $this->assertArrayNotHasKey('_report_meta', $data);
        }
    }
}
