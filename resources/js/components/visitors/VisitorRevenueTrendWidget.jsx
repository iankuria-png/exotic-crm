import React, { useMemo, useState } from 'react';
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
    { key: 'value', label: 'Revenue', money: true },
    { key: 'payments_count', label: 'Paid unlocks', money: false },
    { key: 'average_ticket', label: 'Avg unlock', money: true },
];

const BUCKETS = [
    { key: 'auto', label: 'Auto' },
    { key: 'day', label: 'Daily' },
    { key: 'week', label: 'Weekly' },
    { key: 'month', label: 'Monthly' },
];

function EmptyState({ message, hint }) {
    return (
        <div className="flex h-72 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center">
            <p className="text-sm font-medium text-slate-600">{message}</p>
            {hint ? <p className="text-xs text-slate-500">{hint}</p> : null}
        </div>
    );
}

function parseDayLabel(label) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(label || ''));
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
}

function AxisTick({ x, y, payload }) {
    const label = String(payload?.value ?? '');
    const date = parseDayLabel(label);
    if (!date) {
        return <text x={x} y={y} dy={12} textAnchor="middle" fontSize={11} fill="#64748b">{label}</text>;
    }

    return (
        <text x={x} y={y} textAnchor="middle">
            <tspan x={x} dy={12} fontSize={11} fill="#475569" fontWeight={600}>
                {date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })}
            </tspan>
            <tspan x={x} dy={13} fontSize={10} fill="#94a3b8">
                {date.toLocaleDateString(undefined, { weekday: 'short' })}
            </tspan>
        </text>
    );
}

function formatValue(value, metric, currency) {
    if (metric.money) return formatCurrency(Number(value || 0), currency);
    return Number(value || 0).toLocaleString();
}

function TrendTooltip({ active, payload, label, currency, metric, showComparison }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};
    const date = parseDayLabel(label);
    const heading = date
        ? date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' })
        : label;

    return (
        <div className="min-w-[200px] rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{heading}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">
                Current: {formatValue(point[metric.key], metric, currency)}
            </p>
            {showComparison ? (
                <p className="text-sm font-medium text-slate-500">
                    Prior: {formatValue(point[`prior_${metric.key}`], metric, currency)}
                </p>
            ) : null}
            <p className="mt-2 border-t border-slate-100 pt-2 text-xs text-slate-500">
                {Number(point.payments_count || 0).toLocaleString()} paid unlocks · Avg {formatCurrency(point.average_ticket || 0, currency)}
            </p>
        </div>
    );
}

export default function VisitorRevenueTrendWidget({
    data,
    isLoading,
    errorMessage,
    currency = 'USD',
    bucket = 'auto',
    onBucketChange,
    onRetry,
}) {
    const [metricKey, setMetricKey] = useState('value');
    const [showComparison, setShowComparison] = useState(true);
    const metric = METRICS.find((item) => item.key === metricKey) || METRICS[0];
    const activeBucket = data?.window?.bucket || 'day';

    const points = useMemo(() => (data?.points || []).map((point) => ({
        ...point,
        value: Number(point.value || 0),
        prior_value: Number(point.prior_value || 0),
        payments_count: Number(point.payments_count || 0),
        prior_payments_count: Number(point.prior_payments_count || 0),
        average_ticket: Number(point.average_ticket || 0),
        prior_average_ticket: Number(point.prior_average_ticket || 0),
    })), [data?.points]);

    const hasData = points.some((point) => point[metric.key] > 0 || point[`prior_${metric.key}`] > 0);
    const totals = data?.totals || {};
    const delta = totals.delta_percent;

    return (
        <SectionFrame
            title="Visitor Revenue Trend"
            subtitle={`Contact-unlock revenue by ${activeBucket} bucket. Unlock revenue only — advertiser subscriptions are not included.`}
            className="overflow-hidden"
            action={(
                <div className="flex flex-wrap justify-end gap-2">
                    <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend metric">
                        {METRICS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                onClick={() => setMetricKey(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${metric.key === item.key ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
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
                                onClick={() => onBucketChange?.(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${bucket === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        onClick={() => setShowComparison((current) => !current)}
                        aria-pressed={showComparison}
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
                <div className="space-y-3">
                    <div className="h-10 w-64 animate-pulse rounded bg-slate-100" />
                    <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
                </div>
            ) : errorMessage ? (
                <div className="flex h-72 flex-col items-center justify-center gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 text-center">
                    <p className="text-sm font-semibold text-rose-900">{errorMessage}</p>
                    {onRetry ? <button type="button" className="crm-btn-secondary" onClick={onRetry}>Retry</button> : null}
                </div>
            ) : !hasData ? (
                <EmptyState
                    message="No visitor unlock revenue in this window yet."
                    hint="Widen the date range, or check that unlocks are enabled for this market under Setup."
                />
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-end gap-x-8 gap-y-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Window revenue</p>
                            <p className="mt-0.5 text-xl font-semibold tracking-tight text-slate-900">
                                {totals.normalized_total === null || totals.normalized_total === undefined
                                    ? '--'
                                    : formatCurrency(totals.normalized_total, currency)}
                            </p>
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Paid unlocks</p>
                            <p className="mt-0.5 text-xl font-semibold tracking-tight text-slate-900">
                                {Number(totals.payments_count || 0).toLocaleString()}
                            </p>
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Avg unlock</p>
                            <p className="mt-0.5 text-xl font-semibold tracking-tight text-slate-900">
                                {totals.average_order_value === null || totals.average_order_value === undefined
                                    ? '--'
                                    : formatCurrency(totals.average_order_value, currency)}
                            </p>
                        </div>
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Vs prior window</p>
                            <p className={`mt-0.5 text-xl font-semibold tracking-tight ${
                                delta === null || delta === undefined ? 'text-slate-400' : delta >= 0 ? 'text-emerald-700' : 'text-rose-600'
                            }`}
                            >
                                {delta === null || delta === undefined ? 'No prior data' : `${delta > 0 ? '+' : ''}${Number(delta).toFixed(1)}%`}
                            </p>
                        </div>
                    </div>

                    <div className="h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={points} margin={{ top: 12, right: 16, left: 4, bottom: 0 }}>
                                <defs>
                                    <linearGradient id="visitorRevenueTrend" x1="0" x2="0" y1="0" y2="1">
                                        <stop offset="5%" stopColor="#0f766e" stopOpacity={0.24} />
                                        <stop offset="95%" stopColor="#0f766e" stopOpacity={0.02} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                                <XAxis dataKey="label" tick={<AxisTick />} tickLine={false} axisLine={false} minTickGap={24} height={36} interval="preserveStartEnd" />
                                <YAxis
                                    tick={{ fontSize: 11, fill: '#64748b' }}
                                    tickLine={false}
                                    axisLine={false}
                                    width={72}
                                    tickFormatter={(value) => metric.money
                                        ? formatCurrency(value, currency).replace(`${currency} `, '')
                                        : Number(value || 0).toLocaleString()}
                                />
                                <Tooltip content={<TrendTooltip currency={currency} metric={metric} showComparison={showComparison} />} />
                                <Area type="monotone" dataKey={metric.key} stroke="#0f766e" strokeWidth={2.5} fill="url(#visitorRevenueTrend)" name="Current" dot={false} activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }} />
                                {showComparison ? (
                                    <Line type="monotone" dataKey={`prior_${metric.key}`} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                                ) : null}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}
