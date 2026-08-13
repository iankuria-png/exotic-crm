import React, { useMemo, useState } from 'react';
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
const CHART_VIEWS = [
    { key: 'base', label: 'Net change' },
    { key: 'mix', label: 'Breakdown' },
    { key: 'trials', label: 'Free trials' },
    { key: 'payments', label: 'Payments' },
];
const VIEW_HELPERS = {
    base: 'Profile additions minus paid exits. Additions are first-time paid, won-back clients, and free trials. Renewals are retained work, not new additions.',
    mix: 'Shows what kind of movement happened: first-time paid, renewals, won-back clients, free trials, and exits.',
    trials: 'Free-trial activations alongside first-time paid subscriptions. Trials activate profiles but do not count as paid revenue.',
    payments: 'All successful payment events compared with renewals and first-time paid subscriptions.',
};
const TERM_HELP = {
    firstPaid: 'Clients whose first successful paid subscription happened in this selected period.',
    renewals: 'Existing paying clients who paid again or extended in this period. They are retention, not new profile growth.',
    wonBack: 'Previously inactive or churned paid clients who returned with a successful payment. This is separate from a normal renewal.',
    trials: 'Profiles activated on a free trial. They increase profile activity but do not count as paid revenue.',
    paidExits: 'Paid clients whose profile moved inactive or churned in this period.',
    additions: 'Profiles added to the active profile pool: first-time paid + won-back + free trials. Renewals are excluded because the client was already paying.',
    netChange: 'Profile additions minus paid exits. Positive means additions were higher than exits; negative means exits were higher.',
    totalEvents: 'All movement events in the period: paid activations, renewals, free trials, and paid exits.',
    successfulPayments: 'All reportable successful payments in the period, including first payments, renewals, and repeat payments.',
};

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
    if (tone === 'positive') return 'Additions ahead of exits';
    if (tone === 'negative') return 'Exits ahead of additions';
    return 'Additions and exits balanced';
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

function DefinitionTooltip({ label, text }) {
    if (!text) return null;

    return (
        <span className="group relative inline-flex align-middle">
            <button
                type="button"
                className="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-bold leading-none text-slate-500 transition hover:border-slate-400 hover:text-slate-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                aria-label={`${label} explanation`}
            >
                ?
            </button>
            <span
                role="tooltip"
                className="pointer-events-none absolute left-1/2 top-full z-20 mt-2 hidden w-72 -translate-x-1/2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-left text-xs font-medium normal-case leading-5 tracking-normal text-slate-600 shadow-[0_18px_48px_rgba(15,23,42,0.16)] group-hover:block group-focus-within:block"
            >
                <span className="block font-semibold text-slate-900">{label}</span>
                <span className="mt-1 block">{text}</span>
            </span>
        </span>
    );
}

function tooltipRows(point, view) {
    if (view === 'mix') {
        return [
            ['First-time paid', point.new_paid_activations, 'text-teal-700'],
            ['Renewals', point.renewed_profiles, 'text-sky-700'],
            ['Won-back', point.reactivated_profiles, 'text-amber-700'],
            ['Free trials', point.free_trial_activations, 'text-violet-700'],
            ['Paid exits', point.inactive_profiles, 'text-rose-700'],
        ];
    }

    if (view === 'trials') {
        return [
            ['Free trials', point.free_trial_activations, 'text-violet-700'],
            ['First-time paid', point.new_paid_activations, 'text-teal-700'],
            ['Profile additions', point.base_gain, 'text-slate-900'],
        ];
    }

    if (view === 'payments') {
        return [
            ['Successful payments', point.successful_payments, 'text-slate-900'],
            ['Renewals', point.renewed_profiles, 'text-sky-700'],
            ['First-time paid', point.new_paid_activations, 'text-teal-700'],
        ];
    }

    return [
        ['Profile additions', point.base_gain, 'text-teal-700'],
        ['Paid exits', point.inactive_profiles, 'text-rose-700'],
        ['Renewals', point.renewed_profiles, 'text-sky-700'],
        ['Net change', point.net_active_movement, 'text-slate-900', true],
    ];
}

function MovementTooltip({ active, payload, label, view }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};
    const rows = tooltipRows(point, view);

    return (
        <div className="rounded-xl border border-slate-200 bg-white/95 p-3 shadow-[0_18px_48px_rgba(15,23,42,0.16)]">
            <p className="text-xs font-semibold text-slate-900">{label}</p>
            <p className="mt-0.5 text-[11px] font-medium text-slate-400">{formatRange(point)}</p>
            <div className="mt-3 grid min-w-48 gap-2 text-sm">
                {rows.map(([rowLabel, rowValue, rowClass, signed]) => (
                    <p key={rowLabel} className={`flex items-center justify-between gap-8 ${signed ? 'border-t border-slate-100 pt-2' : ''} ${rowClass}`}>
                        <span>{rowLabel}</span>
                        <span className="crm-mono font-semibold">{signed ? formatSigned(rowValue) : formatNumber(rowValue)}</span>
                    </p>
                ))}
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

function MicroMetric({ label, value, helper, tone = 'neutral', definition = null }) {
    const toneClass = {
        active: 'text-teal-700',
        inactive: 'text-rose-700',
        net: 'text-slate-950',
        renewal: 'text-sky-700',
        trial: 'text-violet-700',
        neutral: 'text-slate-800',
    }[tone] || 'text-slate-800';

    return (
        <div className="min-w-0">
            <p className="flex items-center text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                <span>{label}</span>
                <DefinitionTooltip label={label} text={definition} />
            </p>
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
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Paid subscriber snapshot</p>
                <div className="mt-3 flex items-end justify-between gap-3">
                    <div>
                        <p className="crm-mono text-3xl font-semibold tracking-tight text-slate-950">{formatNumber(total)}</p>
                        <p className="mt-1 text-sm text-slate-500">
                            paid clients in selected scope
                            {currentScope.as_of ? <span> - snapshot {currentScope.as_of}</span> : null}
                        </p>
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
                        <p className="text-xs font-medium text-slate-400">Active paying now</p>
                        <p className="mt-1 crm-mono font-semibold text-teal-700">{formatNumber(active)}</p>
                    </div>
                    <div>
                        <p className="text-xs font-medium text-slate-400">Paid but inactive</p>
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
                    <span className="flex items-center font-medium text-teal-800">
                        Profile additions
                        <DefinitionTooltip label="Profile additions" text={TERM_HELP.additions} />
                    </span>
                    <span className="crm-mono font-semibold text-teal-900">{formatNumber(totals.base_gain)}</span>
                </div>
                <div className="flex items-center justify-between rounded-xl bg-sky-50 px-3 py-2 text-sm">
                    <span className="flex items-center font-medium text-sky-800">
                        Renewals
                        <DefinitionTooltip label="Renewals" text={TERM_HELP.renewals} />
                    </span>
                    <span className="crm-mono font-semibold text-sky-900">{formatNumber(totals.renewed_profiles)}</span>
                </div>
                <div className="flex items-center justify-between rounded-xl bg-rose-50 px-3 py-2 text-sm">
                    <span className="flex items-center font-medium text-rose-800">
                        Paid exits
                        <DefinitionTooltip label="Paid exits" text={TERM_HELP.paidExits} />
                    </span>
                    <span className="crm-mono font-semibold text-rose-900">{formatNumber(totals.inactive_profiles)}</span>
                </div>
                <div className="flex items-center justify-between rounded-xl bg-slate-100 px-3 py-2 text-sm">
                    <span className="flex items-center font-medium text-slate-700">
                        Successful payments
                        <DefinitionTooltip label="Successful payments" text={TERM_HELP.successfulPayments} />
                    </span>
                    <span className="crm-mono font-semibold text-slate-900">{formatNumber(totals.successful_payments)}</span>
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
    const [chartView, setChartView] = useState('base');
    const points = useMemo(() => (
        Array.isArray(data?.points)
            ? data.points.map((point) => ({
                ...point,
                active_profiles: asNumber(point.active_profiles),
                new_paid_activations: asNumber(point.new_paid_activations),
                renewed_profiles: asNumber(point.renewed_profiles),
                reactivated_profiles: asNumber(point.reactivated_profiles),
                free_trial_activations: asNumber(point.free_trial_activations),
                activation_events: asNumber(point.activation_events ?? point.active_profiles),
                base_gain: asNumber(point.base_gain ?? point.active_profiles),
                inactive_profiles: asNumber(point.inactive_profiles),
                created_profiles: asNumber(point.created_profiles),
                net_active_movement: asNumber(point.net_active_movement),
                successful_payments: asNumber(point.successful_payments),
            }))
            : []
    ), [data?.points]);
    const totals = data?.totals || {};
    const normalizedTotals = {
        ...totals,
        active_profiles: asNumber(totals.active_profiles),
        new_paid_activations: asNumber(totals.new_paid_activations ?? totals.active_profiles),
        renewed_profiles: asNumber(totals.renewed_profiles),
        reactivated_profiles: asNumber(totals.reactivated_profiles),
        free_trial_activations: asNumber(totals.free_trial_activations),
        base_gain: asNumber(totals.base_gain ?? totals.active_profiles),
        inactive_profiles: asNumber(totals.inactive_profiles),
        net_active_movement: asNumber(totals.net_active_movement),
        successful_payments: asNumber(totals.successful_payments),
    };
    const comparison = data?.comparison || {};
    const currentScope = data?.current_scope || {};
    const hasMovement = points.some((point) => (
        point.activation_events > 0
        || point.base_gain > 0
        || point.free_trial_activations > 0
        || point.inactive_profiles > 0
        || point.net_active_movement !== 0
    ));
    const hasActivityContext = hasMovement || normalizedTotals.successful_payments > 0;
    const hasLoadedPayload = Boolean(data?.range || points.length || currentScope.total_profiles !== undefined);
    const resolvedBucket = BUCKETS.some((item) => item.key === bucket) ? bucket : (data?.bucket || 'day');
    const netTone = movementTone(normalizedTotals.net_active_movement);
    const totalProfileActivity = normalizedTotals.active_profiles + normalizedTotals.free_trial_activations + normalizedTotals.inactive_profiles;

    let content;
    if (isLoading) {
        content = <MovementSkeleton />;
    } else if (errorMessage && !hasLoadedPayload) {
        content = <ErrorState message={errorMessage} />;
    } else if (!hasMovement) {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <EmptyState
                    title="No subscriber movement in this window"
                    message="The selected scope has no paid profile activations, free-trial activations, or paid exits for this bucket. Successful payments can still happen here as repeat payments."
                />
                <CurrentBaseRail currentScope={currentScope} totals={normalizedTotals} comparison={comparison} data={data} />
            </div>
        );
    } else {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <div className="rounded-[1.25rem] bg-slate-950 p-1.5 text-white shadow-[0_22px_60px_rgba(15,23,42,0.18)]">
                    <div className="rounded-[1rem] bg-[radial-gradient(circle_at_20%_0%,rgba(20,184,166,0.22),transparent_32%),linear-gradient(145deg,#07111f,#0f172a_60%,#111827)] p-4 ring-1 ring-white/10">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="flex items-center text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                                    Profile flow
                                    <DefinitionTooltip label="Profile flow" text="A period-by-period count of profiles added, renewed, trialed, and moved inactive. It is movement during the window, not a historical reconstruction." />
                                </p>
                                <div className="mt-2 flex flex-wrap items-end gap-x-4 gap-y-2">
                                    <p className="crm-mono text-4xl font-semibold tracking-tight">{formatSigned(normalizedTotals.net_active_movement)}</p>
                                    <p className={`pb-1 text-sm font-semibold ${
                                        netTone === 'positive'
                                            ? 'text-teal-300'
                                            : netTone === 'negative'
                                                ? 'text-rose-300'
                                                : 'text-slate-300'
                                    }`}>
                                        {movementLabel(normalizedTotals.net_active_movement)}
                                    </p>
                                </div>
                                <p className="mt-2 max-w-2xl text-xs leading-5 text-slate-400">
                                    {VIEW_HELPERS[chartView]} Net change = profile additions - paid exits.
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2 text-xs">
                                <span className="rounded-full bg-teal-400/12 px-2.5 py-1 font-semibold text-teal-200 ring-1 ring-teal-300/20">
                                    First-time paid {formatNumber(normalizedTotals.new_paid_activations)}
                                </span>
                                <span className="rounded-full bg-sky-400/12 px-2.5 py-1 font-semibold text-sky-100 ring-1 ring-sky-300/20">
                                    Renewals {formatNumber(normalizedTotals.renewed_profiles)}
                                </span>
                                {normalizedTotals.reactivated_profiles > 0 ? (
                                    <span className="rounded-full bg-amber-400/12 px-2.5 py-1 font-semibold text-amber-100 ring-1 ring-amber-300/20">
                                        Won-back {formatNumber(normalizedTotals.reactivated_profiles)}
                                    </span>
                                ) : null}
                                <span className="rounded-full bg-violet-400/12 px-2.5 py-1 font-semibold text-violet-100 ring-1 ring-violet-300/20">
                                    Free trials {formatNumber(normalizedTotals.free_trial_activations)}
                                </span>
                                <span className="rounded-full bg-rose-400/12 px-2.5 py-1 font-semibold text-rose-200 ring-1 ring-rose-300/20">
                                    Exits {formatNumber(normalizedTotals.inactive_profiles)}
                                </span>
                            </div>
                        </div>

                        <div className="mt-5 flex flex-wrap gap-2">
                            {CHART_VIEWS.map((item) => (
                                <button
                                    key={item.key}
                                    type="button"
                                    onClick={() => setChartView(item.key)}
                                    className={`rounded-full px-3 py-1.5 text-xs font-semibold transition duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.98] ${
                                        chartView === item.key
                                            ? 'bg-white text-slate-950'
                                            : 'bg-white/7 text-slate-300 ring-1 ring-white/10 hover:bg-white/12 hover:text-white'
                                    }`}
                                >
                                    {item.label}
                                </button>
                            ))}
                        </div>

                        <div className="mt-4 h-80 rounded-2xl bg-white/[0.03] px-2 py-3 ring-1 ring-white/10">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={points} margin={{ top: 14, right: 14, left: 0, bottom: 0 }}>
                                    <defs>
                                        <linearGradient id="profileMovementBaseGain" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="5%" stopColor="#2dd4bf" stopOpacity={0.34} />
                                            <stop offset="95%" stopColor="#2dd4bf" stopOpacity={0.02} />
                                        </linearGradient>
                                        <linearGradient id="profileMovementPayments" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="5%" stopColor="#e2e8f0" stopOpacity={0.26} />
                                            <stop offset="95%" stopColor="#e2e8f0" stopOpacity={0.02} />
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
                                    <Tooltip content={<MovementTooltip view={chartView} />} />
                                    <ReferenceLine y={0} stroke="rgba(226,232,240,0.34)" strokeDasharray="4 4" />
                                    {chartView === 'base' ? (
                                        <>
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
                                                dataKey="base_gain"
                                                stroke="#2dd4bf"
                                                strokeWidth={2.75}
                                                fill="url(#profileMovementBaseGain)"
                                                name="Profile additions"
                                                dot={false}
                                                activeDot={{ r: 5, strokeWidth: 2, stroke: '#07111f', fill: '#5eead4' }}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="inactive_profiles"
                                                stroke="#fb7185"
                                                strokeWidth={2.4}
                                                dot={false}
                                                name="Paid exits"
                                                activeDot={{ r: 5, strokeWidth: 2, stroke: '#07111f', fill: '#fb7185' }}
                                            />
                                        </>
                                    ) : null}
                                    {chartView === 'mix' ? (
                                        <>
                                            <Bar dataKey="new_paid_activations" fill="#2dd4bf" fillOpacity={0.82} radius={[5, 5, 0, 0]} barSize={12} name="First-time paid" />
                                            <Bar dataKey="renewed_profiles" fill="#38bdf8" fillOpacity={0.78} radius={[5, 5, 0, 0]} barSize={12} name="Renewals" />
                                            <Line type="monotone" dataKey="reactivated_profiles" stroke="#f59e0b" strokeWidth={2.4} dot={false} name="Won-back" />
                                            <Line type="monotone" dataKey="free_trial_activations" stroke="#a78bfa" strokeWidth={2.4} dot={false} name="Free trials" />
                                            <Line type="monotone" dataKey="inactive_profiles" stroke="#fb7185" strokeWidth={2.2} dot={false} name="Paid exits" />
                                        </>
                                    ) : null}
                                    {chartView === 'trials' ? (
                                        <>
                                            <Bar dataKey="free_trial_activations" fill="#a78bfa" fillOpacity={0.84} radius={[6, 6, 0, 0]} barSize={18} name="Free trials" />
                                            <Line type="monotone" dataKey="new_paid_activations" stroke="#2dd4bf" strokeWidth={2.75} dot={false} name="First-time paid" />
                                            <Line type="monotone" dataKey="base_gain" stroke="#e2e8f0" strokeWidth={2} strokeDasharray="5 6" dot={false} name="Profile additions" />
                                        </>
                                    ) : null}
                                    {chartView === 'payments' ? (
                                        <>
                                            <Area type="monotone" dataKey="successful_payments" stroke="#e2e8f0" strokeWidth={2.6} fill="url(#profileMovementPayments)" dot={false} name="Successful payments" />
                                            <Line type="monotone" dataKey="renewed_profiles" stroke="#38bdf8" strokeWidth={2.6} dot={false} name="Renewals" />
                                            <Bar dataKey="new_paid_activations" fill="#2dd4bf" fillOpacity={0.76} radius={[5, 5, 0, 0]} barSize={14} name="First-time paid" />
                                        </>
                                    ) : null}
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>

                <CurrentBaseRail currentScope={currentScope} totals={normalizedTotals} comparison={comparison} data={data} />
            </div>
        );
    }

    return (
        <SectionFrame
            title="Profile Movement"
            subtitle="First-time paid subscriptions, renewals, won-back clients, free trials, exits, and payment volume across the selected window."
            className={className}
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

            {!isLoading && hasLoadedPayload && hasActivityContext ? (
                <div className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <MicroMetric
                        label="First-time paid"
                        value={formatNumber(normalizedTotals.new_paid_activations)}
                        helper={comparisonLine(comparison, 'new_paid_activations', 'first-time paid')}
                        tone="active"
                        definition={TERM_HELP.firstPaid}
                    />
                    <MicroMetric
                        label="Renewals"
                        value={formatNumber(normalizedTotals.renewed_profiles)}
                        helper={comparisonLine(comparison, 'renewed_profiles', 'renewals')}
                        tone="renewal"
                        definition={TERM_HELP.renewals}
                    />
                    <MicroMetric
                        label="Free trials"
                        value={formatNumber(normalizedTotals.free_trial_activations)}
                        helper="Profile activation, no payment"
                        tone="trial"
                        definition={TERM_HELP.trials}
                    />
                    <MicroMetric
                        label="Paid exits"
                        value={formatNumber(normalizedTotals.inactive_profiles)}
                        helper={comparisonLine(comparison, 'inactive_profiles', 'paid exits')}
                        tone="inactive"
                        definition={TERM_HELP.paidExits}
                    />
                    <MicroMetric
                        label="Net change"
                        value={formatSigned(normalizedTotals.net_active_movement)}
                        helper={`${formatSigned(comparison.net_active_movement?.delta)} vs prior window`}
                        tone="net"
                        definition={TERM_HELP.netChange}
                    />
                    <MicroMetric
                        label="Total events"
                        value={formatNumber(totalProfileActivity)}
                        helper={`${formatNumber(normalizedTotals.successful_payments)} successful payments`}
                        tone="neutral"
                        definition={TERM_HELP.totalEvents}
                    />
                </div>
            ) : null}

            {content}
        </SectionFrame>
    );
}
