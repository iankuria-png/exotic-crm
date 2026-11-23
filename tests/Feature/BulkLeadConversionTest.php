<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Lead;
use App\Models\Platform;
use App\Models\TimelineEvent;
use App\Models\User;
use App\Services\ClientSyncService;
use App\Services\DynamicDatabaseService;
use App\Services\WpDirectProvisioningService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BulkLeadConversionTest extends TestCase
{
    use RefreshDatabase;

    private array $databases = [];

    private Platform $market;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->market = Platform::factory()->create(['phone_prefix' => '254', 'db_host' => 'fixture', 'db_name' => 'fixture', 'db_user' => 'fixture', 'db_pass' => 'fixture']);
        $this->staff = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->market->id]]);
        Sanctum::actingAs($this->staff);
    }

    protected function tearDown(): void
    {
        foreach ($this->databases as $path) {
            @unlink($path);
        }
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_classifies_existing_ambiguous_archived_and_invalid_leads(): void
    {
        $ready = $this->lead();
        $existing = $this->lead(['phone_normalized' => '254711111112']);
        $ambiguous = $this->lead(['phone_normalized' => '254711111113']);
        Client::factory()->create(['platform_id' => $this->market->id, 'phone_normalized' => $existing->phone_normalized]);
        Client::factory()->count(2)->create(['platform_id' => $this->market->id, 'phone_normalized' => $ambiguous->phone_normalized]);
        $archived = $this->lead(['phone_normalized' => '254711111114', 'archived_at' => now()]);
        $invalid = $this->lead(['phone_normalized' => null, 'email' => null]);
        $before = [Lead::count(), Client::count(), TimelineEvent::count()];
        $rows = $this->preview([$ready, $existing, $ambiguous, $archived, $invalid])->assertOk()->json('rows');
        $this->assertSame(['create', 'existing_match', 'existing_match', 'skipped', 'needs_attention'], array_column($rows, 'action'));
        $this->assertNotEmpty($rows[0]['review_token']);
        foreach (array_slice($rows, 1) as $row) {
            $this->assertArrayNotHasKey('review_token', $row);
        }
        $this->assertNull($rows[2]['client_id']);
        $this->assertSame($before, [Lead::count(), Client::count(), TimelineEvent::count()]);
        Http::assertNothingSent();
    }

    public function test_repeated_contacts_in_selection_are_all_flagged_and_other_markets_are_independent(): void
    {
        $one = $this->lead();
        $two = $this->lead(['phone_normalized' => '+254 711 111 111']);
        $rows = $this->preview([$one, $two])->assertOk()->json('rows');
        $this->assertSame(['needs_attention', 'needs_attention'], array_column($rows, 'action'));
        $this->assertStringContainsString('Shares a contact', $rows[0]['message']);
        $this->assertSame('create', $this->preview([$one])->assertOk()->json('rows.0.action'));
        $other = Platform::factory()->create(['phone_prefix' => '254', 'db_host' => 'f', 'db_name' => 'f', 'db_user' => 'f', 'db_pass' => 'f']);
        $this->staff->update(['assigned_market_ids' => [$this->market->id, $other->id]]);
        $three = $this->lead(['platform_id' => $other->id]);
        $this->assertSame(['create', 'create'], array_column($this->preview([$one, $three])->assertOk()->json('rows'), 'action'));
    }

    public function test_selection_limit_duplicate_ids_missing_ids_and_market_permissions_are_enforced(): void
    {
        $one = $this->lead();
        $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => []])->assertUnprocessable();
        $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => range(1, 101)])->assertUnprocessable();
        $this->preview([$one, $one])->assertUnprocessable();
        $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => [$one->id, 999999]])->assertUnprocessable();
        $other = $this->lead(['platform_id' => Platform::factory()->create()->id]);
        $this->preview([$one, $other])->assertForbidden();
        $this->staff->update(['role' => 'marketing']);
        $this->preview([$one])->assertForbidden();
    }

    public function test_tokens_are_actor_lead_and_expiry_bound_and_cannot_be_tampered_with(): void
    {
        $lead = $this->lead();
        $other = $this->lead(['phone_normalized' => '254711111115']);
        $token = $this->token($lead);
        $this->execute($lead, $token.'tampered')->assertUnprocessable();
        $this->execute($other, $token)->assertUnprocessable();
        $actor = User::factory()->create(['role' => 'sales', 'status' => 'active', 'assigned_market_ids' => [$this->market->id]]);
        Sanctum::actingAs($actor);
        $this->execute($lead, $token)->assertForbidden();
        Sanctum::actingAs($this->staff);
        $this->travel(31)->minutes();
        $this->execute($lead, $token)->assertUnprocessable();
    }

    public function test_changed_lead_new_match_archive_and_revoked_access_are_rechecked(): void
    {
        $lead = $this->lead();
        $token = $this->token($lead);
        $lead->update(['name' => 'Edited while reviewing']);
        $this->execute($lead, $token)->assertConflict()->assertJsonPath('status', 'review_required');
        $token = $this->token($lead);
        Client::factory()->create(['platform_id' => $this->market->id, 'phone_normalized' => $lead->phone_normalized]);
        $this->execute($lead, $token)->assertConflict();
        $lead->update(['archived_at' => now()]);
        $this->execute($lead, $token)->assertConflict();
        $this->staff->update(['assigned_market_ids' => []]);
        $this->execute($lead, $token)->assertForbidden();
    }

    public function test_busy_market_refuses_both_bulk_and_single_conversion(): void
    {
        $lead = $this->lead();
        $token = $this->token($lead);
        $lock = Cache::store('file')->lock('lead-conversion-market:'.$this->market->id, 600);
        $this->assertTrue($lock->get());
        try {
            $this->execute($lead, $token)->assertConflict()->assertJsonPath('status', 'busy');
            $this->postJson('/api/crm/leads/'.$lead->id.'/convert-to-client', ['name' => $lead->name])->assertConflict();
        } finally {
            $lock->release();
        }
    }

    public function test_creation_preserves_market_owner_private_status_audits_and_replay_has_one_wp_profile(): void
    {
        $connection = $this->wordpress();
        $lead = $this->lead(['assigned_to' => $this->staff->id]);
        $token = $this->token($lead);
        $result = $this->execute($lead, $token)->assertOk()->assertJsonPath('status', 'created');
        $client = Client::findOrFail($result->json('client_id'));
        $this->assertSame((int) $this->market->id, (int) $client->platform_id);
        $this->assertSame((int) $this->staff->id, (int) $client->assigned_to);
        $this->assertSame('private', $client->profile_status);
        $this->assertSame('converted', $lead->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['entity_type' => 'lead', 'entity_id' => $lead->id, 'action' => 'lead_convert_to_client']);
        $this->assertSame(2, TimelineEvent::whereIn('event_type', ['lead_converted_to_client', 'client_created_from_lead'])->count());
        $this->execute($lead, $token)->assertOk()->assertJsonPath('status', 'already_converted')->assertJsonPath('client_id', $client->id);
        $this->assertSame(1, DB::connection($connection)->table('posts')->count());
        $this->assertSame(1, DB::connection($connection)->table('exotic_crm_provisions')->count());
        $this->assertSame('lead-client-'.$lead->id, DB::connection($connection)->table('exotic_crm_provisions')->value('request_id'));
    }

    public function test_wp_success_crm_failure_retries_the_same_profile_and_changed_payload_fails_closed(): void
    {
        $connection = $this->wordpress();
        $lead = $this->lead();
        $token = $this->token($lead);
        $fail = true;
        TimelineEvent::creating(function () use (&$fail) {
            if ($fail) {
                throw new \RuntimeException('Simulated CRM transaction failure');
            }
        });
        $this->execute($lead, $token)->assertStatus(500);
        $this->assertSame(0, Client::count());
        $this->assertSame('new', $lead->fresh()->status);
        $this->assertSame(1, DB::connection($connection)->table('posts')->count());
        $fail = false;
        $lead->update(['name' => 'Changed after WP success']);
        $this->execute($lead, $this->token($lead))->assertConflict()->assertJsonPath('status', 'recovery_required');
        $this->assertSame(1, DB::connection($connection)->table('posts')->count());
        $lead->update(['name' => 'Test lead']);
        $this->execute($lead, $this->token($lead))->assertOk();
        $this->assertSame(1, DB::connection($connection)->table('posts')->count());
    }

    public function test_prelinked_and_wordpress_history_never_receive_creation_tokens(): void
    {
        $client = Client::factory()->create(['platform_id' => $this->market->id]);
        $linked = $this->lead(['converted_client_id' => $client->id]);
        $history = $this->lead(['wp_user_id' => 123]);
        $broken = $this->lead(['status' => 'converted']);
        $this->assertSame(['existing_match', 'needs_attention', 'needs_attention'], array_column($this->preview([$linked, $history, $broken])->assertOk()->json('rows'), 'action'));
    }

    public function test_changed_wordpress_destination_invalidates_the_review(): void
    {
        $lead = $this->lead();
        $token = $this->token($lead);
        $this->market->update(['db_name' => 'different_destination']);
        $this->execute($lead, $token)->assertConflict()->assertJsonPath('status', 'review_required');
        $this->assertSame(0, Client::count());
    }

    public function test_individual_conversion_uses_the_same_stable_provisioning_identity(): void
    {
        $connection = $this->wordpress();
        $lead = $this->lead(['assigned_to' => $this->staff->id]);
        $token = $this->token($lead);
        $this->postJson('/api/crm/leads/'.$lead->id.'/convert-to-client', [
            'name' => $lead->name,
            'phone_normalized' => $lead->phone_normalized,
            'profile_status' => 'private',
        ])->assertOk();
        $this->execute($lead, $token)->assertOk()->assertJsonPath('status', 'already_converted');
        $this->assertSame(1, DB::connection($connection)->table('posts')->count());
        $this->assertSame('lead-client-'.$lead->id, DB::connection($connection)->table('exotic_crm_provisions')->value('request_id'));
    }

    private function lead(array $attributes = []): Lead
    {
        return Lead::create(array_merge(['platform_id' => $this->market->id, 'name' => 'Test lead', 'phone_normalized' => '254711111111', 'source' => 'import', 'status' => 'new'], $attributes));
    }

    private function preview(array $leads)
    {
        return $this->postJson('/api/crm/leads/bulk-convert/preview', ['lead_ids' => array_map(fn ($lead) => $lead->id, $leads)]);
    }

    private function token(Lead $lead): string
    {
        return $this->preview([$lead])->assertOk()->json('rows.0.review_token');
    }

    private function execute(Lead $lead, string $token)
    {
        return $this->postJson('/api/crm/leads/'.$lead->id.'/bulk-convert', ['review_token' => $token, 'reason' => 'Reviewed bulk batch']);
    }

    private function wordpress(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk_lead_wp_');
        $this->databases[] = $path;
        $config = ['driver' => 'sqlite', 'database' => $path, 'prefix' => 'wp_', 'foreign_key_constraints' => false];
        $connection = 'bulk_lead_fixture';
        DynamicDatabaseService::switchConnection($connection, $config);
        $this->createWordPressTables($connection);
        $this->app->bind(WpDirectProvisioningService::class, fn ($app, $params) => new WpDirectProvisioningService($params['site'], $config));
        $sync = \Mockery::mock(ClientSyncService::class);
        $sync->shouldReceive('syncOne')->andThrow(new \RuntimeException('Fixture: no external sync'));
        $this->app->bind(ClientSyncService::class, fn () => $sync);

        return $connection;
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
            [
                'option_name' => 'taxonomy_profile_url',
                'option_value' => 'escort',
                'autoload' => 'yes',
            ],
            [
                'option_name' => 'taxonomy_location_url',
                'option_value' => 'escorts-from',
                'autoload' => 'yes',
            ],
        ]);
    }
}
