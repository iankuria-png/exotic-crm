import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../../services/api';
import SectionFrame from '../SectionFrame';

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatPercent(value, digits = 1) {
    return `${asNumber(value).toFixed(digits)}%`;
}

function abbreviateNumber(n) {
    const num = asNumber(n);
    if (num >= 1_000_000) return `${(num / 1_000_000).toFixed(1)}M`;
    if (num >= 10_000) return `${(num / 1_000).toFixed(1)}K`;
    return num.toLocaleString();
}

function describeWindow(fromDate, toDate) {
    if (!fromDate || !toDate) {
        return 'selected window';
    }

    const from = new Date(fromDate);
    const to = new Date(toDate);
    if (Number.isNaN(from.getTime()) || Number.isNaN(to.getTime())) {
        return 'selected window';
    }

    const diffDays = Math.max(1, Math.round((to.getTime() - from.getTime()) / 86_400_000) + 1);
    return `${diffDays}-day window`;
}

function MiniStat({ label, value, delta, deltaSuffix }) {
    const sign = delta >= 0 ? '+' : '';
    const deltaColor = delta > 0
        ? 'text-emerald-600'
        : delta < 0
            ? 'text-rose-500'
            : 'text-slate-400';

    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50/60 px-3.5 py-3">
            <p className="text-[10px] font-semibold uppercase tracking-[0.10em] text-slate-400">{label}</p>
            <p className="mt-1.5 text-base font-semibold tracking-tight text-slate-900">{value}</p>
            {delta !== undefined ? (
                <p className={`mt-1 text-[11px] font-medium ${deltaColor}`}>
                    {sign}{asNumber(delta).toFixed(1)}{deltaSuffix} vs prev.
                </p>
            ) : null}
        </div>
    );
}

function EmptyState({ message }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function LoadingList() {
    return (
        <div className="space-y-2">
            {Array.from({ length: 4 }).map((_, index) => (
                <div key={index} className="h-14 animate-pulse rounded-lg bg-slate-200" />
            ))}
        </div>
    );
}

function RankingList({ title, rows, onOpenProfile }) {
    if (!rows.length) {
        return (
            <div className="space-y-2">
                <p className="text-sm font-semibold text-slate-800">{title}</p>
                <EmptyState message="No profiles available for this slice yet." />
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <p className="text-sm font-semibold text-slate-800">{title}</p>
            <div className="space-y-2">
                {rows.map((profile) => (
                    <button
                        key={`${title}-${profile.post_id}`}
                        type="button"
                        disabled={!profile.crm_client_id}
                        onClick={() => profile.crm_client_id && onOpenProfile?.(profile.crm_client_id)}
                        className="flex w-full items-center justify-between gap-3 rounded-lg border border-slate-200 px-3.5 py-3 text-left transition hover:border-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:cursor-default disabled:hover:border-slate-200"
                    >
                        <div className="min-w-0">
                            <p className="truncate text-sm font-semibold text-slate-900">{profile.name}</p>
                            <p className="truncate text-xs text-slate-500">
                                {profile.subscription_tier || 'No plan'} • {profile.assigned_agent_name || 'Unassigned'}
                            </p>
                        </div>
                        <div className="shrink-0 text-right">
                            <p className="text-sm font-semibold text-slate-900">{formatPercent(profile.contact_rate_percent)}</p>
                            <p className="text-xs text-slate-500">{asNumber(profile.contact_actions_total).toLocaleString()} contacts</p>
                        </div>
                    </button>
                ))}
            </div>
        </div>
    );
}

export default function ProfileEngagementWidget({
    platformFilter,
    fromDate,
    toDate,
    onOpenProfile,
    onOpenReport,
}) {
    const enabled = Boolean(platformFilter);
    const windowLabel = describeWindow(fromDate, toDate);
    const sharedParams = useMemo(() => ({
        platform_id: Number(platformFilter),
        ...(fromDate ? { from: fromDate } : {}),
        ...(toDate ? { to: toDate } : {}),
    }), [fromDate, platformFilter, toDate]);

    const topQuery = useQuery({
        queryKey: ['dashboard-profile-engagement', 'top', sharedParams],
        queryFn: () => api.get('/crm/reports/profile-engagement', {
            params: {
                ...sharedParams,
                per_page: 5,
                sort_by: 'engagement_score',
                order: 'desc',
            },
        }).then((response) => response.data),
        enabled,
        staleTime: 300_000,
    });

    const bottomQuery = useQuery({
        queryKey: ['dashboard-profile-engagement', 'bottom', sharedParams],
        queryFn: () => api.get('/crm/reports/profile-engagement', {
            params: {
                ...sharedParams,
                per_page: 3,
                sort_by: 'engagement_score',
                order: 'asc',
            },
        }).then((response) => response.data),
        enabled,
        staleTime: 300_000,
    });

    const topRows = topQuery.data?.profiles || [];
    const topIds = new Set(topRows.map((profile) => profile.post_id));
    const bottomRows = (bottomQuery.data?.profiles || []).filter((profile) => !topIds.has(profile.post_id));
    const platformTotals = topQuery.data?.platform_totals || {};

    return (
        <SectionFrame
            title="Profile Engagement"
            subtitle={`Selected market performance across the ${windowLabel}. Deltas compare against the previous matching window.`}
            action={onOpenReport ? (
                <button
                    type="button"
                    onClick={onOpenReport}
                    disabled={!enabled}
                    className="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Open report
                </button>
            ) : null}
        >
            {!enabled ? (
                <EmptyState message="Select a market to load WordPress profile engagement analytics." />
            ) : topQuery.isLoading || bottomQuery.isLoading ? (
                <div className="space-y-4">
                    <div className="grid gap-2 sm:grid-cols-4">
                        {Array.from({ length: 4 }).map((_, index) => (
                            <div key={index} className="h-20 animate-pulse rounded-lg bg-slate-200" />
                        ))}
                    </div>
                    <LoadingList />
                </div>
            ) : topQuery.error || bottomQuery.error ? (
                <EmptyState message={topQuery.error?.response?.data?.message || bottomQuery.error?.response?.data?.message || 'Profile engagement analytics are currently unavailable.'} />
            ) : (
                <div className="space-y-4">
                    <div className="grid gap-2 sm:grid-cols-4">
                        <MiniStat
                            label="Views"
                            value={abbreviateNumber(platformTotals.profile_view?.total)}
                            delta={asNumber(platformTotals.profile_view?.delta_percent)}
                            deltaSuffix="%"
                        />
                        <MiniStat
                            label="Unique Visitors"
                            value={abbreviateNumber(platformTotals.unique_visitors?.total)}
                            delta={asNumber(platformTotals.unique_visitors?.delta_percent)}
                            deltaSuffix="%"
                        />
                        <MiniStat
                            label="Contacts"
                            value={abbreviateNumber(platformTotals.contact_actions?.total)}
                            delta={asNumber(platformTotals.contact_actions?.delta_percent)}
                            deltaSuffix="%"
                        />
                        <MiniStat
                            label="Contact Rate"
                            value={formatPercent(platformTotals.contact_rate_percent?.value)}
                            delta={asNumber(platformTotals.contact_rate_percent?.delta_pp)}
                            deltaSuffix="pp"
                        />
                    </div>

                    <div className="grid gap-4 xl:grid-cols-2">
                        <RankingList title="Top 5 performers" rows={topRows} onOpenProfile={onOpenProfile} />
                        <RankingList title="Bottom 3 to watch" rows={bottomRows} onOpenProfile={onOpenProfile} />
                    </div>
                </div>
            )}
        </SectionFrame>
    );
}
