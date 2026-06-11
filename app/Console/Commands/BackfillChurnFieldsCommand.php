<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Deal;
use App\Services\ClientChurnStamper;
use App\Support\CrmClientChurnReason;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillChurnFieldsCommand extends Command
{
    protected $signature = 'crm:backfill-churn-fields
                            {--limit=2000 : Maximum number of clients to process}
                            {--dry-run : Log intent without mutating}
                            {--platform= : Restrict to a single platform_id}';

    protected $description = 'Backfill churned_at, churn_reason_code, churn_source, and first_activated_at from existing deal history. Idempotent.';

    public function handle(ClientChurnStamper $stamper): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $platformId = $this->option('platform') ? (int) $this->option('platform') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] No mutations will be made.');
        }

        $firstActivatedCount = $this->backfillFirstActivatedAt($limit, $dryRun, $platformId, $stamper);
        $churnCount = $this->backfillChurnedAt($limit, $dryRun, $platformId, $stamper);
        $caseClosedCount = $this->backfillCaseClosed($limit, $dryRun, $platformId, $stamper);

        $this->info(sprintf(
            'Done. first_activated_at: %d, churned_at (deal): %d, churned_at (case_closed): %d',
            $firstActivatedCount,
            $churnCount,
            $caseClosedCount,
        ));

        return self::SUCCESS;
    }

    private function backfillFirstActivatedAt(int $limit, bool $dryRun, ?int $platformId, ClientChurnStamper $stamper): int
    {
        $this->info('Step 1: Backfilling first_activated_at...');

        $query = Client::query()
            ->whereNull('first_activated_at')
            ->whereHas('deals', fn ($q) => $q->whereNotNull('activated_at'))
            ->limit($limit);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        $clients = $query->get();
        $count = 0;

        foreach ($clients as $client) {
            $earliest = $client->deals()
                ->whereNotNull('activated_at')
                ->orderBy('activated_at', 'asc')
                ->value('activated_at');

            if ($earliest === null) {
                continue;
            }

            $this->line(sprintf(
                '  client #%d (%s) — first_activated_at = %s',
                $client->id,
                $client->name,
                $earliest
            ));

            if (!$dryRun) {
                Client::withoutRetentionRefresh(function () use ($client, $earliest): void {
                    $client->forceFill(['first_activated_at' => $earliest])->save();
                });
            }

            $count++;
        }

        return $count;
    }

    private function backfillChurnedAt(int $limit, bool $dryRun, ?int $platformId, ClientChurnStamper $stamper): int
    {
        $this->info('Step 2: Backfilling churned_at from deal history...');

        // Find clients with at least one deal and no active deal, and no churn stamp yet
        $query = Client::query()
            ->whereNull('churned_at')
            ->whereNotNull('first_activated_at')
            ->whereDoesntHave('deals', fn ($q) => $q->where('status', 'active'))
            ->whereHas('deals', fn ($q) => $q->whereIn('status', ['cancelled', 'expired', 'deactivated']))
            ->limit($limit);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        $clients = $query->get();
        $count = 0;

        foreach ($clients as $client) {
            // Get the latest terminal deal
            $latestDeal = $client->deals()
                ->whereIn('status', ['cancelled', 'expired', 'deactivated'])
                ->orderBy('updated_at', 'desc')
                ->first();

            if ($latestDeal === null) {
                continue;
            }

            [$reasonCode, $source] = match ((string) $latestDeal->status) {
                'cancelled' => [
                    CrmClientChurnReason::fromDealCancellation($latestDeal->cancellation_reason_code),
                    'deal_cancelled',
                ],
                'expired' => [
                    CrmClientChurnReason::fromDealExpiry(),
                    'deal_expired',
                ],
                'deactivated' => [
                    CrmClientChurnReason::fromAdminDeactivation($latestDeal->cancellation_reason_code),
                    'deal_deactivated',
                ],
                default => [CrmClientChurnReason::OTHER, 'deal_cancelled'],
            };

            $churnedAt = $latestDeal->updated_at ?? now();

            $this->line(sprintf(
                '  client #%d (%s) — churned_at=%s reason=%s source=%s',
                $client->id,
                $client->name,
                $churnedAt,
                $reasonCode,
                $source
            ));

            if (!$dryRun) {
                Client::withoutRetentionRefresh(function () use ($client, $reasonCode, $source, $churnedAt): void {
                    $client->forceFill([
                        'churned_at' => $churnedAt,
                        'churn_reason_code' => $reasonCode,
                        'churn_source' => $source,
                    ])->save();
                });
            }

            $count++;
        }

        return $count;
    }

    private function backfillCaseClosed(int $limit, bool $dryRun, ?int $platformId, ClientChurnStamper $stamper): int
    {
        $this->info('Step 3: Backfilling churned_at from case-closed paid clients...');

        // Clients who were case-closed AND had paid at least once (first_activated_at set)
        // and don't yet have a churn stamp
        $query = Client::query()
            ->whereNull('churned_at')
            ->whereNotNull('closed_at')
            ->whereNotNull('first_activated_at')
            ->whereNotNull('close_reason_code')
            ->limit($limit);

        if ($platformId) {
            $query->where('platform_id', $platformId);
        }

        $clients = $query->get();
        $count = 0;

        foreach ($clients as $client) {
            $churnReasonCode = CrmClientChurnReason::fromCloseCase((string) $client->close_reason_code);

            $this->line(sprintf(
                '  client #%d (%s) — case_closed churned_at=%s reason=%s',
                $client->id,
                $client->name,
                $client->closed_at,
                $churnReasonCode
            ));

            if (!$dryRun) {
                Client::withoutRetentionRefresh(function () use ($client, $churnReasonCode): void {
                    $client->forceFill([
                        'churned_at' => $client->closed_at,
                        'churn_reason_code' => $churnReasonCode,
                        'churn_source' => 'case_closed',
                    ])->save();
                });
            }

            $count++;
        }

        return $count;
    }
}
