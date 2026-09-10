<?php

namespace App\Services\Revenue;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer-mix segmentation for collected revenue: was this payment made by a client we
 * first saw inside the window ("new"), before it ("existing"), or neither?
 *
 * Attribution is frozen at payment time. It deliberately does NOT consult the client's
 * *current* active status, because that status is rewritten by the WP sync every time a
 * profile expires. Judging an old window through today's status silently moves churned
 * clients out of new/existing and into other_matched, and since an older window has had
 * longer to churn, that deflates the prior baseline and inflates every period-over-period
 * delta built on top of it. (It read as +189.6% growth on the CEO card in Sept 2026 for a
 * bucket that was actually flat: the filter retained 59% of the current window's new-user
 * revenue but only 19% of the prior window's.)
 *
 * The four segments are mutually exclusive and exhaustive over the payments in a window,
 * so the bucket amounts always reconcile back to collected revenue.
 */
class CustomerMixSegments
{
    public const NEW_ACTIVE = 'new_active';
    public const EXISTING_ACTIVE = 'existing_active';
    public const UNATTRIBUTED = 'unattributed';
    public const OTHER_MATCHED = 'other_matched';

    /**
     * Segment keys, in display order. These double as the `customer_mix_segment` query
     * parameter, so they are part of the URL contract — rename with care.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [self::NEW_ACTIVE, self::EXISTING_ACTIVE, self::UNATTRIBUTED, self::OTHER_MATCHED];
    }

    /**
     * Constrain a payments query to one segment.
     *
     * A null $from means the window is open-ended at the start, so nothing can predate it:
     * "existing" is then empty by definition rather than accidentally matching everything.
     */
    public static function apply(Builder $query, string $bucketKey, ?Carbon $from, Carbon $to): Builder
    {
        return match ($bucketKey) {
            self::NEW_ACTIVE => $query->whereHas('client', function (Builder $clientQuery) use ($from, $to) {
                if ($from) {
                    $clientQuery->where('created_at', '>=', $from);
                }

                $clientQuery->where('created_at', '<=', $to);
            }),

            self::EXISTING_ACTIVE => $from
                ? $query->whereHas('client', fn (Builder $clientQuery) => $clientQuery->where('created_at', '<', $from))
                : $query->whereRaw('1 = 0'),

            self::UNATTRIBUTED => $query->whereNull('payments.client_id'),

            // Everything still matched to a client but outside both windows: the client row
            // has since been deleted, or it was created after the window closed (a profile
            // backfilled later than the payment it is matched to).
            self::OTHER_MATCHED => $query
                ->whereNotNull('payments.client_id')
                ->where(function (Builder $builder) use ($to) {
                    $builder->whereDoesntHave('client')
                        ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery->where('created_at', '>', $to));
                }),

            default => $query,
        };
    }
}
