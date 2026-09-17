<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\ClientSyncExclusion;
use App\Services\ClientDeletionService;
use Illuminate\Console\Command;

class ReconcilePendingClientDeletions extends Command
{
    protected $signature = 'crm:reconcile-pending-client-deletions
        {--dry-run : List pending deletions without retrying them}
        {--retry : Probe WordPress and retry the CRM deletion}';

    protected $description = 'List or retry durable client deletions whose source result was ambiguous or whose CRM cleanup failed.';

    public function handle(ClientDeletionService $deletions): int
    {
        $rows = ClientSyncExclusion::query()
            ->where('reason', 'like', '[pending_client_delete]%')
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No pending client deletions.');

            return self::SUCCESS;
        }

        $retry = (bool) $this->option('retry') && ! (bool) $this->option('dry-run');
        $unresolved = 0;

        foreach ($rows as $row) {
            $client = Client::query()
                ->where('platform_id', $row->platform_id)
                ->where('wp_post_id', $row->wp_post_id)
                ->first();

            if (! $client) {
                $this->line("resolved/tombstone platform={$row->platform_id} wp_post_id={$row->wp_post_id}");

                continue;
            }

            if (! $retry) {
                $unresolved++;
                $this->warn("pending client={$client->id} platform={$row->platform_id} wp_post_id={$row->wp_post_id}");

                continue;
            }

            try {
                $result = $deletions->deleteClient(
                    $client,
                    (int) ($row->deleted_by ?? 0),
                    trim(str_replace('[pending_client_delete]', '', (string) $row->reason)) ?: 'Pending client deletion reconciliation',
                );
            } catch (\Throwable $exception) {
                $unresolved++;
                $this->error("retry client={$client->id} failed: {$exception->getMessage()}");

                continue;
            }
            if (! ($result['deleted'] ?? false)) {
                $unresolved++;
            }
            $this->line(sprintf(
                'retry client=%d state=%s',
                (int) $client->id,
                (string) ($result['state'] ?? 'unknown'),
            ));
        }

        $this->line("Unresolved pending deletions: {$unresolved}");

        return $unresolved > 0 ? self::FAILURE : self::SUCCESS;
    }
}
