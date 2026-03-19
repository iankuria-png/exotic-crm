<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeploymentStatusService
{
    public function snapshot(): array
    {
        $currentCheckout = $this->currentCheckoutVersion();
        $status = $this->readStatusFile();
        $deployedVersion = $this->deployedVersion($status, $currentCheckout);
        $remote = $this->remoteCompare($deployedVersion['sha'] ?? null);
        $deployAvailability = $this->deployAvailability();
        $manualDeploy = $this->manualDeployState($status, $deployAvailability);

        return [
            'deployed_version' => $deployedVersion,
            'current_checkout_version' => $currentCheckout,
            'tracked_branch' => $this->trackedBranch(),
            'remote' => $remote,
            'ahead_by' => (int) ($remote['ahead_by'] ?? 0),
            'commits' => $remote['commits'] ?? [],
            'deploy_available' => $deployAvailability,
            'manual_deploy' => $manualDeploy,
            'last_deploy' => $this->lastDeploy($status, $deployedVersion),
        ];
    }

    public function logSnapshot(int $lines = 80): array
    {
        return [
            'manual_deploy' => $this->manualDeployState($this->readStatusFile(), $this->deployAvailability()),
            'log_path' => $this->logPath(),
            'log_lines' => $this->tail($this->logPath(), $lines),
        ];
    }

    public function startManualDeploy(?User $user = null, ?string $reason = null): array
    {
        $availability = $this->deployAvailability();

        if (!($availability['available'] ?? false)) {
            throw ValidationException::withMessages([
                'deploy' => [$availability['message'] ?? 'Manual deployment is not available.'],
            ]);
        }

        $currentStatus = $this->readStatusFile();
        if (($currentStatus['in_progress'] ?? false) === true) {
            throw ValidationException::withMessages([
                'deploy' => ['A deployment is already in progress.'],
            ]);
        }

        $currentCheckout = $this->currentCheckoutVersion();
        $queuedAt = now()->toIso8601String();
        $initialStatus = [
            'state' => 'running',
            'in_progress' => true,
            'trigger_source' => 'manual',
            'branch' => $currentCheckout['branch'] ?? $this->trackedBranch(),
            'commit_sha' => $currentCheckout['sha'] ?? null,
            'commit_short' => $currentCheckout['short_sha'] ?? null,
            'started_at' => $queuedAt,
            'finished_at' => null,
            'deployed_at' => null,
            'message' => 'Manual deployment has been queued.',
            'requested_by' => $this->serializeUser($user),
            'reason' => $this->normalizeReason($reason),
            'last_successful_deploy' => $currentStatus['last_successful_deploy'] ?? null,
        ];

        $this->ensureDeploymentDirectory();
        File::put($this->logPath(), '[' . $queuedAt . "] Manual deployment queued.\n");
        $this->writeStatusFile($initialStatus);

        $command = $this->buildManualDeployCommand($user, $reason);
        $output = [];
        $exitCode = 0;

        exec($command, $output, $exitCode);

        if ($exitCode !== 0) {
            $this->writeStatusFile(array_merge($initialStatus, [
                'state' => 'failed',
                'in_progress' => false,
                'finished_at' => now()->toIso8601String(),
                'message' => 'Unable to start the deployment process.',
            ]));

            throw ValidationException::withMessages([
                'deploy' => ['Unable to start the deployment process.'],
            ]);
        }

        $pid = trim((string) collect($output)->last());
        if ($pid !== '') {
            $initialStatus['pid'] = $pid;
            $this->writeStatusFile($initialStatus);
        }

        return $this->snapshot();
    }

    public function deployAvailability(): array
    {
        $issues = [];

        if (!$this->manualDeployEnabled()) {
            $issues[] = 'Manual deployment is disabled by configuration.';
        }

        $scriptPath = $this->scriptPath();
        if (!is_file($scriptPath)) {
            $issues[] = 'Deploy script is missing.';
        } elseif (!is_executable($scriptPath)) {
            $issues[] = 'Deploy script is not executable.';
        }

        if (!$this->commandExecutionAvailable()) {
            $issues[] = 'PHP command execution is disabled on this server.';
        }

        return [
            'available' => empty($issues),
            'script_path' => $scriptPath,
            'issues' => $issues,
            'message' => empty($issues) ? 'Manual deployment is available.' : $issues[0],
        ];
    }

    private function manualDeployState(array $status, array $availability): array
    {
        return [
            'enabled' => $this->manualDeployEnabled(),
            'available' => (bool) ($availability['available'] ?? false),
            'in_progress' => (bool) ($status['in_progress'] ?? false),
            'state' => $status['state'] ?? 'idle',
            'message' => $status['message'] ?? ($availability['message'] ?? 'No deployment has been recorded yet.'),
            'started_at' => $status['started_at'] ?? null,
            'finished_at' => $status['finished_at'] ?? null,
            'trigger_source' => $status['trigger_source'] ?? null,
            'requested_by' => $status['requested_by'] ?? null,
            'pid' => $status['pid'] ?? null,
            'issues' => $availability['issues'] ?? [],
        ];
    }

    private function lastDeploy(array $status, array $deployedVersion): ?array
    {
        if (!empty($status['last_successful_deploy']) && is_array($status['last_successful_deploy'])) {
            return $status['last_successful_deploy'];
        }

        if (!empty($status['commit_sha']) || !empty($deployedVersion['sha'])) {
            return [
                'sha' => $status['commit_sha'] ?? $deployedVersion['sha'] ?? null,
                'short_sha' => $status['commit_short'] ?? $deployedVersion['short_sha'] ?? null,
                'branch' => $status['branch'] ?? $deployedVersion['branch'] ?? null,
                'deployed_at' => $status['deployed_at'] ?? $deployedVersion['deployed_at'] ?? null,
                'trigger_source' => $status['trigger_source'] ?? null,
                'status' => $status['state'] ?? null,
            ];
        }

        return null;
    }

    private function deployedVersion(array $status, array $currentCheckout): array
    {
        $sha = $status['commit_sha'] ?? null;

        if (!$sha) {
            return [
                'sha' => $currentCheckout['sha'] ?? null,
                'short_sha' => $currentCheckout['short_sha'] ?? null,
                'branch' => $currentCheckout['branch'] ?? $this->trackedBranch(),
                'deployed_at' => null,
                'trigger_source' => null,
                'status' => 'inferred',
                'inferred' => true,
            ];
        }

        return [
            'sha' => $sha,
            'short_sha' => $status['commit_short'] ?? $this->shortSha($sha),
            'branch' => $status['branch'] ?? $this->trackedBranch(),
            'deployed_at' => $status['deployed_at'] ?? null,
            'trigger_source' => $status['trigger_source'] ?? null,
            'status' => $status['state'] ?? 'success',
            'inferred' => false,
        ];
    }

    public function commitHistory(int $page = 1, int $perPage = 10): array
    {
        $owner = (string) config('deployment.github.owner');
        $repo = (string) config('deployment.github.repo');
        $trackedBranch = $this->trackedBranch();

        if ($owner === '' || $repo === '') {
            return ['commits' => [], 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
        }

        try {
            $client = Http::baseUrl('https://api.github.com')
                ->timeout(10)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'ExoticCRM Deployment Status']);

            $token = trim((string) config('deployment.github.token'));
            if ($token !== '') {
                $client = $client->withToken($token);
            }

            $response = $client->get(sprintf(
                '/repos/%s/%s/commits',
                rawurlencode($owner),
                rawurlencode($repo)
            ), [
                'sha' => $trackedBranch,
                'per_page' => $perPage + 1,
                'page' => $page,
            ]);

            if (!$response->successful()) {
                return ['commits' => [], 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
            }

            $all = collect($response->json());
            $hasMore = $all->count() > $perPage;

            $commits = $all->take($perPage)->map(fn (array $commit) => [
                'sha' => $commit['sha'] ?? null,
                'short_sha' => isset($commit['sha']) ? $this->shortSha($commit['sha']) : null,
                'message' => (string) data_get($commit, 'commit.message', ''),
                'message_subject' => Str::before((string) data_get($commit, 'commit.message', ''), "\n"),
                'author' => data_get($commit, 'commit.author.name') ?: data_get($commit, 'author.login'),
                'authored_at' => data_get($commit, 'commit.author.date'),
                'url' => $commit['html_url'] ?? null,
            ])->values()->all();

            return [
                'commits' => $commits,
                'page' => $page,
                'per_page' => $perPage,
                'has_more' => $hasMore,
            ];
        } catch (\Throwable) {
            return ['commits' => [], 'page' => $page, 'per_page' => $perPage, 'has_more' => false];
        }
    }

    private function remoteCompare(?string $baseSha): array
    {
        $owner = (string) config('deployment.github.owner');
        $repo = (string) config('deployment.github.repo');
        $trackedBranch = $this->trackedBranch();

        if ($owner === '' || $repo === '') {
            return [
                'available' => false,
                'status' => 'unavailable',
                'message' => 'GitHub repository details are not configured.',
                'ahead_by' => 0,
                'commits' => [],
            ];
        }

        if ($baseSha === null || $baseSha === '') {
            return [
                'available' => false,
                'status' => 'unavailable',
                'message' => 'No deployed commit is available for comparison.',
                'ahead_by' => 0,
                'commits' => [],
            ];
        }

        try {
            $client = Http::baseUrl('https://api.github.com')
                ->timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'User-Agent' => 'ExoticCRM Deployment Status',
                ]);

            $token = trim((string) config('deployment.github.token'));
            if ($token !== '') {
                $client = $client->withToken($token);
            }

            $response = $client->get(sprintf(
                '/repos/%s/%s/compare/%s...%s',
                rawurlencode($owner),
                rawurlencode($repo),
                $baseSha,
                rawurlencode($trackedBranch)
            ));

            if (!$response->successful()) {
                return [
                    'available' => false,
                    'status' => 'degraded',
                    'message' => 'Remote compare is unavailable right now.',
                    'ahead_by' => 0,
                    'commits' => [],
                ];
            }

            $payload = $response->json();
            $commits = collect($payload['commits'] ?? [])
                ->take(5)
                ->map(fn (array $commit) => [
                    'sha' => $commit['sha'] ?? null,
                    'short_sha' => isset($commit['sha']) ? $this->shortSha($commit['sha']) : null,
                    'message' => Str::before((string) data_get($commit, 'commit.message', ''), "\n"),
                    'author' => data_get($commit, 'commit.author.name') ?: data_get($commit, 'author.login'),
                    'authored_at' => data_get($commit, 'commit.author.date'),
                    'url' => $commit['html_url'] ?? null,
                ])
                ->values()
                ->all();

            return [
                'available' => true,
                'status' => 'ready',
                'message' => ((int) ($payload['ahead_by'] ?? 0)) > 0
                    ? sprintf('%d commit(s) are waiting to deploy.', (int) ($payload['ahead_by'] ?? 0))
                    : 'Deployed version matches the tracked branch.',
                'ahead_by' => (int) ($payload['ahead_by'] ?? 0),
                'commits' => $commits,
                'compare_url' => $payload['html_url'] ?? null,
            ];
        } catch (\Throwable $exception) {
            return [
                'available' => false,
                'status' => 'degraded',
                'message' => 'Remote compare is unavailable right now.',
                'ahead_by' => 0,
                'commits' => [],
            ];
        }
    }

    private function currentCheckoutVersion(): array
    {
        [$branch, $sha] = $this->readGitHead();

        return [
            'sha' => $sha,
            'short_sha' => $sha ? $this->shortSha($sha) : null,
            'branch' => $branch ?: $this->trackedBranch(),
        ];
    }

    private function readGitHead(): array
    {
        $gitDir = $this->resolvedGitDir();
        $headPath = $gitDir . DIRECTORY_SEPARATOR . 'HEAD';

        if (!is_file($headPath)) {
            return [null, null];
        }

        $head = trim((string) File::get($headPath));
        if (str_starts_with($head, 'ref: ')) {
            $ref = trim(Str::after($head, 'ref: '));
            $sha = $this->readGitRef($gitDir, $ref);

            return [basename($ref), $sha];
        }

        return [null, $head !== '' ? $head : null];
    }

    private function readGitRef(string $gitDir, string $ref): ?string
    {
        $refPath = $gitDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $ref);
        if (is_file($refPath)) {
            return trim((string) File::get($refPath));
        }

        $packedRefsPath = $gitDir . DIRECTORY_SEPARATOR . 'packed-refs';
        if (!is_file($packedRefsPath)) {
            return null;
        }

        foreach (file($packedRefsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#') || str_starts_with($line, '^')) {
                continue;
            }

            [$sha, $packedRef] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, null);
            if ($packedRef === $ref) {
                return $sha;
            }
        }

        return null;
    }

    private function resolvedGitDir(): string
    {
        $path = (string) config('deployment.git_dir', base_path('.git'));
        if (is_file($path)) {
            $contents = trim((string) File::get($path));
            if (str_starts_with($contents, 'gitdir:')) {
                $gitDir = trim(Str::after($contents, 'gitdir:'));

                if (str_starts_with($gitDir, DIRECTORY_SEPARATOR)) {
                    return $gitDir;
                }

                return realpath(dirname($path) . DIRECTORY_SEPARATOR . $gitDir)
                    ?: dirname($path) . DIRECTORY_SEPARATOR . $gitDir;
            }
        }

        return $path;
    }

    private function buildManualDeployCommand(?User $user, ?string $reason): string
    {
        $env = [
            'DEPLOY_TRIGGER_SOURCE' => 'manual',
            'DEPLOY_REQUESTED_BY_ID' => $user?->id,
            'DEPLOY_REQUESTED_BY_NAME' => $user?->name,
            'DEPLOY_REQUESTED_BY_EMAIL' => $user?->email,
            'DEPLOY_REASON' => $this->normalizeReason($reason),
            'DEPLOY_STATUS_FILE' => $this->statusPath(),
            'DEPLOY_LOG_FILE' => $this->logPath(),
            'DEPLOY_LOCK_FILE' => $this->lockPath(),
            'DEPLOY_REPOSITORY_PATH' => $this->repositoryPath(),
            'APP_ENV' => app()->environment(),
        ];

        $databaseConfig = config('database.connections.' . config('database.default'), []);
        foreach ([
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => $databaseConfig['host'] ?? null,
            'DB_PORT' => $databaseConfig['port'] ?? null,
            'DB_DATABASE' => $databaseConfig['database'] ?? null,
            'DB_USERNAME' => $databaseConfig['username'] ?? null,
            'DB_PASSWORD' => $databaseConfig['password'] ?? null,
        ] as $key => $value) {
            $env[$key] = $value;
        }

        $pairs = collect($env)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value, $key) => sprintf('%s=%s', $key, escapeshellarg((string) $value)))
            ->implode(' ');

        return sprintf(
            'env %s /bin/bash %s > /dev/null 2>&1 & echo $!',
            $pairs,
            escapeshellarg($this->scriptPath())
        );
    }

    private function ensureDeploymentDirectory(): void
    {
        File::ensureDirectoryExists(dirname($this->statusPath()));
        File::ensureDirectoryExists(dirname($this->logPath()));
    }

    private function readStatusFile(): array
    {
        $path = $this->statusPath();
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) File::get($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function writeStatusFile(array $payload): void
    {
        $this->ensureDeploymentDirectory();
        File::put($this->statusPath(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function tail(string $path, int $lines = 80): array
    {
        if (!is_file($path)) {
            return [];
        }

        $content = file($path, FILE_IGNORE_NEW_LINES);
        if ($content === false) {
            return [];
        }

        return array_values(array_slice($content, max($lines * -1, -count($content))));
    }

    private function serializeUser(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }

    private function shortSha(?string $sha): ?string
    {
        return $sha ? substr($sha, 0, 8) : null;
    }

    private function normalizeReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }

    private function trackedBranch(): string
    {
        return (string) config('deployment.tracked_branch', 'main');
    }

    private function scriptPath(): string
    {
        return (string) config('deployment.script_path');
    }

    private function statusPath(): string
    {
        return (string) config('deployment.status_path');
    }

    private function logPath(): string
    {
        return (string) config('deployment.log_path');
    }

    private function lockPath(): string
    {
        return (string) config('deployment.lock_path');
    }

    private function repositoryPath(): string
    {
        return (string) config('deployment.repository_path', base_path());
    }

    private function manualDeployEnabled(): bool
    {
        return (bool) config('deployment.manual_enabled', true);
    }

    private function commandExecutionAvailable(): bool
    {
        if (!function_exists('exec')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return !in_array('exec', $disabled, true);
    }
}
