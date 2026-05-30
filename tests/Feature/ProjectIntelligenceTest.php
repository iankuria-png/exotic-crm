<?php

namespace Tests\Feature;

use App\Services\Ai\ProjectIntelligenceService;
use App\Services\DeploymentStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_context_normalizes_commit_and_deploy_evidence(): void
    {
        config([
            'ai.project_intelligence.enabled' => true,
            'ai.project_intelligence.commit_lookback' => 20,
            'ai.project_intelligence.include_deployment_history' => true,
        ]);

        $service = new ProjectIntelligenceService($this->deploymentService());

        $context = $service->context();

        $this->assertTrue($context['available']);
        $this->assertSame('main', $context['tracked_branch']);
        $this->assertSame(2, $context['ahead_by']);
        $this->assertSame('abc12345', $context['commits'][0]['short_sha']);
        $this->assertSame('Ship billing insight summary', $context['commits'][0]['message_subject']);
        $this->assertSame('def67890', $context['deployments'][0]['short_sha']);

        $evidence = $service->evidenceText($context);
        $this->assertStringContainsString('abc12345', $evidence);
        $this->assertStringContainsString('Ship billing insight summary', $evidence);
        $this->assertStringContainsString('Deployment history', $evidence);
    }

    public function test_project_context_gracefully_reports_unavailable_snapshot(): void
    {
        $service = new ProjectIntelligenceService(new class extends DeploymentStatusService {
            public function snapshot(): array
            {
                throw new \RuntimeException('github down');
            }
        });

        $context = $service->context();

        $this->assertFalse($context['available']);
        $this->assertSame([], $context['commits']);
        $this->assertStringContainsString('unavailable', $service->evidenceText($context));
    }

    public function test_project_intelligence_enabled_flag_is_configured(): void
    {
        config(['ai.project_intelligence.enabled' => false]);

        $this->assertFalse((new ProjectIntelligenceService($this->deploymentService()))->enabled());
    }

    private function deploymentService(): DeploymentStatusService
    {
        return new class extends DeploymentStatusService {
            public function snapshot(): array
            {
                return [
                    'deployed_version' => [
                        'sha' => 'def678901234',
                        'short_sha' => 'def67890',
                        'deployed_at' => '2026-05-29T08:00:00Z',
                        'inferred' => false,
                    ],
                    'tracked_branch' => 'main',
                    'ahead_by' => 2,
                    'remote' => ['available' => true, 'status' => 'ok', 'message' => null],
                    'commits' => [],
                ];
            }

            public function commitHistory(int $page = 1, int $perPage = 10): array
            {
                return [
                    'commits' => [[
                        'sha' => 'abc123456789',
                        'message' => "Ship billing insight summary\n\nMore detail",
                        'author' => 'Dev One',
                        'authored_at' => '2026-05-30T09:00:00Z',
                        'url' => 'https://github.test/repo/commit/abc12345',
                    ]],
                    'page' => $page,
                    'per_page' => $perPage,
                    'has_more' => false,
                ];
            }

            public function deploymentHistory(): array
            {
                return [
                    'deployments' => [[
                        'sha' => 'def678901234',
                        'short_sha' => 'def67890',
                        'status' => 'success',
                        'deployed_at' => '2026-05-29T08:00:00Z',
                        'trigger_source' => 'manual',
                    ]],
                ];
            }
        };
    }
}
