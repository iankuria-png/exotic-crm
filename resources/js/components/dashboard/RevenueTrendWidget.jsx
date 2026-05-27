import React from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import SectionFrame from '../SectionFrame';
import { formatCurrency } from '../../utils/currency';

const METRICS = [
    { key: 'revenue', label: 'Revenue' },
    { key: 'payments', label: 'Payments' },
    { key: 'average_ticket', label: 'Avg ticket' },
];

const BUCKETS = [
    { key: 'auto', label: 'Auto' },
    { key: 'day', label: 'Daily' },
    { key: 'week', label: 'Weekly' },
    { key: 'month', label: 'Monthly' },
];

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function valueForMetric(point, metric, prefix = '') {
    const key = prefix ? `${prefix}_${metric}` : metric;
    if (metric === 'revenue') {
        return Number(prefix ? point.prior_value : point.value || 0);
    }

    return Number(point[key] || 0);
}

function formatMetricValue(value, metric, currency) {
    if (metric === 'revenue' || metric === 'average_ticket') {
        return formatCurrency(value || 0, currency);
    }

    return Number(value || 0).toLocaleString();
}

function TrendTooltip({ active, payload, label, currency, metric }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};
    const current = valueForMetric(point, metric);
    const prior = valueForMetric(point, metric, 'prior');

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">Current: {formatMetricValue(current, metric, currency)}</p>
            <p className="text-sm font-medium text-slate-500">Prior: {formatMetricValue(prior, metric, currency)}</p>
            <p className="mt-2 text-xs text-slate-500">
                {Number(point.payments_count || 0).toLocaleString()} payments · Avg {formatCurrency(point.average_ticket || 0, currency)}
            </p>
        </div>
    );
}

export default function RevenueTrendWidget({
    data,
    isLoading,
    errorMessage,
    currency = 'USD',
    metric = 'revenue',
    onMetricChange,
    bucket = 'auto',
    onBucketChange,
    showComparison = true,
    onShowComparisonChange,
}) {
    const points = (data?.points || []).map((point) => ({
        ...point,
        revenue: Number(point.value || 0),
        prior_revenue: Number(point.prior_value || 0),
        payments: Number(point.payments_count || 0),
        prior_payments: Number(point.prior_payments_count || 0),
        average_ticket: Number(point.average_ticket || 0),
        prior_average_ticket: Number(point.prior_average_ticket || 0),
    }));
    const hasData = points.some((point) => Number(valueForMetric(point, metric) || 0) > 0 || Number(valueForMetric(point, metric, 'prior') || 0) > 0);
    const currentKey = metric === 'revenue' ? 'revenue' : metric;
    const priorKey = `prior_${currentKey}`;
    const activeMetric = METRICS.find((item) => item.key === metric)?.label || 'Revenue';

    return (
        <SectionFrame
            title="Revenue Trend"
            subtitle={`${activeMetric} by ${data?.bucket || 'auto'} bucket, with optional prior-window overlay.`}
            className="overflow-hidden"
            contentClassName="min-h-[330px]"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend metric">
                        {METRICS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => onMetricChange(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${metric === item.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend bucket">
                        {BUCKETS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => onBucketChange(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${bucket === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        onClick={() => onShowComparisonChange(!showComparison)}
                        className={`rounded-md border px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                            showComparison ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                        }`}
                    >
                        Prior window
                    </button>
                </div>
            )}
        >
            {isLoading ? (
                <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
            ) : errorMessage ? (
                <EmptyState message={errorMessage} />
            ) : !hasData ? (
                <EmptyState message="No collected revenue in this window yet." />
            ) : (
                <div className="h-72">
                    <ResponsiveContainer width="100%" height="100%">
                        <AreaChart data={points} margin={{ top: 12, right: 16, left: 4, bottom: 0 }}>
                            <defs>
                                <linearGradient id="ceoRevenueTrend" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="5%" stopColor="#0f766e" stopOpacity={0.24} />
                                    <stop offset="95%" stopColor="#0f766e" stopOpacity={0.02} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                            <XAxis dataKey="label" tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} minTickGap={20} />
                            <YAxis tick={{ fontSize: 11, fill: '#64748b' }} tickLine={false} axisLine={false} width={72} tickFormatter={(value) => metric === 'payments' ? Number(value || 0).toLocaleString() : formatCurrency(value, currency).replace(`${currency} `, '')} />
                            <Tooltip content={<TrendTooltip currency={currency} metric={metric} />} />
                            <Area type="monotone" dataKey={currentKey} stroke="#0f766e" strokeWidth={2.5} fill="url(#ceoRevenueTrend)" name="Current" />
                            {showComparison ? (
                                <Line type="monotone" dataKey={priorKey} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                            ) : null}
                        </AreaChart>
                    </ResponsiveContainer>
                </div>
            )}
        </SectionFrame>
    );
}
