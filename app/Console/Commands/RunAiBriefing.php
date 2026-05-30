<?php

namespace App\Console\Commands;

use App\Services\Ai\BriefingService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunAiBriefing extends Command
{
    protected $signature = 'crm:ai-briefing
        {--audience=ceo : Audience to brief: ceo|sales}
        {--period=weekly : Reporting period (only weekly is supported)}
        {--dry-run : Compute and print the SMS + body without sending or persisting}
        {--date= : Anchor date (Y-m-d) inside the week AFTER the period to brief; defaults to now}';

    protected $description = 'Generate and send the weekly AI performance briefing for an audience';

    public function __construct(private readonly BriefingService $briefings)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $audience = (string) $this->option('audience');
        if (!in_array($audience, ['ceo', 'sales'], true)) {
            $this->error("Invalid --audience '{$audience}'. Use ceo or sales.");

            return self::INVALID;
        }

        if ((string) $this->option('period') !== 'weekly') {
            $this->error('Only --period=weekly is supported.');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $date   = null;
        if ($this->option('date')) {
            try {
                $date = Carbon::createFromFormat('Y-m-d', (string) $this->option('date'));
            } catch (\Throwable $e) {
                $this->error('Invalid --date. Use Y-m-d.');

                return self::INVALID;
            }
        }

        $result = $this->briefings->run($audience, $dryRun, $date);

        $this->line(sprintf(
            'Briefing %s | audience=%s | dry_run=%s | period=%s..%s (%s)',
            $result['status'],
            $result['audience'],
            $dryRun ? 'yes' : 'no',
            $result['period']['from'] ?? '?',
            $result['period']['to'] ?? '?',
            $result['period']['timezone'] ?? '?',
        ));

        if ($result['status'] === 'skipped') {
            $this->warn('Skipped: ' . ($result['reason'] ?? 'unknown'));

            return self::SUCCESS;
        }

        foreach ($result['briefings'] as $i => $briefing) {
            $scope = $briefing['scope']['platform_ids'] === null
                ? 'org-wide'
                : 'markets [' . implode(',', $briefing['scope']['platform_ids']) . ']';

            $this->newLine();
            $this->info(sprintf('Scope #%d: %s (%s)', $i + 1, $scope, $briefing['used_ai'] ? 'AI' : 'template'));
            $this->line('  SMS digest: ' . $briefing['sms_digest']);

            foreach ($briefing['recipients'] as $r) {
                $this->line(sprintf(
                    '  -> %s (%s) [%s, %d units / %d seg]',
                    $r['name'] ?? 'user#' . $r['user_id'],
                    $r['phone'] ?? 'no phone',
                    $r['delivery_status'],
                    $r['sms_char_count'],
                    $r['sms_segments'],
                ));
                if ($dryRun) {
                    $this->line('     ' . $r['sms_text']);
                }
            }
        }

        if (isset($result['cost_usd'])) {
            $this->newLine();
            $this->line(sprintf('Estimated AI cost this run: $%.6f', $result['cost_usd']));
        }

        return self::SUCCESS;
    }
}
