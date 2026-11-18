<?php

namespace Tests\Feature\Mcp;

use App\Models\Payment;
use App\Models\Platform;
use App\Models\User;
use App\Services\Mcp\Diagnostics\PaymentFlowTraceService;
use App\Services\Mcp\McpAuthorizationContext;
use App\Services\Mcp\McpProtocolException;
use App\Services\Mcp\OpaqueEntityLocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class McpPaymentFlowTraceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The protocol exception is declared alongside the routed MCP server.
        class_exists(\App\Services\Mcp\McpServer::class);
    }

    public function test_old_locator_uses_bounded_id_pages_without_loading_receipt_payloads(): void
    {
        $payment = Payment::factory()->create(['status' => 'completed', 'provider_environment' => 'live', 'raw_payload' => ['private_receipt' => 'PRIVATE RECEIPT']]);
        $row = DB::table('payments')->where('id', $payment->id)->first();
        $data = (array) $row;
        unset($data['id']);
        for ($i = 0; $i < 1001; $i++) {
            $data['transaction_uuid'] = \Illuminate\Support\Str::uuid()->toString();
            $data['transaction_reference'] = 'synthetic-'.$i;
            $data['reference_number'] = 'synthetic-'.$i;
            DB::table('payments')->insert($data);
        }
        $before = DB::table('payments')->orderBy('id')->get()->toJson();
        DB::enableQueryLog();
        $result = $this->trace($payment);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame('completed', $result['stages'][0]['state']);
        $this->assertSame('live', $result['environment']);
        $this->assertSame('unobserved', $result['stages'][1]['state']);
        $this->assertSame('unobserved', $result['stages'][2]['state']);
        $this->assertStringNotContainsString('PRIVATE RECEIPT', json_encode($result));
        $idPages = array_filter($queries, fn ($q) => str_contains($q['query'], 'select "id" from "payments"'));
        $this->assertCount(3, $idPages);
        foreach ($idPages as $q) {
            $this->assertStringContainsString('limit 500', $q['query']);
        }
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('select *', strtolower($q['query']));
            $this->assertStringNotContainsString('raw_payload', $q['query']);
        }
        $this->assertSame($before, DB::table('payments')->orderBy('id')->get()->toJson());
    }

    public function test_latest_provider_and_callback_observations_are_selected(): void
    {
        $payment = Payment::factory()->create();
        foreach (['failed', 'succeeded'] as $status) {
            DB::table('payment_attempts')->insert(['payment_id' => $payment->id, 'attempt_type' => 'charge', 'status' => $status, 'error_code' => $status === 'failed' ? 'old_error' : null]);
            DB::table('billing_webhook_events')->insert(['provider_type_key' => 'synthetic', 'dedupe_key' => $status, 'raw_body' => '{}', 'signature_status' => 'verified', 'processing_status' => $status]);
        }
        DB::table('billing_webhook_events')->update(['payment_id' => $payment->id]);
        $result = $this->trace($payment);
        $this->assertSame('succeeded', $result['stages'][1]['state']);
        $this->assertNull($result['stages'][1]['code']);
        $this->assertSame('succeeded', $result['stages'][2]['state']);
    }

    public function test_market_scope_does_not_resolve_another_markets_payment(): void
    {
        $payment = Payment::factory()->create();
        $other = Platform::factory()->create();
        $this->expectException(McpProtocolException::class);
        $this->trace($payment, [$other->id]);
    }

    public function test_empty_market_scope_cannot_resolve_payment(): void
    {
        $payment = Payment::factory()->create();
        $this->expectException(McpProtocolException::class);
        $this->trace($payment, []);
    }

    public function test_unknown_locator_returns_not_found(): void
    {
        $this->expectException(McpProtocolException::class);
        app(PaymentFlowTraceService::class)->trace(app(OpaqueEntityLocator::class)->payment(99999999), new McpAuthorizationContext(User::factory()->create(['role' => 'admin']), [], null));
    }

    public function test_connector_trace_and_diagnosis_accept_three_generated_locators(): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        Config::set('mcp.waves.diagnostics', true);
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('mcp:trace-regression', ['mcp:read', 'mcp:tool:exotic_payment_flow_trace', 'mcp:tool:exotic_payment_failure_diagnosis'])->plainTextToken;
        $headers = ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => '2026-07-28'];
        foreach (['380.00', '230.00', '380.00'] as $amount) {
            $payment = Payment::factory()->create(['amount' => $amount, 'status' => 'completed']);
            $locator = app(OpaqueEntityLocator::class)->payment($payment->id);
            foreach (['exotic_payment_flow_trace', 'exotic_payment_failure_diagnosis'] as $tool) {
                $response = $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => $payment->id, 'method' => 'tools/call', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => '2026-07-28'], 'name' => $tool, 'arguments' => ['locator' => $locator]]], $headers);
                $response->assertOk()->assertJsonPath('result.isError', false)->assertJsonPath('result.structuredContent.data.locator', $locator);
            }
        }
    }

    private function trace(Payment $payment, ?array $platforms = null): array
    {
        return app(PaymentFlowTraceService::class)->trace(app(OpaqueEntityLocator::class)->payment($payment->id), new McpAuthorizationContext(User::factory()->create(['role' => 'admin']), [], $platforms));
    }
}
