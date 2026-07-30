<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ProfileBioScrubService;
use Illuminate\Console\Command;

/**
 * Backfill: redact contact details from the bios of profiles that are already
 * Expired or Archived — including everything that lapsed before the scrubber
 * existed. New expiries are scrubbed automatically by the reconciler.
 */
class ScrubProfileBios extends Command
{
    protected $signature = 'crm:scrub-bios
        {--platform= : Restrict to a single platform id}
        {--client= : Scrub one client id only}
        {--limit=500 : Maximum number of profiles to process this run}
        {--dry-run : Report what would be redacted without writing anything}';

    protected $description = 'Redact contact details from the bios of Expired/Archived profiles on lifecycle markets.';

    public function handle(ProfileBioScrubService $scrubber): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;
        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;

        // Single client: bypass the batch query so a specific profile can be
        // re-checked on demand (useful for verification and spot fixes).
        if ($clientId) {
            $client = Client::query()->find($clientId);
            if (! $client) {
                $this->error("Client #{$clientId} not found.");

                return self::FAILURE;
            }

            $row = $scrubber->scrub($client, null, $dryRun);
            $this->info(sprintf(
                'client #%d %s → %s (%d redaction(s)%s)',
                $client->id,
                (string) $client->name,
                $row['action'],
                $row['redactions'],
                $row['kinds'] ? ': ' . implode(', ', array_keys($row['kinds'])) : ''
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scrubbing bios of Expired/Archived profiles%s (%s, limit %d).',
            $platformId ? " on platform #{$platformId}" : '',
            $dryRun ? 'DRY-RUN' : 'LIVE',
            $limit
        ));

        $summary = $scrubber->runBatch(
            $platformId,
            $limit,
            $dryRun,
            null,
            function (array $row, Client $client): void {
                if (in_array($row['action'] ?? '', ['scrubbed', 'would_scrub'], true)) {
                    $this->line(sprintf(
                        '  %s #%d %s — %d redaction(s): %s',
                        $row['action'] === 'scrubbed' ? 'scrubbed  ' : 'would scrub',
                        $client->id,
                        (string) $client->name,
                        $row['redactions'] ?? 0,
                        implode(', ', array_keys($row['kinds'] ?? []))
                    ));
                } elseif (($row['action'] ?? '') === 'failed') {
                    $this->error("  Failed client #{$client->id}: " . ($row['error'] ?? 'unknown error'));
                }
            }
        );

        $this->info(sprintf(
            'Done. processed=%d %s=%d clean=%d skipped=%d failed=%d redactions=%d',
            $summary['processed'],
            $dryRun ? 'would_scrub' : 'scrubbed',
            $dryRun ? $summary['would_scrub'] : $summary['scrubbed'],
            $summary['clean'],
            $summary['skipped'],
            $summary['failed'],
            $summary['redactions']
        ));

        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
