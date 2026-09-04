<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBulkMediaDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(Platform $platform): User
    {
        return User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }

    private function makePlatform(): Platform
    {
        return Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
    }

    public function test_bulk_delete_removes_every_selected_attachment_and_resyncs_once(): void
    {
        $platform = $this->makePlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
        ]);
        Sanctum::actingAs($this->makeUser($platform));

        $base = rtrim($platform->wp_api_url, '/');
        $deleted = [];
        $profileGetCount = 0;

        Http::fake(function ($request) use ($base, $client, &$deleted, &$profileGetCount) {
            if ($request->method() === 'DELETE' && str_starts_with($request->url(), "{$base}/clients/{$client->wp_post_id}/media/")) {
                $deleted[] = (int) basename($request->url());

                return Http::response(['deleted' => true], 200);
            }

            if ($request->method() === 'GET' && $request->url() === "{$base}/clients/{$client->wp_post_id}") {
                $profileGetCount++;

                return Http::response(['data' => ['id' => $client->wp_post_id, 'title' => 'Profile']], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $this->postJson("/api/crm/clients/{$client->id}/media/bulk-delete", [
            'attachment_ids' => [101, 102, 103],
            'reason' => 'Bulk cleanup',
        ])
            ->assertOk()
            ->assertJsonPath('deleted_count', 3)
            ->assertJsonPath('failed_count', 0);

        $this->assertSame([101, 102, 103], $deleted);
        $this->assertLessThanOrEqual(1, $profileGetCount);
    }

    public function test_bulk_delete_reports_per_attachment_failures_without_aborting_the_batch(): void
    {
        $platform = $this->makePlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
        ]);
        Sanctum::actingAs($this->makeUser($platform));

        $base = rtrim($platform->wp_api_url, '/');

        Http::fake(function ($request) use ($base, $client) {
            if ($request->method() === 'DELETE' && str_starts_with($request->url(), "{$base}/clients/{$client->wp_post_id}/media/")) {
                return ((int) basename($request->url())) === 102
                    ? Http::response(['message' => 'Attachment not found'], 404)
                    : Http::response(['deleted' => true], 200);
            }

            return Http::response(['data' => []], 200);
        });

        $response = $this->postJson("/api/crm/clients/{$client->id}/media/bulk-delete", [
            'attachment_ids' => [101, 102, 103],
        ])
            ->assertOk()
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('failed_count', 1);

        $statuses = collect($response->json('results'))->pluck('status', 'attachment_id')->all();
        $this->assertSame('deleted', $statuses[101]);
        $this->assertSame('failed', $statuses[102]);
        $this->assertSame('deleted', $statuses[103]);
    }

    public function test_bulk_delete_skips_resync_when_asked_to_defer_it(): void
    {
        $platform = $this->makePlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
        ]);
        Sanctum::actingAs($this->makeUser($platform));

        $base = rtrim($platform->wp_api_url, '/');
        $profileGetCount = 0;

        Http::fake(function ($request) use ($base, $client, &$profileGetCount) {
            if ($request->method() === 'GET' && $request->url() === "{$base}/clients/{$client->wp_post_id}") {
                $profileGetCount++;
            }

            return Http::response(['data' => []], 200);
        });

        $this->postJson("/api/crm/clients/{$client->id}/media/bulk-delete", [
            'attachment_ids' => [101],
            'resync' => false,
        ])->assertOk();

        $this->assertSame(0, $profileGetCount);
    }

    public function test_bulk_delete_requires_attachment_ids(): void
    {
        $platform = $this->makePlatform();
        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 61783,
        ]);
        Sanctum::actingAs($this->makeUser($platform));

        $this->postJson("/api/crm/clients/{$client->id}/media/bulk-delete", [
            'attachment_ids' => [],
        ])->assertStatus(422);
    }
}
