<?php

return [
    'enabled' => env('DB_CONTAINMENT_ENABLED', false),
    'filesystem_enabled' => env('DB_CONTAINMENT_FILESYSTEM_ENABLED', false),
    'quarantine_enabled' => env('DB_CONTAINMENT_QUARANTINE_ENABLED', false),
    'key' => env('DB_CONTAINMENT_BACKUP_KEY'),
    'key_version' => env('DB_CONTAINMENT_KEY_VERSION', '1'),
    'retained_keys' => [],
    'vault' => storage_path('app/private/db-containment'),
    'policy_version' => '1',
    'approval_seconds' => 300,
    'retention_days' => 7,
    'lease_seconds' => 180,
    'queue' => 'db-containment',
    'hidden_link_hosts' => ['aviator-game.com.gh', 'aviator-bet.co.ke'],
    'shell_paths' => ['moon.php', 'wp-includes/customize/moon.php', 'wp-admin/css/colors/coffee/server.php', 'wp-admin/css/colors/themes.php', 'wp-includes/style-engine/license.php', 'wp-includes/wp-includes.php', 'wp-admin/app.php', 'wp-admin/lib.php', 'wp-includes/css/dist/block-directory/module.php'],
];
