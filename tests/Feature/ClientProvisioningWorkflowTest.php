<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use App\Http\Controllers\CRM\ClientController;
use App\Services\DynamicDatabaseService;
use App\Services\WpDirectProvisioningService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;
use Tests\TestCase;

class ClientProvisioningWorkflowTest extends TestCase
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

    public function test_direct_provisioning_writes_sales_intake_profile_meta(): void
    {
        [$platform, $connectionName, $connectionConfig] = $this->createWordPressProvisioningFixture();

        $result = (new WpDirectProvisioningService($platform, $connectionConfig))->provisionEscort([
            'name' => 'Nairobi Demo',
            'email' => 'nairobi.demo@example.test',
            'phone' => '254712345678',
            'whatsapp' => '254712345678',
            'city' => 'Nairobi',
            'birthday' => '1998-06-15',
            'height' => '167',
            'weight' => '55',
            'bio' => 'Warm, professional profile introduction for first publication.',
            'post_status' => 'private',
            'username' => 'nairobi.demo',
            'password' => 'password123',
        ]);

        $meta = DB::connection($connectionName)
            ->table('postmeta')
            ->where('post_id', $result['wp_post_id'])
            ->pluck('meta_value', 'meta_key')
            ->all();

        $this->assertSame('254712345678', $meta['phone'] ?? null);
        $this->assertSame('254712345678', $meta['whatsapp'] ?? null);
        $this->assertSame('1998-06-15', $meta['birthday'] ?? null);
        $this->assertSame('167', $meta['height'] ?? null);
        $this->assertSame('55', $meta['weight'] ?? null);

        $post = DB::connection($connectionName)
            ->table('posts')
            ->where('ID', $result['wp_post_id'])
            ->first();

        $this->assertSame('Warm, professional profile introduction for first publication.', $post->post_content);

        $taxonomy = DB::connection($connectionName)
            ->table('term_relationships')
            ->join('term_taxonomy', 'term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id')
            ->join('terms', 'term_taxonomy.term_id', '=', 'terms.term_id')
            ->where('term_relationships.object_id', $result['wp_post_id'])
            ->where('term_taxonomy.taxonomy', 'city')
            ->select('terms.name', 'terms.slug', 'term_taxonomy.count')
            ->first();

        $this->assertNotNull($taxonomy);
        $this->assertSame('Nairobi', $taxonomy->name);
        $this->assertSame('nairobi', $taxonomy->slug);
        $this->assertSame(1, (int) $taxonomy->count);
    }

    public function test_direct_provisioning_allows_profile_basics_to_be_omitted(): void
    {
        [$platform, $connectionName, $connectionConfig] = $this->createWordPressProvisioningFixture();

        $result = (new WpDirectProvisioningService($platform, $connectionConfig))->provisionEscort([
            'name' => 'Minimal Demo',
            'email' => 'minimal.demo@example.test',
            'phone' => '254700000001',
            'post_status' => 'private',
            'password' => 'password123',
        ]);

        $meta = DB::connection($connectionName)
            ->table('postmeta')
            ->where('post_id', $result['wp_post_id'])
            ->pluck('meta_value', 'meta_key')
            ->all();

        $this->assertSame('254700000001', $meta['phone'] ?? null);
        $this->assertSame('254700000001', $meta['whatsapp'] ?? null);
        $this->assertArrayNotHasKey('birthday', $meta);
        $this->assertArrayNotHasKey('height', $meta);
        $this->assertArrayNotHasKey('weight', $meta);

        $post = DB::connection($connectionName)
            ->table('posts')
            ->where('ID', $result['wp_post_id'])
            ->first();

        $this->assertSame('', $post->post_content);
        $this->assertSame(0, DB::connection($connectionName)->table('term_relationships')->count());
    }

    public function test_wordpress_provisioning_requires_email_or_phone_before_database_work(): void
    {
        $platform = Platform::factory()->create();
        $user = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales@example.test',
            'password' => bcrypt('password'),
            'role' => 'sales',
            'assigned_market_ids' => [$platform->id],
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/crm/clients', [
            'platform_id' => $platform->id,
            'name' => 'No Contact',
            'onboarding_mode' => 'wp_provision',
            'birthday' => '1999-01-01',
            'height' => '170',
            'weight' => '60',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Email or phone is required when provisioning a WordPress profile.');
    }

    public function test_provisioned_profile_finalization_reuses_wordpress_update_endpoint_for_city(): void
    {
        $platform = Platform::factory()->create([
            'wp_api_url' => 'https://ghana.example.test/wp-json/exotic-crm-sync/v1',
            'wp_api_user' => 'crm-user',
            'wp_api_password' => 'secret',
        ]);
        $baseUrl = rtrim((string) $platform->wp_api_url, '/');
        $wpPostId = 258859;

        Http::fake([
            "{$baseUrl}/clients/{$wpPostId}/update" => Http::response(['success' => true], 200),
        ]);

        $controller = (new ReflectionClass(ClientController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ClientController::class, 'finalizeProvisionedWpProfile');

        $status = $method->invoke($controller, $platform, $wpPostId, [
            'city' => 'Accra City',
            'bio' => 'A concise first profile bio.',
        ]);

        $this->assertSame('success', $status);
        Http::assertSent(function ($request) use ($baseUrl, $wpPostId) {
            return $request->url() === "{$baseUrl}/clients/{$wpPostId}/update"
                && $request->method() === 'POST'
                && data_get($request->data(), 'fields.city') === 'Accra City'
                && data_get($request->data(), 'fields.content') === 'A concise first profile bio.';
        });
    }

    private function createWordPressProvisioningFixture(): array
    {
        $platform = Platform::factory()->create([
            'domain' => 'https://kenya.example.test',
            'db_prefix' => 'wp_',
        ]);

        $databasePath = tempnam(sys_get_temp_dir(), 'wp_provision_');
        $this->temporaryDatabases[] = $databasePath;

        $connectionName = 'wp_provision_' . $platform->id;
        $connectionConfig = [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'prefix' => 'wp_',
            'foreign_key_constraints' => false,
        ];

        DynamicDatabaseService::switchConnection($connectionName, $connectionConfig);
        $this->createWordPressTables($connectionName);

        return [$platform, $connectionName, $connectionConfig];
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

        DB::connection($connectionName)->table('options')->insert([
            'option_name' => 'taxonomy_profile_url',
            'option_value' => 'escort',
            'autoload' => 'yes',
        ]);
    }
}
