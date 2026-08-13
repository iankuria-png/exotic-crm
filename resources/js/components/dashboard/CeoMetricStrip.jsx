import React from 'react';
import MetricCard from '../MetricCard';
import { deltaTone, formatDelta, moneyFromBreakdown } from './ceoFormatters';

function metricValue(key, metric, reporting) {
    if (!metric) return '--';

    if (key === 'collected_revenue') {
        return moneyFromBreakdown(
            metric.value?.source_breakdown,
            metric.value?.normalized_total,
            metric.value?.normalized_currency || reporting?.targetCurrency,
            reporting?.displayMode
        );
    }

    if (key === 'new_user_revenue' || key === 'existing_user_revenue') {
        return moneyFromBreakdown(
            metric.value?.source_breakdown,
            metric.value?.normalized_amount,
            metric.value?.normalized_currency || reporting?.targetCurrency,
            reporting?.displayMode
        );
    }

    if (key === 'active_clients') {
        return Number(metric.value?.count || 0).toLocaleString();
    }

    if (key === 'failed_payment_recovery') {
        return `${Number(metric.value?.payment_recovery_rate || 0).toFixed(1)}%`;
    }

    return Number(metric.value?.count || 0).toLocaleString();
}

function metricSubHint(key, metric) {
    if (!metric) return '';

    if (key === 'active_clients' && metric.value?.approximate) {
        return `Snapshot as of ${metric.value?.as_of || 'nearest prior date'}`;
    }

    if (key === 'new_user_revenue' || key === 'existing_user_revenue') {
        const share = Number(metric.value?.share_percent || 0).toFixed(1);
        const payments = Number(metric.value?.payments_count || 0).toLocaleString();
        const clients = Number(metric.value?.clients_count || 0).toLocaleString();

        return `${share}% of revenue · ${payments} payments · ${clients} clients`;
    }

    if (key === 'collected_revenue') {
        return `${Number(metric.value?.payments_count || 0).toLocaleString()} successful payments`;
    }

    if (key === 'failed_payment_recovery') {
        const recovered = Number(metric.value?.recovered_payments || 0).toLocaleString();
        const failed = Number(metric.value?.failed_payments || 0).toLocaleString();
        const lost = Number(metric.value?.lost_payments || 0).toLocaleString();
        const customerRate = Number(metric.value?.customer_recovery_rate || 0).toFixed(1);

        return `${recovered} recovered · ${lost} lost of ${failed} failed · ${customerRate}% customers`;
    }

    return 'Compared with the prior matching window';
}

function formatCount(value) {
    return Number(value || 0).toLocaleString();
}

function formatSignedCount(value) {
    const numeric = Number(value || 0);
    return `${numeric > 0 ? '+' : ''}${numeric.toLocaleString()}`;
}

function snapshotMeta(item) {
    if (!item) return '';
    if (item.approximate) {
        return `nearest snapshot ${item.as_of || 'before date'}`;
    }

    return item.as_of ? `snapshot ${item.as_of}` : 'exact snapshot';
}

function SnapshotRow({ item, tone = 'default' }) {
    const toneClass = {
        current: 'text-teal-700',
        past: 'text-slate-800',
        range: 'text-sky-700',
    }[tone] || 'text-slate-800';

    return (
        <div
            className="flex items-center justify-between gap-3 rounded-lg border border-slate-100 bg-slate-50/70 px-2.5 py-2"
            title={snapshotMeta(item)}
        >
            <div className="min-w-0">
                <p className="truncate text-xs font-semibold text-slate-700">{item?.label || '--'}</p>
                <p className="mt-0.5 truncate text-[11px] text-slate-400">
                    {item?.date || '--'}{item?.approximate ? ` · as of ${item?.as_of || 'prior'}` : ''}
                </p>
            </div>
            <p className={`crm-mono shrink-0 text-sm font-semibold ${toneClass}`}>{formatCount(item?.count)}</p>
        </div>
    );
}

function ActiveSubscribersCard({ metric, isLoading, onOpen }) {
    const history = metric?.value?.history || {};
    const rangeChange = history.range_change || {};
    const trendTone = deltaTone(metric?.delta_percent);
    const trendClass = trendTone === 'success'
        ? 'text-teal-700'
        : trendTone === 'warning'
            ? 'text-amber-600'
            : 'text-slate-500';

    const content = (
        <div className="crm-kpi h-full text-left">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.10em] text-slate-500">
                        <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-teal-500" aria-hidden="true" />
                        {metric?.label || 'Active Subscribers'}
                    </p>
                    <p className="mt-2 text-3xl leading-tight font-semibold tracking-tight text-slate-900">
                        {isLoading
                            ? <span className="inline-block h-9 w-24 animate-pulse rounded bg-slate-100" />
                            : formatCount(metric?.value?.count)}
                    </p>
                </div>
                <div className="rounded-lg bg-slate-50 px-2.5 py-1.5 text-right ring-1 ring-slate-100">
                    <p className={`crm-mono text-sm font-semibold ${trendClass}`}>{formatDelta(metric?.delta_percent)}</p>
                    <p className="mt-0.5 text-[10px] font-medium text-slate-400">vs range start</p>
                </div>
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                <SnapshotRow item={history.current || metric?.value} tone="current" />
                <SnapshotRow item={history.yesterday} tone="past" />
                <SnapshotRow item={history.seven_days_ago} tone="past" />
                <SnapshotRow item={history.thirty_days_ago} tone="past" />
                <SnapshotRow item={history.range_start} tone="range" />
                <div
                    className="flex items-center justify-between gap-3 rounded-lg border border-teal-100 bg-teal-50/70 px-2.5 py-2"
                    title="Range end minus range start."
                >
                    <div className="min-w-0">
                        <p className="truncate text-xs font-semibold text-teal-900">Range change</p>
                        <p className="mt-0.5 truncate text-[11px] text-teal-700/70">{rangeChange.from_date || '--'} to {rangeChange.to_date || '--'}</p>
                    </div>
                    <p className="crm-mono shrink-0 text-sm font-semibold text-teal-900">{formatSignedCount(rangeChange.change)}</p>
                </div>
            </div>

            <p className="mt-3 text-xs leading-5 text-slate-500">
                Snapshot-backed paid customer base. Approximate rows use the nearest earlier snapshot when that exact date has not been captured.
            </p>
        </div>
    );

    if (metric?.href && typeof onOpen === 'function') {
        return (
            <button
                type="button"
                onClick={() => onOpen(metric.href)}
                className="h-full w-full rounded-xl text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 active:scale-[0.995]"
            >
                {content}
            </button>
        );
    }

    return content;
}

export default function CeoMetricStrip({ metrics = {}, reporting, isLoading, onOpen }) {
    const order = [
        ['collected_revenue', 'Collected Revenue'],
        ['active_clients', 'Active Subscribers'],
        ['new_user_revenue', 'New User Revenue'],
        ['existing_user_revenue', 'Existing User Revenue'],
        ['failed_payment_recovery', 'Failed Payment Recovery'],
    ];

    return (
        <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            {order.map(([key, fallbackLabel]) => {
                const metric = metrics[key];
                const tone = deltaTone(metric?.delta_percent);

                if (key === 'active_clients') {
                    return (
                        <div key={key} className="md:col-span-2 xl:col-span-2">
                            <ActiveSubscribersCard
                                metric={metric || { label: fallbackLabel }}
                                isLoading={isLoading}
                                onOpen={onOpen}
                            />
                        </div>
                    );
                }

                return (
                    <MetricCard
                        key={key}
                        label={metric?.label || fallbackLabel}
                        value={metricValue(key, metric, reporting)}
                        hint={formatDelta(metric?.delta_percent)}
                        subHint={metricSubHint(key, metric)}
                        tone={tone}
                        isLoading={isLoading}
                        onClick={metric?.href ? () => onOpen(metric.href) : undefined}
                    />
                );
            })}
        </section>
    );
}
