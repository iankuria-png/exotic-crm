<?php

namespace Tests\Feature\AutoOptimize;

use App\Models\Platform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies that the driver-safe unique guards work on the SQLite test DB.
 */
class MigrationGuardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_enabled_plan_per_market(): void
    {
        $platform = $this->createPlatform();
        $user = $this->createUser();

        // Insert first plan with enabled_platform_key set
        DB::table('auto_optimize_plans')->insert([
            'name' => 'Plan A',
            'platform_id' => $platform->id,
            'enabled' => true,
            'autopilot' => false,
            'enabled_platform_key' => $platform->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second plan with same enabled_platform_key must be rejected
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('auto_optimize_plans')->insert([
            'name' => 'Plan B',
            'platform_id' => $platform->id,
            'enabled' => true,
            'autopilot' => false,
            'enabled_platform_key' => $platform->id, // duplicate — should fail
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_multiple_disabled_plans_allowed(): void
    {
        $platform = $this->createPlatform();

        // Both disabled plans have NULL enabled_platform_key — both should succeed
        DB::table('auto_optimize_plans')->insert([
            'name' => 'Disabled A',
            'platform_id' => $platform->id,
            'enabled' => false,
            'autopilot' => false,
            'enabled_platform_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('auto_optimize_plans')->insert([
            'name' => 'Disabled B',
            'platform_id' => $platform->id,
            'enabled' => false,
            'autopilot' => false,
            'enabled_platform_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('auto_optimize_plans')->where('platform_id', $platform->id)->count());
    }

    public function test_only_one_active_item_per_client(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClient($platform->id);
        $planId = DB::table('auto_optimize_plans')->insertGetId([
            'name' => 'Plan',
            'platform_id' => $platform->id,
            'enabled' => false,
            'enabled_platform_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('auto_optimize_runs')->insertGetId([
            'auto_optimize_plan_id' => $planId,
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run2Id = DB::table('auto_optimize_runs')->insertGetId([
            'auto_optimize_plan_id' => $planId,
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // First active item for client
        DB::table('auto_optimize_items')->insert([
            'auto_optimize_plan_id' => $planId,
            'auto_optimize_run_id' => $runId,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'active_client_key' => $client->id, // active
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Second active item for same client — must be rejected
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('auto_optimize_items')->insert([
            'auto_optimize_plan_id' => $planId,
            'auto_optimize_run_id' => $run2Id,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'active_client_key' => $client->id, // duplicate — should fail
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_terminal_item_allows_new_active_item_for_same_client(): void
    {
        $platform = $this->createPlatform();
        $client = $this->createClient($platform->id);
        $planId = DB::table('auto_optimize_plans')->insertGetId([
            'name' => 'Plan',
            'platform_id' => $platform->id,
            'enabled' => false,
            'enabled_platform_key' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::table('auto_optimize_runs')->insertGetId([
            'auto_optimize_plan_id' => $planId,
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $run2Id = DB::table('auto_optimize_runs')->insertGetId([
            'auto_optimize_plan_id' => $planId,
            'platform_id' => $platform->id,
            'status' => 'running',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('auto_optimize_items')->insertGetId([
            'auto_optimize_plan_id' => $planId,
            'auto_optimize_run_id' => $runId,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'applied',
            'active_client_key' => null, // terminal — cleared
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // New active item for same client should succeed now
        DB::table('auto_optimize_items')->insert([
            'auto_optimize_plan_id' => $planId,
            'auto_optimize_run_id' => $run2Id,
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'status' => 'pending',
            'active_client_key' => $client->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(2, DB::table('auto_optimize_items')->where('client_id', $client->id)->count());
    }

    public function test_system_user_exists(): void
    {
        $this->assertDatabaseHas('users', [
            'email' => 'automation+auto-optimize@system.local',
            'status' => 'inactive',
        ]);
    }

    // Helpers

    private function createPlatform(): Platform
    {
        return Platform::query()->create([
            'name' => 'Kenya Test ' . Str::random(4),
            'domain' => 'test-' . Str::random(4) . '.example',
            'country' => 'Kenya',
            'timezone' => 'Africa/Nairobi',
            'wp_api_url' => 'https://test.example/wp-json/wp/v2',
            'phone_prefix' => '254',
            'currency_code' => 'KES',
            'is_active' => true,
        ]);
    }

    private function createClient(int $platformId): \App\Models\Client
    {
        return \App\Models\Client::factory()->create([
            'platform_id' => $platformId,
        ]);
    }

    private function createUser(): User
    {
        return User::query()->create([
            'name' => 'Admin',
            'email' => 'admin+' . Str::random(6) . '@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
