<?php

namespace App\Console\Commands;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Bookkeeping only: closes out completed payments whose paid window has passed.
 *
 * This command USED TO own subscription expiry. It decided expiry from a single
 * payments row (status=completed AND end_date <= now) and never read the
 * profile's actual escort_expire, then privatised every escort post belonging to
 * that author with a direct write into the market's WordPress database and sent
 * an "expired" SMS.
 *
 * That was wrong in two compounding ways:
 *
 *   1. A renewal advances deals.expires_at and the WordPress escort_expire but
 *      leaves the ORIGINAL payment row behind, still `completed`, still carrying
 *      the pre-renewal end_date. On that date this command privatised a profile
 *      that had weeks of paid access left. Production data for 1-2 Sep 2026 shows
 *      70 and 86 profiles destroyed on consecutive nights, owed up to 31 days.
 *   2. Posts were resolved by `post_author`, so one lapsed payment took down every
 *      profile that author owned, including fully paid ones.
 *
 * Profile expiry now belongs solely to crm:reconcile-expired-subscriptions, which
 * reads escort_expire under a market-timezone end-of-day cutoff and refuses to act
 * on any client holding a future active deal. Expiry messaging belongs to
 * LifecycleSmsService's reactivation flow, which triggers off the same escort_expire.
 *
 * What remains here is the harmless part: marking a payment's window closed. Both
 * `completed` and `expired` count as successful (Payment::SUCCESSFUL_STATUSES), so
 * this transition carries no revenue meaning and no side effects. The command is no
 * longer scheduled; it is kept, and kept safe, because a stale cPanel cron calling
 * it directly must not be able to take profiles offline again.
 */
class CheckExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:check {--dry-run : Report what would be closed out without writing}';

    protected $description = 'Close out completed payments whose paid window has passed (bookkeeping only — does NOT expire profiles).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $now = Carbon::now();

        $this->warn('subscriptions:check no longer expires profiles.');
        $this->line('Profile expiry is owned by crm:reconcile-expired-subscriptions.');
        $this->newLine();

        $query = Payment::query()
            ->where('status', 'completed')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', $now);

        $total = (int) $query->clone()->count();
        $this->info(sprintf(
            '%s %d payment(s) with a closed window at %s.',
            $dryRun ? 'Would close out' : 'Closing out',
            $total,
            $now->toDateTimeString()
        ));

        if ($dryRun || $total === 0) {
            return self::SUCCESS;
        }

        $closed = 0;

        // chunkById keeps memory flat as payments grows. Safe with the status
        // mutation below: the id-forward cursor never revisits processed rows.
        $query->select(['id'])->chunkById(200, function ($payments) use (&$closed): void {
            $ids = $payments->pluck('id')->all();
            $closed += Payment::query()->whereIn('id', $ids)->update(['status' => 'expired']);
        });

        $this->info("Closed out {$closed} payment(s).");

        Log::info('Payment windows closed out (bookkeeping only, no profile changes)', [
            'closed' => $closed,
        ]);

        return self::SUCCESS;
    }
}
