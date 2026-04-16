<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Platform;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientWpLinkRepairTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWordPressMirrorTables();
    }

    public function test_wp_profile_endpoint_preserves_upstream_not_found_and_marks_stale_link(): void
    {
        [$platform, $client] = $this->createClientFixture();
        $user = $this->createUser($platform, 'sales');

        Http::fake([
            rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}" => Http::response([
                'code' => 'not_found',
                'message' => 'Client not found',
                'data' => ['status' => 404],
            ], 404),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/clients/{$client->id}/wp-profile")
            ->assertStatus(404)
            ->assertJsonPath('message', 'The linked WordPress profile could not be found for this client.')
            ->assertJsonPath('stale_link.client_id', $client->id)
            ->assertJsonPath('stale_link.wp_post_id', $client->wp_post_id)
            ->assertJsonPath('stale_link.wp_user_id', $client->wp_user_id)
            ->assertJsonPath('stale_link.repairable', true);
    }

    public function test_media_endpoint_preserves_upstream_not_found_and_marks_stale_link(): void
    {
        [$platform, $client] = $this->createClientFixture();
        $user = $this->createUser($platform, 'sales');

        Http::fake([
            rtrim($platform->wp_api_url, '/') . "/clients/{$client->wp_post_id}/media" => Http::response([
                'code' => 'not_found',
                'message' => 'Client not found',
                'data' => ['status' => 404],
            ], 404),
        ]);

        Sanctum::actingAs($user);

        $this->getJson("/api/crm/clients/{$client->id}/media")
            ->assertStatus(404)
            ->assertJsonPath('message', 'The linked WordPress profile could not be found for this client.')
            ->assertJsonPath('stale_link.client_id', $client->id)
            ->assertJsonPath('stale_link.repairable', true);
    }

    public function test_repair_wp_link_updates_stale_wp_post_id_when_one_candidate_exists(): void
    {
        [$platform, $client] = $this->createClientFixture();
        $user = $this->createUser($platform, 'sales');

        Schema::table('posts', function (Blueprint $table) {
            // no-op to ensure sqlite sees table before inserts in some environments
        });

        \DB::table('options')->insert([
            'option_name' => 'taxonomy_profile_url',
            'option_value' => 'escort',
        ]);

        \DB::table('posts')->insert([
            'ID' => 9911,
            'post_author' => $client->wp_user_id,
            'post_status' => 'publish',
            'post_type' => 'escort',
            'post_modified' => now()->toDateTimeString(),
        ]);

        Http::fake([
            rtrim($platform->wp_api_url, '/') . '/clients/9911' => Http::response([
                'wp_post_id' => 9911,
                'wp_user_id' => $client->wp_user_id,
                'name' => 'Relinked Client',
                'post_status' => 'publish',
                'phone' => $client->phone_normalized,
                'email' => $client->email,
                'city' => $client->city,
                'premium' => false,
                'featured' => false,
                'verified' => false,
                'main_image_url' => null,
                'last_online' => null,
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/clients/{$client->id}/repair-wp-link")
            ->assertOk()
            ->assertJsonPath('repair.status', 'repaired')
            ->assertJsonPath('repair.wp_post_id', 9911)
            ->assertJsonPath('client.wp_post_id', 9911);

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'wp_post_id' => 9911,
        ]);
    }

    public function test_repair_wp_link_returns_validation_error_when_no_candidate_exists(): void
    {
        [$platform, $client] = $this->createClientFixture();
        $user = $this->createUser($platform, 'sales');

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/clients/{$client->id}/repair-wp-link")
            ->assertStatus(422)
            ->assertJsonPath('repair.status', 'no_candidate');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'wp_post_id' => 9001,
        ]);
    }

    public function test_repair_wp_link_returns_validation_error_when_multiple_candidates_exist(): void
    {
        [$platform, $client] = $this->createClientFixture();
        $user = $this->createUser($platform, 'sales');

        \DB::table('posts')->insert([
            [
                'ID' => 9911,
                'post_author' => $client->wp_user_id,
                'post_status' => 'publish',
                'post_type' => 'escort',
                'post_modified' => now()->subMinute()->toDateTimeString(),
            ],
            [
                'ID' => 9912,
                'post_author' => $client->wp_user_id,
                'post_status' => 'private',
                'post_type' => 'escort',
                'post_modified' => now()->toDateTimeString(),
            ],
        ]);

        Sanctum::actingAs($user);

        $this->postJson("/api/crm/clients/{$client->id}/repair-wp-link")
            ->assertStatus(422)
            ->assertJsonPath('repair.status', 'ambiguous')
            ->assertJsonCount(2, 'repair.candidate_post_ids');

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'wp_post_id' => 9001,
        ]);
    }

    private function ensureWordPressMirrorTables(): void
    {
        if (!Schema::hasTable('options')) {
            Schema::create('options', function (Blueprint $table) {
                $table->increments('id');
                $table->string('option_name')->unique();
                $table->text('option_value')->nullable();
            });
        }

        if (!Schema::hasTable('posts')) {
            Schema::create('posts', function (Blueprint $table) {
                $table->integer('ID')->primary();
                $table->unsignedInteger('post_author')->default(0);
                $table->string('post_status')->default('draft');
                $table->string('post_type')->default('post');
                $table->dateTime('post_modified')->nullable();
            });
        }
    }

    /**
     * @return array{0: Platform, 1: Client}
     */
    private function createClientFixture(): array
    {
        $platform = Platform::factory()->create([
            'db_host' => null,
            'db_name' => null,
            'db_user' => null,
            'db_pass' => null,
            'db_prefix' => '',
            'wp_api_url' => 'https://repair-wp-link-' . Str::lower(Str::random(6)) . '.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);

        $client = Client::factory()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 9001,
            'wp_user_id' => 7001,
        ]);

        return [$platform, $client];
    }

    private function createUser(Platform $platform, string $role): User
    {
        return User::query()->create([
            'name' => ucfirst($role) . ' Support',
            'email' => strtolower($role) . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);
    }
}
