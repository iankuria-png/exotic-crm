import React, { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import SectionFrame from '../components/SectionFrame';
import CountryRevenueWidget from '../components/dashboard/CountryRevenueWidget';
import QuickStatsWidget from '../components/dashboard/QuickStatsWidget';
import RecentActivityWidget from '../components/dashboard/RecentActivityWidget';
import CommsBalanceWidget from '../components/dashboard/CommsBalanceWidget';
import useDashboardWidgets from '../hooks/useDashboardWidgets';
import { getCountryFlag } from '../utils/flags';

const DASHBOARD_REFRESH_MS = 30_000;
const LIST_PREVIEW_LIMIT = 6;
const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function clampPercent(value) {
    return Math.max(0, Math.min(100, value));
}

function formatCurrency(value, currency = 'KES') {
    return `${currency} ${asNumber(value).toLocaleString()}`;
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

function formatDate(value) {
    if (!value) return '--';
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(String(value))
        ? `${value}T00:00:00`
        : value;
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleDateString();
}

function toInputDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatRelativeTime(value) {
    if (!value) return '--';
    const timestamp = new Date(value).getTime();
    if (Number.isNaN(timestamp)) return '--';

    const deltaMinutes = Math.floor((Date.now() - timestamp) / 60_000);
    if (deltaMinutes < 1) return 'just now';
    if (deltaMinutes < 60) return `${deltaMinutes}m ago`;

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) return `${deltaHours}h ago`;

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}

function formatExpiryWindow(value) {
    if (!value) return 'No expiry date';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'No expiry date';

    const daysDiff = Math.ceil((date.getTime() - Date.now()) / 86_400_000);
    if (daysDiff < 0) return `${Math.abs(daysDiff)}d overdue`;
    if (daysDiff === 0) return 'Due today';
    return `${daysDiff}d left`;
}

function formatDelta(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return null;
    if (numeric === 0) return 'Flat vs previous window';
    return `${numeric > 0 ? '+' : ''}${numeric}% vs previous window`;
}

function LoadingRows() {
    return (
        <div className="space-y-2">
            {[1, 2, 3].map((item) => (
                <div key={item} className="h-14 animate-pulse rounded-md bg-slate-100" />
            ))}
        </div>
    );
}

function EmptyState({ message }) {
    return (
        <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function InfoHint({ text }) {
    return (
        <span
            className="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-300 bg-white text-[10px] font-semibold text-slate-500 cursor-help"
            title={text}
            aria-label={text}
        >
            ?
        </span>
    );
}

function MetricProgress({ label, helper, value, tone, tooltip }) {
    const clamped = clampPercent(value);
    const fillMap = {
        accent: 'bg-teal-600',
        success: 'bg-emerald-600',
        warning: 'bg-amber-600',
    };

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between">
                <p className="flex items-center gap-1.5 text-sm font-medium text-slate-700">
                    {label}
                    {tooltip ? (
                        <span
                            className="inline-flex h-4 w-4 items-center justify-center rounded-full border border-slate-200 text-[10px] font-semibold text-slate-400 cursor-help"
                            title={tooltip}
                        >
                            ?
                        </span>
                    ) : null}
                </p>
                <p className="crm-mono text-xs font-semibold text-slate-500">{Math.round(clamped)}%</p>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${fillMap[tone] || fillMap.accent}`} style={{ width: `${clamped}%` }} />
            </div>
            <p className="text-xs text-slate-500">{helper}</p>
        </div>
    );
}

function PreviewFooter({ hiddenCount, noun, ctaLabel, onOpen }) {
    if (hiddenCount <= 0) return null;

    return (
        <div className="flex items-center justify-between gap-3">
            <p className="text-sm text-slate-500">+{hiddenCount} more {noun}</p>
            <button
                type="button"
                onClick={onOpen}
                className="text-sm font-semibold text-teal-700 transition hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
            >
                {ctaLabel}
            </button>
        </div>
    );
}

function MetricCard({ metric, isLoading }) {
    const interactive = typeof metric.onClick === 'function';
    const Wrapper = interactive ? 'button' : 'article';

    return (
        <Wrapper
            {...(interactive ? { type: 'button', onClick: metric.onClick } : {})}
            className={`rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm ${interactive ? 'w-full cursor-pointer text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600' : ''}`}
        >
            <p className="flex items-center gap-2 text-sm font-medium text-slate-600">
                <span className={`h-2 w-2 rounded-full ${metric.accentDot}`} aria-hidden="true" />
                {metric.label}
            </p>
            <p className="mt-2 text-[1.95rem] leading-none font-semibold tracking-tight text-slate-900 sm:text-[2.05rem]">
                {isLoading ? <span className="inline-block h-9 w-20 animate-pulse rounded bg-slate-100" /> : metric.value}
            </p>
            <p className={`mt-2 text-sm font-medium ${metric.hintClass}`}>{metric.hint}</p>
            {metric.subHint ? <p className="mt-1 text-xs text-slate-500">{metric.subHint}</p> : null}
        </Wrapper>
    );
}

function RetentionWatchWidget({ summary, isLoading, onOpenWatchlist }) {
    const watchCount = asNumber(summary?.watch_count);
    const logoChurn30d = Number(summary?.logo_churn_30d || 0);
    const bandDistribution = summary?.band_distribution || {};
    const behaviorDistribution = summary?.behavior_distribution || {};
    const bandOrder = ['Critical', 'Needs Attention', 'Watchlist', 'Stable'];
    const monochromeBadgeClasses = {
        Stable: 'border-slate-200 bg-white text-slate-500',
        Watchlist: 'border-slate-300 bg-white text-slate-700',
        'Needs Attention': 'border-slate-500 bg-slate-50 text-slate-800',
        Critical: 'border-slate-900 bg-slate-100 text-slate-950',
    };
    const bandEntries = bandOrder.map((band) => ({
        band,
        count: asNumber(bandDistribution?.[band]),
    }));
    const totalBandCount = bandEntries.reduce((sum, entry) => sum + entry.count, 0);
    const behaviorEntries = Object.entries(behaviorDistribution)
        .map(([behavior, count]) => ({ behavior, count: asNumber(count) }))
        .sort((left, right) => right.count - left.count)
        .slice(0, 4);
    const totalBehaviorCount = behaviorEntries.reduce((sum, entry) => sum + entry.count, 0);

    const shareOf = (count, total) => {
        if (!total) {
            return 0;
        }

        return Math.round((count / total) * 100);
    };

    return (
        <SectionFrame
            title="Retention Watch"
            subtitle="Churn signals"
            action={(
                <button
                    type="button"
                    onClick={onOpenWatchlist}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                >
                    Open clients
                </button>
            )}
        >
            {isLoading ? (
                <LoadingRows />
            ) : (
                <div className="space-y-5">
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Watch count</p>
                                <InfoHint text="Clients currently flagged for retention follow-up in the active dashboard scope." />
                            </div>
                            <p className="mt-2 text-3xl leading-none font-semibold text-slate-950">{watchCount.toLocaleString()}</p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                            <div className="flex items-center justify-between gap-2">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Logo churn 30d</p>
                                <InfoHint text="Trailing 30-day churn from daily snapshots. Not computed live on page load." />
                            </div>
                            <p className="mt-2 text-3xl leading-none font-semibold text-slate-950">{logoChurn30d.toFixed(2)}%</p>
                        </div>
                    </div>

                    <div className="space-y-4">
                        <div>
                            <div className="mb-2 flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <p className="text-sm font-semibold text-slate-900">Band mix</p>
                                    <InfoHint text="Distribution of retention risk across the current dashboard scope." />
                                </div>
                            </div>
                            <div className="space-y-2">
                                {bandEntries.map(({ band, count }) => {
                                    const share = shareOf(count, totalBandCount);

                                    return (
                                        <div key={band} className="space-y-1.5">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <span className={`inline-flex shrink-0 items-center rounded-full border px-2.5 py-1 text-[11px] font-semibold whitespace-nowrap ${monochromeBadgeClasses[band] || monochromeBadgeClasses.Watchlist}`}>
                                                        {band}
                                                    </span>
                                                </div>
                                                <div className="shrink-0 text-right">
                                                    <p className="text-base font-semibold text-slate-950">{count.toLocaleString()}</p>
                                                    <p className="text-[11px] font-medium text-slate-500">{share}%</p>
                                                </div>
                                            </div>
                                            <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                <div
                                                    className="h-full rounded-full bg-slate-700"
                                                    style={{ width: `${Math.max(share, count > 0 ? 4 : 0)}%` }}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        </div>

                        <div>
                            <div className="mb-2 flex items-center gap-2">
                                <p className="text-sm font-semibold text-slate-900">Behavior mix</p>
                                <InfoHint text="Behavior tags explain the dominant reasons clients are drifting." />
                            </div>
                            {behaviorEntries.length ? (
                                <div className="space-y-2">
                                    {behaviorEntries.map(({ behavior, count }) => {
                                        const share = shareOf(count, totalBehaviorCount);

                                        return (
                                            <div key={behavior} className="space-y-1.5">
                                                <div className="flex items-center justify-between gap-3">
                                                    <p className="min-w-0 truncate text-sm font-medium text-slate-700">{behavior}</p>
                                                    <div className="shrink-0 text-right">
                                                        <p className="text-base font-semibold text-slate-950">{count.toLocaleString()}</p>
                                                        <p className="text-[11px] font-medium text-slate-500">{share}%</p>
                                                    </div>
                                                </div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-slate-100">
                                                    <div
                                                        className="h-full rounded-full bg-slate-700"
                                                        style={{ width: `${Math.max(share, count > 0 ? 4 : 0)}%` }}
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>
                            ) : (
                                <EmptyState message="Behavior data will appear once retention snapshots have enough history." />
                            )}
                        </div>
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}

export default function Dashboard() {
    const navigate = useNavigate();
    const { config: widgetConfig } = useDashboardWidgets();
    const [platformFilter, setPlatformFilter] = useState(() => {
        if (typeof window === 'undefined') {
            return '';
        }

        return normalizePlatformFilter(window.localStorage.getItem(DASHBOARD_MARKET_STORAGE_KEY));
    });
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [countryPeriod, setCountryPeriod] = useState('week');
    const [didHydrateDefaultRange, setDidHydrateDefaultRange] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['dashboard', platformFilter, fromDate, toDate, countryPeriod],
        queryFn: () =>
            api.get('/crm/dashboard', {
                params: {
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                    ...(fromDate ? { from: fromDate } : {}),
                    ...(toDate ? { to: toDate } : {}),
                    country_period: countryPeriod,
                },
            }).then((response) => response.data),
        refetchInterval: DASHBOARD_REFRESH_MS,
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'dashboard-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platforms = integrationData?.platforms || [];
    const selectedPlatform = platforms.find(
        (platform) => String(platform.platform_id) === String(platformFilter),
    ) || null;
    const selectedCurrency = selectedPlatform?.currency || 'KES';
    const withMarketScope = (path) => {
        if (!platformFilter) {
            return path;
        }

        const [pathname, search = ''] = path.split('?');
        const params = new URLSearchParams(search);
        params.set('platform_id', platformFilter);
        const query = params.toString();

        return query ? `${pathname}?${query}` : pathname;
    };
    const defaultWindowFrom = data?.window?.default_from || data?.filters?.from || '';
    const defaultWindowTo = data?.window?.default_to || data?.filters?.to || '';

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (platformFilter) {
            window.localStorage.setItem(DASHBOARD_MARKET_STORAGE_KEY, platformFilter);
            return;
        }

        window.localStorage.removeItem(DASHBOARD_MARKET_STORAGE_KEY);
    }, [platformFilter]);

    useEffect(() => {
        if (!platformFilter || !platforms.length) {
            return;
        }

        const stillAccessible = platforms.some(
            (platform) => String(platform.platform_id) === String(platformFilter),
        );

        if (!stillAccessible) {
            setPlatformFilter('');
        }
    }, [platformFilter, platforms]);

    useEffect(() => {
        if (didHydrateDefaultRange) {
            return;
        }

        if (!defaultWindowFrom || !defaultWindowTo) {
            return;
        }

        if (!fromDate && !toDate) {
            setFromDate(defaultWindowFrom);
            setToDate(defaultWindowTo);
        }

        setDidHydrateDefaultRange(true);
    }, [defaultWindowFrom, defaultWindowTo, didHydrateDefaultRange, fromDate, toDate]);

    const kpis = data?.kpis || {};
    const activeClients = asNumber(kpis.active_clients);
    const totalClients = asNumber(kpis.total_clients);
    const pendingLeads = asNumber(kpis.pending_leads);
    const totalLeads = asNumber(kpis.total_leads);
    const revenueWindow = asNumber(kpis.revenue_window ?? kpis.revenue_mtd);
    const averageTicketWindow = asNumber(kpis.average_ticket_window);
    const revenueDeltaLabel = formatDelta(kpis.revenue_delta_percent);
    const recentPaymentsCount = asNumber(kpis.completed_payments_window ?? kpis.completed_payments_mtd ?? kpis.recent_payments);
    const unmatchedPaymentsWindow = asNumber(kpis.unmatched_payments_window ?? kpis.unmatched_payments);
    const paymentRecoveryPending = asNumber(kpis.payment_recovery_pending);
    const paymentRecoveryFailed = asNumber(kpis.payment_recovery_failed);
    const paymentRecoveryUnmatched = asNumber(kpis.payment_recovery_unmatched);
    const paymentRecoveryQueueTotal = asNumber(kpis.payment_recovery_queue_total);
    const renewalRisk72h = asNumber(kpis.renewal_risk_72h ?? kpis.expiring_soon);
    const renewalPipeline14d = asNumber(kpis.renewal_pipeline_4_14d);
    const renewalWorkload14d = asNumber(kpis.renewal_workload_14d ?? (renewalRisk72h + renewalPipeline14d));
    const retentionSummary = data?.retention_summary || {};
    const retentionWatchCount = asNumber(retentionSummary.watch_count);
    const logoChurn30d = Number(retentionSummary.logo_churn_30d || 0);

    const matchQuality = recentPaymentsCount > 0
        ? clampPercent(((recentPaymentsCount - unmatchedPaymentsWindow) / recentPaymentsCount) * 100)
        : 100;
    const leadBacklog = totalLeads > 0 ? clampPercent((pendingLeads / totalLeads) * 100) : 0;
    const activeCoverage = totalClients > 0 ? clampPercent((activeClients / totalClients) * 100) : 0;

    const todayLabel = new Intl.DateTimeFormat('en-KE', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    }).format(new Date());

    const metrics = [
        {
            key: 'revenue',
            label: 'Collected Revenue',
            value: formatCurrency(revenueWindow, selectedCurrency),
            hint: recentPaymentsCount > 0
                ? `${recentPaymentsCount} completed • avg ${formatCurrency(averageTicketWindow, selectedCurrency)}`
                : 'No completed payments in selected range',
            subHint: revenueDeltaLabel || 'No previous window baseline',
            accentDot: 'bg-teal-600',
            hintClass: 'text-teal-700',
            onClick: () => navigate(withMarketScope('/payments?status=completed')),
        },
        {
            key: 'clients',
            label: 'Active Clients',
            value: activeClients.toLocaleString(),
            hint: `${Math.round(activeCoverage)}% coverage of ${totalClients.toLocaleString()} records`,
            accentDot: 'bg-slate-400',
            hintClass: 'text-slate-600',
            onClick: () => navigate(withMarketScope('/clients?status=publish')),
        },
        {
            key: 'recovery',
            label: 'Payment Recovery Queue',
            value: paymentRecoveryQueueTotal.toLocaleString(),
            hint: paymentRecoveryQueueTotal > 0
                ? `${paymentRecoveryFailed} failed • ${paymentRecoveryPending} pending • ${paymentRecoveryUnmatched} unmatched`
                : 'No payment recovery backlog',
            accentDot: paymentRecoveryQueueTotal > 0 ? 'bg-rose-500' : 'bg-slate-400',
            hintClass: paymentRecoveryQueueTotal > 0 ? 'text-rose-700' : 'text-slate-600',
            onClick: () => navigate(withMarketScope('/payments?status=recovery_queue')),
        },
        {
            key: 'renewals',
            label: 'Renewal Workload (14d)',
            value: renewalWorkload14d.toLocaleString(),
            hint: renewalWorkload14d > 0
                ? `${renewalRisk72h} in 0-3d • ${renewalPipeline14d} in 4-14d`
                : 'No renewals due in next 14 days',
            accentDot: renewalWorkload14d > 0 ? 'bg-amber-500' : 'bg-slate-400',
            hintClass: renewalWorkload14d > 0 ? 'text-amber-700' : 'text-emerald-700',
            onClick: () => navigate(withMarketScope('/deals?bucket=workload')),
        },
        {
            key: 'retention_watch',
            label: 'Retention Watch',
            value: retentionWatchCount.toLocaleString(),
            hint: retentionWatchCount > 0
                ? `${logoChurn30d.toFixed(2)}% logo churn in trailing 30 days`
                : 'No clients currently flagged in retention watch',
            subHint: 'Signals combine payments, renewals, engagement, reminders, and market baseline.',
            accentDot: retentionWatchCount > 0 ? 'bg-rose-500' : 'bg-slate-400',
            hintClass: retentionWatchCount > 0 ? 'text-rose-700' : 'text-slate-600',
            onClick: () => navigate(withMarketScope('/clients?retention_band=watch')),
        },
    ];

    const expiringDeals = data?.expiring_deals || [];
    const expiringPreview = expiringDeals.slice(0, LIST_PREVIEW_LIMIT);
    const hiddenExpiringCount = Math.max(0, expiringDeals.length - LIST_PREVIEW_LIMIT);

    const followUps = data?.upcoming_follow_ups || [];
    const followUpPreview = followUps.slice(0, LIST_PREVIEW_LIMIT);
    const hiddenFollowUpCount = Math.max(0, followUps.length - LIST_PREVIEW_LIMIT);
    const appliedRangeFrom = data?.window?.applied_from || data?.filters?.from || fromDate || '';
    const appliedRangeTo = data?.window?.applied_to || data?.filters?.to || toDate || '';
    const isDefaultDateWindow = Boolean(data?.window?.is_default);
    const hasNonDefaultDateRange = Boolean(
        fromDate
        && toDate
        && defaultWindowFrom
        && defaultWindowTo
        && (fromDate !== defaultWindowFrom || toDate !== defaultWindowTo)
    );

    const applyAllTimeWindow = () => {
        if (!defaultWindowFrom || !defaultWindowTo) {
            return;
        }

        setFromDate(defaultWindowFrom);
        setToDate(defaultWindowTo);
    };

    const applyRelativeDaysWindow = (days) => {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - (days - 1));
        setFromDate(toInputDateString(start));
        setToDate(toInputDateString(end));
    };

    const resetFilters = () => {
        setPlatformFilter('');
        applyAllTimeWindow();
    };

    return (
        <div className="space-y-4">
            <section className="rounded-lg border border-slate-200 bg-white px-5 py-5 shadow-sm">
                <div className="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{todayLabel}</p>
                        <h2 className="mt-1 text-[2.1rem] leading-[1.08] font-semibold tracking-tight text-slate-900 sm:text-[2.3rem]">Dashboard</h2>
                        <p className="mt-1.5 text-[1.05rem] text-slate-600">Sales, payments, and renewal workload in one operational view.</p>
                    </div>

                    <div className="flex flex-wrap gap-2 xl:justify-end">
                        <button
                            type="button"
                            onClick={() => navigate('/payments?status=recovery_queue')}
                            className="inline-flex items-center gap-2 rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                            title="Open payment recovery queue"
                        >
                            Recovery
                        </button>
                        <button
                            type="button"
                            onClick={() => navigate('/deals?bucket=workload')}
                            className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            title="Open renewal workload"
                        >
                            Renewals
                        </button>
                        <button
                            type="button"
                            onClick={() => navigate('/leads')}
                            className="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            title="Open lead backlog"
                        >
                            Leads
                        </button>
                    </div>
                </div>

                <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50/80 p-3 sm:p-4">
                    <div className="grid gap-2 lg:grid-cols-[minmax(220px,1fr)_160px_160px_auto] lg:items-end">
                        <label className="space-y-1">
                            <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Market</span>
                            <span className="inline-flex w-full items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2">
                                <span className={`h-2 w-2 rounded-full ${platformFilter ? 'bg-emerald-500' : 'bg-slate-300'}`} aria-hidden="true" />
                                <select
                                    id="dashboard-market"
                                    value={platformFilter}
                                    onChange={(event) => setPlatformFilter(normalizePlatformFilter(event.target.value))}
                                    className="w-full border-0 bg-transparent text-sm font-medium text-slate-700 focus:outline-none"
                                >
                                    <option value="">{'\u{1F30D}'}  All accessible markets</option>
                                    {platforms.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {getCountryFlag(platform.country)}  {platform.platform_name}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        </label>

                        <label className="space-y-1">
                            <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">From</span>
                            <input
                                type="date"
                                value={fromDate}
                                onChange={(event) => setFromDate(event.target.value)}
                                className="crm-input w-full"
                                aria-label="From date"
                            />
                        </label>

                        <label className="space-y-1">
                            <span className="block text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">To</span>
                            <input
                                type="date"
                                value={toDate}
                                onChange={(event) => setToDate(event.target.value)}
                                className="crm-input w-full"
                                aria-label="To date"
                            />
                        </label>

                        <div className="flex flex-wrap gap-1 lg:justify-end">
                            <button
                                type="button"
                                onClick={applyAllTimeWindow}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            >
                                All-time
                            </button>
                            <button
                                type="button"
                                onClick={() => applyRelativeDaysWindow(30)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            >
                                30d
                            </button>
                            <button
                                type="button"
                                onClick={() => applyRelativeDaysWindow(7)}
                                className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            >
                                7d
                            </button>
                            {(platformFilter || hasNonDefaultDateRange) ? (
                                <button
                                    type="button"
                                    onClick={resetFilters}
                                    className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                >
                                    Reset
                                </button>
                            ) : null}
                        </div>
                    </div>

                    <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                        <span className="rounded-full border border-slate-300 bg-white px-3 py-1">
                            Range: <span className="crm-mono font-medium text-slate-700">{formatDate(appliedRangeFrom)}</span> to <span className="crm-mono font-medium text-slate-700">{formatDate(appliedRangeTo)}</span>
                        </span>
                        <span className="rounded-full border border-slate-300 bg-white px-3 py-1">
                            {isDefaultDateWindow ? 'Default: oldest to today' : 'Custom range'}
                        </span>
                        {platformFilter ? (
                            <span className="rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-emerald-700">
                                Market filter active
                            </span>
                        ) : null}
                    </div>
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                {metrics.map((metric) => (
                    <MetricCard key={metric.key} metric={metric} isLoading={isLoading} />
                ))}
            </section>

            <p className="px-1 text-xs text-slate-500">Click any metric card to open the relevant action queue.</p>

            <section className="grid gap-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-8">
                    {widgetConfig.country_revenue ? (
                        <CountryRevenueWidget
                            data={data?.country_revenue || []}
                            period={countryPeriod}
                            onPeriodChange={setCountryPeriod}
                            isLoading={isLoading}
                        />
                    ) : null}

                    <div className="grid gap-4 xl:grid-cols-2">
                        {widgetConfig.expiring_subs ? (
                        <SectionFrame
                            title="Expiring Subscriptions"
                            subtitle="Earliest renewals first"
                            action={(
                                <button
                                    type="button"
                                    onClick={() => navigate('/deals?status=active')}
                                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                >
                                    View all
                                </button>
                            )}
                            footer={(
                                <PreviewFooter
                                    hiddenCount={hiddenExpiringCount}
                                    noun="expiring subscriptions"
                                    ctaLabel="Open subscriptions"
                                    onOpen={() => navigate('/deals?status=active')}
                                />
                            )}
                        >
                            {isLoading ? (
                                <LoadingRows />
                            ) : expiringPreview.length > 0 ? (
                                <div className="space-y-2">
                                    {expiringPreview.map((deal) => (
                                        <button
                                            key={deal.id}
                                            type="button"
                                            onClick={() => deal.client && navigate(`/clients/${deal.client.id}`)}
                                            className="flex w-full items-center justify-between gap-3 rounded-md border border-slate-200 px-3.5 py-2.5 text-left transition hover:border-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-semibold text-slate-900">{deal.client?.name || 'Unknown client'}</p>
                                                <p className="truncate text-xs text-slate-500">{deal.product?.name || deal.plan_type}</p>
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <p className="text-xs font-semibold text-amber-700">{formatExpiryWindow(deal.expires_at)}</p>
                                                <p className="mt-1 text-xs text-slate-400">{formatDate(deal.expires_at)}</p>
                                            </div>
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState message="No subscriptions expiring soon." />
                            )}
                        </SectionFrame>
                        ) : null}

                        {widgetConfig.follow_ups ? (
                        <SectionFrame
                            title="Upcoming Follow-ups"
                            subtitle="Scheduled client callbacks due soon"
                            action={(
                                <div className="flex items-center gap-2">
                                    <span
                                        className="inline-flex h-6 w-6 items-center justify-center rounded-md border border-slate-200 text-xs font-semibold text-slate-500"
                                        title="Follow-ups are notes with due dates created by agents. They help prevent missed callbacks."
                                    >
                                        ?
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => navigate('/clients')}
                                        className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                    >
                                        Open clients
                                    </button>
                                </div>
                            )}
                            footer={(
                                <PreviewFooter
                                    hiddenCount={hiddenFollowUpCount}
                                    noun="follow-ups"
                                    ctaLabel="Open all follow-ups"
                                    onOpen={() => navigate('/clients')}
                                />
                            )}
                        >
                            {isLoading ? (
                                <LoadingRows />
                            ) : followUpPreview.length > 0 ? (
                                <div className="space-y-2">
                                    {followUpPreview.map((note) => (
                                        <button
                                            key={note.id}
                                            type="button"
                                            onClick={() => note.client && navigate(`/clients/${note.client.id}`)}
                                            className="flex w-full items-start gap-3 rounded-md border border-slate-200 px-3.5 py-2.5 text-left transition hover:border-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                        >
                                            <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-slate-400" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-semibold text-slate-900">{note.client?.name || 'Unknown client'}</span>
                                                <span className="mt-0.5 block line-clamp-2 text-xs text-slate-500">{note.content}</span>
                                                <span className="mt-1.5 block text-[11px] text-slate-400">
                                                    {formatDate(note.follow_up_at)} • {formatRelativeTime(note.follow_up_at)}
                                                </span>
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            ) : (
                                <EmptyState message="No pending follow-ups." />
                            )}
                        </SectionFrame>
                        ) : null}
                    </div>
                </div>

                <div className="space-y-4 xl:col-span-4">
                    {widgetConfig.performance_pulse ? (
                        <SectionFrame title="Performance Pulse" subtitle="Health indicators for today">
                            <div className="space-y-4">
                                <MetricProgress
                                    label="Payment match quality"
                                    helper={`${Math.round(matchQuality)}% matched this month`}
                                    value={matchQuality}
                                    tone="accent"
                                    tooltip="Percentage of completed payments in the selected window that are linked to a client."
                                />
                                <MetricProgress
                                    label="Lead backlog pressure"
                                    helper={`${pendingLeads.toLocaleString()} pending of ${totalLeads.toLocaleString()} leads`}
                                    value={leadBacklog}
                                    tone="warning"
                                    tooltip="Proportion of leads still in 'new' or 'contacted' status vs. total leads."
                                />
                                <MetricProgress
                                    label="Active client coverage"
                                    helper={`${activeClients.toLocaleString()} active profiles`}
                                    value={activeCoverage}
                                    tone="success"
                                    tooltip="Share of all client records that have an active (published) profile."
                                />
                            </div>
                        </SectionFrame>
                    ) : null}

                    {widgetConfig.retention_watch ? (
                        <RetentionWatchWidget
                            summary={retentionSummary}
                            isLoading={isLoading}
                            onOpenWatchlist={() => navigate(withMarketScope('/clients?retention_band=watch'))}
                        />
                    ) : null}

                    {widgetConfig.quick_stats ? (
                        <QuickStatsWidget
                            kpis={kpis}
                            activeCampaigns={data?.active_campaigns_count || 0}
                            isLoading={isLoading}
                        />
                    ) : null}

                    {widgetConfig.recent_activity ? (
                        <RecentActivityWidget
                            events={data?.recent_activity || []}
                            isLoading={isLoading}
                        />
                    ) : null}

                    {widgetConfig.comms_balance ? (
                        <CommsBalanceWidget
                            stats={data?.comms_stats || {}}
                            isLoading={isLoading}
                        />
                    ) : null}
                </div>
            </section>
        </div>
    );
}
