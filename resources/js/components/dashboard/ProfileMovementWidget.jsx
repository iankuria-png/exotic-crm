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
    { key: 'snapshot', label: 'Subscriber snapshot' },
];
const VIEW_HELPERS = {
    base: 'Profile additions minus paid exits. Additions are first-time paid, won-back clients, and free trials. Renewals are retained work, not new additions.',
    mix: 'Shows what kind of movement happened: first-time paid, renewals, won-back clients, free trials, and exits.',
    trials: 'Free-trial activations alongside first-time paid subscriptions. Trials activate profiles but do not count as paid revenue.',
    payments: 'All successful payment events compared with renewals and first-time paid subscriptions.',
    snapshot: 'Point-in-time paid subscriber base. This answers how many paying clients existed now, yesterday, 7 days ago, 30 days ago, and at the selected range edges.',
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
    subscriberSnapshot: 'A date-based count of active paying clients from the active-client snapshot table. If an exact date has no snapshot, the nearest earlier captured snapshot is used.',
    rangeChange: 'Range end subscriber count minus range start subscriber count. This is a base count comparison, not the same thing as payment volume.',
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

function hasPaymentCount(summaryMetrics, key) {
    return Object.prototype.hasOwnProperty.call(summaryMetrics?.[key]?.value || {}, 'payments_count');
}

function summaryAlignedMovementStats(summaryMetrics) {
    if (
        !summaryMetrics
        || !hasPaymentCount(summaryMetrics, 'collected_revenue')
        || !hasPaymentCount(summaryMetrics, 'new_user_revenue')
        || !hasPaymentCount(summaryMetrics, 'existing_user_revenue')
    ) {
        return null;
    }

    return {
        successfulPayments: asNumber(summaryMetrics.collected_revenue?.value?.payments_count),
        newUserPayments: asNumber(summaryMetrics.new_user_revenue?.value?.payments_count),
        newUserClients: asNumber(summaryMetrics.new_user_revenue?.value?.clients_count),
        existingUserPayments: asNumber(summaryMetrics.existing_user_revenue?.value?.payments_count),
        existingUserClients: asNumber(summaryMetrics.existing_user_revenue?.value?.clients_count),
    };
}

function alignMovementTotalsWithSummary(totals, summaryStats) {
    if (!summaryStats) return totals;

    const newPaid = summaryStats.newUserPayments;
    const existingPaid = summaryStats.existingUserPayments;
    const reactivated = asNumber(totals.reactivated_profiles);
    const freeTrials = asNumber(totals.free_trial_activations);
    const exits = asNumber(totals.inactive_profiles);
    const baseGain = newPaid + reactivated + freeTrials;

    return {
        ...totals,
        active_profiles: newPaid + existingPaid + reactivated,
        activation_events: newPaid + existingPaid + reactivated,
        new_paid_activations: newPaid,
        renewed_profiles: existingPaid,
        base_gain: baseGain,
        net_active_movement: baseGain - exits,
        successful_payments: summaryStats.successfulPayments,
    };
}

function alignSinglePointWithSummary(point, summaryStats) {
    if (!summaryStats) return point;

    return alignMovementTotalsWithSummary(point, summaryStats);
}

function movementCopy(summaryStats) {
    const aligned = Boolean(summaryStats);

    return {
        firstPaidLabel: aligned ? 'New-user payments' : 'First-time paid',
        renewalLabel: aligned ? 'Existing payments' : 'Renewals',
        firstPaidDefinition: aligned
            ? 'Successful payment events counted in the New User Revenue card for this same dashboard window.'
            : TERM_HELP.firstPaid,
        renewalDefinition: aligned
            ? 'Successful payment events counted in the Existing User Revenue card for this same dashboard window.'
            : TERM_HELP.renewals,
        paymentHelper: aligned
            ? 'Matches the CEO stat cards: collected revenue payments, new-user payments, and existing-user payments.'
            : VIEW_HELPERS.payments,
        mixHelper: aligned
            ? 'Uses the same new-user and existing-user payment counts shown in the CEO stat cards, with trials and paid exits layered in.'
            : VIEW_HELPERS.mix,
    };
}

function EmptyState({ title, message }) {
    return (
        <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50/80 px-5 py-10 text-center">
            <p className="text-sm font-semibold text-slate-900">{title}</p>
            <p className="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-500">{message}</p>
        </div>
    );
}

function ErrorState({ message }) {
    const routeMissing = /route .*profile-movement.*could not be found/i.test(String(message || ''));

    return (
        <div className="rounded-xl border border-rose-200 bg-rose-50 px-5 py-5">
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

function tooltipRows(point, view, labels = {}) {
    const firstPaidLabel = labels.firstPaidLabel || 'First-time paid';
    const renewalLabel = labels.renewalLabel || 'Renewals';

    if (view === 'mix') {
        return [
            [firstPaidLabel, point.new_paid_activations, 'text-teal-700'],
            [renewalLabel, point.renewed_profiles, 'text-slate-700'],
            ['Won-back', point.reactivated_profiles, 'text-amber-700'],
            ['Free trials', point.free_trial_activations, 'text-slate-700'],
            ['Paid exits', point.inactive_profiles, 'text-rose-700'],
        ];
    }

    if (view === 'trials') {
        return [
            ['Free trials', point.free_trial_activations, 'text-slate-700'],
            [firstPaidLabel, point.new_paid_activations, 'text-teal-700'],
            ['Profile additions', point.base_gain, 'text-slate-900'],
        ];
    }

    if (view === 'payments') {
        return [
            ['Successful payments', point.successful_payments, 'text-slate-900'],
            [renewalLabel, point.renewed_profiles, 'text-slate-700'],
            [firstPaidLabel, point.new_paid_activations, 'text-teal-700'],
        ];
    }

    return [
        ['Profile additions', point.base_gain, 'text-teal-700'],
        ['Paid exits', point.inactive_profiles, 'text-rose-700'],
        [renewalLabel, point.renewed_profiles, 'text-slate-700'],
        ['Net change', point.net_active_movement, 'text-slate-900', true],
    ];
}

function MovementTooltip({ active, payload, label, view, labels }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};
    const rows = tooltipRows(point, view, labels);

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
            <div className="rounded-xl bg-slate-100 p-4">
                <div className="h-8 w-48 animate-pulse rounded-lg bg-white" />
                <div className="mt-6 h-72 animate-pulse rounded-xl bg-white" />
            </div>
            <div className="rounded-xl bg-slate-100 p-4">
                <div className="h-8 w-36 animate-pulse rounded-lg bg-white" />
                <div className="mt-6 space-y-3">
                    <div className="h-20 animate-pulse rounded-xl bg-white" />
                    <div className="h-20 animate-pulse rounded-xl bg-white" />
                    <div className="h-20 animate-pulse rounded-xl bg-white" />
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
        trial: 'text-slate-700',
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

function snapshotDateLine(item) {
    if (!item) return '--';
    const date = item.date || '--';

    if (item.approximate && item.as_of) {
        return `${date} - nearest ${item.as_of}`;
    }

    return date;
}

function snapshotTitle(item) {
    if (!item) return '';
    if (item.approximate && item.as_of) {
        return `No exact snapshot for ${item.date}. Showing nearest earlier snapshot from ${item.as_of}.`;
    }

    return `Snapshot for ${item.date || 'selected date'}.`;
}

function SnapshotCheckpoint({ item, maxCount, emphasis = false }) {
    const count = asNumber(item?.count);
    const width = maxCount > 0 ? clampPercent((count / maxCount) * 100) : 0;

    return (
        <div className="group" title={snapshotTitle(item)}>
            <div className="flex items-end justify-between gap-4">
                <div className="min-w-0">
                    <p className={`truncate text-sm font-semibold ${emphasis ? 'text-slate-950' : 'text-slate-700'}`}>
                        {item?.label || '--'}
                    </p>
                    <p className="mt-1 truncate text-xs text-slate-500">{snapshotDateLine(item)}</p>
                </div>
                <p className={`crm-mono shrink-0 font-semibold ${emphasis ? 'text-lg text-teal-700' : 'text-sm text-slate-900'}`}>
                    {formatNumber(count)}
                </p>
            </div>
            <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                    className={`h-full rounded-full transition-all duration-300 ${emphasis ? 'bg-teal-600' : 'bg-slate-400'}`}
                    style={{ width: `${width}%` }}
                />
            </div>
        </div>
    );
}

function snapshotSeries(history) {
    const preferred = [
        history?.thirty_days_ago,
        history?.seven_days_ago,
        history?.yesterday,
        history?.range_start,
        history?.range_end,
        history?.current,
    ].filter((item) => item?.date);
    const byDate = new Map();

    preferred.forEach((item) => {
        byDate.set(item.date, {
            ...item,
            active_count: asNumber(item.count),
            inactive_count: asNumber(item.inactive_count),
            total_paid_profiles: asNumber(item.total_paid_profiles),
        });
    });

    return Array.from(byDate.values())
        .sort((a, b) => new Date(a.date).getTime() - new Date(b.date).getTime());
}

function SnapshotTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};

    return (
        <div className="rounded-lg border border-slate-200 bg-white/95 p-3 shadow-[0_18px_48px_rgba(15,23,42,0.16)]">
            <p className="text-xs font-semibold text-slate-900">{point.label || label}</p>
            <p className="mt-0.5 text-[11px] font-medium text-slate-400">{snapshotDateLine(point)}</p>
            <div className="mt-3 grid min-w-48 gap-2 text-sm">
                <p className="flex items-center justify-between gap-8 text-teal-700">
                    <span>Active paying</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.active_count)}</span>
                </p>
                <p className="flex items-center justify-between gap-8 border-t border-slate-100 pt-2 text-slate-700">
                    <span>Paid but inactive</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.inactive_count)}</span>
                </p>
                <p className="flex items-center justify-between gap-8 border-t border-slate-100 pt-2 text-slate-900">
                    <span>Total paid profiles</span>
                    <span className="crm-mono font-semibold">{formatNumber(point.total_paid_profiles)}</span>
                </p>
            </div>
        </div>
    );
}

function SubscriberSnapshotPanel({ history, currentScope, data }) {
    const current = history?.current || history?.range_end || null;
    const rangeStart = history?.range_start || null;
    const rangeEnd = history?.range_end || current;
    const rangeChange = history?.range_change || {
        change: asNumber(rangeEnd?.count) - asNumber(rangeStart?.count),
        from_date: rangeStart?.date,
        to_date: rangeEnd?.date,
        percent: null,
    };
    const checkpoints = [
        current,
        history?.yesterday,
        history?.seven_days_ago,
        history?.thirty_days_ago,
        rangeStart,
        rangeEnd,
    ].filter(Boolean);
    const maxCount = checkpoints.reduce((max, item) => Math.max(max, asNumber(item?.count)), 1);
    const deltaTone = movementTone(rangeChange.change);
    const active = asNumber(current?.count ?? currentScope.active_profiles);
    const inactive = asNumber(current?.inactive_count ?? currentScope.inactive_profiles);
    const total = asNumber(current?.total_paid_profiles ?? currentScope.total_profiles);
    const share = total > 0 ? clampPercent((active / total) * 100) : 0;
    const series = snapshotSeries(history);

    return (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
            <div className="rounded-xl bg-white p-5 ring-1 ring-slate-200">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p className="flex items-center text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                            Paid subscriber snapshot
                            <DefinitionTooltip label="Paid subscriber snapshot" text={TERM_HELP.subscriberSnapshot} />
                        </p>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                            Snapshot counts answer how many active paying clients existed on a date. Payment totals answer how many payment events happened during the window.
                        </p>
                    </div>
                    <div className="rounded-full border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600">
                        Stock count, not event volume
                    </div>
                </div>

                <div className="mt-5 grid gap-5 xl:grid-cols-[17rem_minmax(0,1fr)]">
                    <div className="rounded-xl bg-slate-950 p-5 text-white shadow-[0_18px_48px_rgba(15,23,42,0.14)]">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Current snapshot</p>
                            <p className="mt-3 crm-mono text-5xl font-semibold tracking-tight">{formatNumber(active)}</p>
                            <p className="mt-2 text-sm leading-6 text-slate-300">
                                active paying clients
                                {current?.date ? <span> on {current.date}</span> : null}
                                {current?.approximate && current?.as_of ? <span> using {current.as_of}</span> : null}
                            </p>
                        </div>

                        <div className="mt-6 border-t border-white/10 pt-4">
                            <p className="flex items-center text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">
                                Range change
                                <DefinitionTooltip label="Range change" text={TERM_HELP.rangeChange} />
                            </p>
                            <p className={`mt-2 crm-mono text-3xl font-semibold ${
                                deltaTone === 'positive'
                                    ? 'text-teal-200'
                                    : deltaTone === 'negative'
                                        ? 'text-rose-200'
                                        : 'text-slate-200'
                            }`}>
                                {formatSigned(rangeChange.change)}
                            </p>
                            <p className="mt-1 text-xs leading-5 text-slate-400">
                                {rangeChange.from_date || '--'} to {rangeChange.to_date || '--'}
                            </p>
                        </div>
                    </div>

                    <div className="rounded-xl border border-slate-200 p-4">
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-slate-950">Snapshot trend</p>
                                <p className="mt-1 text-xs leading-5 text-slate-500">
                                    Point-in-time active paying base across available checkpoints.
                                </p>
                            </div>
                            <div className="flex items-center gap-3 text-xs font-semibold text-slate-500">
                                <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-teal-600" /> Active</span>
                                <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-slate-400" /> Inactive</span>
                            </div>
                        </div>
                        <div className="mt-4 h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <ComposedChart data={series} margin={{ top: 10, right: 10, left: 0, bottom: 0 }}>
                                    <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 6" vertical={false} />
                                    <XAxis
                                        dataKey="date"
                                        tick={{ fontSize: 11, fill: '#64748b', fontWeight: 600 }}
                                        tickLine={false}
                                        axisLine={false}
                                        minTickGap={14}
                                    />
                                    <YAxis
                                        tick={{ fontSize: 11, fill: '#94a3b8' }}
                                        tickLine={false}
                                        axisLine={false}
                                        width={50}
                                        tickFormatter={(value) => Number(value || 0).toLocaleString()}
                                    />
                                    <Tooltip content={<SnapshotTooltip />} />
                                    <Area
                                        type="monotone"
                                        dataKey="active_count"
                                        stroke="#0f766e"
                                        strokeWidth={2.5}
                                        fill="#ccfbf1"
                                        fillOpacity={0.55}
                                        name="Active paying"
                                        dot={{ r: 3, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }}
                                        activeDot={{ r: 5, strokeWidth: 2, stroke: '#ffffff', fill: '#0f766e' }}
                                    />
                                    <Line
                                        type="monotone"
                                        dataKey="inactive_count"
                                        stroke="#94a3b8"
                                        strokeWidth={2}
                                        strokeDasharray="5 5"
                                        dot={false}
                                        name="Paid but inactive"
                                    />
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                        <div className="mt-4 grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-2 xl:grid-cols-4">
                            <SnapshotCheckpoint item={history?.yesterday} maxCount={maxCount} />
                            <SnapshotCheckpoint item={history?.seven_days_ago} maxCount={maxCount} />
                            <SnapshotCheckpoint item={history?.thirty_days_ago} maxCount={maxCount} />
                            <SnapshotCheckpoint item={rangeStart} maxCount={maxCount} />
                        </div>
                    </div>
                </div>
            </div>

            <aside className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
                <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">Base composition</p>
                <div className="mt-4 flex items-end justify-between gap-3">
                    <div>
                        <p className="crm-mono text-3xl font-semibold tracking-tight text-slate-950">{formatNumber(total)}</p>
                        <p className="mt-1 text-sm text-slate-500">paid profiles ever seen in scope</p>
                    </div>
                    <p className="crm-mono text-sm font-semibold text-teal-700">{share.toFixed(1)}%</p>
                </div>
                <div className="mt-5 h-3 overflow-hidden rounded-full bg-slate-100">
                    <div className="flex h-full">
                        <div className="bg-teal-600" style={{ width: `${share}%` }} />
                        <div className="bg-slate-300" style={{ width: `${clampPercent(100 - share)}%` }} />
                    </div>
                </div>
                <div className="mt-4 grid gap-2">
                    <div className="flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
                        <span className="text-slate-500">Active paying</span>
                        <span className="crm-mono font-semibold text-teal-700">{formatNumber(active)}</span>
                    </div>
                    <div className="flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
                        <span className="text-slate-500">Paid but inactive</span>
                        <span className="crm-mono font-semibold text-slate-900">{formatNumber(inactive)}</span>
                    </div>
                    <div className="flex items-center justify-between border-t border-slate-100 pt-3 text-sm">
                        <span className="text-slate-500">Selected window</span>
                        <span className="crm-mono text-xs font-semibold text-slate-900">{data?.range?.from || '--'} to {data?.range?.to || '--'}</span>
                    </div>
                </div>
            </aside>
        </div>
    );
}

function ChartViewSwitcher({ chartView, onChange, hasSnapshot, viewHelpers = VIEW_HELPERS }) {
    const helperText = viewHelpers[chartView] || VIEW_HELPERS[chartView];

    return (
        <div className="flex flex-col gap-3 border-t border-slate-100 pt-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="inline-flex w-fit flex-wrap rounded-lg bg-white p-1 ring-1 ring-slate-200" role="group" aria-label="Profile movement analysis view">
                {CHART_VIEWS.filter((item) => item.key !== 'snapshot' || hasSnapshot).map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => onChange(item.key)}
                        className={`rounded-md px-3 py-1.5 text-xs font-semibold transition duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.98] ${
                            chartView === item.key
                                ? 'bg-slate-950 text-white shadow-sm'
                                : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'
                        }`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>
            <p className="max-w-3xl text-xs leading-5 text-slate-500">{helperText}</p>
        </div>
    );
}

function CurrentBaseRail({ currentScope, totals, comparison, data, labels = movementCopy(null) }) {
    const active = asNumber(currentScope.active_profiles);
    const inactive = asNumber(currentScope.inactive_profiles);
    const total = asNumber(currentScope.total_profiles);
    const activeShare = total > 0
        ? clampPercent(currentScope.active_share_percent || (active / total) * 100)
        : 0;
    const inactiveShare = total > 0 ? clampPercent(100 - activeShare) : 0;
    const netTone = movementTone(totals.net_active_movement);

    return (
        <aside className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
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

            <div className="mt-5 rounded-xl bg-slate-50 p-3">
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
                <div className="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2 text-sm">
                    <span className="flex items-center font-medium text-slate-700">
                        {labels.renewalLabel}
                        <DefinitionTooltip label={labels.renewalLabel} text={labels.renewalDefinition} />
                    </span>
                    <span className="crm-mono font-semibold text-slate-900">{formatNumber(totals.renewed_profiles)}</span>
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
    summaryMetrics = null,
    isLoading = false,
    errorMessage = null,
    bucket = 'day',
    onBucketChange,
    className = '',
}) {
    const [chartView, setChartView] = useState('snapshot');
    const summaryStats = useMemo(() => summaryAlignedMovementStats(summaryMetrics), [summaryMetrics]);
    const labels = useMemo(() => movementCopy(summaryStats), [summaryStats]);
    const viewHelpers = useMemo(() => ({
        ...VIEW_HELPERS,
        mix: labels.mixHelper,
        payments: labels.paymentHelper,
    }), [labels]);
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
            })).map((point, _index, allPoints) => (
                allPoints.length === 1 ? alignSinglePointWithSummary(point, summaryStats) : point
            ))
            : []
    ), [data?.points, summaryStats]);
    const totals = data?.totals || {};
    const baseNormalizedTotals = {
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
    const normalizedTotals = alignMovementTotalsWithSummary(baseNormalizedTotals, summaryStats);
    const comparison = data?.comparison || {};
    const currentScope = data?.current_scope || {};
    const subscriberHistory = data?.subscriber_history || {};
    const hasSubscriberHistory = Boolean(subscriberHistory.current || subscriberHistory.range_end);
    const effectiveChartView = chartView === 'snapshot' && !hasSubscriberHistory ? 'base' : chartView;
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
    } else if (effectiveChartView === 'snapshot' && hasSubscriberHistory) {
        content = (
            <SubscriberSnapshotPanel
                history={subscriberHistory}
                currentScope={currentScope}
                data={data}
            />
        );
    } else if (!hasMovement) {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <EmptyState
                    title="No subscriber movement in this window"
                    message="The selected scope has no paid profile activations, free-trial activations, or paid exits for this bucket. Successful payments can still happen here as repeat payments."
                />
                <CurrentBaseRail currentScope={currentScope} totals={normalizedTotals} comparison={comparison} data={data} labels={labels} />
            </div>
        );
    } else {
        content = (
            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_21rem]">
                <div className="rounded-xl bg-slate-950 p-1.5 text-white shadow-[0_22px_60px_rgba(15,23,42,0.16)]">
                    <div className="rounded-lg bg-[linear-gradient(145deg,#07111f,#0f172a_58%,#10212b)] p-4 ring-1 ring-white/10">
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
                                    Profile additions, paid exits, renewals, trials, and payment volume across the selected window.
                                    {summaryStats ? ' Payment counts match the CEO stat cards above.' : ''}
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-2 text-xs">
                                <span className="rounded-full bg-teal-400/12 px-2.5 py-1 font-semibold text-teal-200 ring-1 ring-teal-300/20">
                                    {labels.firstPaidLabel} {formatNumber(normalizedTotals.new_paid_activations)}
                                </span>
                                <span className="rounded-full bg-slate-400/12 px-2.5 py-1 font-semibold text-slate-100 ring-1 ring-slate-300/20">
                                    {labels.renewalLabel} {formatNumber(normalizedTotals.renewed_profiles)}
                                </span>
                                {normalizedTotals.reactivated_profiles > 0 ? (
                                    <span className="rounded-full bg-amber-400/12 px-2.5 py-1 font-semibold text-amber-100 ring-1 ring-amber-300/20">
                                        Won-back {formatNumber(normalizedTotals.reactivated_profiles)}
                                    </span>
                                ) : null}
                                <span className="rounded-full bg-slate-400/12 px-2.5 py-1 font-semibold text-slate-100 ring-1 ring-slate-300/20">
                                    Free trials {formatNumber(normalizedTotals.free_trial_activations)}
                                </span>
                                <span className="rounded-full bg-rose-400/12 px-2.5 py-1 font-semibold text-rose-200 ring-1 ring-rose-300/20">
                                    Exits {formatNumber(normalizedTotals.inactive_profiles)}
                                </span>
                            </div>
                        </div>

                        <div className="mt-5 h-80 rounded-lg bg-white/[0.03] px-2 py-3 ring-1 ring-white/10">
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
                                    <Tooltip content={<MovementTooltip view={effectiveChartView} labels={labels} />} />
                                    <ReferenceLine y={0} stroke="rgba(226,232,240,0.34)" strokeDasharray="4 4" />
                                    {effectiveChartView === 'base' ? (
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
                                    {effectiveChartView === 'mix' ? (
                                        <>
                                            <Bar dataKey="new_paid_activations" fill="#2dd4bf" fillOpacity={0.82} radius={[5, 5, 0, 0]} barSize={12} name={labels.firstPaidLabel} />
                                            <Bar dataKey="renewed_profiles" fill="#94a3b8" fillOpacity={0.72} radius={[5, 5, 0, 0]} barSize={12} name={labels.renewalLabel} />
                                            <Line type="monotone" dataKey="reactivated_profiles" stroke="#f59e0b" strokeWidth={2.4} dot={false} name="Won-back" />
                                            <Line type="monotone" dataKey="free_trial_activations" stroke="#94a3b8" strokeWidth={2.4} dot={false} name="Free trials" />
                                            <Line type="monotone" dataKey="inactive_profiles" stroke="#fb7185" strokeWidth={2.2} dot={false} name="Paid exits" />
                                        </>
                                    ) : null}
                                    {effectiveChartView === 'trials' ? (
                                        <>
                                            <Bar dataKey="free_trial_activations" fill="#94a3b8" fillOpacity={0.84} radius={[6, 6, 0, 0]} barSize={18} name="Free trials" />
                                            <Line type="monotone" dataKey="new_paid_activations" stroke="#2dd4bf" strokeWidth={2.75} dot={false} name={labels.firstPaidLabel} />
                                            <Line type="monotone" dataKey="base_gain" stroke="#e2e8f0" strokeWidth={2} strokeDasharray="5 6" dot={false} name="Profile additions" />
                                        </>
                                    ) : null}
                                    {effectiveChartView === 'payments' ? (
                                        <>
                                            <Area type="monotone" dataKey="successful_payments" stroke="#e2e8f0" strokeWidth={2.6} fill="url(#profileMovementPayments)" dot={false} name="Successful payments" />
                                            <Line type="monotone" dataKey="renewed_profiles" stroke="#94a3b8" strokeWidth={2.6} dot={false} name={labels.renewalLabel} />
                                            <Bar dataKey="new_paid_activations" fill="#2dd4bf" fillOpacity={0.76} radius={[5, 5, 0, 0]} barSize={14} name={labels.firstPaidLabel} />
                                        </>
                                    ) : null}
                                </ComposedChart>
                            </ResponsiveContainer>
                        </div>
                    </div>
                </div>

                <CurrentBaseRail currentScope={currentScope} totals={normalizedTotals} comparison={comparison} data={data} labels={labels} />
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
                <div className="inline-flex rounded-lg bg-slate-100 p-1 ring-1 ring-slate-200" role="group" aria-label="Profile movement bucket">
                    {BUCKETS.map((item) => (
                        <button
                            key={item.key}
                            type="button"
                            onClick={() => onBucketChange?.(item.key)}
                            className={`rounded-md px-3 py-1.5 text-xs font-semibold transition duration-200 ease-[cubic-bezier(0.32,0.72,0,1)] active:scale-[0.98] ${
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
                <div className="grid gap-x-5 gap-y-4 md:grid-cols-3 xl:grid-cols-6">
                    <MicroMetric
                        label={labels.firstPaidLabel}
                        value={formatNumber(normalizedTotals.new_paid_activations)}
                        helper={summaryStats
                            ? `${formatNumber(summaryStats.newUserClients)} clients from New User Revenue`
                            : comparisonLine(comparison, 'new_paid_activations', 'first-time paid')}
                        tone="active"
                        definition={labels.firstPaidDefinition}
                    />
                    <MicroMetric
                        label={labels.renewalLabel}
                        value={formatNumber(normalizedTotals.renewed_profiles)}
                        helper={summaryStats
                            ? `${formatNumber(summaryStats.existingUserClients)} clients from Existing User Revenue`
                            : comparisonLine(comparison, 'renewed_profiles', 'renewals')}
                        tone={summaryStats ? 'neutral' : 'renewal'}
                        definition={labels.renewalDefinition}
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

            {!isLoading && hasLoadedPayload && (hasActivityContext || hasSubscriberHistory) ? (
                <ChartViewSwitcher
                    chartView={effectiveChartView}
                    onChange={setChartView}
                    hasSnapshot={hasSubscriberHistory}
                    viewHelpers={viewHelpers}
                />
            ) : null}

            {content}
        </SectionFrame>
    );
}
