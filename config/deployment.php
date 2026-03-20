<?php

return [
    'php_binary' => env('DEPLOY_PHP_BINARY', 'php'),
    'script_path' => env('DEPLOY_SCRIPT_PATH', base_path('deploy.sh')),
    'tracked_branch' => env('DEPLOY_TRACKED_BRANCH', 'main'),
    'manual_enabled' => env('DEPLOY_MANUAL_ENABLED', true),
    'status_path' => env('DEPLOY_STATUS_PATH', storage_path('app/deployment/status.json')),
    'log_path' => env('DEPLOY_LOG_PATH', storage_path('app/deployment/latest.log')),
    'lock_path' => env('DEPLOY_LOCK_PATH', storage_path('app/deployment/deploy.lock')),
    'git_dir' => env('DEPLOY_GIT_DIR', base_path('.git')),
    'repository_path' => env('DEPLOY_REPOSITORY_PATH', base_path()),
    'history_path' => env('DEPLOY_HISTORY_PATH', storage_path('app/deployment/history.json')),
    'history_max_entries' => env('DEPLOY_HISTORY_MAX', 20),
    'db_backups_path' => env('DEPLOY_DB_BACKUPS_PATH', storage_path('app/deployment/backups')),
    'github' => [
        'owner' => env('GITHUB_REPO_OWNER'),
        'repo' => env('GITHUB_REPO_NAME'),
        'token' => env('GITHUB_TOKEN'),
    ],
];
