<?php

namespace App\Console\Commands;

use App\Models\Commission;
use App\Models\Deal;
use App\Services\CommissionService;
use Illuminate\Console\Command;

class BackfillFieldCommissionsCommand extends Command
{
    protected $signature = 'crm:backfill-field-commissions
        {--apply : Persist commissions. Without this flag the command is dry-run only.}
        {--client= : Restrict scan to a single client_id.}
        {--limit=0 : Limit the number of deals scanned (0 = no limit).}';

    protected $description = 'Backfill missing field-sales commissions for paid deals whose activation path did not seed activated_by_field_agent.';

    public function handle(CommissionService $commissionService): int
    {
        $apply = (bool) $this->option('apply');
        $clientFilter = (int) $this->option('client');
        $limit = max(0, (int) $this->option('limit'));

        $query = Deal::query()
            ->with(['client.platform', 'platform'])
            ->where('is_free_trial', false)
            ->whereIn('status', ['active', 'expired', 'renewed'])
            ->whereNotNull('activated_at')
            ->orderBy('id');

        if ($clientFilter > 0) {
            $query->where('client_id', $clientFilter);
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $scanned = 0;
        $activationsCreated = 0;
        $renewalsCreated = 0;
        $activationsSkippedNoAgent = 0;
        $renewalsSkippedNoAgent = 0;
        $alreadyExisted = 0;
        $sampleRows = [];

        $this->line($apply ? 'Applying commissions...' : 'Dry-run — no writes will occur.');

        $query->chunkById(200, function ($deals) use (
            $commissionService,
            $apply,
            &$scanned,
            &$activationsCreated,
            &$renewalsCreated,
            &$activationsSkippedNoAgent,
            &$renewalsSkippedNoAgent,
            &$alreadyExisted,
            &$sampleRows
        ) {
            foreach ($deals as $deal) {
                $scanned++;

                $existingByType = Commission::query()
                    ->where('deal_id', (int) $deal->id)
                    ->pluck('type')
                    ->all();

                $agentId = (int) (
                    $deal->activated_by_field_agent
                    ?: $commissionService->resolveFieldAgentForClient($deal->client)
                );

                $needsActivation = !in_array('activation', $existingByType, true);
                $needsRenewal = !in_array('renewal', $existingByType, true);

                if (!$needsActivation && !$needsRenewal) {
                    $alreadyExisted++;
                    continue;
                }

                if ($agentId <= 0) {
                    if ($needsActivation) {
                        $activationsSkippedNoAgent++;
                    }
                    if ($needsRenewal) {
                        $renewalsSkippedNoAgent++;
                    }
                    continue;
                }

                if (!$apply) {
                    if (count($sampleRows) < 20) {
                        $sampleRows[] = [
                            'client_id' => (int) $deal->client_id,
                            'deal_id' => (int) $deal->id,
                            'client' => (string) ($deal->client?->name ?? '—'),
                            'agent_id' => $agentId,
                            'amount' => (float) $deal->amount,
                            'currency' => (string) ($deal->currency ?: $deal->client?->platform?->currency_code ?: 'KES'),
                        ];
                    }
                    if ($needsActivation) {
                        $activationsCreated++;
                    }
                    if ($needsRenewal) {
                        $renewalsCreated++;
                    }
                    continue;
                }

                if ((int) ($deal->activated_by_field_agent ?? 0) !== $agentId) {
                    $deal->forceFill(['activated_by_field_agent' => $agentId])->save();
                }

                if ($needsActivation) {
                    $created = $commissionService->recordActivationCommission($deal);
                    if ($created && $created->wasRecentlyCreated) {
                        $activationsCreated++;
                    }
                }
                if ($needsRenewal) {
                    $created = $commissionService->recordRenewalCommission($deal);
                    if ($created && $created->wasRecentlyCreated) {
                        $renewalsCreated++;
                    }
                }
            }
        });

        if (!$apply && $sampleRows) {
            $this->table(
                ['client_id', 'deal_id', 'client', 'agent_id', 'amount', 'ccy'],
                array_map(fn ($r) => [
                    $r['client_id'],
                    $r['deal_id'],
                    $r['client'],
                    $r['agent_id'],
                    number_format($r['amount'], 2),
                    $r['currency'],
                ], $sampleRows)
            );
        }

        $this->newLine();
        $this->info(sprintf(
            '%s | scanned=%d | activation=%d | renewal=%d | skipped_no_agent (act/ren)=%d/%d | already_existed=%d',
            $apply ? 'APPLIED' : 'DRY-RUN',
            $scanned,
            $activationsCreated,
            $renewalsCreated,
            $activationsSkippedNoAgent,
            $renewalsSkippedNoAgent,
            $alreadyExisted
        ));

        if (!$apply) {
            $this->warn('Re-run with --apply to persist.');
        }

        return self::SUCCESS;
    }
}
