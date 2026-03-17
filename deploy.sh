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
    record_manual_audit "success" "Deployment completed successfully."
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
