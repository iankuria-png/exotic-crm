import React from 'react';
import MetricCard from '../MetricCard';
import FxNormalizationNotice from '../FxNormalizationNotice';
import { compactNumber, moneyRowsLabel, percentLabel, revenueDisplay } from './visitorFormat';
import { formatCurrency } from '../../utils/currency';

function FunnelStep({ label, count, rate, tone = 'slate' }) {
    const color = {
        slate: 'bg-slate-500',
        teal: 'bg-teal-600',
        amber: 'bg-amber-500',
        emerald: 'bg-emerald-600',
    }[tone] || 'bg-slate-500';
    const width = Math.max(4, Math.min(100, Number(rate || 0)));

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3">
            <div className="flex items-baseline justify-between gap-3">
                <p className="text-xs font-semibold text-slate-500">{label}</p>
                <p className="text-sm font-semibold tabular-nums text-slate-900">{compactNumber(count)}</p>
            </div>
            <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${color}`} style={{ width: `${width}%` }} />
            </div>
            <p className="mt-2 text-xs text-slate-500">{percentLabel(rate)} rate</p>
        </div>
    );
}

export default function VisitorOverviewPanel({ summary = {}, pulse = {}, reportingCurrency, isLoading }) {
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

    return (
        <div className="space-y-4">
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard label={`Confirmed revenue (${summary.window_label || 'all time'})`} value={summaryRevenue.value} hint={summaryRevenue.hint} tone="success" isLoading={isLoading} />
                <MetricCard label="Completed unlock payments" value={compactNumber(summary.completed_payments)} hint={`${compactNumber(summary.total_unlocks)} attempts`} tone="accent" isLoading={isLoading} />
                <MetricCard label="Active unlocks" value={compactNumber(summary.active_unlocks)} hint={`${compactNumber(summary.pending_unlocks)} pending payment`} tone={Number(summary.pending_unlocks || 0) > 0 ? 'warning' : 'success'} isLoading={isLoading} />
                <MetricCard label="Selected-window revenue" value={pulseRevenue.value} hint={pulseRevenue.hint} tone="slate" isLoading={isLoading} />
            </div>

            <FxNormalizationNotice meta={reportingCurrency.isFlat ? summary.confirmed_revenue_normalization_meta : null} />

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <FunnelStep label="Eligible views" count={kpis.eligible_profile_views} rate={100} />
                <FunnelStep label="CTA clicks" count={kpis.unlock_cta_clicks} rate={kpis.cta_rate_percent} tone="teal" />
                <FunnelStep label="Checkout starts" count={kpis.checkout_starts} rate={kpis.checkout_rate_percent} tone="amber" />
                <FunnelStep label="Paid unlocks" count={kpis.successful_payments} rate={kpis.payment_completion_percent} tone="emerald" />
            </div>

            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <MetricCard label="Average order value" value={aov} hint={`${compactNumber(kpis.successful_payments)} successful`} />
                <MetricCard label="Repeat buyers" value={percentLabel(kpis.repeat_buyer_percent)} hint="Same masked visitor purchased again" />
                <MetricCard label="Upgrade rate" value={percentLabel(kpis.upgrade_rate_percent)} hint="Single unlock buyers upgraded" />
                <MetricCard label="Revenue per view" value={moneyRowsLabel((kpis.revenue || []).map((entry) => ({ ...entry, amount: Number(entry.amount || 0) / Math.max(1, Number(kpis.eligible_profile_views || 0)) })), '-')} hint="Native currencies" />
            </div>
        </div>
    );
}
