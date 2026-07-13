<?php

namespace Tests\Feature;

use App\Models\ErrorLogGroup;
use App\Models\ErrorLogOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GlobalErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(string $role = 'sales'): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' ' . Str::random(6),
            'email' => Str::random(8) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => [],
        ]);
    }

    public function test_every_api_response_carries_a_request_id_header(): void
    {
        $response = $this->getJson('/api/crm/me'); // 401, but header must still be present

        $this->assertNotEmpty($response->headers->get('X-Request-Id'));
        $this->assertMatchesRegularExpression('/^CRM-\d{8}-[a-z0-9]{6}$/', $response->headers->get('X-Request-Id'));
    }

    public function test_crm_api_errors_are_normalized_with_code_and_request_id(): void
    {
        $response = $this->getJson('/api/crm/me'); // unauthenticated → 401

        $response->assertStatus(401);
        $response->assertJsonStructure(['message', 'code', 'request_id']);
        $this->assertSame('auth_error', $response->json('code'));
        // message preserved verbatim (additive contract), request_id matches header
        $this->assertSame('Unauthenticated.', $response->json('message'));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('request_id'));
    }

    public function test_crm_validation_errors_keep_their_errors_bag(): void
    {
        Sanctum::actingAs($this->activeUser());

        // Missing required "message" → 422 with an errors bag, plus our additive code.
        $response = $this->postJson('/api/crm/client-errors', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors' => ['message'], 'code', 'request_id']);
        $this->assertSame('validation_error', $response->json('code'));
    }

    public function test_client_errors_are_ingested_into_error_logs_with_client_source(): void
    {
        Sanctum::actingAs($this->activeUser());

        $response = $this->postJson('/api/crm/client-errors', [
            'message' => 'TypeError: cannot read properties of undefined',
            'category' => 'react_error',
            'url' => 'https://crm.example.test/clients/42',
            'component' => 'ClientDetail',
            'app_build' => 'abc12345',
            'request_id' => 'CRM-20260713-zzz999',
        ]);

        $response->assertStatus(202);

        $group = ErrorLogGroup::query()->where('source', 'client')->first();
        $this->assertNotNull($group, 'A client-source error group should be recorded.');
        $this->assertStringContainsString('[client]', $group->message);

        $occurrence = ErrorLogOccurrence::query()->where('group_id', $group->id)->first();
        $this->assertNotNull($occurrence);
        $this->assertSame('react_error', $occurrence->context['category'] ?? null);
        $this->assertSame('CRM-20260713-zzz999', $occurrence->context['request_id'] ?? null);
    }

    public function test_whoami_ip_returns_the_caller_ip(): void
    {
        Sanctum::actingAs($this->activeUser());

        $response = $this->getJson('/api/crm/whoami-ip');

        $response->assertStatus(200);
        $response->assertJsonStructure(['ip', 'time']);
    }

    public function test_non_crm_api_errors_are_not_decorated(): void
    {
        // Guard: the Ads API surface (api/* outside api/crm/*) must be untouched —
        // a non-crm route 404s WITHOUT our added `code` field. (An unmatched URL
        // never enters the api middleware group, so no request-id header here;
        // the header is proven on matched crm routes above.)
        $response = $this->getJson('/api/definitely-not-a-real-route-xyz');

        $response->assertStatus(404);
        $this->assertNull($response->json('code'), 'Non-CRM API errors must not gain the CRM code field.');
    }
}
