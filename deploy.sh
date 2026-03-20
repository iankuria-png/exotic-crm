#!/bin/bash

set -euo pipefail

APP_DIR="${DEPLOY_REPOSITORY_PATH:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)}"
cd "$APP_DIR"

STATUS_FILE="${DEPLOY_STATUS_FILE:-$APP_DIR/storage/app/deployment/status.json}"
LOG_FILE="${DEPLOY_LOG_FILE:-$APP_DIR/storage/app/deployment/latest.log}"
LOCK_FILE="${DEPLOY_LOCK_FILE:-$APP_DIR/storage/app/deployment/deploy.lock}"
DEPLOY_TRIGGER_SOURCE="${DEPLOY_TRIGGER_SOURCE:-cpanel}"
DEPLOY_REASON="${DEPLOY_REASON:-}"
DEPLOY_REQUESTED_BY_ID="${DEPLOY_REQUESTED_BY_ID:-}"
DEPLOY_REQUESTED_BY_NAME="${DEPLOY_REQUESTED_BY_NAME:-}"
DEPLOY_REQUESTED_BY_EMAIL="${DEPLOY_REQUESTED_BY_EMAIL:-}"
DEPLOY_BRANCH="${DEPLOY_TRACKED_BRANCH:-main}"
HISTORY_FILE="${DEPLOY_HISTORY_FILE:-$APP_DIR/storage/app/deployment/history.json}"
DEPLOY_HISTORY_MAX="${DEPLOY_HISTORY_MAX:-20}"
ROLLBACK_TARGET_SHA="${ROLLBACK_TARGET_SHA:-}"
ROLLBACK_DB_BACKUP="${ROLLBACK_DB_BACKUP:-}"

mkdir -p "$(dirname "$STATUS_FILE")"
mkdir -p "$(dirname "$LOG_FILE")"
mkdir -p "$(dirname "$LOCK_FILE")"

exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    echo "Another deployment is already in progress." >&2
    exit 1
fi

: > "$LOG_FILE"
exec > >(tee -a "$LOG_FILE") 2>&1

CURRENT_COMMIT="$(git rev-parse HEAD 2>/dev/null || true)"
PREVIOUS_COMMIT="$CURRENT_COMMIT"
CURRENT_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || printf '%s' "$DEPLOY_BRANCH")"
CURRENT_SHORT_COMMIT="${CURRENT_COMMIT:0:8}"
STARTED_AT="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"

write_status() {
    DEPLOY_STATUS_FILE_RUNTIME="$STATUS_FILE" \
    DEPLOY_STATE_RUNTIME="$1" \
    DEPLOY_IN_PROGRESS_RUNTIME="$2" \
    DEPLOY_MESSAGE_RUNTIME="$3" \
    DEPLOY_FINISHED_AT_RUNTIME="${4:-}" \
    DEPLOY_DEPLOYED_AT_RUNTIME="${5:-}" \
    DEPLOY_LAST_SUCCESS_JSON_RUNTIME="${6:-}" \
    DEPLOY_PID_RUNTIME="$$" \
    DEPLOY_TRIGGER_SOURCE_RUNTIME="$DEPLOY_TRIGGER_SOURCE" \
    DEPLOY_BRANCH_RUNTIME="$CURRENT_BRANCH" \
    DEPLOY_COMMIT_RUNTIME="$CURRENT_COMMIT" \
    DEPLOY_SHORT_COMMIT_RUNTIME="$CURRENT_SHORT_COMMIT" \
    DEPLOY_STARTED_AT_RUNTIME="$STARTED_AT" \
    DEPLOY_REQUESTED_BY_ID_RUNTIME="$DEPLOY_REQUESTED_BY_ID" \
    DEPLOY_REQUESTED_BY_NAME_RUNTIME="$DEPLOY_REQUESTED_BY_NAME" \
    DEPLOY_REQUESTED_BY_EMAIL_RUNTIME="$DEPLOY_REQUESTED_BY_EMAIL" \
    DEPLOY_REASON_RUNTIME="$DEPLOY_REASON" \
    php <<'PHP'
<?php
$path = getenv('DEPLOY_STATUS_FILE_RUNTIME');
$existing = [];
if (is_file($path)) {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (is_array($decoded)) {
        $existing = $decoded;
    }
}

$requestedBy = null;
if (getenv('DEPLOY_REQUESTED_BY_ID_RUNTIME') || getenv('DEPLOY_REQUESTED_BY_NAME_RUNTIME') || getenv('DEPLOY_REQUESTED_BY_EMAIL_RUNTIME')) {
    $requestedBy = [
        'id' => getenv('DEPLOY_REQUESTED_BY_ID_RUNTIME') !== '' ? (int) getenv('DEPLOY_REQUESTED_BY_ID_RUNTIME') : null,
        'name' => getenv('DEPLOY_REQUESTED_BY_NAME_RUNTIME') ?: null,
        'email' => getenv('DEPLOY_REQUESTED_BY_EMAIL_RUNTIME') ?: null,
    ];
}

$payload = [
    'state' => getenv('DEPLOY_STATE_RUNTIME') ?: 'running',
    'in_progress' => filter_var(getenv('DEPLOY_IN_PROGRESS_RUNTIME'), FILTER_VALIDATE_BOOLEAN),
    'trigger_source' => getenv('DEPLOY_TRIGGER_SOURCE_RUNTIME') ?: 'cpanel',
    'branch' => getenv('DEPLOY_BRANCH_RUNTIME') ?: null,
    'commit_sha' => getenv('DEPLOY_COMMIT_RUNTIME') ?: null,
    'commit_short' => getenv('DEPLOY_SHORT_COMMIT_RUNTIME') ?: null,
    'started_at' => getenv('DEPLOY_STARTED_AT_RUNTIME') ?: null,
    'finished_at' => getenv('DEPLOY_FINISHED_AT_RUNTIME') ?: null,
    'deployed_at' => getenv('DEPLOY_DEPLOYED_AT_RUNTIME') ?: null,
    'message' => getenv('DEPLOY_MESSAGE_RUNTIME') ?: null,
    'requested_by' => $requestedBy,
    'reason' => getenv('DEPLOY_REASON_RUNTIME') ?: null,
    'pid' => getenv('DEPLOY_PID_RUNTIME') !== '' ? (int) getenv('DEPLOY_PID_RUNTIME') : null,
    'last_successful_deploy' => $existing['last_successful_deploy'] ?? null,
];

$lastSuccessJson = getenv('DEPLOY_LAST_SUCCESS_JSON_RUNTIME');
if ($lastSuccessJson) {
    $lastSuccess = json_decode($lastSuccessJson, true);
    if (is_array($lastSuccess)) {
        $payload['last_successful_deploy'] = $lastSuccess;
    }
}

file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP
}

record_manual_audit() {
    local status="$1"
    local message="$2"

    if [[ "$DEPLOY_TRIGGER_SOURCE" != "manual" ]] || [[ -z "$DEPLOY_REQUESTED_BY_ID" ]]; then
        return 0
    fi

    php artisan crm:record-deploy-audit "$status" \
        --user-id="$DEPLOY_REQUESTED_BY_ID" \
        --source="$DEPLOY_TRIGGER_SOURCE" \
        --branch="$CURRENT_BRANCH" \
        --commit="$CURRENT_COMMIT" \
        --reason="$DEPLOY_REASON" \
        --message="$message" >/dev/null 2>&1 || true
}

backup_database() {
    php <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$connection = config('database.default');
$config = config("database.connections.$connection", []);

if (($config['driver'] ?? null) !== 'mysql') {
    echo "Skipping DB backup: only mysql connections are supported.\n";
    exit(0);
}

$binary = function_exists('shell_exec') ? trim((string) shell_exec('command -v mysqldump 2>/dev/null')) : '';
if ($binary === '') {
    echo "Skipping DB backup: mysqldump is unavailable.\n";
    exit(0);
}

$backupDir = storage_path('app/deployment/backups');
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0775, true);
}

$timestamp = gmdate('Ymd_His');
$database = $config['database'] ?? null;
if (!$database) {
    echo "Skipping DB backup: database name is unavailable.\n";
    exit(0);
}

$backupPath = $backupDir . DIRECTORY_SEPARATOR . $database . '_' . $timestamp . '.sql';
$parts = [
    escapeshellarg($binary),
    '--single-transaction',
    '--quick',
    '--lock-tables=false',
];

foreach ([
    'host' => '--host',
    'port' => '--port',
    'username' => '--user',
] as $key => $flag) {
    if (!empty($config[$key])) {
        $parts[] = $flag . '=' . escapeshellarg((string) $config[$key]);
    }
}

if (array_key_exists('password', $config) && $config['password'] !== null) {
    $parts[] = '--password=' . escapeshellarg((string) $config['password']);
}

$parts[] = escapeshellarg((string) $database);
$command = implode(' ', $parts) . ' > ' . escapeshellarg($backupPath);

passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Database backup failed.\n");
    exit($exitCode);
}

echo "Database backup created at {$backupPath}\n";
PHP
}

record_history() {
    DEPLOY_HISTORY_FILE_RUNTIME="$HISTORY_FILE" \
    DEPLOY_HISTORY_MAX_RUNTIME="$DEPLOY_HISTORY_MAX" \
    DEPLOY_HISTORY_SHA_RUNTIME="$CURRENT_COMMIT" \
    DEPLOY_HISTORY_BRANCH_RUNTIME="$CURRENT_BRANCH" \
    DEPLOY_HISTORY_DEPLOYED_AT_RUNTIME="$1" \
    DEPLOY_HISTORY_TRIGGER_RUNTIME="$DEPLOY_TRIGGER_SOURCE" \
    DEPLOY_HISTORY_BY_ID_RUNTIME="$DEPLOY_REQUESTED_BY_ID" \
    DEPLOY_HISTORY_BY_NAME_RUNTIME="$DEPLOY_REQUESTED_BY_NAME" \
    DEPLOY_HISTORY_BY_EMAIL_RUNTIME="$DEPLOY_REQUESTED_BY_EMAIL" \
    DEPLOY_HISTORY_PREV_SHA_RUNTIME="${PREVIOUS_COMMIT:-}" \
    php <<'PHP'
<?php
$path = getenv('DEPLOY_HISTORY_FILE_RUNTIME');
$maxEntries = (int) getenv('DEPLOY_HISTORY_MAX_RUNTIME') ?: 20;

$dir = dirname($path);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$existing = [];
if (is_file($path)) {
    $decoded = json_decode((string) file_get_contents($path), true);
    if (is_array($decoded) && isset($decoded['deployments'])) {
        $existing = $decoded['deployments'];
    }
}

$sha = getenv('DEPLOY_HISTORY_SHA_RUNTIME');
$deployedAt = getenv('DEPLOY_HISTORY_DEPLOYED_AT_RUNTIME');
$timestamp = str_replace(['-', 'T', ':', 'Z'], ['', '_', '', ''], $deployedAt);
$id = 'dep_' . substr($timestamp, 0, 15);

$requestedBy = null;
if (getenv('DEPLOY_HISTORY_BY_ID_RUNTIME') || getenv('DEPLOY_HISTORY_BY_NAME_RUNTIME')) {
    $requestedBy = [
        'id' => getenv('DEPLOY_HISTORY_BY_ID_RUNTIME') !== '' ? (int) getenv('DEPLOY_HISTORY_BY_ID_RUNTIME') : null,
        'name' => getenv('DEPLOY_HISTORY_BY_NAME_RUNTIME') ?: null,
        'email' => getenv('DEPLOY_HISTORY_BY_EMAIL_RUNTIME') ?: null,
    ];
}

$entry = [
    'id' => $id,
    'sha' => $sha,
    'short_sha' => substr((string) $sha, 0, 8),
    'branch' => getenv('DEPLOY_HISTORY_BRANCH_RUNTIME') ?: 'main',
    'deployed_at' => $deployedAt,
    'trigger_source' => getenv('DEPLOY_HISTORY_TRIGGER_RUNTIME') ?: 'cpanel',
    'requested_by' => $requestedBy,
    'status' => 'success',
    'previous_sha' => getenv('DEPLOY_HISTORY_PREV_SHA_RUNTIME') ?: null,
];

array_unshift($existing, $entry);
$existing = array_slice($existing, 0, $maxEntries);

file_put_contents($path, json_encode(['deployments' => $existing], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP
}

finish_success() {
    local finished_at deployed_at last_success_json
    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    deployed_at="$finished_at"
    last_success_json="$(
        CURRENT_COMMIT_ENV="$CURRENT_COMMIT" \
        CURRENT_BRANCH_ENV="$CURRENT_BRANCH" \
        DEPLOYED_AT_ENV="$deployed_at" \
        DEPLOY_TRIGGER_SOURCE_ENV="$DEPLOY_TRIGGER_SOURCE" \
        php <<'PHP'
<?php
echo json_encode([
    'sha' => getenv('CURRENT_COMMIT_ENV'),
    'short_sha' => substr((string) getenv('CURRENT_COMMIT_ENV'), 0, 8),
    'branch' => getenv('CURRENT_BRANCH_ENV'),
    'deployed_at' => getenv('DEPLOYED_AT_ENV'),
    'trigger_source' => getenv('DEPLOY_TRIGGER_SOURCE_ENV'),
    'status' => 'success',
], JSON_UNESCAPED_SLASHES);
PHP
    )"
    write_status "success" "false" "Deployment completed successfully." "$finished_at" "$deployed_at" "$last_success_json"
    record_history "$deployed_at"
    record_manual_audit "success" "Deployment completed successfully."
}

restore_database() {
    local backup_path="$1"
    RESTORE_BACKUP_PATH_RUNTIME="$backup_path" \
    php <<'PHP'
<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$backupPath = getenv('RESTORE_BACKUP_PATH_RUNTIME');
if (!is_file($backupPath)) {
    fwrite(STDERR, "Database backup file not found: {$backupPath}\n");
    exit(1);
}

$connection = config('database.default');
$config = config("database.connections.$connection", []);

if (($config['driver'] ?? null) !== 'mysql') {
    fwrite(STDERR, "Database restore: only mysql connections are supported.\n");
    exit(1);
}

$binary = function_exists('shell_exec') ? trim((string) shell_exec('command -v mysql 2>/dev/null')) : '';
if ($binary === '') {
    fwrite(STDERR, "Database restore: mysql client is unavailable.\n");
    exit(1);
}

$database = $config['database'] ?? null;
if (!$database) {
    fwrite(STDERR, "Database restore: database name is unavailable.\n");
    exit(1);
}

$parts = [escapeshellarg($binary)];
foreach ([
    'host' => '--host',
    'port' => '--port',
    'username' => '--user',
] as $key => $flag) {
    if (!empty($config[$key])) {
        $parts[] = $flag . '=' . escapeshellarg((string) $config[$key]);
    }
}
if (array_key_exists('password', $config) && $config['password'] !== null) {
    $parts[] = '--password=' . escapeshellarg((string) $config['password']);
}
$parts[] = escapeshellarg((string) $database);
$command = implode(' ', $parts) . ' < ' . escapeshellarg($backupPath);

passthru($command, $exitCode);
if ($exitCode !== 0) {
    fwrite(STDERR, "Database restore failed.\n");
    exit($exitCode);
}

echo "Database restored from {$backupPath}\n";
PHP
}

finish_failure() {
    local message="$1"
    local finished_at
    finished_at="$(date -u +"%Y-%m-%dT%H:%M:%SZ")"
    write_status "failed" "false" "$message" "$finished_at" ""
    record_manual_audit "failed" "$message"
}

trap 'finish_failure "Deployment failed. Review latest.log for details."' ERR

write_status "running" "true" "Deployment is in progress." "" ""

echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Starting deployment from ${DEPLOY_TRIGGER_SOURCE}."
echo "Repository: $APP_DIR"
echo "Branch: ${CURRENT_BRANCH}"
echo "Commit: ${CURRENT_COMMIT}"

GITHUB_TOKEN="${GITHUB_TOKEN:-}"

git_fetch() {
    if [[ -n "$GITHUB_TOKEN" ]]; then
        git -c credential.helper="!f() { echo \"password=$GITHUB_TOKEN\"; echo \"username=x-access-token\"; }; f" \
            fetch origin "$DEPLOY_BRANCH"
    else
        git fetch origin "$DEPLOY_BRANCH"
    fi
}

if [[ -n "$ROLLBACK_TARGET_SHA" ]]; then
    echo "Rolling back to commit: ${ROLLBACK_TARGET_SHA}"
    write_status "rolling_back" "true" "Rolling back to ${ROLLBACK_TARGET_SHA:0:8}..." "" ""

    git_fetch
    git reset --hard "$ROLLBACK_TARGET_SHA"

    CURRENT_COMMIT="$(git rev-parse HEAD 2>/dev/null || true)"
    CURRENT_SHORT_COMMIT="${CURRENT_COMMIT:0:8}"
    echo "Checked out commit: ${CURRENT_COMMIT}"

    composer install --no-interaction --prefer-dist --optimize-autoloader

    if [[ -n "$ROLLBACK_DB_BACKUP" ]]; then
        echo "Restoring database from backup: ${ROLLBACK_DB_BACKUP}"
        restore_database "$ROLLBACK_DB_BACKUP"
    else
        echo "Skipping database restore (app-only rollback)."
        php artisan migrate --force
    fi

    php artisan optimize:clear
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    php artisan queue:restart

    finish_success
    echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Rollback completed successfully."
    exit 0
fi

echo "Pulling latest changes from origin/${DEPLOY_BRANCH}..."
git_fetch
git reset --hard "origin/$DEPLOY_BRANCH"

CURRENT_COMMIT="$(git rev-parse HEAD 2>/dev/null || true)"
CURRENT_SHORT_COMMIT="${CURRENT_COMMIT:0:8}"
echo "Updated to commit: ${CURRENT_COMMIT}"

composer install --no-interaction --prefer-dist --optimize-autoloader
backup_database
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart

finish_success
echo "[$(date -u +"%Y-%m-%dT%H:%M:%SZ")] Deployment finished successfully."
