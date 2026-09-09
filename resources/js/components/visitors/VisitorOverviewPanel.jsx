import React from 'react';
import MetricCard from '../MetricCard';
import FxNormalizationNotice from '../FxNormalizationNotice';
import { compactNumber, moneyRowsLabel, percentLabel, revenueDisplay } from './visitorFormat';
import { formatCurrency } from '../../utils/currency';

function FunnelStep({ label, count, rate, tone = 'slate', onClick, hint }) {
    const color = {
        slate: 'bg-slate-500',
        teal: 'bg-teal-600',
        amber: 'bg-amber-500',
        emerald: 'bg-emerald-600',
    }[tone] || 'bg-slate-500';
    const width = Math.max(4, Math.min(100, Number(rate || 0)));
    const interactive = typeof onClick === 'function';
    const className = `rounded-lg border border-slate-200 bg-white p-3 text-left ${
        interactive
            ? 'w-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500'
            : ''
    }`;

    const content = (
        <>
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-xs font-semibold text-slate-500">{label}</p>
                <p className="text-sm font-semibold tabular-nums text-slate-900">{compactNumber(count)}</p>
            </div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${color}`} style={{ width: `${width}%` }} />
            </div>
            <p className="mt-2 text-xs text-slate-500">{hint || `${percentLabel(rate)} rate`}</p>
        </>
    );

    return interactive
        ? <button type="button" onClick={onClick} className={className}>{content}</button>
        : <article className={className}>{content}</article>;
}

export default function VisitorOverviewPanel({ summary = {}, pulse = {}, reportingCurrency, isLoading, onOpenMetric }) {
    const kpis = pulse.kpis || {};
    const summaryRevenue = revenueDisplay({
        rows: summary.confirmed_revenue_native || [],
        normalizedAmount: summary.confirmed_revenue_normalized,
        normalizedDisplay: summary.confirmed_revenue_normalized_display,
        normalizedCurrency: summary.normalized_currency || reportingCurrency.targetCurrency,
        reporting: reportingCurrency,
        emptyLabel: 'No confirmed revenue yet',
    });
    const pulseRevenue = revenueDisplay({
        rows: kpis.revenue || [],
        normalizedAmount: kpis.revenue_normalized,
        normalizedDisplay: kpis.revenue_normalized_display,
        normalizedCurrency: kpis.normalized_currency || reportingCurrency.targetCurrency,
        reporting: reportingCurrency,
    });
    const aov = reportingCurrency.isFlat && kpis.average_order_value_normalized !== null && kpis.average_order_value_normalized !== undefined
        ? formatCurrency(kpis.average_order_value_normalized, kpis.normalized_currency || reportingCurrency.targetCurrency)
        : moneyRowsLabel(kpis.average_order_value || [], '-');
    const revenuePerView = moneyRowsLabel(
        (kpis.revenue || []).map((entry) => ({
            ...entry,
            amount: Number(entry.amount || 0) / Math.max(1, Number(kpis.eligible_profile_views || 0)),
        })),
        '-'
    );

    // Every card is a door: clicking opens the drill-down drawer with the definition, the
    // per-market split, and (where rows exist) a jump into the filtered Unlocks trail.
    const open = (metric) => (typeof onOpenMetric === 'function' ? () => onOpenMetric(metric) : undefined);

    return (
        <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label={`Confirmed revenue (${summary.window_label || 'all time'})`}
                    value={summaryRevenue.value}
                    hint={summaryRevenue.hint}
                    tone="success"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'confirmed_revenue',
                        label: `Confirmed unlock revenue (${summary.window_label || 'all time'})`,
                        value: summaryRevenue.value,
                        hint: summaryRevenue.hint,
                        kind: 'money',
                        definition: 'Successful payments with purpose = visitor_contact_unlock, dated by completion, inside the selected market and date range.',
                        caveat: 'Never counted in advertiser subscription revenue — the CEO Collected Revenue query excludes this purpose outright.',
                        unlockFilters: { payment_status: 'completed', status: 'all' },
                        unlockCta: 'Open paid unlocks',
                    })}
                />
                <MetricCard
                    label="Completed unlock payments"
                    value={compactNumber(summary.completed_payments)}
                    hint={`${compactNumber(summary.total_unlocks)} attempts`}
                    tone="accent"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'completed_payments',
                        label: 'Completed unlock payments',
                        value: compactNumber(summary.completed_payments),
                        hint: `${compactNumber(summary.total_unlocks)} checkout attempts in the same window`,
                        kind: 'count',
                        definition: 'Count of unlock payments that reached a successful terminal state (completed or expired-after-capture).',
                        unlockFilters: { payment_status: 'completed', status: 'all' },
                        unlockCta: 'Open paid unlocks',
                    })}
                />
                <MetricCard
                    label="Active unlocks"
                    value={compactNumber(summary.active_unlocks)}
                    hint={`${compactNumber(summary.pending_unlocks)} pending payment`}
                    tone={Number(summary.pending_unlocks || 0) > 0 ? 'warning' : 'success'}
                    isLoading={isLoading}
                    onClick={open({
                        key: 'active_unlocks',
                        label: 'Active unlocks',
                        value: compactNumber(summary.active_unlocks),
                        hint: `${compactNumber(summary.pending_unlocks)} still pending payment`,
                        kind: 'count',
                        definition: 'Unlocks that are paid and inside their access window right now — the visitor can still see the contact.',
                        caveat: 'Pending-payment rows are checkouts that never completed; they are the recoverable demand.',
                        unlockFilters: { status: 'active', payment_status: 'all' },
                        unlockCta: 'Open active unlocks',
                    })}
                />
                <MetricCard
                    label="Selected-window revenue"
                    value={pulseRevenue.value}
                    hint={pulseRevenue.hint}
                    tone="slate"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'window_revenue',
                        label: 'Selected-window unlock revenue',
                        value: pulseRevenue.value,
                        hint: pulseRevenue.hint,
                        kind: 'money',
                        definition: 'Unlock revenue restricted to the overview market selector and the chosen date range, normalized to the reporting currency.',
                        unlockFilters: { payment_status: 'completed', status: 'all' },
                        unlockCta: 'Open paid unlocks',
                    })}
                />
            </div>

            <FxNormalizationNotice meta={reportingCurrency.isFlat ? summary.confirmed_revenue_normalization_meta : null} />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <FunnelStep
                    label="Eligible views"
                    count={kpis.eligible_profile_views}
                    rate={100}
                    onClick={open({
                        key: 'eligible_views',
                        label: 'Eligible profile views',
                        value: compactNumber(kpis.eligible_profile_views),
                        hint: 'Top of the unlock funnel',
                        kind: 'count',
                        definition: 'Profile views where the visitor was shown a locked contact and could have started an unlock.',
                        breakdown: 'markets',
                        marketValue: (market) => compactNumber(market.eligible_views),
                    })}
                />
                <FunnelStep
                    label="CTA clicks"
                    count={kpis.unlock_cta_clicks}
                    rate={kpis.cta_rate_percent}
                    tone="teal"
                    onClick={open({
                        key: 'cta_clicks',
                        label: 'Unlock CTA clicks',
                        value: compactNumber(kpis.unlock_cta_clicks),
                        hint: `${percentLabel(kpis.cta_rate_percent)} of eligible views`,
                        kind: 'count',
                        definition: 'Clicks on the "unlock contact" call to action, recorded as contact_unlock_events of type cta_click.',
                        breakdown: 'markets',
                        marketValue: (market) => compactNumber(market.cta_clicks),
                    })}
                />
                <FunnelStep
                    label="Checkout starts"
                    count={kpis.checkout_starts}
                    rate={kpis.checkout_rate_percent}
                    tone="amber"
                    onClick={open({
                        key: 'checkout_starts',
                        label: 'Checkout starts',
                        value: compactNumber(kpis.checkout_starts),
                        hint: `${percentLabel(kpis.checkout_rate_percent)} of CTA clicks`,
                        kind: 'count',
                        definition: 'Unlock records created when a visitor began paying — one row per attempt, whether or not it completed.',
                        breakdown: 'markets',
                        marketValue: (market) => compactNumber(market.checkout_starts),
                        unlockFilters: { status: 'all', payment_status: 'all' },
                        unlockCta: 'Open all checkout attempts',
                    })}
                />
                <FunnelStep
                    label="Paid unlocks"
                    count={kpis.successful_payments}
                    rate={kpis.payment_completion_percent}
                    tone="emerald"
                    onClick={open({
                        key: 'paid_unlocks',
                        label: 'Paid unlocks',
                        value: compactNumber(kpis.successful_payments),
                        hint: `${percentLabel(kpis.payment_completion_percent)} of checkout starts`,
                        kind: 'count',
                        definition: 'Checkout attempts that ended in a successful payment.',
                        breakdown: 'markets',
                        unlockFilters: { payment_status: 'completed', status: 'all' },
                        unlockCta: 'Open paid unlocks',
                    })}
                />
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Average order value"
                    value={aov}
                    hint={`${compactNumber(kpis.successful_payments)} successful`}
                    isLoading={isLoading}
                    onClick={open({
                        key: 'aov',
                        label: 'Average order value',
                        value: aov,
                        hint: `Across ${compactNumber(kpis.successful_payments)} successful unlock payments`,
                        kind: 'money',
                        definition: 'Unlock revenue divided by the number of successful unlock payments in the window.',
                        marketValue: (market, currency) => market.average_order_value === null || market.average_order_value === undefined
                            ? '--'
                            : formatCurrency(market.average_order_value, currency),
                        unlockFilters: { payment_status: 'completed', status: 'all' },
                        unlockCta: 'Open paid unlocks',
                    })}
                />
                <MetricCard
                    label="Repeat buyers"
                    value={percentLabel(kpis.repeat_buyer_percent)}
                    hint="Same masked visitor purchased again"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'repeat_buyers',
                        label: 'Repeat buyers',
                        value: percentLabel(kpis.repeat_buyer_percent),
                        hint: 'Share of unlock buyers who bought more than once in this window',
                        kind: 'percent',
                        definition: 'Buyers are grouped by hashed phone. A buyer counts as repeat when that hash has more than one successful unlock payment in the window.',
                        caveat: 'Visitors are anonymous — there is no account behind the hash, so this is a lower bound.',
                        breakdown: 'none',
                    })}
                />
                <MetricCard
                    label="Upgrade rate"
                    value={percentLabel(kpis.upgrade_rate_percent)}
                    hint="Single unlock buyers upgraded"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'upgrade_rate',
                        label: 'Upgrade rate',
                        value: percentLabel(kpis.upgrade_rate_percent),
                        hint: 'Buyers who moved from a single profile to full market access',
                        kind: 'percent',
                        definition: 'Share of distinct buyers who bought a market-wide unlock with a credit applied from an earlier single-profile purchase.',
                        breakdown: 'none',
                        unlockFilters: { scope: 'market_inactive_profiles', payment_status: 'completed' },
                        unlockCta: 'Open full-access unlocks',
                    })}
                />
                <MetricCard
                    label="Revenue per view"
                    value={revenuePerView}
                    hint="Native currencies"
                    isLoading={isLoading}
                    onClick={open({
                        key: 'revenue_per_view',
                        label: 'Revenue per eligible view',
                        value: revenuePerView,
                        hint: `Across ${compactNumber(kpis.eligible_profile_views)} eligible views`,
                        kind: 'money',
                        definition: 'Unlock revenue divided by eligible profile views — what one locked-contact impression is worth.',
                        caveat: 'Shown per native currency because views are not currency-scoped; use the market split below to compare.',
                        breakdown: 'markets',
                    })}
                />
            </div>
        </div>
    );
}
