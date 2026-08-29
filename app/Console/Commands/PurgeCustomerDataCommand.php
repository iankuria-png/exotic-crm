<?php

namespace App\Console\Commands;

use App\Models\CustomerActivityEvent;
use App\Models\CustomerCompareItem;
use App\Models\CustomerCompareSet;
use App\Models\CustomerRecentView;
use App\Models\CustomerSafetyReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Enforces the signed customer-data retention policy.
 *
 * | Data              | Window                                     |
 * | ----------------- | ------------------------------------------ |
 * | Activity events   | 180 days from `occurred_at`                |
 * | Recent views      | 90 days from `last_viewed_at`              |
 * | Compare sets      | 30 days after last update                  |
 * | Safety reports    | account link dropped after 730 days        |
 *
 * Saved objects, follows, and saved searches have no expiry: they live until
 * the customer removes them or the account-deletion cascade runs. Notification
 * retention joins this command when that table ships in Phase 5.
 *
 * Safety reports are anonymized rather than deleted, matching the signed
 * reachability policy: the moderation record survives, the link to a named
 * member does not.
 */
class PurgeCustomerDataCommand extends Command
{
    protected $signature = 'crm:purge-customer-data
        {--chunk=1000 : Rows deleted per batch}
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete customer activity events, recent views, and compare sets past their signed retention windows.';

    public function handle(): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $this->purgeActivityEvents($chunk, $dryRun);
        $this->purgeRecentViews($chunk, $dryRun);
        $this->purgeCompareSets($chunk, $dryRun);
        $this->anonymizeSafetyReports($chunk, $dryRun);

        return self::SUCCESS;
    }

    /**
     * Drop the account link on old reports instead of deleting the row.
     *
     * The report itself is a moderation record with its own retention need, so
     * what expires is the association with a named member, not the fact that a
     * profile was reported.
     */
    private function anonymizeSafetyReports(int $chunk, bool $dryRun): void
    {
        $cutoff = Carbon::now()->subDays(CustomerSafetyReport::ANONYMIZE_AFTER_DAYS);
        $total = CustomerSafetyReport::query()
            ->whereNotNull('customer_account_id')
            ->where('submitted_at', '<', $cutoff)
            ->count();

        if ($total === 0) {
            $this->info('No customer safety reports past retention.');

            return;
        }

        if ($dryRun) {
            $this->info(sprintf('Would anonymize %d customer safety reports submitted before %s.', $total, $cutoff->toDateString()));

            return;
        }

        $updated = 0;
        do {
            $ids = CustomerSafetyReport::query()
                ->whereNotNull('customer_account_id')
                ->where('submitted_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                break;
            }

            $updated += CustomerSafetyReport::query()
                ->whereIn('id', $ids)
                ->update(['customer_account_id' => null]);
        } while (true);

        $this->info(sprintf('Anonymized %d customer safety reports submitted before %s.', $updated, $cutoff->toDateString()));
    }

    private function purgeActivityEvents(int $chunk, bool $dryRun): void
    {
        $cutoff = Carbon::now()->subDays(CustomerActivityEvent::RETENTION_DAYS);
        $total = CustomerActivityEvent::query()->where('occurred_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info('No customer activity events past retention.');

            return;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d customer activity events older than %s.', $total, $cutoff->toDateString()));

            return;
        }

        $deleted = 0;
        do {
            $batch = CustomerActivityEvent::query()
                ->where('occurred_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Deleted %d customer activity events older than %s.', $deleted, $cutoff->toDateString()));
    }

    private function purgeRecentViews(int $chunk, bool $dryRun): void
    {
        $cutoff = Carbon::now()->subDays(CustomerRecentView::RETENTION_DAYS);
        $total = CustomerRecentView::query()->where('last_viewed_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info('No customer recent views past retention.');

            return;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d customer recent views older than %s.', $total, $cutoff->toDateString()));

            return;
        }

        $deleted = 0;
        do {
            $batch = CustomerRecentView::query()
                ->where('last_viewed_at', '<', $cutoff)
                ->limit($chunk)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info(sprintf('Deleted %d customer recent views older than %s.', $deleted, $cutoff->toDateString()));
    }

    /**
     * The signed window is measured from the set's last update, so the whole
     * set goes at once — items first, then the header that timed it.
     */
    private function purgeCompareSets(int $chunk, bool $dryRun): void
    {
        $cutoff = Carbon::now()->subDays(CustomerCompareSet::RETENTION_DAYS);
        $total = CustomerCompareSet::query()->where('last_activity_at', '<', $cutoff)->count();

        if ($total === 0) {
            $this->info('No customer compare sets past retention.');

            return;
        }

        if ($dryRun) {
            $this->info(sprintf('Would delete %d customer compare sets last updated before %s.', $total, $cutoff->toDateString()));

            return;
        }

        $sets = 0;
        $items = 0;
        do {
            $ids = CustomerCompareSet::query()
                ->where('last_activity_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if (empty($ids)) {
                break;
            }

            $items += CustomerCompareItem::query()->whereIn('compare_set_id', $ids)->delete();
            $sets += CustomerCompareSet::query()->whereIn('id', $ids)->delete();
        } while (true);

        $this->info(sprintf(
            'Deleted %d customer compare sets (%d items) last updated before %s.',
            $sets,
            $items,
            $cutoff->toDateString()
        ));
    }
}
