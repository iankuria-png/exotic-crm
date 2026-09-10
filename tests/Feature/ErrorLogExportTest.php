<?php

namespace Tests\Feature;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ErrorLogExportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::query()->create([
            'name' => 'Admin '.Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    private function group(array $attributes = []): ErrorLogGroup
    {
        return ErrorLogGroup::query()->create(array_merge([
            'signature' => Str::random(32),
            'level' => 'error',
            'source' => 'exception',
            'exception_class' => 'RuntimeException',
            'message' => 'Deal activation failed',
            'file' => 'app/Services/DealService.php',
            'line' => 42,
            'occurrence_count' => 7,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now()->subMinutes(5),
        ], $attributes));
    }

    private function streamed($response): string
    {
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }

    public function test_csv_export_returns_one_row_per_signature(): void
    {
        $this->group();
        $this->group(['message' => 'SMS dispatch failed', 'exception_class' => null, 'source' => 'log']);

        Sanctum::actingAs($this->admin());

        $response = $this->get('/api/crm/settings/error-logs/export?format=csv');
        $response->assertOk();

        $csv = $this->streamed($response->baseResponse);

        $this->assertStringContainsString('Error,Message,File,Line,Level,Source,Status', $csv);
        $this->assertStringContainsString('Deal activation failed', $csv);
        $this->assertStringContainsString('SMS dispatch failed', $csv);
        $this->assertSame(3, substr_count(trim($csv), "\n") + 1, 'header plus two rows');
    }

    public function test_csv_export_honours_the_active_filters(): void
    {
        $this->group(['message' => 'Unresolved failure']);
        $this->group(['message' => 'Already handled', 'resolved_at' => now()]);

        Sanctum::actingAs($this->admin());

        $csv = $this->streamed(
            $this->get('/api/crm/settings/error-logs/export?status=unresolved')->baseResponse
        );

        $this->assertStringContainsString('Unresolved failure', $csv);
        $this->assertStringNotContainsString('Already handled', $csv);
    }

    public function test_json_bundle_carries_stack_traces_for_each_group(): void
    {
        $group = $this->group();

        ErrorLogOccurrence::query()->create([
            'group_id' => $group->id,
            'occurred_at' => now()->subMinutes(5),
            'trace' => "#0 app/Services/DealService.php(42): activate()\n#1 {main}",
            'context' => ['request_id' => 'CRM-20260910-abc123'],
            'url' => 'https://crm.exotic-online.com/api/crm/deals/10728/activate',
            'method' => 'POST',
        ]);

        Sanctum::actingAs($this->admin());

        $payload = json_decode($this->streamed(
            $this->get('/api/crm/settings/error-logs/export?format=json')->baseResponse
        ), true);

        $this->assertFalse($payload['truncated']);
        $this->assertSame(1, $payload['matched_groups']);
        $this->assertCount(1, $payload['groups']);
        $this->assertSame('RuntimeException', $payload['groups'][0]['exception_class']);

        $occurrence = $payload['groups'][0]['recent_occurrences'][0];
        $this->assertStringContainsString('DealService.php(42)', $occurrence['trace']);
        $this->assertSame('CRM-20260910-abc123', $occurrence['context']['request_id']);
        $this->assertSame('POST', $occurrence['method']);
    }

    public function test_export_route_is_not_swallowed_by_the_group_binding(): void
    {
        Sanctum::actingAs($this->admin());

        $this->get('/api/crm/settings/error-logs/export')->assertOk();
    }

    public function test_export_is_admin_only(): void
    {
        $sales = User::query()->create([
            'name' => 'Sales '.Str::random(6),
            'email' => Str::random(8).'@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);

        Sanctum::actingAs($sales);

        $this->get('/api/crm/settings/error-logs/export')->assertForbidden();
        $this->get('/api/crm/settings/pulse/export')->assertForbidden();
    }

    public function test_pulse_export_returns_a_section_per_recorder(): void
    {
        Sanctum::actingAs($this->admin());

        $response = $this->get('/api/crm/settings/pulse/export?format=json&period=24_hours');
        $response->assertOk();

        $payload = json_decode($this->streamed($response->baseResponse), true);

        $this->assertSame('24_hours', $payload['period']);
        $this->assertSame(24, $payload['period_hours']);
        $this->assertSame(
            ['exceptions', 'slow_requests', 'slow_queries', 'slow_jobs', 'slow_outgoing_requests'],
            array_keys($payload['sections'])
        );
    }

    public function test_pulse_export_reads_the_recorded_aggregates(): void
    {
        Pulse::record('slow_query', json_encode([
            'select count(*) as aggregate from `clients` where `platform_id` in (?)',
            'app/Http/Controllers/CRM/ClientController.php:1836',
        ]), 3436)->max()->count();

        Pulse::record('slow_request', json_encode([
            'GET',
            '/api/crm/ai/insights/headline',
            'App\\Http\\Controllers\\CRM\\AiInsightsController@headline',
        ]), 36642)->max()->count();

        Pulse::ingest();

        Sanctum::actingAs($this->admin());

        $payload = json_decode($this->streamed(
            $this->get('/api/crm/settings/pulse/export?format=json')->baseResponse
        ), true);

        $query = $payload['sections']['slow_queries']['rows'][0];
        $this->assertStringContainsString('from `clients`', $query['item']);
        $this->assertSame('app/Http/Controllers/CRM/ClientController.php:1836', $query['detail']);
        $this->assertSame(3436, $query['slowest_ms']);
        $this->assertSame(1, $query['count']);

        $request = $payload['sections']['slow_requests']['rows'][0];
        $this->assertSame('GET /api/crm/ai/insights/headline', $request['item']);
        $this->assertSame('App\\Http\\Controllers\\CRM\\AiInsightsController@headline', $request['detail']);
        $this->assertSame(36642, $request['slowest_ms']);
    }

    public function test_pulse_export_falls_back_to_a_known_period(): void
    {
        Sanctum::actingAs($this->admin());

        $payload = json_decode($this->streamed(
            $this->get('/api/crm/settings/pulse/export?format=json&period=all_time')->baseResponse
        ), true);

        $this->assertSame('24_hours', $payload['period']);
    }

    public function test_pulse_csv_export_has_a_header_row(): void
    {
        Sanctum::actingAs($this->admin());

        $csv = $this->streamed(
            $this->get('/api/crm/settings/pulse/export')->baseResponse
        );

        $this->assertStringContainsString('Section,Item,Detail,Count,"Slowest (ms)",Latest,Period', $csv);
    }
}
