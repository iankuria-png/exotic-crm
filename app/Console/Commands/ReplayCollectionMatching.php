<?php

namespace App\Console\Commands;

use App\Models\Collections\CollectionAccount;
use App\Services\Collections\CollectionMatchAccuracyService;
use App\Services\Collections\CollectionMatchService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Read-only: re-runs the current matching rules over past receipts and compares them with the saved
 * suggestions and the profiles staff activated. Use before shipping any rule change.
 */
class ReplayCollectionMatching extends Command
{
    protected $signature = 'collections:match-replay {--account= : Receiving account id (default: all)} {--days=30} {--json : Print the full comparison as JSON}';

    protected $description = 'Compare current matching rules with saved suggestions against staff-activated profiles (read-only)';

    public function handle(CollectionMatchAccuracyService $accuracy): int
    {
        $accounts = $this->option('account') ? [(string) $this->option('account')] : CollectionAccount::pluck('id')->all();
        $days = max(1, min(180, (int) $this->option('days')));
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $saved = $accuracy->build($accounts, $from, $to);
        $replay = $accuracy->build($accounts, $from, $to, function ($receipt) {
            try {
                return app(CollectionMatchService::class)->automatic($receipt);
            } catch (\Throwable) {
                return ['suggestions' => []];
            }
        });
        if ($this->option('json')) {
            $this->line(json_encode(['saved' => $saved, 'current_rules' => $replay], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $pct = fn ($v) => $v === null ? '—' : number_format($v * 100, 1).'%';
        $this->info("Receipts with a staff outcome: {$saved['totals']['with_outcome']} (last {$days} days)");
        $this->table(['Measure', 'Saved suggestions', 'Current rules'], [
            ['Suggested a profile', $pct($saved['rates']['coverage']), $pct($replay['rates']['coverage'])],
            ['Top suggestion = staff choice (all receipts)', $pct($saved['rates']['agreement']), $pct($replay['rates']['agreement'])],
            ['Correct when it suggests', $pct($saved['rates']['precision']), $pct($replay['rates']['precision'])],
            ['Would auto-activate', (string) $saved['would_auto']['count'], (string) $replay['would_auto']['count']],
            ['Auto pick correct', $pct($saved['would_auto']['precision']), $pct($replay['would_auto']['precision'])],
        ]);
        $this->table(['Score band', 'Saved n', 'Saved correct', 'Current n', 'Current correct'], array_map(fn ($a, $b) => [
            $a['band'], $a['n'], $pct($a['precision']), $b['n'], $pct($b['precision'])], $saved['bands'], $replay['bands']));
        $held = collect($replay['guards'])->map(fn ($g) => $g['guard'].' ×'.$g['count'])->implode(', ');
        $this->line($held ? 'Current rules hold from automatic activation: '.$held : 'Current rules hold nothing by guard.');
        $this->comment('Replay uses current profile and history data, so it approximates what the rules would have done at the time.');

        return self::SUCCESS;
    }
}
