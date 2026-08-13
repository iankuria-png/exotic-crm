import React, { useMemo } from 'react';
import {
    Area,
    Bar,
    CartesianGrid,
    Cell,
    ComposedChart,
    Line,
    ReferenceLine,
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

function clampPercent(value) {
    return Math.max(0, Math.min(100, asNumber(value)));
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

function movementTone(value) {
    const numeric = asNumber(value);
    if (numeric > 0) return 'positive';
    if (numeric < 0) return 'negative';
    return 'flat';
}

function movementLabel(value) {
    const tone = movementTone(value);
    if (tone === 'positive') return 'Active base expanding';
    if (tone === 'negative') return 'Inactive exits outpacing activations';
    return 'No net movement';
}

function comparisonLine(comparison, key, noun) {
    const entry = comparison?.[key] || {};
    if (entry.percent === null || entry.percent === undefined) {
        return `No prior ${noun} baseline`;
    }

    return `${formatPercent(entry.percent)} vs prior ${noun}`;
}

function EmptyState({ title, message }) {
    return (
        <div className="rounded-[1.15rem] border border-dashed border-slate-200 bg-slate-50/80 px-5 py-10 text-center">
            <p className="text-sm font-semibold text-slate-900">{title}</p>
            <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">{message}</p>
        </div>
    );
}

function ErrorState({ message }) {
    const routeMissing = /route .*profile-movement.*could not be found/i.test(String(message || ''));

    return (
        <div className="rounded-[1.15rem] border border-rose-200 bg-rose-50 px-5 py-5">
            <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <p className="text-sm font-semibold text-rose-950">
                        {routeMissing ? 'Profile movement API is not available on this server build.' : 'Profile movement could not be loaded.'}
                    </p>
                    <p className="mt-1 max-w-3xl text-sm leading-6 text-rose-700">
                        {routeMissing
                            ? 'The dashboard route exists in the current code. Production is likely serving a stale route cache or an older PHP release.'
                            : message}
                    </p>
                </div>
                <div className="rounded-xl bg-white/70 px-3 py-2 text-xs font-semibold uppercase tracking-[0.14em] text-rose-700 ring-1 ring-rose-200">
                    Data unavailable
                </div>
            </div>
        </div>
    );
}

function MovementTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};

    return (
        <div className="rounded-xl border border-slate-200 bg-white/95 p-3 shadow-[0_18px_48px_rgba(15,23,42,0.16)]">
            <p className="text-xs font-semibold text-slate-900">{label}</p>
            <p className="mt-0.5 text-[11px] font-medium text-slate-400">{formatRange(point)}</p>
            <div className="mt-3 grid min-w-48 gap-2 text-sm">
                <p className="flex items-center justify-between gap-8 text-teal-700">
                    <span>Activated</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.active_profiles)}</span>
                </p>
                <p className="flex items-center justify-between gap-8 text-rose-700">
                    <span>Inactive</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.inactive_profiles)}</span>
                </p>
                <p className="flex items-center justify-between gap-8 border-t border-slate-100 pt-2 text-slate-900">
                    <span>Net movement</span>
                    <span className="crm-mono font-semibold">{formatSigned(point.net_active_movement)}</span>
                </p>
            </div>
        </div>
    );
}

function MovementSkeleton() {
    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
            <div className="rounded-[1.25rem] bg-slate-100 p-4">
                <div className="h-8 w-48 animate-pulse rounded-lg bg-white" />
                <div className="mt-6 h-72 animate-pulse rounded-2xl bg-white" />
            </div>
            <div className="rounded-[1.25rem] bg-slate-100 p-4">
                <div className="h-8 w-36 animate-pulse rounded-lg bg-white" />
                <div className="mt-6 space-y-3">
                    <div className="h-20 animate-pulse rounded-2xl bg-white" />
                    <div className="h-20 animate-pulse rounded-2xl bg-white" />
                    <div className="h-20 animate-pulse rounded-2xl bg-white" />
                </div>
            </div>
        </div>
    );
}

function MicroMetric({ label, value, helper, tone = 'neutral' }) {
    const toneClass = {
        active: 'text-teal-700',
        inactive: 'text-rose-700',
        net: 'text-slate-950',
        neutral: 'text-slate-800',
    }[tone] || 'text-slate-800';

    return (
        <div className="min-w-0">
            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">{label}</p>
            <p className={`mt-1 crm-mono text-xl font-semibold ${toneClass}`}>{value}</p>
            <p className="mt-1 truncate text-xs text-slate-500">{helper}</p>
        </div>
    );
}

function CurrentBaseRail({ currentScope, totals, comparison, data }) {
    const active = asNumber(currentScope.active_profiles);
    const inactive = asNumber(currentScope.inactive_profiles);
    const total = asNumber(currentScope.total_profiles);
    const activeShare = total > 0
        ? clampPercent(currentScope.active_share_percent || (active / total) * 100)
        : 0;
    const inactiveShare = total > 0 ? clampPercent(100 - activeShare) : 0;
    const netTone = movementTone(totals.net_active_movement);

    return (
        <aside className="rounded-[1.2rem] bg-white p-4 ring-1 ring-slate-200">
            <div>
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Current base</p>
                <div className="mt-3 flex items-end justify-between gap-3">
                    <div>
                        <p className="crm-mono text-3xl font-semibold tracking-tight text-slate-950">{formatNumber(total)}</p>
                        <p className="mt-1 text-sm text-slate-500">profiles in selected scope</p>
                    </div>
                    <div className={`rounded-xl px-2.5 py-1 text-xs font-semibold ${
                        netTone === 'positive'
                            ? 'bg-teal-50 text-teal-700'
                            : netTone === 'negative'
                                ? 'bg-rose-50 text-rose-700'
                                : 'bg-slate-100 text-slate-600'
                    }`}>
                        {movementLabel(totals.net_active_movement)}
                    </div>
                </div>
                <div className="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                    <div className="flex h-full">
                        <div className="bg-teal-600" style={{ width: `${activeShare}%` }} />
                        <div className="bg-rose-400" style={{ width: `${inactiveShare}%` }} />
                    </div>
                </div>
                <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <p className="text-xs font-medium text-slate-400">Active now</p>
                        <p className="mt-1 crm-mono font-semibold text-teal-700">{formatNumber(active)}</p>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-slate-400">Inactive now</p>
                        <p className="mt-1 crm-mono font-semibold text-rose-700">{formatNumber(inactive)}</p>
                    </div>
                </div>
            </div>

            <div className="mt-5 rounded-2xl bg-slate-50 p-3">
                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-400">Window</p>
                <p className="mt-2 crm-mono text-sm font-semibold text-slate-900">
                    {data?.range?.from || '--'} to {data?.range?.to || '--'}
                </p>
                <p className="mt-2 text-xs leading-5 text-slate-500">
                    {comparisonLine(comparison, 'net_active_movement', 'movement')}
                </p>
            </div>

            <div className="mt-3 grid gap-2">
                <div className="flex items-center justify-between rounded-xl bg-teal-50 px-3 py-2 text-sm">
                    <span className="font-medium text-teal-800">Activated in window</span>
                    <span className="crm-mono font-semibold text-teal-900">{formatNumber(totals.active_profiles)}</span>
                </div>
                <div className="flex items-center justify-between rounded-xl bg-rose-50 px-3 py-2 text-sm">
                    <span className="font-medium text-rose-800">Moved inactive</span>
                    <span className="crm-mono font-semibold text-rose-900">{formatNumber(totals.inactive_profiles)}</span>
                </div>
            </div>
        </aside>
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
    const hasLoadedPayload = Boolean(data?.range || points.length || currentScope.total_profiles !== undefined);
    const resolvedBucket = BUCKETS.some((item) => item.key === bucket) ? bucket : (data?.bucket || 'day');
    const netTone = movementTone(totals.net_active_movement);

    let content;
    if (isLoading) {
        content = <MovementSkeleton />;
    } else if (errorMessage && !hasLoadedPayload) {
        content = <ErrorState message={errorMessage} />;
    } else if (!hasMovement) {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <EmptyState
                    title="No active/inactive movement in this window"
                    message="The selected scope has no paid-profile activations or inactive-profile exits for this bucket. Current base totals are still shown on the right."
                />
                <CurrentBaseRail currentScope={currentScope} totals={totals} comparison={comparison} data={data} />
            </div>
        );
    } else {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <div className="rounded-[1.25rem] bg-slate-950 p-1.5 text-white shadow-[0_22px_60px_rgba(15,23,42,0.18)]">
                    <div className="rounded-[1rem] bg-[radial-gradient(circle_at_20%_0%,rgba(20,184,166,0.22),transparent_32%),linear-gradient(145deg,#07111f,#0f172a_60%,#111827)] p-4 ring-1 ring-white/10">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Movement ledger</p>
                                <div className="mt-2 flex flex-wrap items-end gap-x-4 gap-y-2">
                                    <p className="crm-mono text-4xl font-semibold tracking-tight">{formatSigned(totals.net_active_movement)}</p>
                                    <p className={`pb-1 text-sm font-semibold ${
                                        netTone === 'positive'
                                            ? 'text-teal-300'
                                            : netTone === 'negative'
                                                ? 'text-rose-300'
                                                : 'text-slate-300'
                                    }`}>
                                        {movementLabel(totals.net_active_movement)}
                                    </p>
                                </div>
                            </div>
                            <div className="flex flex-wrap gap-2 text-xs">
                                <span className="rounded-full bg-teal-400/12 px-2.5 py-1 font-semibold text-teal-200 ring-1 ring-teal-300/20">
                                    Activated {formatNumber(totals.active_profiles)}
                                </span>
                                <span className="rounded-full bg-rose-400/12 px-2.5 py-1 font-semibold text-rose-200 ring-1 ring-rose-300/20">
                                    Inactive {formatNumber(totals.inactive_profiles)}
                                </span>
                            </div>
                        </div>

                        <div className="mt-5 h-80 rounded-2xl bg-white/[0.03] px-2 py-3 ring-1 ring-white/10">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={points} margin={{ top: 14, right: 14, left: 0, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="profileMovementActive" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="5%" stopColor="#2dd4bf" stopOpacity={0.34} />
                                            <stop offset="95%" stopColor="#2dd4bf" stopOpacity={0.02} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid stroke="rgba(148,163,184,0.16)" strokeDasharray="3 6" vertical={false} />
                                    <XAxis
                                        dataKey="label"
                                        tick={{ fontSize: 11, fill: '#94a3b8', fontWeight: 600 }}
                                        tickLine={false}
                                        axisLine={false}
                                        minTickGap={18}
                                        interval="preserveStartEnd"
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11, fill: '#94a3b8' }}
                                        tickLine={false}
                                        axisLine={false}
                                        width={52}
                                        tickFormatter={(value) => Number(value || 0).toLocaleString()}
                                    />
                                    <Tooltip content={<MovementTooltip />} />
                                    <ReferenceLine y={0} stroke="rgba(226,232,240,0.34)" strokeDasharray="4 4" />
                                    <Bar dataKey="net_active_movement" radius={[6, 6, 0, 0]} barSize={18} name="Net movement">
                                        {points.map((point) => (
                                            <Cell
                                                key={`net-${point.label}`}
                                                fill={point.net_active_movement >= 0 ? '#5eead4' : '#fb7185'}
                                                fillOpacity={point.net_active_movement === 0 ? 0.35 : 0.82}
                                            />
                                        ))}
                                    </Bar>
                                    <Area
                                        type="monotone"
                                        dataKey="active_profiles"
                                        stroke="#2dd4bf"
                                        strokeWidth={2.75}
                                        fill="url(#profileMovementActive)"
                                        name="Activated"
                                        dot={false}
                                        activeDot={{ r: 5, strokeWidth: 2, stroke: '#07111f', fill: '#5eead4' }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="inactive_profiles"
                                        stroke="#fb7185"
                                        strokeWidth={2.4}
                                        dot={false}
                                        name="Inactive"
                                        activeDot={{ r: 5, strokeWidth: 2, stroke: '#07111f', fill: '#fb7185' }}
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>

                <CurrentBaseRail currentScope={currentScope} totals={totals} comparison={comparison} data={data} />
            </div>
        );
    }

    return (
        <SectionFrame
            title="Profile Movement"
            subtitle="Active-profile entries, inactive exits, and the resulting base shift."
            className={`overflow-hidden ${className}`}
            contentClassName="space-y-4 bg-slate-50/50"
            action={(
                <div className="inline-flex rounded-xl bg-slate-100 p-1 ring-1 ring-slate-200" role="group" aria-label="Profile movement bucket">
                    {BUCKETS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onBucketChange?.(item.key)}
                            className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.98] ${
                                resolvedBucket === item.key
                                    ? 'bg-slate-950 text-white shadow-sm'
                                    : 'text-slate-600 hover:bg-white hover:text-slate-900'
                            }`}
                        >
                            {item.label}
                        </button>
                    ))}
                </div>
            )}
        >
            {!errorMessage || !hasLoadedPayload ? null : <ErrorState message={errorMessage} />}

            {!isLoading && hasLoadedPayload && hasMovement ? (
                <div className="grid gap-3 md:grid-cols-3">
                    <MicroMetric
                        label="Activation intake"
                        value={formatNumber(totals.active_profiles)}
                        helper={comparisonLine(comparison, 'active_profiles', 'activation')}
                        tone="active"
                    />
                    <MicroMetric
                        label="Inactive exits"
                        value={formatNumber(totals.inactive_profiles)}
                        helper={comparisonLine(comparison, 'inactive_profiles', 'inactive')}
                        tone="inactive"
                    />
                    <MicroMetric
                        label="Net swing"
                        value={formatSigned(totals.net_active_movement)}
                        helper={`${formatSigned(comparison.net_active_movement?.delta)} vs prior window`}
                        tone="net"
                    />
                </div>
            ) : null}

            {content}
        </SectionFrame>
    );
}
