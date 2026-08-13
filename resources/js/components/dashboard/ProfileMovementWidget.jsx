import React, { useMemo } from 'react';
import {
    Area,
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import SectionFrame from '../SectionFrame';

const BUCKETS = [
    { key: 'day', label: 'Day' },
    { key: 'week', label: 'Week' },
    { key: 'month', label: 'Month' },
];

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatNumber(value) {
    return asNumber(value).toLocaleString();
}

function formatSigned(value) {
    const numeric = asNumber(value);
    return `${numeric > 0 ? '+' : ''}${numeric.toLocaleString()}`;
}

function formatPercent(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) {
        return 'No baseline';
    }

    const numeric = Number(value);
    return `${numeric >= 0 ? '+' : ''}${numeric.toFixed(1)}%`;
}

function formatRange(point) {
    if (!point?.from || !point?.to || point.from === point.to) {
        return point?.from || point?.label || '';
    }

    return `${point.from} to ${point.to}`;
}

function MetricTile({ label, value, helper, tone = 'neutral' }) {
    const tones = {
        active: 'border-teal-200 bg-teal-50/70 text-teal-800',
        inactive: 'border-rose-200 bg-rose-50/70 text-rose-800',
        net: 'border-slate-200 bg-white text-slate-900',
        neutral: 'border-slate-200 bg-white text-slate-900',
    };

    return (
        <div className={`rounded-lg border px-3.5 py-3 ${tones[tone] || tones.neutral}`}>
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] opacity-70">{label}</p>
            <p className="mt-2 crm-mono text-2xl font-semibold leading-none tracking-tight">{value}</p>
            {helper ? <p className="mt-2 text-xs font-medium opacity-75">{helper}</p> : null}
        </div>
    );
}

function EmptyState({ message }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function MovementTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-0.5 text-[11px] font-medium text-slate-400">{formatRange(point)}</p>
            <div className="mt-2 space-y-1.5 text-sm">
                <p className="flex items-center justify-between gap-6 text-teal-700">
                    <span>Activated</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.active_profiles)}</span>
                </p>
                <p className="flex items-center justify-between gap-6 text-rose-700">
                    <span>Inactive</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.inactive_profiles)}</span>
                </p>
                <p className="flex items-center justify-between gap-6 text-slate-800">
                    <span>Net</span>
                    <span className="crm-mono font-semibold">{formatSigned(point.net_active_movement)}</span>
                </p>
            </div>
        </div>
    );
}

export default function ProfileMovementWidget({
    data,
    isLoading = false,
    errorMessage = null,
    bucket = 'day',
    onBucketChange,
    className = '',
}) {
    const points = useMemo(() => (
        Array.isArray(data?.points)
            ? data.points.map((point) => ({
                ...point,
                active_profiles: asNumber(point.active_profiles),
                inactive_profiles: asNumber(point.inactive_profiles),
                created_profiles: asNumber(point.created_profiles),
                net_active_movement: asNumber(point.net_active_movement),
            }))
            : []
    ), [data?.points]);
    const totals = data?.totals || {};
    const comparison = data?.comparison || {};
    const currentScope = data?.current_scope || {};
    const hasMovement = points.some((point) => (
        point.active_profiles > 0
        || point.inactive_profiles > 0
        || point.net_active_movement !== 0
    ));
    const resolvedBucket = BUCKETS.some((item) => item.key === bucket) ? bucket : (data?.bucket || 'day');
    const activeShare = asNumber(currentScope.active_share_percent);

    return (
        <SectionFrame
            title="Profile Movement"
            subtitle="Activated vs inactive paid profiles across the selected window."
            className={`overflow-hidden ${className}`}
            contentClassName="space-y-4"
            action={(
                <div className="inline-flex rounded-md border border-slate-300 bg-white p-0.5" role="group" aria-label="Profile movement bucket">
                    {BUCKETS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onBucketChange?.(item.key)}
                            className={`rounded px-2.5 py-1.5 text-xs font-semibold transition ${
                                resolvedBucket === item.key ? 'bg-teal-700 text-white' : 'text-slate-600 hover:bg-slate-50'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        >
            <div className="grid gap-3 md:grid-cols-4">
                <MetricTile
                    label="Activated"
                    value={formatNumber(totals.active_profiles)}
                    helper={`${formatPercent(comparison.active_profiles?.percent)} vs prior`}
                    tone="active"
                />
                <MetricTile
                    label="Inactive"
                    value={formatNumber(totals.inactive_profiles)}
                    helper={`${formatPercent(comparison.inactive_profiles?.percent)} vs prior`}
                    tone="inactive"
                />
                <MetricTile
                    label="Net Movement"
                    value={formatSigned(totals.net_active_movement)}
                    helper={`${formatSigned(comparison.net_active_movement?.delta)} profile swing`}
                    tone="net"
                />
                <MetricTile
                    label="Current Active"
                    value={`${activeShare.toFixed(1)}%`}
                    helper={`${formatNumber(currentScope.active_profiles)} active · ${formatNumber(currentScope.inactive_profiles)} inactive`}
                />
            </div>

            {isLoading ? (
                <div className="h-80 animate-pulse rounded-lg bg-slate-100" />
            ) : errorMessage ? (
                <EmptyState message={errorMessage} />
            ) : !hasMovement ? (
                <EmptyState message="No profile movement in this window yet." />
            ) : (
                <div className="h-80">
                    <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart data={points} margin={{ top: 16, right: 16, left: 4, bottom: 0 }}>
                            <defs>
                                <linearGradient id="profileMovementActive" x1="0" x2="0" y1="0" y2="1">
                                    <stop offset="5%" stopColor="#14b8a6" stopOpacity={0.28} />
                                    <stop offset="95%" stopColor="#14b8a6" stopOpacity={0.03} />
                                </linearGradient>
                            </defs>
                            <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                            <XAxis
                                dataKey="label"
                                tick={{ fontSize: 11, fill: '#64748b', fontWeight: 600 }}
                                tickLine={false}
                                axisLine={false}
                                minTickGap={18}
                                interval="preserveStartEnd"
                            />
                            <YAxis
                                tick={{ fontSize: 11, fill: '#64748b' }}
                                tickLine={false}
                                axisLine={false}
                                width={56}
                                tickFormatter={(value) => Number(value || 0).toLocaleString()}
                            />
                            <Tooltip content={<MovementTooltip />} />
                            <Bar dataKey="net_active_movement" radius={[5, 5, 0, 0]} barSize={18} name="Net movement">
                                {points.map((point) => (
                                    <Cell
                                        key={`net-${point.label}`}
                                        fill={point.net_active_movement >= 0 ? '#99f6e4' : '#fecdd3'}
                                    />
                                ))}
                            </Bar>
                            <Area
                                type="monotone"
                                dataKey="active_profiles"
                                stroke="#0f766e"
                                strokeWidth={2.5}
                                fill="url(#profileMovementActive)"
                                name="Activated"
                                dot={false}
                                activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }}
                            />
                            <Line
                                type="monotone"
                                dataKey="inactive_profiles"
                                stroke="#e11d48"
                                strokeWidth={2.25}
                                dot={false}
                                name="Inactive"
                                activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#e11d48' }}
                            />
                        </ComposedChart>
                    </ResponsiveContainer>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-500">
                <span>
                    Window <span className="crm-mono font-semibold text-slate-700">{data?.range?.from || '--'} to {data?.range?.to || '--'}</span>
                </span>
                <span>
                    Current scope <span className="crm-mono font-semibold text-slate-700">{formatNumber(currentScope.total_profiles)} profiles</span>
                </span>
            </div>
        </SectionFrame>
    );
}
