import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import StatusBadge from '../components/StatusBadge';

const DASHBOARD_REFRESH_MS = 30_000;
const LIST_PREVIEW_LIMIT = 6;

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function clampPercent(value) {
    return Math.max(0, Math.min(100, value));
}

function formatKes(value) {
    return `KES ${asNumber(value).toLocaleString()}`;
}

function formatDate(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '--';
    return date.toLocaleDateString();
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

function SectionFrame({ title, subtitle, action, children, footer }) {
    return (
        <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
            <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3.5">
                <div>
                    <h3 className="text-[1.08rem] leading-6 font-semibold tracking-tight text-slate-900">{title}</h3>
                    {subtitle ? <p className="mt-1 text-sm text-slate-500">{subtitle}</p> : null}
                </div>
                {action}
            </header>
            <div className="p-4">{children}</div>
            {footer ? <footer className="border-t border-slate-100 px-4 py-3">{footer}</footer> : null}
        </section>
    );
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

function MetricProgress({ label, helper, value, tone }) {
    const clamped = clampPercent(value);
    const fillMap = {
        accent: 'bg-teal-600',
        success: 'bg-emerald-600',
        warning: 'bg-amber-600',
    };

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between">
                <p className="text-sm font-medium text-slate-700">{label}</p>
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
    return (
        <article className={`rounded-lg border border-slate-200 border-t-[3px] ${metric.accentBorder} bg-white px-4 py-4 shadow-sm`}>
            <p className="text-sm font-medium text-slate-600">{metric.label}</p>
            <p className="mt-2 text-[1.95rem] leading-none font-semibold tracking-tight text-slate-900 sm:text-[2.05rem]">
                {isLoading ? <span className="inline-block h-9 w-20 animate-pulse rounded bg-slate-100" /> : metric.value}
            </p>
            <p className={`mt-2 text-sm font-medium ${metric.hintClass}`}>{metric.hint}</p>
        </article>
    );
}

export default function Dashboard() {
    const navigate = useNavigate();
    const [platformFilter, setPlatformFilter] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['dashboard', platformFilter],
        queryFn: () =>
            api.get('/crm/dashboard', {
                params: {
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        refetchInterval: DASHBOARD_REFRESH_MS,
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'dashboard-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platforms = integrationData?.platforms || [];

    const kpis = data?.kpis || {};
    const activeClients = asNumber(kpis.active_clients);
    const totalClients = asNumber(kpis.total_clients);
    const pendingLeads = asNumber(kpis.pending_leads);
    const totalLeads = asNumber(kpis.total_leads);
    const expiringSoon = asNumber(kpis.expiring_soon);
    const recentPaymentsCount = asNumber(kpis.completed_payments_mtd ?? kpis.recent_payments);
    const unmatchedPayments = asNumber(kpis.unmatched_payments);

    const matchQuality = recentPaymentsCount > 0
        ? clampPercent(((recentPaymentsCount - unmatchedPayments) / recentPaymentsCount) * 100)
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
            label: 'Revenue This Month',
            value: formatKes(kpis.revenue_mtd),
            hint: `${recentPaymentsCount} confirmed payments`,
            accentBorder: 'border-t-teal-600',
            hintClass: 'text-teal-700',
        },
        {
            key: 'clients',
            label: 'Active Clients',
            value: activeClients.toLocaleString(),
            hint: `${Math.round(activeCoverage)}% of ${totalClients.toLocaleString()} total`,
            accentBorder: 'border-t-slate-300',
            hintClass: 'text-slate-600',
        },
        {
            key: 'unmatched',
            label: 'Unmatched Payments',
            value: unmatchedPayments.toLocaleString(),
            hint: unmatchedPayments > 0 ? 'Needs review in queue' : 'No payment review backlog',
            accentBorder: unmatchedPayments > 0 ? 'border-t-rose-500' : 'border-t-slate-300',
            hintClass: unmatchedPayments > 0 ? 'text-rose-700' : 'text-slate-600',
        },
        {
            key: 'renewals',
            label: 'Renewals At Risk',
            value: expiringSoon.toLocaleString(),
            hint: expiringSoon > 0 ? 'Expiring within 7 days' : 'No urgent renewals',
            accentBorder: expiringSoon > 0 ? 'border-t-amber-500' : 'border-t-slate-300',
            hintClass: expiringSoon > 0 ? 'text-amber-700' : 'text-emerald-700',
        },
    ];

    const payments = data?.payment_review_queue || data?.recent_payments || [];
    const paymentPreview = payments.slice(0, LIST_PREVIEW_LIMIT);
    const hiddenPaymentCount = Math.max(0, payments.length - LIST_PREVIEW_LIMIT);

    const expiringDeals = data?.expiring_deals || [];
    const expiringPreview = expiringDeals.slice(0, LIST_PREVIEW_LIMIT);
    const hiddenExpiringCount = Math.max(0, expiringDeals.length - LIST_PREVIEW_LIMIT);

    const followUps = data?.upcoming_follow_ups || [];
    const followUpPreview = followUps.slice(0, LIST_PREVIEW_LIMIT);
    const hiddenFollowUpCount = Math.max(0, followUps.length - LIST_PREVIEW_LIMIT);

    return (
        <div className="space-y-4">
            <section className="rounded-lg border border-slate-200 bg-white px-5 py-5 shadow-sm">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">{todayLabel}</p>
                        <h2 className="mt-1 text-[2.1rem] leading-[1.08] font-semibold tracking-tight text-slate-900 sm:text-[2.3rem]">Dashboard</h2>
                        <p className="mt-1.5 text-[1.05rem] text-slate-600">Sales, payments, and renewal workload in one operational view.</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => navigate('/payments')}
                            className="rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                        >
                            Review payment queue
                        </button>
                        <button
                            type="button"
                            onClick={() => navigate('/deals?status=active')}
                            className="rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        >
                            Open deals
                        </button>
                    </div>
                </div>

                <div className="mt-4 flex flex-wrap items-center gap-2">
                    <label htmlFor="dashboard-market" className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">Market filter</label>
                    <select
                        id="dashboard-market"
                        value={platformFilter}
                        onChange={(event) => setPlatformFilter(event.target.value)}
                        className="crm-select min-w-[200px]"
                    >
                        <option value="">All accessible markets</option>
                        {platforms.map((platform) => (
                            <option key={platform.platform_id} value={platform.platform_id}>
                                {platform.platform_name}
                            </option>
                        ))}
                    </select>
                    {platformFilter ? (
                        <button
                            type="button"
                            onClick={() => setPlatformFilter('')}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Clear filter
                        </button>
                    ) : null}
                </div>
            </section>

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                {metrics.map((metric) => (
                    <MetricCard key={metric.key} metric={metric} isLoading={isLoading} />
                ))}
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                <div className="space-y-4 xl:col-span-8">
                    <SectionFrame
                        title="Payment Review Queue"
                        subtitle="Unmatched completed payments that need manual client matching."
                        action={(
                            <button
                                type="button"
                                onClick={() => navigate('/payments')}
                                className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            >
                                View all
                            </button>
                        )}
                        footer={(
                            <PreviewFooter
                                hiddenCount={hiddenPaymentCount}
                                noun="payments"
                                ctaLabel="Open full queue"
                                onOpen={() => navigate('/payments')}
                            />
                        )}
                    >
                        {isLoading ? (
                            <LoadingRows />
                        ) : paymentPreview.length > 0 ? (
                            <div className="space-y-2">
                                {paymentPreview.map((payment) => (
                                    <button
                                        key={payment.id}
                                        type="button"
                                        onClick={() => navigate('/payments')}
                                        className="flex w-full items-center justify-between gap-4 rounded-md border border-slate-200 bg-white px-3.5 py-2.5 text-left transition hover:border-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                    >
                                        <div className="min-w-0">
                                            <p className="truncate text-sm font-semibold text-slate-900">{formatKes(payment.amount)}</p>
                                            <p className="truncate text-xs text-slate-500">
                                                {payment.phone || 'No phone'} {payment.transaction_reference ? `- ${payment.transaction_reference}` : ''}
                                            </p>
                                        </div>
                                        <div className="shrink-0 text-right">
                                            <StatusBadge status={payment.status} />
                                            <p className="mt-1 text-xs text-slate-400">{formatRelativeTime(payment.created_at)}</p>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        ) : (
                            <EmptyState message="No unmatched completed payments in review queue." />
                        )}
                    </SectionFrame>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <SectionFrame
                            title="Expiring Deals"
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
                                    noun="expiring deals"
                                    ctaLabel="Open deal list"
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
                                <EmptyState message="No deals expiring soon." />
                            )}
                        </SectionFrame>

                        <SectionFrame
                            title="Upcoming Follow-ups"
                            subtitle="Nearest follow-up actions"
                            action={(
                                <button
                                    type="button"
                                    onClick={() => navigate('/clients')}
                                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                >
                                    Open clients
                                </button>
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
                    </div>
                </div>

                <div className="xl:col-span-4">
                    <SectionFrame title="Performance Pulse" subtitle="Health indicators for today">
                        <div className="space-y-4">
                            <MetricProgress
                                label="Payment match quality"
                                helper={`${Math.round(matchQuality)}% matched this month`}
                                value={matchQuality}
                                tone="accent"
                            />
                            <MetricProgress
                                label="Lead backlog pressure"
                                helper={`${pendingLeads.toLocaleString()} pending of ${totalLeads.toLocaleString()} leads`}
                                value={leadBacklog}
                                tone="warning"
                            />
                            <MetricProgress
                                label="Active client coverage"
                                helper={`${activeClients.toLocaleString()} active profiles`}
                                value={activeCoverage}
                                tone="success"
                            />
                        </div>
                    </SectionFrame>
                </div>
            </section>
        </div>
    );
}
