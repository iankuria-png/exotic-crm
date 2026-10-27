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
        [$state, $subject, $firstSeen] = PaymentAttributionQuery::expressions($query);
        return match ($bucketKey) {
            self::NEW_ACTIVE => PaymentAttributionQuery::matched($query)->whereRaw("($firstSeen) <= ?", [$to])->when($from, fn($q) => $q->whereRaw("($firstSeen) >= ?", [$from])),
            self::EXISTING_ACTIVE => $from ? PaymentAttributionQuery::matched($query)->whereRaw("($firstSeen) < ?", [$from]) : $query->whereRaw('1 = 0'),
            self::UNATTRIBUTED => PaymentAttributionQuery::matched($query, false),
            self::OTHER_MATCHED => PaymentAttributionQuery::matched($query)->where(fn($q) => $q->whereRaw("($firstSeen) IS NULL")->orWhereRaw("($firstSeen) > ?", [$to])),
            default => $query,
        };
    }
}
