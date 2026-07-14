<?php

namespace App\Console\Commands;

use App\Models\Platform;
use App\Models\PushCampaignItem;
use Illuminate\Console\Command;

/**
 * Triage failing push items at a glance. Groups the most recent failures by
 * their error_message code prefix (e.g. `epe_rate_limited`) and prints counts
 * so an operator can tell "EPE is 5xx-ing" apart from "our tokens expired"
 * apart from "one URL had bad chars" without opening 20 items in the UI.
 *
 * Ad-hoc use during an incident:
 *
 *   php artisan crm:push-item-errors
 *   php artisan crm:push-item-errors --hours=6 --platform=13
 *   php artisan crm:push-item-errors --show-samples
 */
class PushItemErrorsCommand extends Command
{
    protected $signature = 'crm:push-item-errors
        {--hours=24 : Lookback window in hours}
        {--platform= : Restrict to a single platform id}
        {--show-samples : Print one example error_message per code}';

    protected $description = 'Summarize recently failed push items grouped by error code.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $platformId = $this->option('platform') !== null ? (int) $this->option('platform') : null;
        $showSamples = (bool) $this->option('show-samples');

        $since = now()->subHours($hours);

        $query = PushCampaignItem::query()
            ->where('status', 'failed')
            ->where('updated_at', '>=', $since)
            ->whereNotNull('error_message');

        if ($platformId !== null) {
            $query->whereHas('campaign', fn ($q) => $q->where('platform_id', $platformId));
        }

        $items = $query->get(['id', 'campaign_id', 'error_message']);
        $total = $items->count();

        if ($total === 0) {
            $this->info("No failed push items in the last {$hours}h" . ($platformId ? " for platform {$platformId}" : '') . '.');
            return self::SUCCESS;
        }

        // Bucket by the code prefix (`code:` at the start of error_message).
        // Non-prefixed / raw JSON blobs get bucketed as `unparsed`.
        $buckets = [];
        foreach ($items as $item) {
            $message = trim((string) $item->error_message);
            $code = $this->extractCode($message);
            $buckets[$code] ??= ['count' => 0, 'sample' => null, 'sample_item_id' => null];
            $buckets[$code]['count']++;
            if ($buckets[$code]['sample'] === null) {
                $buckets[$code]['sample'] = mb_substr($message, 0, 240);
                $buckets[$code]['sample_item_id'] = (int) $item->id;
            }
        }

        uasort($buckets, static fn ($a, $b) => $b['count'] <=> $a['count']);

        $marketLabel = $platformId
            ? (Platform::query()->whereKey($platformId)->value('country') ?? "platform {$platformId}")
            : 'all markets';

        $this->line('');
        $this->info("Failed push items — last {$hours}h — {$marketLabel}");
        $this->line(str_repeat('-', 72));

        $rows = [];
        foreach ($buckets as $code => $data) {
            $pct = round($data['count'] / $total * 100);
            $rows[] = [$code, $data['count'], "{$pct}%"];
        }
        $this->table(['Code', 'Count', 'Share'], $rows);

        $this->line("Total: {$total} failed items");

        if ($showSamples) {
            $this->line('');
            $this->info('Sample error_message per code:');
            foreach ($buckets as $code => $data) {
                $this->line("  <fg=yellow>{$code}</> (item #{$data['sample_item_id']}):");
                $this->line("    {$data['sample']}");
            }
        }

        return self::SUCCESS;
    }

    private function extractCode(string $message): string
    {
        $colon = strpos($message, ':');
        if ($colon === false) {
            return 'no_prefix';
        }

        $candidate = trim(substr($message, 0, $colon));

        // Must look like an identifier — otherwise it's a raw JSON blob or garbage.
        if ($candidate === '' || !preg_match('/^[a-z][a-z0-9_]*$/i', $candidate)) {
            return 'unparsed';
        }

        return $candidate;
    }
}
