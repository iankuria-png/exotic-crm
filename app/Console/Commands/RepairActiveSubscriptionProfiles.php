<?php

namespace App\Console\Commands;

use App\Services\ActiveSubscriptionProfileRepairService;
use Illuminate\Console\Command;

class RepairActiveSubscriptionProfiles extends Command
{
    protected $signature = 'crm:repair-active-subscription-profiles
        {--apply : Write repairs. Without this flag the command is a dry-run}
        {--limit=200 : Maximum number of affected profiles to inspect}
        {--platform= : Restrict to a single platform id}
        {--client= : Repair one CRM client id}
        {--wp-post-id= : Repair one WordPress post id}';

    protected $description = 'Repair CRM profiles that still look expired/offline even though they have a future active subscription.';

    public function handle(ActiveSubscriptionProfileRepairService $repair): int
    {
        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;
        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;
        $wpPostId = $this->option('wp-post-id') !== null ? (int) $this->option('wp-post-id') : null;

        $clients = $repair->affectedClients($platformId, $clientId, $wpPostId, $limit);

        $this->info(sprintf(
            'Repairing active subscription profile drift (%s)%s%s%s, limit %d.',
            $apply ? 'LIVE' : 'DRY-RUN',
            $platformId ? " platform #{$platformId}" : '',
            $clientId ? " client #{$clientId}" : '',
            $wpPostId ? " WP post #{$wpPostId}" : '',
            $limit
        ));
        $this->info("Found {$clients->count()} affected profile(s).");

        $summary = ['repaired' => 0, 'would_repair' => 0, 'already_ok' => 0, 'skipped' => 0];

        foreach ($clients as $client) {
            $row = $repair->repairClient($client, null, ! $apply, 'artisan_repair_active_subscription_profiles');
            $action = (string) ($row['action'] ?? 'skipped');
            $summary[$action] = ($summary[$action] ?? 0) + 1;

            $this->line(sprintf(
                '  [%s] client #%d WP #%d %s deal #%s expires %s -> %s%s',
                $row['market'] ?? $client->platform_id,
                $row['client_id'] ?? $client->id,
                $row['wp_post_id'] ?? $client->wp_post_id,
                $row['name'] ?? $client->name,
                $row['deal_id'] ?? '-',
                $row['expires_at'] ?? '-',
                $action,
                ! empty($row['changes']) ? ' ('.implode(', ', $row['changes']).')' : ''
            ));
        }

        $this->info(sprintf(
            'Done. repaired=%d would_repair=%d already_ok=%d skipped=%d.',
            (int) ($summary['repaired'] ?? 0),
            (int) ($summary['would_repair'] ?? 0),
            (int) ($summary['already_ok'] ?? 0),
            (int) ($summary['skipped'] ?? 0),
        ));

        if (! $apply && $clients->isNotEmpty()) {
            $this->warn('Dry-run only. Re-run with --apply to write these repairs.');
        }

        if (($clientId || $wpPostId) && $clients->isEmpty()) {
            $this->warn('No matching drift row found. Check the client id / WP post id and whether the deal is active with a future expires_at.');
        }

        return self::SUCCESS;
    }
}
