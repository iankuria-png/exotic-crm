<?php

namespace Tests\Feature\DbContainment;

use App\Models\DbContainmentMarket;
use App\Models\DbContainmentOperation;
use App\Models\Platform;
use App\Services\DbContainment\CacheVerificationAdapter;
use App\Services\DbContainment\ContainmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CacheAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_versioned_request_retries_same_body_uuid_after_unknown_response(): void
    {
        $platform = Platform::query()->create(['name' => 'Synthetic', 'domain' => 'market.test', 'country' => 'Synthetic', 'currency_code' => 'USD', 'is_active' => true, 'db_prefix' => 'wp_']);
        $secret = str_repeat('s', 32);
        $market = DbContainmentMarket::query()->create(['platform_id' => $platform->id, 'configuration' => ['cache_secret' => $secret, 'cache_key_version' => '2', 'cache_runtime_trusted' => true, 'session_canary_verified_at' => now()->toIso8601String()]]);
        $op = DbContainmentOperation::query()->create(['id' => (string) Str::uuid(), 'actor_id' => 1, 'platform_id' => $platform->id, 'request_key' => (string) Str::uuid(), 'selection' => [], 'preview' => [], 'sealed_intent' => 'sealed', 'preview_digest' => str_repeat('a', 64), 'credential_fingerprint' => str_repeat('b', 64), 'policy_version' => '1', 'expires_at' => now()->addMinutes(5)]);
        $requests = [];
        Http::fake(function ($request) use (&$requests, $op) {
            $requests[] = $request;

            return count($requests) === 1 ? Http::response([], 503) : Http::response(['operation_id' => $op->id, 'key_version' => '2', 'manager' => 'WP_User_Meta_Session_Tokens', 'verified' => true, 'content_verified' => true]);
        });
        $adapter = new CacheVerificationAdapter;
        $targets = ['user_ids' => [42], 'option_names' => [], 'session_check' => true, 'content_check' => false];
        try {
            $adapter->call($platform, $market, $op->id, $op->preview_digest, $targets, [], true);
            $this->fail('Unexpected verification');
        } catch (ContainmentException $e) {
            $this->assertSame('cache_adapter_unverified', $e->reason);
        }
        $adapter->call($platform, $market, $op->id, $op->preview_digest, $targets, [], true);
        $this->assertSame($requests[0]->body(), $requests[1]->body());
        $this->assertSame($requests[0]->header('X-Containment-Request'), $requests[1]->header('X-Containment-Request'));
        $request = $requests[0];
        $expected = hash_hmac('sha256', implode("\n", ['POST', '/exotic-crm-sync/v1/containment/cache', hash('sha256', $request->body()), (string) $platform->id, $request->header('X-Containment-Time')[0], $request->header('X-Containment-Request')[0], $op->id, $op->preview_digest, '2']), $secret);
        $this->assertSame($expected, $request->header('X-Containment-Signature')[0]);
        $this->assertStringNotContainsString('cache_requests', $op->fresh()->toJson());
    }

    public function test_untrusted_adapter_refuses_without_http(): void
    {
        Http::fake();
        $this->expectExceptionMessage('trusted_cache_adapter_not_provisioned');
        (new CacheVerificationAdapter)->call(new Platform, new DbContainmentMarket, 'x', 'x', []);
        Http::assertNothingSent();
    }

    public function test_known_failed_verification_rechecks_without_repeating_invalidation(): void
    {
        $platform = Platform::query()->create(['name' => 'Synthetic', 'domain' => 'market.test', 'country' => 'Synthetic', 'currency_code' => 'USD', 'is_active' => true, 'db_prefix' => 'wp_']);
        $market = DbContainmentMarket::query()->create(['platform_id' => $platform->id, 'configuration' => ['cache_secret' => str_repeat('s', 32), 'cache_runtime_trusted' => true]]);
        $op = DbContainmentOperation::query()->create(['id' => (string) Str::uuid(), 'actor_id' => 1, 'platform_id' => $platform->id, 'request_key' => (string) Str::uuid(), 'selection' => [], 'preview' => [], 'sealed_intent' => 'sealed', 'preview_digest' => str_repeat('a', 64), 'credential_fingerprint' => str_repeat('b', 64), 'policy_version' => '1', 'expires_at' => now()->addMinutes(5)]);
        $requests = [];
        Http::fake(function ($request) use (&$requests, $op) {
            $requests[] = $request;

            return Http::response(['operation_id' => $op->id, 'key_version' => '1', 'manager' => 'WP_User_Meta_Session_Tokens', 'verified' => count($requests) >= 3, 'content_verified' => true]);
        });
        $adapter = new CacheVerificationAdapter;
        for ($i = 0; $i < 2; $i++) {
            try {
                $adapter->call($platform, $market, $op->id, $op->preview_digest, ['user_ids' => [42]], [], true);
                $this->fail('Unexpected verification');
            } catch (ContainmentException $e) {
                $this->assertSame('cache_verification_pending', $e->reason);
            }
        }
        $this->assertTrue($adapter->call($platform, $market, $op->id, $op->preview_digest, ['user_ids' => [42]], [], true)['verified']);
        $this->assertTrue(json_decode($requests[0]->body(), true)['invalidate']);
        $this->assertFalse(json_decode($requests[1]->body(), true)['invalidate']);
        $this->assertFalse(json_decode($requests[2]->body(), true)['invalidate']);
        $this->assertNotSame($requests[0]->header('X-Containment-Request'), $requests[1]->header('X-Containment-Request'));
        $this->assertNotSame($requests[1]->header('X-Containment-Request'), $requests[2]->header('X-Containment-Request'));
    }
}
