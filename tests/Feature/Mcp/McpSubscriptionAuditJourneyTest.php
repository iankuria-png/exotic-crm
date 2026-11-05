<?php

namespace Tests\Feature\Mcp;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Deal;
use App\Models\Payment;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Services\Mcp\OpaqueEntityLocator;
use App\Services\Mcp\PseudonymService;
use App\Services\SubscriptionAudit\SubscriptionAuditBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class McpSubscriptionAuditJourneyTest extends TestCase
{
    use RefreshDatabase;

    public static function protocols(): array
    {
        return array_map(fn ($version) => [$version], ['2025-03-26', '2025-06-18', '2025-11-25', '2026-07-28']);
    }

    #[DataProvider('protocols')]
    public function test_real_snapshot_summary_pages_trace_and_crm_identity_agree(string $version): void
    {
        Config::set('mcp.enabled', true);
        Config::set('mcp.waves.contracts', true);
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Private Manager Example']);
        $platform = Platform::factory()->create(['name' => 'Synthetic market', 'country' => 'Synthetic country', 'currency_code' => 'USD']);
        $product = Product::query()->create(['platform_id' => $platform->id, 'name' => 'Synthetic audit plan', 'slug' => 'synthetic-audit-plan', 'tier' => 'custom', 'currency' => 'USD', 'weekly_price' => '10.10', 'biweekly_price' => '10.10', 'monthly_price' => '10.10', 'is_active' => true]);
        $sourceTime = now('UTC')->subDays(2)->startOfSecond();
        $firstClient = $firstPayment = null;
        foreach (['completed', 'failed', null] as $index => $status) {
            $client = Client::factory()->create(['platform_id' => $platform->id, 'name' => 'Private Advertiser Example', 'email' => 'private-person@example.test', 'phone_normalized' => '254700111222']);
            $deal = Deal::factory()->create(['platform_id' => $platform->id, 'product_id' => $product->id, 'client_id' => $client->id, 'amount' => '10.10', 'currency' => 'USD', 'activated_at' => $sourceTime, 'is_free_trial' => false]);
            $payment = $status ? Payment::factory()->create(['platform_id' => $platform->id, 'product_id' => $product->id, 'deal_id' => $deal->id, 'client_id' => $client->id, 'amount' => '10.10', 'currency' => 'USD', 'status' => $status, 'purpose' => 'subscription', 'provider_environment' => 'live', 'transaction_reference' => 'PRIVATE-RECEIPT-EXAMPLE-'.$index]) : null;
            DB::table('deals')->where('id', $deal->id)->update(['payment_id' => $payment?->id]);
            AuditLog::query()->create([
                'platform_id' => $platform->id, 'actor_id' => $admin->id, 'action' => 'deal_activate',
                'entity_type' => 'deal', 'entity_id' => $deal->id, 'before_state' => null,
                'after_state' => ['deal_status' => 'active', 'payment_id' => $payment?->id, 'amount' => '10.10', 'currency' => 'USD', 'expires_at' => $deal->expires_at->toDateTimeString()],
                'reason' => 'Do not expose private-person@example.test', 'created_at' => $sourceTime->copy()->addMinutes($index),
            ]);
            $firstClient ??= $client;
            $firstPayment ??= $payment;
        }
        $financialBefore = DB::table('payments')->orderBy('id')->get()->toJson();
        $dealsBefore = DB::table('deals')->orderBy('id')->get()->toJson();
        $run = app(SubscriptionAuditBuilder::class)->build();
        $token = $admin->createToken('mcp:real-audit-journey', array_merge(['mcp:read', 'mcp:subscription-audit'], array_map(fn ($kind) => 'mcp:tool:exotic_subscription_audit_'.$kind, ['summary', 'events', 'trace', 'coverage'])))->plainTextToken;
        $this->rpc($token, $version, 'initialize', ['protocolVersion' => $version])->assertOk();
        $arguments = ['purpose' => 'Financial control acceptance', 'run_ref' => $run->run_ref];
        $summary = $this->report($token, $version, 'summary', $arguments)->assertOk();
        $summary->assertJsonPath('result.structuredContent.data.summary.event_count', 3)
            ->assertJsonPath('result.structuredContent.data.summary.by_currency.0.potential_exposure', '20.20');
        $seen = [];
        $cursor = null;
        do {
            $pageArguments = $arguments + ['page_size' => 1];
            if ($cursor) {
                $pageArguments['cursor'] = $cursor;
            }
            $page = $this->report($token, $version, 'events', $pageArguments)->assertOk();
            $page->assertJsonPath('result.structuredContent.data.total_matching', 3);
            $rows = $page->json('result.structuredContent.data.rows');
            $this->assertCount(1, $rows);
            $seen[] = $rows[0];
            $cursor = $page->json('result.structuredContent.data.next_cursor');
            if ($cursor) {
                $this->assertLessThan(500, strlen($cursor));
            }
            foreach (['Private Advertiser Example', 'Private Manager Example', 'private-person@example.test', '254700111222', 'PRIVATE-RECEIPT-EXAMPLE'] as $private) {
                $this->assertStringNotContainsString($private, $page->getContent());
            }
            $this->assertLessThanOrEqual(3, count($seen));
        } while ($cursor);
        $this->assertCount(3, array_unique(array_column($seen, 'event_ref')));
        $this->assertSame(app(PseudonymService::class)->handle('client', $firstClient->id), $seen[0]['client_handle']);
        $this->assertSame(app(OpaqueEntityLocator::class)->payment($firstPayment->id), $seen[0]['payment_locator']);
        $trace = $this->report($token, $version, 'trace', $arguments + ['event_ref' => $seen[0]['event_ref']])->assertOk();
        $trace->assertJsonPath('result.structuredContent.data.event.event_ref', $seen[0]['event_ref']);
        $this->report($token, $version, 'coverage', $arguments)->assertOk()->assertJsonPath('result.structuredContent.meta.result_state', 'partial');
        $this->assertSame($financialBefore, DB::table('payments')->orderBy('id')->get()->toJson());
        $this->assertSame($dealsBefore, DB::table('deals')->orderBy('id')->get()->toJson());

        $this->withoutToken();
        Sanctum::actingAs($admin);
        $this->getJson('/api/crm/reports/subscription-audit/trace?'.http_build_query($arguments + ['event_ref' => $seen[0]['event_ref'], 'include_identity' => 1]))
            ->assertOk()->assertJsonPath('data.crm_identity.actor.name', 'Private Manager Example')
            ->assertJsonPath('data.crm_identity.advertiser.name', 'Private Advertiser Example');
        $this->assertDatabaseHas('audit_log', ['action' => 'subscription_audit_identity_read', 'actor_id' => $admin->id]);
    }

    private function report(string $token, string $version, string $kind, array $arguments)
    {
        return $this->rpc($token, $version, 'tools/call', ['name' => 'exotic_subscription_audit_'.$kind, 'arguments' => $arguments]);
    }

    private function rpc(string $token, string $version, string $method, array $params)
    {
        if ($version === '2026-07-28') {
            $params['_meta'] = ['io.modelcontextprotocol/protocolVersion' => $version];
        }

        return $this->withToken($token)->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => random_int(1, 999999), 'method' => $method, 'params' => $params], ['Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json', 'MCP-Protocol-Version' => $version]);
    }
}
