<?php

namespace Tests\Feature;

use App\Models\Platform;
use App\Models\User;
use App\Support\CrmAuditAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
