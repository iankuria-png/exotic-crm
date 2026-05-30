<?php

namespace App\Services\Ai;

use App\Services\DeploymentStatusService;

/**
 * Read-only project-status context for the AI chat.
 *
 * Reuses DeploymentStatusService (the same source the deploy dashboard uses) to
 * assemble citation-friendly facts: deployed version, tracked branch, ahead-by,
 * recent commits (SHA / subject / author / date / URL) and deployment history.
 *
 * This service performs NO mutations. It cannot deploy, roll back, or change any
 * state — it only reads. When GitHub/deploy metadata is unavailable it returns a
 * structured "insufficient evidence" payload rather than fabricating an answer.
 */
class ProjectIntelligenceService
{
    public function __construct(
        private readonly DeploymentStatusService $deployment,
    ) {}

    public function enabled(): bool
    {
        return app(AiInsightsSettingsService::class)->projectIntelligenceEnabled();
    }

    /**
     * Build the structured evidence context for the model.
     *
     * @return array{
     *   available: bool,
     *   deployed_version: array,
     *   tracked_branch: ?string,
     *   ahead_by: int,
     *   remote: array,
     *   commits: array<int, array>,
     *   deployments: array<int, array>,
     *   notes: string[]
     * }
     */
    public function context(): array
    {
        $notes = [];

        try {
            $snapshot = $this->deployment->snapshot();
        } catch (\Throwable $e) {
            return $this->unavailable('Deployment status could not be read.');
        }

        $settings = app(AiInsightsSettingsService::class);
        $lookback = $settings->projectCommitLookback();

        $commits = [];
        try {
            $history = $this->deployment->commitHistory(1, min($lookback, 50));
            $commits = $history['commits'] ?? [];
        } catch (\Throwable $e) {
            $notes[] = 'Commit history from GitHub is unavailable right now.';
        }

        if ($commits === []) {
            // Fall back to the remote-compare commits embedded in the snapshot.
            $commits = (array) ($snapshot['commits'] ?? []);
            if ($commits === []) {
                $notes[] = 'No commit metadata is available; rely only on deploy status.';
            }
        }

        $deployments = [];
        if ($settings->includeDeploymentHistory()) {
            try {
                $deployments = (array) ($this->deployment->deploymentHistory()['deployments'] ?? []);
            } catch (\Throwable $e) {
                $notes[] = 'Deployment history is unavailable.';
            }
        }

        $remote = (array) ($snapshot['remote'] ?? []);
        $remoteAvailable = (bool) ($remote['available'] ?? false);

        return [
            'available'        => true,
            'deployed_version' => (array) ($snapshot['deployed_version'] ?? []),
            'tracked_branch'   => $snapshot['tracked_branch'] ?? null,
            'ahead_by'         => (int) ($snapshot['ahead_by'] ?? 0),
            'remote'           => [
                'available' => $remoteAvailable,
                'status'    => $remote['status'] ?? 'unavailable',
                'message'   => $remote['message'] ?? null,
            ],
            'commits'          => $this->normalizeCommits($commits),
            'deployments'      => $deployments,
            'notes'            => $notes,
        ];
    }

    /**
     * Render the context as a compact, citation-ready text block for the prompt.
     */
    public function evidenceText(array $context): string
    {
        if (!($context['available'] ?? false)) {
            return "PROJECT STATUS: unavailable.\n" . implode("\n", $context['notes'] ?? []);
        }

        $lines = [];
        $deployed = $context['deployed_version'];
        $lines[] = 'Tracked branch: ' . ($context['tracked_branch'] ?? 'unknown');
        $lines[] = 'Deployed version: ' . ($deployed['short_sha'] ?? 'unknown')
            . (isset($deployed['deployed_at']) && $deployed['deployed_at'] ? ' (deployed ' . $deployed['deployed_at'] . ')' : '')
            . (($deployed['inferred'] ?? false) ? ' [inferred from checkout, not an explicit deploy record]' : '');
        $lines[] = 'Commits ahead of deployed: ' . $context['ahead_by'];

        if ($context['commits'] !== []) {
            $lines[] = 'Recent commits:';
            foreach (array_slice($context['commits'], 0, 20) as $c) {
                $lines[] = sprintf(
                    '- %s | %s | %s | %s | %s',
                    $c['short_sha'] ?? '???',
                    $c['message_subject'] ?? $c['message'] ?? '',
                    $c['author'] ?? 'unknown',
                    $c['authored_at'] ?? 'unknown date',
                    $c['url'] ?? 'no-url'
                );
            }
        }

        if (!empty($context['deployments'])) {
            $lines[] = 'Deployment history (most recent first):';
            foreach (array_slice($context['deployments'], 0, 10) as $d) {
                $lines[] = sprintf(
                    '- %s | %s | %s | %s',
                    $d['short_sha'] ?? ($d['sha'] ?? '???'),
                    $d['status'] ?? ($d['state'] ?? 'unknown'),
                    $d['deployed_at'] ?? ($d['finished_at'] ?? 'unknown'),
                    $d['trigger_source'] ?? 'unknown trigger'
                );
            }
        }

        if (!empty($context['notes'])) {
            $lines[] = 'Caveats: ' . implode(' ', $context['notes']);
        }

        return implode("\n", $lines);
    }

    private function normalizeCommits(array $commits): array
    {
        return array_values(array_map(function ($c) {
            $c = (array) $c;

            return [
                'sha'             => $c['sha'] ?? null,
                'short_sha'       => $c['short_sha'] ?? (isset($c['sha']) ? substr((string) $c['sha'], 0, 8) : null),
                'message'         => $c['message'] ?? null,
                'message_subject' => $c['message_subject'] ?? (isset($c['message']) ? strtok((string) $c['message'], "\n") : null),
                'author'          => $c['author'] ?? null,
                'authored_at'     => $c['authored_at'] ?? null,
                'url'             => app(AiInsightsSettingsService::class)->showCommitUrls() ? ($c['url'] ?? null) : null,
            ];
        }, $commits));
    }

    private function unavailable(string $reason): array
    {
        return [
            'available'        => false,
            'deployed_version' => [],
            'tracked_branch'   => null,
            'ahead_by'         => 0,
            'remote'           => ['available' => false, 'status' => 'unavailable', 'message' => $reason],
            'commits'          => [],
            'deployments'      => [],
            'notes'            => [$reason],
        ];
    }
}
