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
import { getCountryFlag } from '../../utils/flags';

const METRICS = [
    { key: 'gross', label: 'Content sales', kind: 'money', hint: 'Buyer spend on active purchases' },
    { key: 'creator_credit', label: 'Creator earnings', kind: 'money', hint: 'Wallet credit earned by escorts' },
    { key: 'pass_revenue', label: 'Pass revenue', kind: 'money', hint: 'Exotic income from selling passes' },
    { key: 'purchases', label: 'Purchases', kind: 'count', hint: 'Paid and available to buyers' },
    { key: 'completion_rate', label: 'Checkout', kind: 'percent', hint: 'Paid checkouts ÷ attempts' },
];

const BUCKETS = [
    { key: 'auto', label: 'Auto' },
    { key: 'day', label: 'Daily' },
    { key: 'week', label: 'Weekly' },
    { key: 'month', label: 'Monthly' },
];

function parseDayLabel(label) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(label || ''));
    if (!match) return null;
    const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    return Number.isNaN(date.getTime()) ? null : date;
}

function bucketHeading(label) {
    const date = parseDayLabel(label);
    if (date) return date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short', year: 'numeric' });
    const month = /^(\d{4})-(\d{2})$/.exec(String(label || ''));
    if (month) return new Date(Number(month[1]), Number(month[2]) - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
    const week = /^(\d{4})-W(\d{2})$/.exec(String(label || ''));
    return week ? `Week ${Number(week[2])}, ${week[1]}` : label;
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
    if (value === null || value === undefined) return '--';
    if (metric.kind === 'money') return formatCurrency(Number(value), currency);
    if (metric.kind === 'percent') return `${Number(value).toFixed(1)}%`;
    return Number(value).toLocaleString();
}

function formatDelta(delta, metric) {
    if (delta === null || delta === undefined) return null;
    const sign = delta > 0 ? '+' : '';
    return metric.kind === 'percent' ? `${sign}${Number(delta).toFixed(1)} pts` : `${sign}${Number(delta).toFixed(1)}%`;
}

function nativeLabel(breakdown = {}) {
    const rows = Object.entries(breakdown).filter(([, amount]) => Number(amount) !== 0);
    return rows.length ? rows.map(([currency, amount]) => formatCurrency(Number(amount), currency)).join(' + ') : '--';
}

function TrendTooltip({ active, payload, label, currency, metric, showComparison }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};

    return (
        <div className="min-w-[220px] rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{bucketHeading(label)}</p>
            <p className="mt-1 text-sm font-semibold text-slate-900">
                {metric.label}: {formatValue(point[metric.key], metric, currency)}
            </p>
            {showComparison ? (
                <p className="text-sm font-medium text-slate-500">
                    Prior: {formatValue(point[`prior_${metric.key}`], metric, currency)}
                </p>
            ) : null}
            <div className="mt-2 space-y-0.5 border-t border-slate-100 pt-2 text-xs text-slate-500">
                <p>{Number(point.purchases || 0).toLocaleString()} purchases · {Number(point.paid_attempts || 0).toLocaleString()} paid of {Number(point.attempts || 0).toLocaleString()} checkouts</p>
                <p>Sales {formatCurrency(point.gross || 0, currency)} · Pass {formatCurrency(point.pass_revenue || 0, currency)}</p>
                {point.fx_unresolved ? <p className="font-medium text-amber-700">Some sales have no FX rate and are excluded.</p> : null}
            </div>
        </div>
    );
}

function Notices({ window: info = {}, totals = {} }) {
    const notices = [];
    if (info.native_fallback) {
        notices.push(`This scope mixes ${(info.source_currencies || []).join(', ')}, so amounts are converted to ${info.currency}. Choose one market to see its native currency.`);
    }
    if ((totals.unresolved_currencies || []).length) {
        notices.push(`No FX rate cached for ${totals.unresolved_currencies.join(', ')} — those amounts are excluded from converted totals. Native-currency cards below are unaffected.`);
    }
    if (info.currency_mode === 'flat' && totals.fx_stale) {
        notices.push(`Some conversions use the most recent earlier rate (latest ${totals.fx_as_of}).`);
    }
    if (!notices.length) return null;

    return (
        <div className="space-y-1 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs font-medium text-amber-800">
            {notices.map((notice) => <p key={notice}>{notice}</p>)}
        </div>
    );
}

function MarketBreakdown({ markets, currency, isNative }) {
    const max = Math.max(...markets.map((market) => Number(market.gross || 0)), 0);

    return (
        <div className="border-t border-slate-100 pt-4">
            <p className="mb-2 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">By market · content sales {isNative ? '' : `in ${currency}`}</p>
            <ul className="divide-y divide-slate-100">
                {markets.map((market) => {
                    const flag = getCountryFlag(market.country);
                    const delta = market.delta_percent;
                    return (
                        <li key={market.platform_id} className="grid gap-x-4 gap-y-1 py-2.5 text-sm sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_auto]">
                            <div className="min-w-0">
                                <p className="truncate font-semibold text-slate-900">{flag ? `${flag} ` : ''}{market.name}</p>
                                <div className="mt-1 h-1.5 rounded-full bg-slate-100">
                                    <div className="h-1.5 rounded-full bg-teal-600" style={{ width: `${max > 0 ? Math.max(2, (Number(market.gross || 0) / max) * 100) : 0}%` }} />
                                </div>
                            </div>
                            <p className="text-xs text-slate-500 sm:self-center">
                                {Number(market.purchases || 0).toLocaleString()} purchases · {market.completion_rate === null ? 'no checkouts' : `${Number(market.completion_rate).toFixed(1)}% checkout`}
                                {Number(market.pass_revenue || 0) ? ` · pass ${formatCurrency(market.pass_revenue, currency)}` : ''}
                            </p>
                            <div className="text-right sm:self-center">
                                <p className="font-semibold text-slate-900">
                                    {market.fx_unresolved ? nativeLabel(market.native_gross) : formatCurrency(market.gross || 0, currency)}
                                </p>
                                <p className="text-xs text-slate-500">
                                    {market.fx_unresolved ? 'No FX rate' : `${Number(market.share_percent || 0).toFixed(1)}%`}
                                    {delta === null || delta === undefined ? '' : (
                                        <span className={delta >= 0 ? 'text-emerald-700' : 'text-rose-600'}> · {delta > 0 ? '+' : ''}{Number(delta).toFixed(1)}% vs prior</span>
                                    )}
                                </p>
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

export default function MonetizeTrendWidget({
    data,
    isLoading,
    errorMessage,
    bucket = 'auto',
    onBucketChange,
    onRetry,
    showMarkets = true,
}) {
    const [metricKey, setMetricKey] = useState('gross');
    const [comparisonOn, setComparisonOn] = useState(true);
    const metric = METRICS.find((item) => item.key === metricKey) || METRICS[0];
    const info = data?.window || {};
    const currency = info.currency || 'USD';
    const totals = data?.totals || {};
    const hasPrior = Boolean(data?.prior_totals);
    const showComparison = comparisonOn && hasPrior;

    const points = useMemo(() => (data?.points || []).map((point) => ({
        ...point,
        [metric.key]: point[metric.key] === null ? null : Number(point[metric.key] || 0),
        [`prior_${metric.key}`]: point[`prior_${metric.key}`] === null ? null : Number(point[`prior_${metric.key}`] || 0),
    })), [data?.points, metric.key]);

    const hasActivity = Number(totals.attempts || 0) > 0 || Number(totals.pass_revenue || 0) > 0
        || (data?.prior_totals && (Number(data.prior_totals.attempts || 0) > 0 || Number(data.prior_totals.pass_revenue || 0) > 0));
    const delta = data?.deltas?.[metric.key];
    const summary = [
        { label: 'Content sales', value: formatValue(totals.gross, METRICS[0], currency) },
        { label: 'Purchases', value: formatValue(totals.purchases, METRICS[3], currency) },
        { label: 'Checkout completion', value: formatValue(totals.completion_rate, METRICS[4], currency) },
        { label: 'Avg sale', value: totals.average_sale === null || totals.average_sale === undefined ? '--' : formatCurrency(totals.average_sale, currency) },
        { label: 'Pass revenue', value: formatValue(totals.pass_revenue, METRICS[2], currency) },
    ];
    const markets = data?.markets || [];
    const windowLabel = info.all_time ? 'All time' : `${info.from} → ${info.to}`;

    return (
        <SectionFrame
            title="Monetize Performance Trend"
            subtitle={`${metric.hint}, by ${info.bucket || 'day'} · ${windowLabel} · ${info.currency_mode === 'native' ? `native ${currency}` : `converted to ${currency}`}. Pass revenue is never added to buyer spend.`}
            className="overflow-hidden"
            headerClassName="flex-col xl:flex-row"
            action={(
                <div className="flex flex-wrap gap-2 xl:justify-end">
                    <div className="inline-flex flex-wrap rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Trend metric">
                        {METRICS.map((item) => (
                            <button
                                key={item.key}
                                type="button"
                                aria-pressed={metric.key === item.key}
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
                                aria-pressed={bucket === item.key}
                                onClick={() => onBucketChange?.(item.key)}
                                className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${bucket === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'}`}
                            >
                                {item.label}
                            </button>
                        ))}
                    </div>
                    {hasPrior ? (
                        <button
                            type="button"
                            onClick={() => setComparisonOn((current) => !current)}
                            aria-pressed={comparisonOn}
                            className={`rounded-md border px-3 py-1.5 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                comparisonOn ? 'border-teal-200 bg-teal-50 text-teal-800' : 'border-slate-300 bg-white text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            Prior window
                        </button>
                    ) : null}
                </div>
            )}
        >
            {isLoading ? (
                <div className="space-y-3">
                    <div className="h-14 animate-pulse rounded-lg bg-slate-100" />
                    <div className="h-72 animate-pulse rounded-lg bg-slate-100" />
                </div>
            ) : errorMessage ? (
                <div className="flex h-72 flex-col items-center justify-center gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 text-center">
                    <p className="text-sm font-semibold text-rose-900">{errorMessage}</p>
                    {onRetry ? <button type="button" className="crm-btn-secondary" onClick={onRetry}>Retry</button> : null}
                </div>
            ) : !hasActivity ? (
                <div className="flex h-72 flex-col items-center justify-center gap-1 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center">
                    <p className="text-sm font-medium text-slate-600">No checkouts or pass activations in this window yet.</p>
                    <p className="text-xs text-slate-500">Widen the date range, include sandbox, or check the market under Setup.</p>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-end gap-x-8 gap-y-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                        {summary.map((item) => (
                            <div key={item.label}>
                                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{item.label}</p>
                                <p className="mt-0.5 text-xl font-semibold tracking-tight text-slate-900">{item.value}</p>
                            </div>
                        ))}
                        <div>
                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{metric.label} vs prior</p>
                            <p className={`mt-0.5 text-xl font-semibold tracking-tight ${
                                delta === null || delta === undefined ? 'text-slate-400' : delta >= 0 ? 'text-emerald-700' : 'text-rose-600'
                            }`}
                            >
                                {info.all_time ? 'All time' : formatDelta(delta, metric) || 'No prior data'}
                            </p>
                        </div>
                    </div>

                    <Notices window={info} totals={totals} />

                    <div className="h-80">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={points} margin={{ top: 12, right: 16, left: 4, bottom: 0 }}>
                                <defs>
                                    <linearGradient id="monetizeTrend" x1="0" x2="0" y1="0" y2="1">
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
                                    domain={metric.kind === 'percent' ? [0, 100] : [0, 'auto']}
                                    allowDecimals={metric.kind !== 'count'}
                                    tickFormatter={(value) => metric.kind === 'money'
                                        ? Number(value || 0).toLocaleString()
                                        : metric.kind === 'percent' ? `${value}%` : Number(value || 0).toLocaleString()}
                                />
                                <Tooltip content={<TrendTooltip currency={currency} metric={metric} showComparison={showComparison} />} />
                                <Area type="monotone" dataKey={metric.key} stroke="#0f766e" strokeWidth={2.5} fill="url(#monetizeTrend)" name="Current" dot={false} activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }} />
                                {showComparison ? (
                                    <Line type="monotone" dataKey={`prior_${metric.key}`} stroke="#94a3b8" strokeWidth={2} strokeDasharray="5 5" dot={false} name="Prior" />
                                ) : null}
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>

                    {showMarkets && markets.length > 1 ? (
                        <MarketBreakdown markets={markets} currency={currency} isNative={info.currency_mode === 'native'} />
                    ) : null}
                </div>
            )}
        </SectionFrame>
    );
}
