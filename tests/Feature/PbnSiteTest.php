<?php

namespace Tests\Feature;

use App\Jobs\RunPbnSeedBatchJob;
use App\Models\Client;
use App\Models\PbnSeedBatch;
use App\Models\PbnSeedEvent;
use App\Models\PbnSeedItem;
use App\Models\PbnSeedPreview;
use App\Models\PbnSite;
use App\Models\Platform;
use App\Models\User;
use App\Services\DynamicDatabaseService;
use App\Services\WpDirectProvisioningService;
use App\Support\WordPressSiteConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PbnSiteTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryDatabases = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDatabases as $path) {
            if (is_string($path) && file_exists($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_pbn_sites_are_created_separately_from_platform_rows(): void
    {
        $platform = Platform::factory()->create(['name' => 'Exotic Uganda']);
        $user = $this->userFor($platform, 'admin');
        Sanctum::actingAs($user);

        $beforePlatforms = Platform::query()->count();

        $response = $this->postJson('/api/crm/settings/integrations/pbn-sites', [
            'name' => 'Uganda Hot Girls',
            'domain' => 'https://ugandahotgirls.com',
            'default_source_platform_id' => $platform->id,
            'source_platform_ids' => [$platform->id],
            'country' => 'Uganda',
            'timezone' => 'Africa/Nairobi',
            'currency_code' => 'UGX',
            'phone_prefix' => '256',
            'is_active' => true,
        ])->assertCreated();

        $this->assertSame('ugandahotgirls.com', $response->json('site.domain'));
        $this->assertDatabaseHas('pbn_sites', ['name' => 'Uganda Hot Girls', 'domain' => 'ugandahotgirls.com']);
        $this->assertDatabaseHas('pbn_site_sources', ['platform_id' => $platform->id, 'is_default' => true]);
        $this->assertSame($beforePlatforms, Platform::query()->count());
    }

    public function test_sales_can_preview_and_queue_ready_pbn_batches_for_configured_sources(): void
    {
        Queue::fake();
        $uganda = Platform::factory()->create(['name' => 'Exotic Uganda']);
        $kenya = Platform::factory()->create(['name' => 'Exotic Kenya']);
        $site = $this->pbnSite($uganda, [$uganda->id, $kenya->id]);
        $best = $this->publishedClient($uganda, ['name' => 'Best Candidate', 'seo_score' => 94, 'verified' => true]);
        $second = $this->publishedClient($kenya, ['name' => 'Second Candidate', 'seo_score' => 75]);
        $this->publishedClient($uganda, ['name' => 'Risky Candidate', 'seo_score' => 99, 'is_high_risk' => true]);
        $this->publishedClient($uganda, ['name' => 'No Media', 'main_image_url' => null, 'display_image_url' => null]);
        $user = $this->userFor($uganda, 'sales', [$uganda->id, $kenya->id]);
        Sanctum::actingAs($user);

        $preview = $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/preview", [
            'source_platform_ids' => [$uganda->id, $kenya->id],
            'target_count' => 2,
            'targets' => [[
                'region_id' => 10,
                'city_id' => 20,
                'region_name' => 'Central',
                'city_name' => 'Kampala',
                'target_count' => 2,
            ]],
        ])->assertOk();

        $this->assertSame(2, $preview->json('eligible_count'));
        $this->assertSame([$best->id, $second->id], $preview->json('selected_client_ids'));

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            'preview_token' => $preview->json('preview_token'),
            'source_platform_ids' => [$uganda->id, $kenya->id],
            'target_count' => 2,
            'targets' => [[
                'region_id' => 10,
                'city_id' => 20,
                'region_name' => 'Central',
                'city_name' => 'Kampala',
                'target_count' => 2,
            ]],
            'selected_client_ids' => [$best->id, $second->id],
        ])->assertCreated();

        $this->assertDatabaseHas('pbn_seed_batches', ['pbn_site_id' => $site->id, 'status' => 'queued', 'selected_count' => 2]);
        $this->assertDatabaseHas('pbn_seed_items', ['pbn_site_id' => $site->id, 'source_client_id' => $best->id, 'status' => 'queued']);
        Queue::assertPushed(RunPbnSeedBatchJob::class);
    }

    public function test_duplicate_warnings_are_soft_but_require_acknowledgement_when_selected(): void
    {
        Queue::fake();
        $platform = Platform::factory()->create();
        $site = $this->pbnSite($platform, [$platform->id]);
        $client = $this->publishedClient($platform, ['seo_score' => 90]);
        $batch = PbnSeedBatch::create([
            'pbn_site_id' => $site->id,
            'created_by' => null,
            'status' => 'completed',
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'selected_count' => 1,
            'created_count' => 1,
        ]);
        PbnSeedItem::create([
            'batch_id' => $batch->id,
            'pbn_site_id' => $site->id,
            'source_platform_id' => $platform->id,
            'source_client_id' => $client->id,
            'source_wp_post_id' => $client->wp_post_id,
            'status' => 'created',
            'duplicate_state' => 'none',
            'payload_hash' => str_repeat('a', 64),
        ]);
        $user = $this->userFor($platform, 'sales');
        Sanctum::actingAs($user);

        $payload = [
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'targets' => [[
                'region_id' => 10,
                'city_id' => 20,
                'city_name' => 'Kampala',
                'target_count' => 1,
            ]],
        ];

        $preview = $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/preview", $payload)
            ->assertOk();

        $this->assertSame('duplicates', $preview->json('warnings.0.type'));
        $this->assertSame([], $preview->json('selected_client_ids'));
        $this->assertSame('existing_same_site', $preview->json('candidates.0.duplicate_state'));

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $preview->json('preview_token'),
            'selected_client_ids' => [$client->id],
        ])->assertStatus(409);

        $itemsBeforeAck = PbnSeedItem::query()->count();

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $preview->json('preview_token'),
            'selected_client_ids' => [$client->id],
            'duplicate_acknowledged' => true,
        ])->assertCreated();

        $this->assertSame($itemsBeforeAck, PbnSeedItem::query()->count());
    }

    public function test_preview_tokens_are_actor_bound_payload_bound_one_time_and_expire(): void
    {
        Queue::fake();
        $platform = Platform::factory()->create();
        $site = $this->pbnSite($platform, [$platform->id]);
        $client = $this->publishedClient($platform);
        $user = $this->userFor($platform, 'sales');
        $otherUser = $this->userFor($platform, 'sales');
        Sanctum::actingAs($user);

        $payload = [
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'targets' => [[
                'region_id' => 10,
                'city_id' => 20,
                'city_name' => 'Kampala',
                'target_count' => 1,
            ]],
            'selected_client_ids' => [$client->id],
        ];

        $preview = $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/preview", $payload)
            ->assertOk();
        $token = $preview->json('preview_token');

        Sanctum::actingAs($otherUser);
        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $token,
        ])->assertStatus(422);

        Sanctum::actingAs($user);
        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'target_count' => 2,
            'preview_token' => $token,
        ])->assertStatus(422);

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $token,
        ])->assertCreated();

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $token,
        ])->assertStatus(422);

        $expiredPreview = $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/preview", $payload)
            ->assertOk();
        PbnSeedPreview::query()
            ->where('preview_token', $expiredPreview->json('preview_token'))
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson("/api/crm/settings/integrations/pbn-sites/{$site->id}/batches", [
            ...$payload,
            'preview_token' => $expiredPreview->json('preview_token'),
        ])->assertStatus(422);
    }

    public function test_pbn_direct_provisioning_uses_pbn_connection_and_is_idempotent(): void
    {
        $platform = Platform::factory()->create();
        $site = $this->pbnSite($platform, [$platform->id], ['domain' => 'pbn-example.test']);
        [$connectionName, $connectionConfig] = $this->createWordPressProvisioningFixture($site);
        [$regionId, $cityId] = $this->seedLocationTerms($connectionName);

        $connection = WordPressSiteConnection::fromPbnSite($site->fresh());
        $payload = [
            'name' => 'PBN Retry Demo',
            'email' => 'pbn.retry@example.test',
            'phone' => '256700000001',
            'region_id' => $regionId,
            'city_id' => $cityId,
            'post_status' => 'publish',
            'provision_request_id' => 'pbn-retry-demo-1',
        ];

        $first = (new WpDirectProvisioningService($connection, $connectionConfig))->provisionEscort($payload);
        $second = (new WpDirectProvisioningService($connection, $connectionConfig))->provisionEscort($payload);

        $this->assertSame($first['wp_post_id'], $second['wp_post_id']);
        $this->assertSame(1, DB::connection($connectionName)->table('posts')->count());
        $this->assertSame(1, DB::connection($connectionName)->table('exotic_crm_provisions')->count());
    }

    public function test_pbn_operations_dashboard_lists_searches_and_paginates(): void
    {
        $platform = Platform::factory()->create(['name' => 'Exotic Uganda']);
        $site = $this->pbnSite($platform, [$platform->id], ['name' => 'Manuel Escorts']);
        $firstClient = $this->publishedClient($platform, ['name' => 'Amina Prime', 'city' => 'Kampala']);
        $secondClient = $this->publishedClient($platform, ['name' => 'Bella Archive', 'city' => 'Entebbe']);
        $user = $this->userFor($platform, 'admin');

        $firstBatch = PbnSeedBatch::create([
            'pbn_site_id' => $site->id,
            'created_by' => $user->id,
            'status' => PbnSeedBatch::STATUS_COMPLETED,
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'selected_count' => 1,
            'created_count' => 1,
            'failed_count' => 0,
            'notes' => 'Kampala launch seed',
        ]);
        $secondBatch = PbnSeedBatch::create([
            'pbn_site_id' => $site->id,
            'created_by' => $user->id,
            'status' => PbnSeedBatch::STATUS_PARTIAL,
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'selected_count' => 1,
            'created_count' => 0,
            'failed_count' => 1,
            'notes' => 'Archive follow-up',
        ]);
        PbnSeedItem::create([
            'batch_id' => $firstBatch->id,
            'pbn_site_id' => $site->id,
            'source_platform_id' => $platform->id,
            'source_client_id' => $firstClient->id,
            'source_wp_post_id' => $firstClient->wp_post_id,
            'target_wp_post_id' => 91001,
            'status' => PbnSeedItem::STATUS_CREATED,
            'payload_hash' => str_repeat('b', 64),
        ]);
        PbnSeedItem::create([
            'batch_id' => $secondBatch->id,
            'pbn_site_id' => $site->id,
            'source_platform_id' => $platform->id,
            'source_client_id' => $secondClient->id,
            'source_wp_post_id' => $secondClient->wp_post_id,
            'status' => PbnSeedItem::STATUS_FAILED,
            'failure_reason' => 'REST timeout',
            'payload_hash' => str_repeat('c', 64),
        ]);
        PbnSeedEvent::create([
            'pbn_site_id' => $site->id,
            'batch_id' => $secondBatch->id,
            'type' => 'item_failed',
            'level' => 'error',
            'message' => 'PBN seed item failed.',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/pbn/overview')
            ->assertOk()
            ->assertJsonPath('sites.ready', 1)
            ->assertJsonPath('items.created', 1)
            ->assertJsonPath('items.failed', 1)
            ->assertJsonPath('recent_failures.0.source_client.name', 'Bella Archive');

        $this->getJson('/api/crm/pbn/batches?per_page=10&q=launch')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.notes', 'Kampala launch seed');

        $this->getJson('/api/crm/pbn/items?q=Amina&status=created')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.source_client.name', 'Amina Prime');

        $this->getJson('/api/crm/pbn/events?level=error')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'item_failed');
    }

    public function test_admin_can_preview_and_revert_created_pbn_batch(): void
    {
        $platform = Platform::factory()->create();
        $site = $this->pbnSite($platform, [$platform->id]);
        [$connectionName, $connectionConfig] = $this->createWordPressProvisioningFixture($site);
        $site->forceFill([
            'db_host' => 'sqlite',
            'db_name' => $connectionConfig['database'],
            'db_user' => 'sqlite',
            'db_pass' => 'sqlite',
            'db_prefix' => 'wp_',
        ])->save();
        $client = $this->publishedClient($platform);
        $admin = $this->userFor($platform, 'admin');
        $sales = $this->userFor($platform, 'sales');
        $targetPostId = (int) DB::connection($connectionName)->table('posts')->insertGetId([
            'post_author' => 1,
            'post_date' => now()->format('Y-m-d H:i:s'),
            'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
            'post_title' => 'Seeded PBN Profile',
            'post_status' => 'publish',
            'post_name' => 'seeded-pbn-profile',
            'post_type' => 'escort',
            'post_modified' => now()->format('Y-m-d H:i:s'),
            'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
        ]);
        $batch = PbnSeedBatch::create([
            'pbn_site_id' => $site->id,
            'created_by' => $admin->id,
            'status' => PbnSeedBatch::STATUS_COMPLETED,
            'source_platform_ids' => [$platform->id],
            'target_count' => 1,
            'selected_count' => 1,
            'created_count' => 1,
            'failed_count' => 0,
        ]);
        PbnSeedItem::create([
            'batch_id' => $batch->id,
            'pbn_site_id' => $site->id,
            'source_platform_id' => $platform->id,
            'source_client_id' => $client->id,
            'source_wp_post_id' => $client->wp_post_id,
            'target_wp_post_id' => $targetPostId,
            'target_wp_user_id' => 1,
            'status' => PbnSeedItem::STATUS_CREATED,
            'payload_hash' => str_repeat('d', 64),
        ]);

        Sanctum::actingAs($sales);
        $this->postJson("/api/crm/pbn/batches/{$batch->id}/revert", [
            'reason' => 'Sales cannot revert',
        ])->assertForbidden();

        Sanctum::actingAs($admin);
        $this->getJson("/api/crm/pbn/batches/{$batch->id}/revert-preview")
            ->assertOk()
            ->assertJsonPath('can_revert', true)
            ->assertJsonPath('eligible_count', 1);

        $this->postJson("/api/crm/pbn/batches/{$batch->id}/revert", [
            'reason' => 'Rollback launch seed',
        ])->assertOk()
            ->assertJsonPath('batch.status', PbnSeedBatch::STATUS_REVERTED)
            ->assertJsonPath('batch.reverted_count', 1);

        $this->assertDatabaseHas('pbn_seed_items', [
            'batch_id' => $batch->id,
            'target_wp_post_id' => $targetPostId,
            'status' => PbnSeedItem::STATUS_REVERTED,
            'reverted_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('pbn_seed_events', [
            'batch_id' => $batch->id,
            'type' => 'batch_reverted',
        ]);
        $this->assertSame('private', DB::connection($connectionName)->table('posts')->where('ID', $targetPostId)->value('post_status'));
    }

    private function pbnSite(Platform $default, array $sourceIds, array $overrides = []): PbnSite
    {
        $site = PbnSite::create(array_merge([
            'name' => 'Uganda Hot Girls',
            'domain' => 'ugandahotgirls.com',
            'default_source_platform_id' => $default->id,
            'is_active' => true,
            'country' => 'Uganda',
            'timezone' => 'Africa/Nairobi',
            'currency_code' => 'UGX',
            'phone_prefix' => '256',
            'wp_api_url' => 'https://ugandahotgirls.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
            'db_host' => '127.0.0.1',
            'db_name' => 'wordpress',
            'db_user' => 'root',
            'db_pass' => 'secret',
            'db_prefix' => 'wp_',
            'copy_policy' => PbnSite::defaultCopyPolicy(),
            'last_status' => 'ready',
        ], $overrides));

        $site->sourcePlatforms()->syncWithPivotValues($sourceIds, ['weight' => 100]);
        $site->sourceRows()->where('platform_id', $default->id)->update(['is_default' => true]);

        return $site->fresh();
    }

    private function publishedClient(Platform $platform, array $overrides = []): Client
    {
        return Client::factory()->create(array_merge([
            'platform_id' => $platform->id,
            'profile_status' => 'publish',
            'wp_post_id' => fake()->unique()->numberBetween(10000, 999999),
            'closed_at' => null,
            'duplicate_of' => null,
            'is_high_risk' => false,
            'source_presence_status' => 'present',
            'main_image_url' => 'https://example.test/profile.jpg',
            'display_image_url' => null,
        ], $overrides));
    }

    private function userFor(Platform $platform, string $role = 'sales', ?array $marketIds = null): User
    {
        return User::factory()->create([
            'role' => $role,
            'assigned_market_ids' => $marketIds ?: [$platform->id],
            'status' => 'active',
        ]);
    }

    private function createWordPressProvisioningFixture(PbnSite $site): array
    {
        $databasePath = tempnam(sys_get_temp_dir(), 'wp_pbn_provision_');
        $this->temporaryDatabases[] = $databasePath;

        $connectionName = 'wp_provision_pbn_site_' . $site->id;
        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => 'wp_',
            'foreign_key_constraints' => false,
        ];

        DynamicDatabaseService::switchConnection($connectionName, $connectionConfig);
        $this->createWordPressTables($connectionName);

        return [$connectionName, $connectionConfig];
    }

    private function createWordPressTables(string $connectionName): void
    {
        $schema = DB::connection($connectionName)->getSchemaBuilder();

        $schema->create('options', function (Blueprint $table): void {
            $table->increments('option_id');
            $table->string('option_name')->unique();
            $table->text('option_value')->nullable();
            $table->string('autoload')->default('yes');
        });
        $schema->create('users', function (Blueprint $table): void {
            $table->increments('ID');
            $table->string('user_login');
            $table->string('user_pass');
            $table->string('user_nicename')->nullable();
            $table->string('user_email')->nullable();
            $table->string('user_url')->nullable();
            $table->dateTime('user_registered')->nullable();
            $table->string('user_activation_key')->nullable();
            $table->integer('user_status')->default(0);
            $table->string('display_name')->nullable();
        });
        $schema->create('usermeta', function (Blueprint $table): void {
            $table->increments('umeta_id');
            $table->unsignedInteger('user_id');
            $table->string('meta_key')->nullable();
            $table->text('meta_value')->nullable();
        });
        $schema->create('posts', function (Blueprint $table): void {
            $table->increments('ID');
            $table->unsignedInteger('post_author')->default(0);
            $table->dateTime('post_date')->nullable();
            $table->dateTime('post_date_gmt')->nullable();
            $table->longText('post_content')->nullable();
            $table->text('post_title')->nullable();
            $table->text('post_excerpt')->nullable();
            $table->string('post_status')->nullable();
            $table->string('comment_status')->nullable();
            $table->string('ping_status')->nullable();
            $table->string('post_password')->nullable();
            $table->string('post_name')->nullable();
            $table->text('to_ping')->nullable();
            $table->text('pinged')->nullable();
            $table->dateTime('post_modified')->nullable();
            $table->dateTime('post_modified_gmt')->nullable();
            $table->longText('post_content_filtered')->nullable();
            $table->unsignedInteger('post_parent')->default(0);
            $table->string('guid')->nullable();
            $table->integer('menu_order')->default(0);
            $table->string('post_type')->nullable();
            $table->string('post_mime_type')->nullable();
            $table->integer('comment_count')->default(0);
        });
        $schema->create('postmeta', function (Blueprint $table): void {
            $table->increments('meta_id');
            $table->unsignedInteger('post_id');
            $table->string('meta_key')->nullable();
            $table->text('meta_value')->nullable();
        });
        $schema->create('terms', function (Blueprint $table): void {
            $table->increments('term_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('term_group')->default(0);
        });
        $schema->create('term_taxonomy', function (Blueprint $table): void {
            $table->increments('term_taxonomy_id');
            $table->unsignedInteger('term_id');
            $table->string('taxonomy');
            $table->longText('description')->nullable();
            $table->unsignedInteger('parent')->default(0);
            $table->integer('count')->default(0);
        });
        $schema->create('term_relationships', function (Blueprint $table): void {
            $table->unsignedInteger('object_id');
            $table->unsignedInteger('term_taxonomy_id');
            $table->integer('term_order')->default(0);
            $table->primary(['object_id', 'term_taxonomy_id']);
        });
        $schema->create('exotic_crm_provisions', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('request_id', 64)->unique();
            $table->string('payload_hash', 64);
            $table->string('status', 16);
            $table->unsignedInteger('wp_post_id')->nullable();
            $table->unsignedInteger('wp_user_id')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('completed_at')->nullable();
        });

        DB::connection($connectionName)->table('options')->insert([
            ['option_name' => 'taxonomy_profile_url', 'option_value' => 'escort', 'autoload' => 'yes'],
            ['option_name' => 'taxonomy_location_url', 'option_value' => 'escorts-from', 'autoload' => 'yes'],
        ]);
    }

    private function seedLocationTerms(string $connectionName): array
    {
        $regionId = (int) DB::connection($connectionName)->table('terms')->insertGetId([
            'name' => 'Central',
            'slug' => 'central',
            'term_group' => 0,
        ]);
        DB::connection($connectionName)->table('term_taxonomy')->insert([
            'term_id' => $regionId,
            'taxonomy' => 'escorts-from',
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ]);
        $cityId = (int) DB::connection($connectionName)->table('terms')->insertGetId([
            'name' => 'Kampala',
            'slug' => 'kampala',
            'term_group' => 0,
        ]);
        DB::connection($connectionName)->table('term_taxonomy')->insert([
            'term_id' => $cityId,
            'taxonomy' => 'escorts-from',
            'description' => '',
            'parent' => $regionId,
            'count' => 0,
        ]);

        return [$regionId, $cityId];
    }
}
