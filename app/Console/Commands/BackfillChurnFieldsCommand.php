<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Services\ClientChurnStamper;
use App\Services\ClientFunnelService;
use App\Support\CrmClientChurnReason;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class BackfillChurnFieldsCommand extends Command
{
    protected $signature = 'crm:backfill-churn-fields
                            {--limit=2000 : Maximum number of clients to process per phase}
                            {--dry-run : Log intent without mutating}
                            {--platform= : Restrict to a single platform_id}';

    protected $description = 'Backfill churn fields from the canonical paid-history and active-profile definitions. Idempotent.';

    public function handle(ClientChurnStamper $stamper): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');
        $platformId = $this->option('platform') ? (int) $this->option('platform') : null;

        if ($dryRun) {
            $this->warn('[DRY-RUN] No mutations will be made.');
        }

        $activationCount = $this->backfillFirstActivatedAt($limit, $dryRun, $platformId, $stamper);
        $churnCount = $this->backfillChurnedAt($limit, $dryRun, $platformId, $stamper);

        $this->info(sprintf(
            'Done. first_activated_at: %d, churned_at (paid + inactive): %d',
            $activationCount,
            $churnCount,
        ));

        return self::SUCCESS;
    }

    private function backfillFirstActivatedAt(
        int $limit,
        bool $dryRun,
        ?int $platformId,
        ClientChurnStamper $stamper,
    ): int {
        $this->info('Step 1: Backfilling first_activated_at from paid history...');

        $query = ClientFunnelService::applyPaidHistory(
            Client::query()->whereNull('first_activated_at')
        );
        $this->applyPlatform($query, $platformId);

        $count = 0;
        foreach ($query->limit($limit)->get() as $client) {
            $firstActivatedAt = $stamper->firstActivationAt($client);
            if ($firstActivatedAt === null) {
                continue;
            }

            $this->line(sprintf(
                '  client #%d (%s) - first_activated_at=%s',
                $client->id,
                $client->name,
                $firstActivatedAt->toDateTimeString(),
            ));

            if (! $dryRun) {
                $stamper->refreshFirstActivatedAt($client);
            }

            $count++;
        }

        return $count;
    }

    private function backfillChurnedAt(
        int $limit,
        bool $dryRun,
        ?int $platformId,
        ClientChurnStamper $stamper,
    ): int {
        $this->info('Step 2: Backfilling churned_at for paid clients whose profile is inactive...');

        $query = ClientFunnelService::applyPaidHistory(
            Client::query()
                ->whereNull('churned_at')
                ->whereNot(fn (Builder $builder) => $builder->active())
        );
        $this->applyPlatform($query, $platformId);

        $count = 0;
        foreach ($query->limit($limit)->get() as $client) {
            [$reasonCode, $source, $churnedAt] = $this->churnMetadata($client);

            $this->line(sprintf(
                '  client #%d (%s) - churned_at=%s reason=%s source=%s',
                $client->id,
                $client->name,
                $churnedAt->toDateTimeString(),
                $reasonCode,
                $source,
            ));

            if (! $dryRun) {
                $stamper->stamp($client, $reasonCode, $source, $churnedAt);
            }

            $count++;
        }

        return $count;
    }

    /**
     * @return array{0:string,1:string,2:Carbon}
     */
    private function churnMetadata(Client $client): array
    {
        if ($client->closed_at !== null && $client->close_reason_code !== null) {
            return [
                CrmClientChurnReason::fromCloseCase((string) $client->close_reason_code),
                'case_closed',
                $client->closed_at,
            ];
        }

        $terminalDeal = $client->deals()
            ->whereIn('status', ['cancelled', 'expired'])
            ->latest('updated_at')
            ->first();

        if ($terminalDeal !== null) {
            return match ((string) $terminalDeal->status) {
                'cancelled' => [
                    CrmClientChurnReason::fromDealCancellation($terminalDeal->cancellation_reason_code),
                    'deal_cancelled',
                    Carbon::parse($terminalDeal->updated_at),
                ],
                default => [
                    CrmClientChurnReason::EXPIRED_UNRENEWED,
                    'deal_expired',
                    Carbon::parse($terminalDeal->updated_at),
                ],
            };
        }

        $when = $client->wp_modified_at
            ?? $client->last_synced_at
            ?? $client->updated_at
            ?? now();
        $when = Carbon::parse($when)->min(now());

        if ($client->first_activated_at !== null) {
            $when = $when->max($client->first_activated_at);
        }

        return [
            CrmClientChurnReason::EXPIRED_UNRENEWED,
            'profile_inactive',
            $when,
        ];
    }

    private function applyPlatform(Builder $query, ?int $platformId): void
    {
        if ($platformId !== null) {
            $query->where('platform_id', $platformId);
        }
    }
}
