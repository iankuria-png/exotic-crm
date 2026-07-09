<?php

namespace Tests\Feature;

use App\Jobs\SendPaymentFailureAlertRecipientJob;
use App\Jobs\SendPaymentFailureAlertsJob;
use App\Models\Client;
use App\Models\ClientSyncRun;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Platform;
use App\Models\Product;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SystemHealthUpdatesTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $cleanupDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanupDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_updates_status_returns_remote_compare_and_deployed_metadata(): void
    {
        $user = $this->createUser('sub_admin');
        $paths = $this->configureDeploymentFixtures();

        File::put($paths['status'], json_encode([
            'state' => 'success',
            'in_progress' => false,
            'trigger_source' => 'cpanel',
            'branch' => 'main',
            'commit_sha' => '1111111111111111111111111111111111111111',
            'commit_short' => '11111111',
            'deployed_at' => '2026-03-16T09:00:00Z',
            'last_successful_deploy' => [
                'sha' => '1111111111111111111111111111111111111111',
                'short_sha' => '11111111',
                'branch' => 'main',
                'deployed_at' => '2026-03-16T09:00:00Z',
                'trigger_source' => 'cpanel',
                'status' => 'success',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Http::preventStrayRequests();
        Http::fake([
            'https://api.github.com/repos/exotic/crm/compare/*' => Http::response([
                'ahead_by' => 3,
                'html_url' => 'https://github.com/exotic/crm/compare/1111111...main',
                'commits' => [
                    [
                        'sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                        'html_url' => 'https://github.com/exotic/crm/commit/aaaaaaaa',
                        'commit' => [
                            'message' => "Add deployment card\n\nMore details",
                            'author' => [
                                'name' => 'Ian',
                                'date' => '2026-03-17T08:00:00Z',
                            ],
                        ],
                        'author' => [
                            'login' => 'ian',
                        ],
                    ],
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/settings/system-health/updates');

        $response->assertOk()
            ->assertJsonPath('deployed_version.short_sha', '11111111')
            ->assertJsonPath('current_checkout_version.short_sha', '22222222')
            ->assertJsonPath('tracked_branch', 'main')
            ->assertJsonPath('ahead_by', 3)
            ->assertJsonPath('remote.available', true)
            ->assertJsonPath('commits.0.short_sha', 'aaaaaaaa')
            ->assertJsonPath('commits.0.message', 'Add deployment card');
    }

    public function test_updates_status_falls_back_to_current_checkout_when_marker_is_missing(): void
    {
        $user = $this->createUser('admin');
        $this->configureDeploymentFixtures([
            'current_sha' => '3333333333333333333333333333333333333333',
            'github' => [
                'owner' => null,
                'repo' => null,
                'token' => null,
            ],
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/crm/settings/system-health/updates');

        $response->assertOk()
            ->assertJsonPath('deployed_version.short_sha', '33333333')
            ->assertJsonPath('deployed_version.inferred', true)
            ->assertJsonPath('remote.available', false);
    }

    public function test_sub_admin_can_view_updates_but_cannot_trigger_manual_deploy(): void
    {
        $user = $this->createUser('sub_admin');
        $this->configureDeploymentFixtures();

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/system-health/updates')->assertOk();
        $this->postJson('/api/crm/settings/system-health/updates/deploy')->assertForbidden();
    }

    public function test_manual_deploy_rejects_when_another_deploy_is_already_running(): void
    {
        $user = $this->createUser('admin');
        $paths = $this->configureDeploymentFixtures();

        File::put($paths['status'], json_encode([
            'state' => 'running',
            'in_progress' => true,
            'trigger_source' => 'manual',
            'branch' => 'main',
            'commit_sha' => '1111111111111111111111111111111111111111',
            'started_at' => '2026-03-17T08:30:00Z',
            'message' => 'Deployment is already in progress.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/settings/system-health/updates/deploy');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['deploy']);
    }

    public function test_manual_deploy_starts_background_script_and_exposes_log_output(): void
    {
        $platform = Platform::factory()->create();
        $user = $this->createUser('admin', [$platform->id]);
        $paths = $this->configureDeploymentFixtures();
        $this->writeFakeDeployScript($paths['script']);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/crm/settings/system-health/updates/deploy', [
            'reason' => 'Deploy release candidate',
        ]);

        $response->assertOk()
            ->assertJsonPath('manual_deploy.in_progress', true)
            ->assertJsonPath('manual_deploy.trigger_source', 'manual');

        $this->assertDatabaseHas('audit_log', [
            'action' => CrmAuditAction::SYSTEM_DEPLOY_START,
            'entity_type' => 'deployment',
            'entity_id' => 1,
        ]);

        $this->waitForDeployStatus($paths['status'], 'success');

        $logResponse = $this->getJson('/api/crm/settings/system-health/updates/log');

        $logResponse->assertOk()
            ->assertJsonPath('manual_deploy.state', 'success');

        $lines = $logResponse->json('log_lines');
        $this->assertIsArray($lines);
        $this->assertStringContainsString('Deployment completed successfully.', implode("\n", $lines));
    }

    public function test_record_deploy_audit_command_writes_success_entry(): void
    {
        $platform = Platform::factory()->create();
        $user = $this->createUser('admin', [$platform->id]);

        Artisan::call('crm:record-deploy-audit', [
            'status' => 'success',
            '--user-id' => $user->id,
            '--source' => 'manual',
            '--branch' => 'main',
            '--commit' => '9999999999999999999999999999999999999999',
            '--reason' => 'Deploy release candidate',
            '--message' => 'Deployment completed successfully.',
        ]);

        $this->assertDatabaseHas('audit_log', [
            'platform_id' => $platform->id,
            'actor_id' => $user->id,
            'action' => CrmAuditAction::SYSTEM_DEPLOY_SUCCESS,
            'entity_type' => 'deployment',
            'entity_id' => 1,
        ]);
    }

    public function test_queue_status_includes_pulse_monitoring_metadata(): void
    {
        $user = $this->createUser('sub_admin');

        config([
            'deployment.php_binary' => '/opt/cpanel/ea-php82/root/usr/bin/php',
            'pulse.path' => 'pulse',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/system-health/queue-status')
            ->assertOk()
            ->assertJsonPath('pulse_url', url('/pulse'))
            ->assertJsonPath('pulse_check_command', 'cd ' . base_path() . ' && /opt/cpanel/ea-php82/root/usr/bin/php artisan pulse:check')
            ->assertJsonPath('pulse_restart_command', 'cd ' . base_path() . ' && /opt/cpanel/ea-php82/root/usr/bin/php artisan pulse:restart');
    }

    public function test_queue_status_includes_alert_queue_metrics_and_recent_alert_attempts(): void
    {
        $user = $this->createUser('sub_admin');
        $platform = Platform::query()->create([
            'name' => 'Kenya Market',
            'domain' => 'kenya.example.test',
            'slug' => 'kenya-market',
            'country' => 'Kenya',
            'currency_code' => 'KES',
            'phone_prefix' => '254',
            'timezone' => 'Africa/Nairobi',
            'payment_instruction' => 'Pay via mobile money',
        ]);
        $client = Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 1001,
            'name' => 'Alert Client',
            'phone_normalized' => '254700000001',
        ]);
        $product = Product::query()->create([
            'name' => 'Featured Boost',
            'display_name' => 'Featured Boost',
            'slug' => 'featured-boost',
            'platform_id' => $platform->id,
            'tier' => 'featured',
            'monthly_price' => 50,
            'biweekly_price' => 30,
            'weekly_price' => 20,
            'currency' => 'KES',
            'is_active' => true,
            'is_archived' => false,
            'sort_order' => 1,
        ]);
        $payment = Payment::query()->create([
            'platform_id' => $platform->id,
            'client_id' => $client->id,
            'product_id' => $product->id,
            'phone' => '254700000001',
            'amount' => 99.99,
            'currency' => 'KES',
            'transaction_reference' => 'TXN-QUEUE-1',
            'reference_number' => 'REF-QUEUE-1',
            'status' => 'failed',
            'source' => 'gateway',
            'payment_data' => [],
            'raw_payload' => [],
        ]);

        PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'attempt_type' => 'payment_failure_alert_sms',
            'provider' => 'paystack',
            'status' => 'sent',
            'request_meta' => [
                'event_key' => 'payment-failure:1:20260426120000.000000',
                'trigger_source' => 'payment_model_saved',
                'user_id' => 9,
                'user_name' => 'Ops Admin',
                'user_role' => 'admin',
                'phone' => '0700000001',
            ],
            'response_meta' => [
                'provider_result' => ['success' => true, 'status' => 'sent'],
            ],
        ]);

        DB::table('jobs')->insert([
            [
                'queue' => 'alerts',
                'payload' => json_encode(['displayName' => SendPaymentFailureAlertsJob::class], JSON_UNESCAPED_SLASHES),
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
            [
                'queue' => 'alerts',
                'payload' => json_encode(['displayName' => SendPaymentFailureAlertRecipientJob::class], JSON_UNESCAPED_SLASHES),
                'attempts' => 0,
                'reserved_at' => now()->timestamp,
                'available_at' => now()->timestamp,
                'created_at' => now()->timestamp,
            ],
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'alerts',
            'payload' => json_encode(['displayName' => SendPaymentFailureAlertRecipientJob::class], JSON_UNESCAPED_SLASHES),
            'exception' => 'Gateway timed out.',
            'failed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/system-health/queue-status')
            ->assertOk()
            ->assertJsonPath('alerts_pending', 1)
            ->assertJsonPath('alerts_processing', 1)
            ->assertJsonPath('alerts_failed', 1)
            ->assertJsonPath('latest_failed_alert_job', 'SendPaymentFailureAlertRecipientJob')
            ->assertJsonPath('recent_alert_attempts.0.attempt_type', 'payment_failure_alert_sms')
            ->assertJsonPath('recent_alert_attempts.0.recipient_name', 'Ops Admin');
    }

    public function test_client_sync_status_includes_config_queue_and_market_health(): void
    {
        config([
            'queue.default' => 'database',
            'services.client_sync.per_page' => 100,
            'services.client_sync.delta_max_platforms_per_run' => 3,
            'services.client_sync.delta_stagger_seconds' => 120,
            'services.client_sync.reconcile_stagger_seconds' => 180,
        ]);

        $user = $this->createUser('sub_admin');
        $platform = Platform::factory()->create([
            'name' => 'Kenya',
            'domain' => 'kenya.example.test',
            'sync_last_status' => 'success',
            'sync_last_synced_at' => now()->subMinutes(20),
            'client_sync_protocol' => 'v2',
            'client_sync_capability_status' => 'v2',
            'client_sync_checkpoint_at' => now()->subMinutes(20),
        ]);

        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 101,
            'name' => 'Published Client',
            'profile_status' => 'publish',
            'last_synced_at' => now(),
        ]);
        Client::query()->create([
            'platform_id' => $platform->id,
            'wp_post_id' => 102,
            'name' => 'Draft Client',
            'profile_status' => 'draft',
            'last_synced_at' => now(),
        ]);

        ClientSyncRun::query()->create([
            'platform_id' => $platform->id,
            'origin' => 'scheduler',
            'mode' => 'delta',
            'protocol' => 'v2',
            'status' => ClientSyncRun::STATUS_COMPLETED,
            'processed' => 2,
            'created' => 1,
            'updated' => 1,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ]);

        DB::table('jobs')->insert([
            'queue' => 'sync-clients',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\RunClientSyncJob'], JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/crm/settings/system-health/client-sync')
            ->assertOk()
            ->assertJsonPath('configuration.delta_schedule', 'every 30 minutes')
            ->assertJsonPath('configuration.per_page', 100)
            ->assertJsonPath('configuration.delta_max_platforms_per_run', 3)
            ->assertJsonPath('queues.sync-clients.pending', 1)
            ->assertJsonPath('summary.total_platforms', 1)
            ->assertJsonPath('summary.wp_ready_platforms', 1)
            ->assertJsonPath('platforms.0.platform_name', 'Kenya')
            ->assertJsonPath('platforms.0.status', 'healthy')
            ->assertJsonPath('platforms.0.clients_total', 2)
            ->assertJsonPath('platforms.0.clients_published', 1)
            ->assertJsonPath('recent_runs.0.status', ClientSyncRun::STATUS_COMPLETED);
    }

    private function createUser(string $role, array $assignedMarketIds = []): User
    {
        return User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'assigned_market_ids' => $assignedMarketIds,
        ]);
    }

    private function configureDeploymentFixtures(array $overrides = []): array
    {
        $root = storage_path('app/testing/deployment-' . Str::random(10));
        $gitDir = $root . '/git';
        $statusPath = $root . '/status.json';
        $logPath = $root . '/latest.log';
        $lockPath = $root . '/deploy.lock';
        $scriptPath = $root . '/deploy.sh';
        $currentSha = $overrides['current_sha'] ?? '2222222222222222222222222222222222222222';
        $currentBranch = $overrides['current_branch'] ?? 'main';

        File::ensureDirectoryExists($gitDir . '/refs/heads');
        File::put($gitDir . '/HEAD', "ref: refs/heads/{$currentBranch}\n");
        File::put($gitDir . '/refs/heads/' . $currentBranch, $currentSha . "\n");
        File::put($scriptPath, "#!/bin/bash\nexit 0\n");
        chmod($scriptPath, 0755);

        config([
            'deployment.git_dir' => $gitDir,
            'deployment.status_path' => $statusPath,
            'deployment.log_path' => $logPath,
            'deployment.lock_path' => $lockPath,
            'deployment.script_path' => $scriptPath,
            'deployment.repository_path' => base_path(),
            'deployment.tracked_branch' => $currentBranch,
            'deployment.manual_enabled' => $overrides['manual_enabled'] ?? true,
            'deployment.github.owner' => data_get($overrides, 'github.owner', 'exotic'),
            'deployment.github.repo' => data_get($overrides, 'github.repo', 'crm'),
            'deployment.github.token' => data_get($overrides, 'github.token', 'test-token'),
        ]);

        $this->cleanupDirectories[] = $root;

        return [
            'root' => $root,
            'git' => $gitDir,
            'status' => $statusPath,
            'log' => $logPath,
            'lock' => $lockPath,
            'script' => $scriptPath,
        ];
    }

    private function writeFakeDeployScript(string $path): void
    {
        $script = <<<'BASH'
#!/bin/bash
set -euo pipefail

mkdir -p "$(dirname "$DEPLOY_STATUS_FILE")"
mkdir -p "$(dirname "$DEPLOY_LOG_FILE")"
sleep 1
cat > "$DEPLOY_LOG_FILE" <<LOG
[2026-03-17T08:45:00Z] Starting fake deployment
[2026-03-17T08:45:01Z] Deployment completed successfully.
LOG
cat > "$DEPLOY_STATUS_FILE" <<JSON
{
  "state": "success",
  "in_progress": false,
  "trigger_source": "manual",
  "branch": "main",
  "commit_sha": "5555555555555555555555555555555555555555",
  "commit_short": "55555555",
  "started_at": "2026-03-17T08:45:00Z",
  "finished_at": "2026-03-17T08:45:01Z",
  "deployed_at": "2026-03-17T08:45:01Z",
  "message": "Deployment completed successfully.",
  "requested_by": {
    "id": ${DEPLOY_REQUESTED_BY_ID:-0},
    "name": "${DEPLOY_REQUESTED_BY_NAME:-Unknown}",
    "email": "${DEPLOY_REQUESTED_BY_EMAIL:-unknown@example.test}"
  },
  "reason": "${DEPLOY_REASON:-}",
  "pid": $$,
  "last_successful_deploy": {
    "sha": "5555555555555555555555555555555555555555",
    "short_sha": "55555555",
    "branch": "main",
    "deployed_at": "2026-03-17T08:45:01Z",
    "trigger_source": "manual",
    "status": "success"
  }
}
JSON
BASH;

        File::put($path, $script);
        chmod($path, 0755);
    }

    private function waitForDeployStatus(string $statusPath, string $expectedState): void
    {
        $attempts = 0;

        while ($attempts < 25) {
            $payload = is_file($statusPath)
                ? json_decode((string) File::get($statusPath), true)
                : null;

            if (($payload['state'] ?? null) === $expectedState) {
                return;
            }

            usleep(200000);
            $attempts++;
        }

        $this->fail("Deployment status did not reach [{$expectedState}] in time.");
    }
}
