import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
    Area,
    Bar,
    CartesianGrid,
    ComposedChart,
    Line,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../../services/api';
import { formatCurrency } from '../../utils/currency';
import { useToast } from '../ToastProvider';
import ConfirmDialog from '../ConfirmDialog';
import CloseCaseDialog from '../CloseCaseDialog';

// ─── Constants ───────────────────────────────────────────────────────────────

const CHURN_REASON_LABELS = {
    payment_reversed: 'Payment Reversed',
    invalid_reference: 'Invalid Reference',
    fraud_suspected: 'Fraud Suspected',
    customer_request: 'Customer Request',
    duplicate_entry: 'Duplicate Entry',
    expired_unrenewed: 'Expired (Unrenewed)',
    admin_deactivated: 'Admin Deactivated',
    not_serious: 'Not Serious',
    no_response: 'No Response',
    declined: 'Declined to Proceed',
    invalid_contact: 'Invalid Contact Details',
    inappropriate: 'Inappropriate Behaviour',
    payment_issue: 'Payment Issue Not Resolved',
    duplicate: 'Duplicate Contact',
    other: 'Other',
};

const CHURN_SOURCE_LABELS = {
    deal_cancelled: 'Deal Cancelled',
    deal_expired: 'Deal Expired',
    deal_deactivated: 'Deal Deactivated',
    case_closed: 'Case Closed',
    profile_inactive: 'Profile Inactive',
};

const CLOSE_CASE_REASON_CODES = new Set([
    'not_serious', 'no_response', 'declined', 'invalid_contact',
    'inappropriate', 'payment_issue', 'duplicate', 'other',
]);

const PRESETS = [
    { key: 'this', label: 'This week' },
    { key: 'last', label: 'Last week' },
    { key: 'month', label: 'Last 30 days' },
    { key: 'custom', label: 'Custom' },
];

const ALLOWED_PRESETS = new Set(PRESETS.map((p) => p.key));

const SERIES = [
    { key: 'signups', label: 'Signups', color: '#14b8a6' },
    { key: 'activations', label: 'Activations', color: '#f59e0b' },
    { key: 'churn', label: 'Churn', color: '#f43f5e' },
];

// ─── URL state helpers ────────────────────────────────────────────────────────

function readChurnRangeFromUrl(searchParams) {
    const from = searchParams.get('from') || '';
    const to = searchParams.get('to') || '';
    if (from || to) {
        return { mode: 'custom', from, to };
    }
    const preset = searchParams.get('week') || 'this';
    return { mode: 'preset', preset: ALLOWED_PRESETS.has(preset) ? preset : 'this' };
}

function rangeParamsFromState(range) {
    if (range.mode === 'custom') {
        return { from: range.from || undefined, to: range.to || undefined };
    }
    return { week: range.preset === 'this' ? undefined : range.preset };
}

// ─── Helper formatters ────────────────────────────────────────────────────────

function formatDays(days) {
    if (days === null || days === undefined) return '—';
    const d = parseFloat(days);
    if (Number.isNaN(d)) return '—';
    return `${d.toFixed(0)}d`;
}

function healthStyles(health) {
    if (health === 'healthy') return { dot: 'bg-emerald-500', text: 'text-emerald-700', bg: 'bg-emerald-50', border: 'border-emerald-200' };
    if (health === 'watch') return { dot: 'bg-amber-500', text: 'text-amber-700', bg: 'bg-amber-50', border: 'border-amber-200' };
    if (health === 'neutral') return { dot: 'bg-slate-400', text: 'text-slate-600', bg: 'bg-slate-100', border: 'border-slate-200' };
    return { dot: 'bg-rose-600', text: 'text-rose-700', bg: 'bg-rose-50', border: 'border-rose-200' };
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
}

// ─── Sub-components ───────────────────────────────────────────────────────────

function InfoHint({ label, text }) {
    return (
        <span className="group relative inline-flex align-middle">
            <button
                type="button"
                aria-label={`About ${label}`}
                onClick={(event) => event.stopPropagation()}
                className="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-bold normal-case text-slate-500 transition hover:border-teal-400 hover:text-teal-700 focus:outline-none focus:ring-2 focus:ring-teal-500"
            >
                ?
            </button>
            <span
                role="tooltip"
                className="pointer-events-none absolute bottom-full left-1/2 z-30 mb-2 hidden w-64 -translate-x-1/2 rounded-lg bg-slate-950 px-3 py-2 text-left text-xs font-normal normal-case tracking-normal text-white shadow-xl group-hover:block group-focus-within:block"
            >
                {text}
            </span>
        </span>
    );
}

function comparisonText(comparison, inverse = false) {
    if (!comparison) return null;
    if (comparison.delta === 0) return { label: 'No change vs prior period', tone: 'text-slate-500' };

    const improved = inverse ? comparison.delta < 0 : comparison.delta > 0;
    const direction = comparison.delta > 0 ? 'up' : 'down';
    const amount = comparison.percent === null
        ? `${Math.abs(comparison.delta).toLocaleString()}`
        : `${Math.abs(comparison.percent).toLocaleString()}%`;

    return {
        label: `${direction === 'up' ? 'Up' : 'Down'} ${amount} vs prior period`,
        tone: improved ? 'text-emerald-700' : 'text-rose-700',
    };
}

function MetricCard({ label, value, subValue, health, comparison, inverseComparison = false, accent = 'slate' }) {
    const styles = health ? healthStyles(health) : null;
    const change = comparisonText(comparison, inverseComparison);
    const accentClass = accent === 'rose'
        ? 'before:bg-rose-500'
        : accent === 'amber'
            ? 'before:bg-amber-500'
            : 'before:bg-teal-500';

    return (
        <div className={`relative overflow-hidden rounded-xl border bg-white p-5 shadow-sm before:absolute before:inset-y-0 before:left-0 before:w-1 ${accentClass} ${styles ? `${styles.border}` : 'border-slate-200'}`}>
            <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">{label}</p>
            <p className={`mt-1 text-3xl font-bold tracking-tight ${styles ? styles.text : 'text-slate-900'}`}>{value}</p>
            {subValue ? <p className="mt-0.5 text-xs text-slate-500">{subValue}</p> : null}
            {change ? <p className={`mt-2 text-xs font-semibold ${change.tone}`}>{change.label}</p> : null}
            {health ? (
                <div className={`mt-2 inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-semibold ${styles.bg} ${styles.text}`}>
                    <span className={`h-1.5 w-1.5 rounded-full ${styles.dot}`} />
                    {health.charAt(0).toUpperCase() + health.slice(1)}
                </div>
            ) : null}
        </div>
    );
}

function SeriesToggle({ visibleSeries, onToggle }) {
    return (
        <div className="flex flex-wrap gap-2">
            {SERIES.map((s) => {
                const active = visibleSeries.has(s.key);
                return (
                    <button
                        key={s.key}
                        type="button"
                        onClick={() => onToggle(s.key)}
                        className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-semibold transition ${
                            active
                                ? 'border-transparent text-white'
                                : 'border-slate-300 bg-white text-slate-500'
                        }`}
                        style={active ? { backgroundColor: s.color, borderColor: s.color } : {}}
                    >
                        <span
                            className="h-2 w-2 rounded-full"
                            style={{ backgroundColor: active ? 'rgba(255,255,255,0.8)' : s.color }}
                        />
                        {s.label}
                    </button>
                );
            })}
        </div>
    );
}

function TrendChart({ daily, visibleSeries }) {
    const data = (daily || []).map((d) => ({
        date: d.date?.slice(5) ?? d.date, // Show MM-DD
        signups: d.signups,
        activations: d.activations,
        churn: d.churn,
        net: d.signups - d.churn,
    }));

    if (!data.length) {
        return (
            <div className="flex h-48 items-center justify-center rounded-lg border border-dashed border-slate-200 bg-slate-50 text-sm text-slate-500">
                No data for this range.
            </div>
        );
    }

    return (
        <div className="h-64 w-full">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 4, right: 12, left: -10, bottom: 0 }}>
                    <defs>
                        <linearGradient id="churnNetFill" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="#14b8a6" stopOpacity={0.2} />
                            <stop offset="100%" stopColor="#14b8a6" stopOpacity={0.01} />
                        </linearGradient>
                    </defs>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" />
                    <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
                    <YAxis tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} allowDecimals={false} />
                    <Tooltip
                        contentStyle={{ fontSize: 12, borderRadius: 8, border: '1px solid #e2e8f0', boxShadow: '0 4px 16px rgba(0,0,0,0.1)' }}
                        labelStyle={{ fontWeight: 600, color: '#334155' }}
                    />
                    <ReferenceLine y={0} stroke="#cbd5e1" />
                    <Area
                        type="monotone"
                        dataKey="net"
                        name="Net movement"
                        stroke="none"
                        fill="url(#churnNetFill)"
                    />
                    {SERIES.filter((s) => visibleSeries.has(s.key)).map((s) => (
                        <Line
                            key={s.key}
                            type="monotone"
                            dataKey={s.key}
                            stroke={s.color}
                            strokeWidth={2}
                            dot={false}
                            activeDot={{ r: 4 }}
                        />
                    ))}
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

function RevenueRiskTooltip({ active, payload, label }) {
    if (!active || !payload?.length) return null;
    const point = payload[0]?.payload || {};

    return (
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-xl">
            <p className="text-xs font-semibold text-slate-500">{label}</p>
            <p className="mt-1 text-sm font-semibold text-rose-700">
                {point.estimated_revenue_at_risk_usd == null
                    ? 'Revenue estimate unavailable'
                    : `${formatCurrency(point.estimated_revenue_at_risk_usd, 'USD')} at risk`}
            </p>
            <p className="mt-1 text-xs text-slate-600">
                {Number(point.churn || 0).toLocaleString()} churned · {point.average_ticket_usd == null
                    ? 'No usable daily ticket'
                    : `${formatCurrency(point.average_ticket_usd, 'USD')} average ticket`}
            </p>
            <p className="text-xs text-slate-400">{Number(point.successful_payments || 0).toLocaleString()} successful payments sampled</p>
        </div>
    );
}

function RevenueRiskChart({ daily }) {
    const data = (daily || []).map((point) => ({
        ...point,
        date: point.date?.slice(5) ?? point.date,
    }));
    const hasEstimate = data.some((point) => point.estimated_revenue_at_risk_usd != null && point.churn > 0);

    if (!hasEstimate) {
        return (
            <div className="flex h-56 items-center justify-center rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 text-center text-sm text-slate-500">
                No daily revenue estimate is available for this period. Successful payment and FX data are required.
            </div>
        );
    }

    return (
        <div className="h-64 w-full">
            <ResponsiveContainer width="100%" height="100%">
                <ComposedChart data={data} margin={{ top: 8, right: 8, left: 0, bottom: 0 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#f1f5f9" vertical={false} />
                    <XAxis dataKey="date" tick={{ fontSize: 10, fill: '#94a3b8' }} tickLine={false} axisLine={false} />
                    <YAxis
                        yAxisId="risk"
                        tick={{ fontSize: 10, fill: '#94a3b8' }}
                        tickLine={false}
                        axisLine={false}
                        width={54}
                        tickFormatter={(value) => `$${Number(value || 0).toLocaleString()}`}
                    />
                    <YAxis
                        yAxisId="ticket"
                        orientation="right"
                        tick={{ fontSize: 10, fill: '#94a3b8' }}
                        tickLine={false}
                        axisLine={false}
                        width={42}
                        tickFormatter={(value) => `$${Number(value || 0).toLocaleString()}`}
                    />
                    <Tooltip content={<RevenueRiskTooltip />} />
                    <Bar
                        yAxisId="risk"
                        dataKey="estimated_revenue_at_risk_usd"
                        name="Estimated revenue at risk"
                        fill="#fb7185"
                        radius={[4, 4, 0, 0]}
                        maxBarSize={28}
                    />
                    <Line
                        yAxisId="ticket"
                        type="monotone"
                        dataKey="average_ticket_usd"
                        name="Daily average ticket"
                        stroke="#0f766e"
                        strokeWidth={2.5}
                        dot={false}
                        connectNulls={false}
                    />
                </ComposedChart>
            </ResponsiveContainer>
        </div>
    );
}

const TIER_COLORS = {
    basic: '#f59e0b',
    featured: '#14b8a6',
    premium: '#6366f1',
    vip: '#8b5cf6',
    vvip: '#d946ef',
    unknown: '#94a3b8',
};

function TierBreakdown({ tiers }) {
    if (!tiers?.length) {
        return (
            <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                No paid-tier history is available for churned clients in this period.
            </div>
        );
    }

    const leader = tiers.find((tier) => tier.key !== 'unknown') || tiers[0];

    return (
        <section className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <div>
                    <div className="flex items-center">
                        <h3 className="text-sm font-semibold text-slate-900">Churn concentration by last paid tier</h3>
                        <InfoHint
                            label="tier churn concentration"
                            text="Groups each churned client by their most recent paid tier before churn. This shows where churn is concentrated, not a causal churn rate across all customers exposed to each tier."
                        />
                    </div>
                    <p className="mt-1 text-xs text-slate-500">Compare volume, share of churn, and how quickly customers left their last tier.</p>
                </div>
                <div className="rounded-lg bg-amber-50 px-3 py-2 text-right ring-1 ring-amber-200">
                    <p className="text-[10px] font-semibold uppercase tracking-wide text-amber-700">Highest concentration</p>
                    <p className="text-sm font-bold text-amber-900">{leader.label} · {leader.share_of_churn_percent}%</p>
                </div>
            </div>
            <div className="grid gap-5 p-5 lg:grid-cols-[1.15fr_1fr]">
                <div className="space-y-4">
                    {tiers.map((tier) => (
                        <div key={tier.key}>
                            <div className="flex items-center justify-between gap-4 text-xs">
                                <span className="font-semibold text-slate-800">{tier.label}</span>
                                <span className="font-semibold text-slate-900">
                                    {tier.churn_count.toLocaleString()} · {tier.share_of_churn_percent}%
                                </span>
                            </div>
                            <div className="mt-1.5 h-2.5 overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className="h-full rounded-full"
                                    style={{
                                        width: `${Math.max(2, tier.share_of_churn_percent)}%`,
                                        backgroundColor: TIER_COLORS[tier.key] || TIER_COLORS.unknown,
                                    }}
                                />
                            </div>
                        </div>
                    ))}
                </div>
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-100 text-left text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                <th className="pb-2">Tier</th>
                                <th className="pb-2 text-right">Avg days on tier</th>
                                <th className="pb-2 text-right">
                                    Left in 30d
                                    <InfoHint
                                        label="left within 30 days"
                                        text="Share of churned clients with known tier dates who left within 30 days of starting their last paid tier."
                                    />
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-slate-100">
                            {tiers.map((tier) => (
                                <tr key={tier.key}>
                                    <td className="py-2.5 font-medium text-slate-800">{tier.label}</td>
                                    <td className="py-2.5 text-right text-slate-600">{formatDays(tier.avg_days_on_last_tier)}</td>
                                    <td className={`py-2.5 text-right font-semibold ${
                                        Number(tier.early_churn_percent || 0) >= 50 ? 'text-rose-700' : 'text-slate-700'
                                    }`}>
                                        {tier.early_churn_percent == null ? '—' : `${tier.early_churn_percent}%`}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    );
}

function MarketDurationTable({ durations, onSelectMarket, selectedMarketId }) {
    const [sortKey, setSortKey] = useState('churn_count');
    const [sortDir, setSortDir] = useState('desc');

    const handleSort = (key) => {
        if (sortKey === key) {
            setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSortKey(key);
            setSortDir('desc');
        }
    };

    const sorted = [...(durations || [])].sort((a, b) => {
        const aVal = a[sortKey] ?? -Infinity;
        const bVal = b[sortKey] ?? -Infinity;

        if (typeof aVal === 'string' && typeof bVal === 'string') {
            return sortDir === 'asc' ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
        }

        return sortDir === 'asc' ? aVal - bVal : bVal - aVal;
    });
    const maxPaidDuration = Math.max(...sorted.map((row) => row.avg_paid_lifetime_days || 0), 1);

    const thClass = (key) =>
        `cursor-pointer select-none px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500 hover:text-slate-700 ${sortKey === key ? 'text-teal-600' : ''}`;

    const SortIcon = ({ k }) => {
        if (sortKey !== k) return <span className="opacity-30"> ↕</span>;
        return <span>{sortDir === 'asc' ? ' ↑' : ' ↓'}</span>;
    };

    return (
        <div className="overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50">
                    <tr>
                        <th className={thClass('name')} onClick={() => handleSort('name')}>Market<SortIcon k="name" /></th>
                        <th className={thClass('avg_paid_lifetime_days')} onClick={() => handleSort('avg_paid_lifetime_days')}>
                            Paid tenure before churn
                            <InfoHint
                                label="paid tenure before churn"
                                text="Average days from a customer's first successful payment or activation until they churned."
                            />
                            <SortIcon k="avg_paid_lifetime_days" />
                        </th>
                        <th className={thClass('avg_total_relationship_days')} onClick={() => handleSort('avg_total_relationship_days')}>
                            Relationship before churn
                            <InfoHint
                                label="customer relationship before churn"
                                text="Average days from the customer's CRM signup date until they churned, including time before their first payment."
                            />
                            <SortIcon k="avg_total_relationship_days" />
                        </th>
                        <th className={thClass('signup_count')} onClick={() => handleSort('signup_count')}>Signups<SortIcon k="signup_count" /></th>
                        <th className={thClass('churn_count')} onClick={() => handleSort('churn_count')}>Churned<SortIcon k="churn_count" /></th>
                        <th className={thClass('net_delta')} onClick={() => handleSort('net_delta')}>Net delta<SortIcon k="net_delta" /></th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {sorted.length === 0 ? (
                        <tr><td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-500">No market movement for this range.</td></tr>
                    ) : sorted.map((row) => {
                        const isSelected = selectedMarketId === row.platform_id;
                        const netColor = row.net_delta > 0 ? 'text-emerald-600' : row.net_delta < 0 ? 'text-rose-600' : 'text-slate-500';
                        return (
                            <tr
                                key={row.platform_id}
                                onClick={() => onSelectMarket(isSelected ? null : row.platform_id)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' || event.key === ' ') {
                                        event.preventDefault();
                                        onSelectMarket(isSelected ? null : row.platform_id);
                                    }
                                }}
                                tabIndex={0}
                                className={`cursor-pointer transition hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-teal-500 ${isSelected ? 'bg-teal-50' : ''}`}
                            >
                                <td className="px-3 py-2 font-medium text-slate-800">{row.name}</td>
                                <td className="px-3 py-2 text-slate-600">
                                    {formatDays(row.avg_paid_lifetime_days)}
                                    <div className="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-slate-100">
                                        <div
                                            className="h-full rounded-full bg-teal-400"
                                            style={{ width: `${Math.max(4, ((row.avg_paid_lifetime_days || 0) / maxPaidDuration) * 100)}%` }}
                                        />
                                    </div>
                                </td>
                                <td className="px-3 py-2 text-slate-600">
                                    {formatDays(row.avg_total_relationship_days)}
                                    <span className="ml-1 text-[10px] text-slate-400">total</span>
                                </td>
                                <td className="px-3 py-2 text-slate-700">{(row.signup_count || 0).toLocaleString()}</td>
                                <td className="px-3 py-2 font-medium text-rose-700">{row.churn_count.toLocaleString()}</td>
                                <td className={`px-3 py-2 font-semibold ${netColor}`}>
                                    {row.net_delta > 0 ? '+' : ''}{row.net_delta}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function ReasonAggregator({ reasons, onSelectReason, selectedReason }) {
    if (!reasons?.length) {
        return (
            <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
                No churn reason data for this range.
            </div>
        );
    }

    const maxCount = Math.max(...reasons.map((r) => r.count), 1);

    return (
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div className="border-b border-slate-100 px-4 py-3">
                <h3 className="text-sm font-semibold text-slate-800">Churn reasons</h3>
                <p className="mt-0.5 text-[11px] text-slate-500">Click a bar to filter the client list below.</p>
            </div>
            <ul className="divide-y divide-slate-100 p-3">
                {reasons.map((r) => {
                    const isSelected = selectedReason === r.code;
                    const pct = Math.round((r.count / maxCount) * 100);
                    return (
                        <li
                            key={r.code}
                            onClick={() => onSelectReason(isSelected ? null : r.code)}
                            className={`cursor-pointer rounded-md px-2 py-2 transition hover:bg-slate-50 ${isSelected ? 'bg-teal-50 ring-1 ring-teal-200' : ''}`}
                        >
                            <div className="flex items-center justify-between gap-3">
                                <span className="text-xs font-medium text-slate-700">{r.label}</span>
                                <div className="flex items-center gap-2">
                                    {Object.entries(r.by_source || {}).map(([src, cnt]) => (
                                        <span key={src} className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-slate-500">
                                            {CHURN_SOURCE_LABELS[src] ?? src} · {cnt}
                                        </span>
                                    ))}
                                    <span className="w-8 text-right text-xs font-semibold text-slate-600">{r.count}</span>
                                </div>
                            </div>
                            <div className="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                <div
                                    className="h-full rounded-full bg-rose-400 transition-all"
                                    style={{ width: `${pct}%` }}
                                />
                            </div>
                        </li>
                    );
                })}
            </ul>
        </div>
    );
}

function ChurnedClientRow({ row, onWinBackSms, onReactivate, onMarkWonBack, onCloseCase }) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <tr className="hover:bg-slate-50">
            <td className="px-4 py-2.5">
                <button type="button" onClick={onReactivate} className="text-left">
                    <p className="text-sm font-semibold text-slate-800 hover:text-teal-700">{row.name || '—'}</p>
                    <p className="text-xs text-slate-500 crm-mono">{row.phone_normalized || ''}</p>
                </button>
            </td>
            <td className="px-4 py-2.5 text-xs text-slate-500">{row.platform?.name || '—'}</td>
            <td className="px-4 py-2.5 text-xs text-slate-600">{formatDate(row.first_activated_at)}</td>
            <td className="px-4 py-2.5 text-xs text-slate-600">{formatDate(row.churned_at)}</td>
            <td className="px-4 py-2.5">
                <div className="flex flex-col gap-0.5">
                    <span className="text-xs font-medium text-slate-700">
                        {CHURN_REASON_LABELS[row.churn_reason_code] || row.churn_reason_code || '—'}
                    </span>
                    {row.churn_source ? (
                        <span className="text-[10px] uppercase tracking-wide text-slate-400">
                            {CHURN_SOURCE_LABELS[row.churn_source] || row.churn_source}
                        </span>
                    ) : null}
                </div>
            </td>
            <td className="px-4 py-2.5 text-xs text-slate-600">{row.plan_label || '—'}</td>
            <td className="px-4 py-2.5">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={onWinBackSms}
                        className="min-h-9 rounded-lg bg-teal-700 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-800 focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-offset-2"
                    >
                        Win-back SMS
                    </button>
                    <button
                        type="button"
                        onClick={onReactivate}
                        className="min-h-9 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                    >
                        Reactivate
                    </button>
                    <div className="relative">
                        <button
                            type="button"
                            aria-label={`More actions for ${row.name || 'client'}`}
                            aria-expanded={menuOpen}
                            onClick={() => setMenuOpen((open) => !open)}
                            className="flex min-h-9 min-w-9 items-center justify-center rounded-lg border border-slate-300 bg-white text-lg leading-none text-slate-600 hover:bg-slate-50"
                        >
                            ···
                        </button>
                        {menuOpen ? (
                            <div className="absolute right-0 top-full z-20 mt-1 w-40 overflow-hidden rounded-lg border border-slate-200 bg-white py-1 shadow-lg">
                                <button type="button" onClick={() => { setMenuOpen(false); onMarkWonBack(); }} className="block w-full px-3 py-2 text-left text-xs font-medium text-emerald-700 hover:bg-emerald-50">
                                    Mark won-back
                                </button>
                                <button type="button" onClick={() => { setMenuOpen(false); onCloseCase(); }} className="block w-full px-3 py-2 text-left text-xs font-medium text-rose-700 hover:bg-rose-50">
                                    Close case
                                </button>
                            </div>
                        ) : null}
                    </div>
                </div>
            </td>
        </tr>
    );
}

// ─── Win-back SMS dialog ──────────────────────────────────────────────────────

function WinBackSmsDialog({ open, client, onCancel, onConfirm, isPending }) {
    const [message, setMessage] = useState('');

    const defaultMsg = client
        ? `Hi ${client.name || 'there'}, we noticed your subscription expired. We'd love to have you back — contact us to reactivate at a special rate!`
        : '';

    React.useEffect(() => {
        if (open && client) setMessage(defaultMsg);
    }, [open, client?.id]);

    if (!open) return null;

    return (
        <div className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/45 p-4" onClick={isPending ? undefined : onCancel}>
            <div role="dialog" aria-modal="true" className="w-full max-w-lg overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Send win-back SMS</h3>
                        <p className="crm-panel-subtitle">Sending to {client?.phone_normalized}</p>
                    </div>
                </header>
                <div className="p-4">
                    <textarea
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        rows={4}
                        maxLength={480}
                        className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                        placeholder="Type your win-back message…"
                        disabled={isPending}
                    />
                    <p className="mt-1 text-right text-[11px] text-slate-400">{message.length}/480</p>
                </div>
                <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                    <button type="button" onClick={onCancel} disabled={isPending} className="crm-btn-secondary">Cancel</button>
                    <button
                        type="button"
                        disabled={!message.trim() || isPending}
                        onClick={() => onConfirm({ message: message.trim() })}
                        className="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {isPending ? 'Sending…' : 'Send SMS'}
                    </button>
                </footer>
            </div>
        </div>
    );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export default function ChurnedQueueView({ platformId = '' }) {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [searchParams, setSearchParams] = useSearchParams();

    // Range state from URL
    const range = useMemo(() => readChurnRangeFromUrl(searchParams), [searchParams]);
    const [customFrom, setCustomFrom] = useState(range.from || '');
    const [customTo, setCustomTo] = useState(range.to || '');
    const [showCustom, setShowCustom] = useState(range.mode === 'custom');

    // Filters
    const [selectedMarketId, setSelectedMarketId] = useState(platformId ? Number(platformId) : null);
    const [selectedReason, setSelectedReason] = useState(null);
    const [visibleSeries, setVisibleSeries] = useState(new Set(['signups', 'activations', 'churn']));

    // Row dialogs
    const [wonBackDialog, setWonBackDialog] = useState({ open: false, client: null });
    const [smsDialog, setSmsDialog] = useState({ open: false, client: null });
    const [closeCaseDialog, setCloseCaseDialog] = useState({ open: false, client: null });
    const [closeCaseError, setCloseCaseError] = useState(null);

    // Pagination
    const [page, setPage] = useState(1);

    const rangeParams = useMemo(() => rangeParamsFromState(range), [range]);
    const platformParam = selectedMarketId || (platformId ? Number(platformId) : null);

    const summaryParams = {
        ...rangeParams,
        ...(platformParam ? { platform_id: platformParam } : {}),
    };

    const summaryQuery = useQuery({
        queryKey: ['clients-churn-summary', summaryParams],
        queryFn: () => api.get('/crm/clients/churn-summary', { params: summaryParams }).then((r) => r.data),
        staleTime: 60_000,
    });

    const listParams = {
        ...rangeParams,
        ...(platformParam ? { platform_id: platformParam } : {}),
        ...(selectedReason ? { reason_code: selectedReason } : {}),
        page,
        per_page: 25,
    };

    const listQuery = useQuery({
        queryKey: ['clients-churned', listParams],
        queryFn: () => api.get('/crm/clients/churned', { params: listParams }).then((r) => r.data),
        keepPreviousData: true,
        staleTime: 30_000,
    });

    const setPreset = (preset) => {
        const params = new URLSearchParams(searchParams);
        params.delete('from');
        params.delete('to');
        params.delete('week');
        if (preset !== 'this') params.set('week', preset);
        setShowCustom(preset === 'custom');
        if (preset !== 'custom') setSearchParams(params, { replace: true });
        setPage(1);
    };

    const applyCustomRange = () => {
        const params = new URLSearchParams(searchParams);
        params.delete('week');
        if (customFrom) params.set('from', customFrom);
        if (customTo) params.set('to', customTo);
        setSearchParams(params, { replace: true });
        setPage(1);
    };

    const toggleSeries = (key) => {
        setVisibleSeries((prev) => {
            const next = new Set(prev);
            if (next.has(key)) {
                if (next.size === 1) return prev; // Keep at least one
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const wonBackMutation = useMutation({
        mutationFn: ({ clientId, note }) =>
            api.post(`/crm/clients/${clientId}/mark-won-back`, { note }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-churned'] });
            queryClient.invalidateQueries({ queryKey: ['clients-churn-summary'] });
            toast?.success?.('Client marked as won-back.');
            setWonBackDialog({ open: false, client: null });
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'Failed to mark as won-back.'),
    });

    const smsMutation = useMutation({
        mutationFn: ({ clientId, message }) =>
            api.post(`/crm/conversations/clients/${clientId}/send`, {
                channel: 'sms',
                message,
                context: 'win_back',
            }).then((r) => r.data),
        onSuccess: () => {
            toast?.success?.('Win-back SMS sent.');
            setSmsDialog({ open: false, client: null });
        },
        onError: (err) => toast?.error?.(err?.response?.data?.message || 'SMS send failed.'),
    });

    const closeCaseMutation = useMutation({
        mutationFn: ({ clientId, payload }) =>
            api.post(`/crm/clients/${clientId}/close-case`, payload).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['clients-churned'] });
            queryClient.invalidateQueries({ queryKey: ['clients-churn-summary'] });
            toast?.success?.('Case closed and removed from churned queue.');
            setCloseCaseDialog({ open: false, client: null });
            setCloseCaseError(null);
        },
        onError: (err) => {
            const msg = err?.response?.data?.message || 'Close case failed.';
            setCloseCaseError(msg);
        },
    });

    const summary = summaryQuery.data;
    const totals = summary?.totals || { signups: 0, activations: 0, churn: 0, net: 0 };
    const comparison = summary?.comparison || {};
    const revenueAtRisk = summary?.revenue_at_risk || {};
    const hasRevenueEstimate =
        Number(revenueAtRisk.covered_churn_count || 0) > 0 ||
        Number(revenueAtRisk.total_churn_count || 0) === 0;
    const health = summary?.health || 'neutral';
    const rows = listQuery.data?.data || [];
    const pagination = listQuery.data;

    const currentPreset = range.mode === 'custom' ? 'custom' : (range.preset || 'this');

    return (
        <div className="space-y-6">
            <section className="overflow-hidden rounded-2xl border border-slate-200 bg-gradient-to-br from-slate-950 via-slate-900 to-teal-950 px-5 py-5 text-white shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-300">Retention pulse</p>
                        <h2 className="mt-1 text-xl font-bold tracking-tight">See who left, why they left, and where to win them back.</h2>
                        <p className="mt-1 max-w-2xl text-sm text-slate-300">
                            Churn follows the same paid-history and inactive-profile definition used across the Clients workspace.
                        </p>
                    </div>
                    {summary?.range ? (
                        <div className="rounded-xl border border-white/10 bg-white/5 px-3 py-2 text-right">
                            <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-400">Selected window</p>
                            <p className="mt-0.5 text-sm font-semibold">{formatDate(summary.range.from)} - {formatDate(summary.range.to)}</p>
                        </div>
                    ) : null}
                </div>
            </section>

            {/* Range selector */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-slate-500">Reporting period</p>
                    <div className="flex items-center gap-1 rounded-xl border border-slate-200 bg-slate-100 p-1">
                    {PRESETS.map((p) => (
                        <button
                            key={p.key}
                            type="button"
                            onClick={() => setPreset(p.key)}
                            className={`min-h-9 rounded-lg px-3 py-1.5 text-sm font-semibold transition ${
                                currentPreset === p.key
                                    ? 'bg-white text-teal-700 shadow-sm ring-1 ring-slate-200'
                                    : 'text-slate-600 hover:text-slate-800'
                            }`}
                        >
                            {p.label}
                        </button>
                    ))}
                    </div>
                </div>
                {showCustom && (
                    <div className="flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-sm">
                        <input
                            type="date"
                            value={customFrom}
                            onChange={(e) => setCustomFrom(e.target.value)}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                        />
                        <span className="pb-2 text-sm text-slate-400">to</span>
                        <input
                            type="date"
                            value={customTo}
                            onChange={(e) => setCustomTo(e.target.value)}
                            className="rounded-md border border-slate-300 px-3 py-1.5 text-sm"
                        />
                        <button
                            type="button"
                            onClick={applyCustomRange}
                            disabled={!customFrom && !customTo}
                            className="rounded-md bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white hover:bg-teal-800 disabled:opacity-50"
                        >
                            Apply
                        </button>
                    </div>
                )}
            </div>

            {summaryQuery.isError || listQuery.isError ? (
                <div role="alert" className="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3">
                    <p className="text-sm font-semibold text-rose-800">Churn data could not be loaded.</p>
                    <p className="mt-0.5 text-xs text-rose-700">Refresh the page or try a different reporting period.</p>
                </div>
            ) : null}

            {/* Metric strip */}
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Net movement"
                    value={totals.net > 0 ? `+${totals.net.toLocaleString()}` : totals.net.toLocaleString()}
                    subValue={`${totals.signups} signups · ${totals.churn} churned`}
                    health={health}
                    comparison={comparison.net}
                />
                <MetricCard
                    label="Churned in range"
                    value={totals.churn.toLocaleString()}
                    subValue={`vs ${totals.activations} activated`}
                    comparison={comparison.churn}
                    inverseComparison
                    accent="rose"
                />
                <div className="relative overflow-hidden rounded-xl border border-slate-200 bg-white p-5 shadow-sm before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-amber-500">
                    <div className="flex items-center">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Paid tenure before churn</p>
                        <InfoHint
                            label="paid tenure before churn"
                            text="Average days from first successful payment or activation to churn, weighted by churned customers across the selected markets."
                        />
                    </div>
                    {summaryQuery.isLoading ? (
                        <div className="mt-3 space-y-2">
                            <div className="h-8 w-28 animate-pulse rounded bg-slate-100" />
                            <div className="h-3 w-40 animate-pulse rounded bg-slate-100" />
                        </div>
                    ) : summary?.averages?.paid_lifetime_days != null ? (
                        <>
                            <p className="mt-1 text-3xl font-bold tracking-tight text-slate-900">
                                {formatDays(summary.averages.paid_lifetime_days)}
                                <span className="ml-1 text-sm font-medium text-slate-500">as a paying customer</span>
                            </p>
                            <p className="mt-0.5 text-xs text-slate-500">
                                {formatDays(summary.averages.total_relationship_days)} from signup to churn
                            </p>
                            <p className="mt-2 text-xs font-medium text-slate-500">Customer-weighted across the selected markets</p>
                        </>
                    ) : (
                        <p className="mt-3 text-sm text-slate-500">No churned customers with usable paid-tenure history in this period.</p>
                    )}
                </div>
                <div className="relative overflow-hidden rounded-xl border border-rose-200 bg-gradient-to-br from-white to-rose-50 p-5 shadow-sm before:absolute before:inset-y-0 before:left-0 before:w-1 before:bg-rose-500">
                    <div className="flex items-center">
                        <p className="text-[11px] font-semibold uppercase tracking-wide text-rose-700">Estimated revenue at risk</p>
                        <InfoHint
                            label="estimated revenue at risk"
                            text="For each day: successful non-wallet revenue divided by successful payments, multiplied by customers who churned that day. This is an operating estimate, not booked accounting loss."
                        />
                    </div>
                    {summaryQuery.isLoading ? (
                        <div className="mt-3 space-y-2">
                            <div className="h-8 w-32 animate-pulse rounded bg-rose-100" />
                            <div className="h-3 w-40 animate-pulse rounded bg-rose-100" />
                        </div>
                    ) : (
                        <>
                            <p className={`${hasRevenueEstimate ? 'text-3xl' : 'text-xl'} mt-1 font-bold tracking-tight text-rose-800`}>
                                {hasRevenueEstimate
                                    ? formatCurrency(revenueAtRisk.estimated_total || 0, revenueAtRisk.currency || 'USD')
                                    : 'Unavailable'}
                            </p>
                            <p className="mt-0.5 text-xs text-slate-600">
                                {revenueAtRisk.weighted_average_ticket == null
                                    ? 'No usable daily ticket'
                                    : `${formatCurrency(revenueAtRisk.weighted_average_ticket, revenueAtRisk.currency || 'USD')} churn-weighted ticket`}
                            </p>
                            <p className="mt-2 text-xs font-semibold text-slate-500">
                                {Number(revenueAtRisk.coverage_percent ?? 0).toFixed(1)}% of churn covered by payment data
                            </p>
                        </>
                    )}
                </div>
            </div>

            {/* Net movement chart */}
            <div className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">Movement over time</h3>
                        <p className="mt-0.5 text-xs text-slate-500">The shaded area shows daily signups minus churn.</p>
                    </div>
                    <SeriesToggle visibleSeries={visibleSeries} onToggle={toggleSeries} />
                </div>
                {summaryQuery.isLoading ? (
                    <div className="h-64 animate-pulse rounded-xl bg-slate-100" />
                ) : (
                    <TrendChart daily={summary?.daily} visibleSeries={visibleSeries} />
                )}
            </div>

            <section className="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div className="flex items-center">
                            <h3 className="text-sm font-semibold text-slate-900">Daily churn revenue estimate</h3>
                            <InfoHint
                                label="daily churn revenue estimate"
                                text="Rose bars estimate revenue at risk from churn. The teal line is the successful average ticket for that day in USD. Missing daily ticket data creates a gap rather than inventing a value."
                            />
                        </div>
                        <p className="mt-0.5 text-xs text-slate-500">Daily average ticket × customers churned that day.</p>
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-xs font-medium text-slate-500">
                        <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-sm bg-rose-400" /> Revenue at risk</span>
                        <span className="inline-flex items-center gap-1.5"><span className="h-0.5 w-4 bg-teal-700" /> Average ticket</span>
                    </div>
                </div>
                {summaryQuery.isLoading ? (
                    <div className="h-64 animate-pulse rounded-xl bg-slate-100" />
                ) : (
                    <RevenueRiskChart daily={summary?.daily} />
                )}
            </section>

            {/* Market duration table */}
            <div>
                <h3 className="text-sm font-semibold text-slate-900">Market retention patterns</h3>
                <p className="mb-2 mt-0.5 text-xs text-slate-500">Compare acquisition, churn, paid tenure, and the full customer relationship. Select a row to focus the full view.</p>
                <MarketDurationTable
                    durations={summary?.durations_by_market}
                    onSelectMarket={(id) => { setSelectedMarketId(id); setPage(1); }}
                    selectedMarketId={selectedMarketId}
                />
            </div>

            <TierBreakdown tiers={summary?.tier_breakdown} />

            {/* Reason aggregator */}
            <div>
                <ReasonAggregator
                    reasons={summary?.reason_breakdown}
                    onSelectReason={(code) => { setSelectedReason(code); setPage(1); }}
                    selectedReason={selectedReason}
                />
            </div>

            {/* Active filters */}
            {(selectedMarketId || selectedReason) ? (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-xs text-slate-500">Filtering by:</span>
                    {selectedMarketId ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-teal-50 px-2 py-0.5 text-xs font-semibold text-teal-700">
                            Market: {summary?.durations_by_market?.find((d) => d.platform_id === selectedMarketId)?.name || `#${selectedMarketId}`}
                            <button type="button" onClick={() => { setSelectedMarketId(null); setPage(1); }} className="ml-1 hover:text-teal-900">×</button>
                        </span>
                    ) : null}
                    {selectedReason ? (
                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700">
                            Reason: {CHURN_REASON_LABELS[selectedReason] || selectedReason}
                            <button type="button" onClick={() => { setSelectedReason(null); setPage(1); }} className="ml-1 hover:text-rose-900">×</button>
                        </span>
                    ) : null}
                </div>
            ) : null}

            {/* Client list */}
            <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div className="border-b border-slate-100 px-4 py-3">
                    <h3 className="text-sm font-semibold text-slate-800">
                        Churned clients
                        {listQuery.data?.total != null ? <span className="ml-2 font-normal text-slate-400">({listQuery.data.total.toLocaleString()})</span> : null}
                    </h3>
                </div>
                <div className="overflow-x-auto">
                <table className="min-w-[1100px] divide-y divide-slate-100 text-sm">
                    <thead className="bg-slate-50">
                        <tr>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Client</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Market</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">First activated</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Churned</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Reason</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Last plan</th>
                            <th className="px-4 py-2 text-left text-[11px] font-semibold uppercase tracking-wide text-slate-500">Actions</th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {listQuery.isLoading ? (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-500">Loading churned clients…</td></tr>
                        ) : rows.length === 0 ? (
                            <tr>
                                <td colSpan={7} className="px-4 py-10 text-center">
                                    <p className="text-sm font-medium text-slate-700">No churn in this reporting period.</p>
                                    <p className="mt-1 text-xs text-slate-500">Try broadening the range or clearing the filters above.</p>
                                </td>
                            </tr>
                        ) : rows.map((row) => (
                            <ChurnedClientRow
                                key={row.id}
                                row={row}
                                onWinBackSms={() => setSmsDialog({ open: true, client: row })}
                                onReactivate={() => navigate(`/clients/${row.id}?tab=deals`)}
                                onMarkWonBack={() => setWonBackDialog({ open: true, client: row })}
                                onCloseCase={() => {
                                    setCloseCaseError(null);
                                    setCloseCaseDialog({ open: true, client: row });
                                }}
                            />
                        ))}
                    </tbody>
                </table>
                </div>

                {pagination && pagination.last_page > 1 ? (
                    <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500">
                        <span>Page {pagination.current_page} of {pagination.last_page} · {pagination.total} total</span>
                        <div className="flex gap-1">
                            <button type="button" disabled={page <= 1} onClick={() => setPage((p) => p - 1)} className="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium disabled:opacity-40">Prev</button>
                            <button type="button" disabled={page >= pagination.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-md border border-slate-200 px-2 py-1 text-xs font-medium disabled:opacity-40">Next</button>
                        </div>
                    </div>
                ) : null}
            </div>

            {/* Dialogs */}
            <WinBackSmsDialog
                open={smsDialog.open}
                client={smsDialog.client}
                isPending={smsMutation.isPending}
                onCancel={() => { if (!smsMutation.isPending) setSmsDialog({ open: false, client: null }); }}
                onConfirm={({ message }) => smsMutation.mutate({ clientId: smsDialog.client?.id, message })}
            />

            <ConfirmDialog
                open={wonBackDialog.open && !!wonBackDialog.client}
                title={`Mark ${wonBackDialog.client?.name || 'client'} as won-back?`}
                message="This clears the churn stamp — the client returns to active queues. Only use if you've confirmed they've re-engaged outside of a new subscription."
                confirmLabel="Mark as won-back"
                tone="default"
                isPending={wonBackMutation.isPending}
                onCancel={() => { if (!wonBackMutation.isPending) setWonBackDialog({ open: false, client: null }); }}
                onConfirm={() => wonBackMutation.mutate({ clientId: wonBackDialog.client?.id, note: 'Manually marked as won-back from churned queue' })}
            />

            <CloseCaseDialog
                open={closeCaseDialog.open}
                clientName={closeCaseDialog.client?.name || ''}
                isPending={closeCaseMutation.isPending}
                error={closeCaseError}
                initialReasonCode={
                    closeCaseDialog.client?.churn_reason_code &&
                    CLOSE_CASE_REASON_CODES.has(closeCaseDialog.client.churn_reason_code)
                        ? closeCaseDialog.client.churn_reason_code
                        : undefined
                }
                onCancel={() => {
                    if (!closeCaseMutation.isPending) {
                        setCloseCaseDialog({ open: false, client: null });
                        setCloseCaseError(null);
                    }
                }}
                onConfirm={(payload) => closeCaseMutation.mutate({ clientId: closeCaseDialog.client?.id, payload })}
            />
        </div>
    );
}
