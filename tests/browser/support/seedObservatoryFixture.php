<?php

// Disposable browser fixtures only. Never connects to a configured market database.
require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = config('database.connections.sqlite.database');
if (! app()->environment('testing') || config('database.default') !== 'sqlite' || ! str_starts_with(basename((string) $db), 'observatory-experience-')) {
    throw new RuntimeException('Refusing to seed outside the disposable Observatory SQLite fixture.');
}
$output = getenv('OBSERVATORY_FIXTURE_OUTPUT');
if (! $output) {
    throw new RuntimeException('A private fixture output path is required.');
}
app(App\Services\DbScanner\PackSynchronizer::class)->sync();
App\Models\DbScanSetting::current()->forceFill(['enabled' => true])->save();
$admin = App\Models\User::query()->create(['name' => 'Observatory Operator', 'email' => 'operator@example.test', 'password' => bcrypt(Illuminate\Support\Str::random(40)), 'role' => 'admin', 'status' => 'active']);
$sub = App\Models\User::query()->create(['name' => 'Scoped Reviewer', 'email' => 'reviewer@example.test', 'password' => bcrypt(Illuminate\Support\Str::random(40)), 'role' => 'sub_admin', 'status' => 'active']);
$platforms = [];
foreach (['Egypt' => 'egypt.example.test', 'Namibia' => 'namibia.example.test', 'UG' => 'ug.example.test'] as $name => $domain) {
    $platforms[$name] = App\Models\Platform::query()->create(['name' => $name, 'domain' => 'https://'.$domain, 'country' => $name, 'currency_code' => 'USD', 'is_active' => true, 'timezone' => 'Africa/Nairobi', 'health_status' => 'healthy', 'health_checked_at' => now()]);
}
$sub->forceFill(['assigned_market_ids' => [$platforms['Egypt']->id]])->save();
$make = function ($p, array $extra = []) {
    return App\Models\DbScanFinding::query()->create(array_replace([
        'platform_id' => $p->id, 'fingerprint' => hash('sha256', Illuminate\Support\Str::uuid()), 'rule_key' => 'access.account_campaign', 'rule_version_hash' => str_repeat('a', 64), 'pack' => 'core', 'pack_version' => '1', 'category' => 'access', 'confidence' => 'strong', 'severity' => 'critical', 'title' => 'Account logins match a campaign', 'subject' => ['object_id' => 474, 'table' => 'activity_log', 'surface' => 'activity.behaviour'], 'subject_hash' => hash('sha256', Illuminate\Support\Str::uuid()), 'evidence' => ['details' => ['login' => 'Moody', 'at_utc' => '2026-09-10T22:26:41Z']], 'status' => 'open', 'first_seen_at' => now(), 'last_seen_at' => now()->subHour(),
    ], $extra));
};
$account = $make($platforms['Egypt']);
$other = $make($platforms['Namibia'], ['evidence' => ['details' => ['login' => 'Other-market account', 'at_utc' => '2026-09-11T02:00:00Z']]]);
$correlations = [];
foreach (['Egypt', 'Namibia'] as $name) {
    $correlations[] = $make($platforms[$name], ['rule_key' => 'malware.reinfection_cluster', 'title' => 'Shared successful IP observed across markets', 'category' => 'malware', 'behavior' => 'cross_market_campaign', 'evidence' => ['details' => ['indicator_type' => 'successful_ip', 'indicator' => '192.0.2.25', 'platform_ids' => [$platforms['Egypt']->id, $platforms['Namibia']->id], 'linked_finding_ids' => [$account->id, $other->id]]]])->id;
}
$script = $make($platforms['Egypt'], ['rule_key' => 'malware.remote_loader', 'title' => 'Stored third-party script needs ownership review', 'category' => 'malware', 'behavior' => 'third_party_widget', 'confidence' => 'needs_review', 'severity' => 'warn', 'subject' => ['surface' => 'options.values', 'table' => 'wp_options', 'row_id' => 79, 'field' => 'option_value[4][text]', 'label' => 'widget_text'], 'evidence' => ['excerpts' => ['<script>alert("inert evidence")</script>'], 'details' => ['remote_host' => 'scripts.example.test']]]);
foreach (['sales', 'seo'] as $role) {
    $make($platforms['Egypt'], ['rule_key' => 'access.role_grants_admin_capability', 'title' => 'Role grants administrative capability', 'severity' => 'warn', 'evidence' => ['details' => ['role' => $role, 'definition_hash' => 'same-capabilities', 'members' => 3, 'privileged_caps' => ['install_plugins', 'promote_users']]]]);
}
$daily = $make($platforms['Egypt'], ['rule_key' => 'access.activity_behaviour', 'title' => 'Daily failed-login pressure across high-volume IPs', 'severity' => 'warn', 'evidence' => ['details' => ['at_utc' => '2026-09-12T00:00:00Z', 'attempts' => 45]]]);
$pass = App\Models\DbScanPass::query()->create(['trigger' => 'manual', 'mode' => 'scan', 'profile' => 'deep', 'status' => 'completed_with_gaps', 'scope' => ['platform_ids' => [$platforms['Egypt']->id]], 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(5)]);
$sweep = App\Models\DbScanSweep::query()->create(['platform_id' => $platforms['Egypt']->id, 'config_version_id' => 1, 'profile' => 'deep', 'status' => 'complete_with_gaps', 'finished_at' => now()->subMinutes(5)]);
$run = App\Models\DbScanMarketRun::query()->create(['pass_id' => $pass->id, 'platform_id' => $platforms['Egypt']->id, 'sweep_id' => $sweep->id, 'profile' => 'deep', 'mode' => 'scan', 'status' => 'completed_with_gaps', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(5), 'heartbeat_at' => now()->subMinutes(5), 'metrics' => ['rows_read' => 1234, 'queries' => 40], 'cursor' => ['surfaces' => ['posts.content' => ['status' => 'complete', 'rows' => 1234]], 'inventory' => ['done' => true, 'surfaces' => []]]]);
App\Models\DbScanSurfaceCoverage::query()->create(['market_run_id' => $run->id, 'surface_key' => 'filesystem.visibility', 'adapter_version' => 'fixture-1', 'status' => 'excluded', 'reason' => 'filesystem_not_inspected']);
$auth = ['admin' => ['token' => $admin->createToken('browser-fixture', ['*'])->plainTextToken, 'user' => $admin->toArray()], 'sub_admin' => ['token' => $sub->createToken('browser-fixture', ['*'])->plainTextToken, 'user' => $sub->toArray()], 'account_id' => $account->id, 'script_id' => $script->id, 'correlation_ids' => $correlations, 'daily_id' => $daily->id, 'run_id' => $run->id];
file_put_contents($output, json_encode($auth, JSON_THROW_ON_ERROR));
chmod($output, 0600);
echo "Disposable Observatory fixture seeded.\n";
