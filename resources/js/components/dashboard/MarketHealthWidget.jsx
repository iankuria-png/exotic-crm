import React, { useMemo, useState } from 'react';
import SectionFrame from '../SectionFrame';
import StatusBadge from '../StatusBadge';
import { marketLabel, relativeTime } from './ceoFormatters';

const INITIAL_VISIBLE_MARKETS = 6;

const STATUS_META = {
    healthy: {
        label: 'Healthy',
        dot: 'bg-emerald-500',
        border: 'border-emerald-200',
        surface: 'bg-emerald-50/45',
        text: 'text-emerald-700',
        priority: 4,
    },
    domain_unreachable: {
        label: 'Domain down',
        dot: 'bg-rose-500',
        border: 'border-rose-200',
        surface: 'bg-rose-50/65',
        text: 'text-rose-700',
        priority: 1,
    },
    server_error: {
        label: 'Server down',
        dot: 'bg-rose-500',
        border: 'border-rose-200',
        surface: 'bg-rose-50/65',
        text: 'text-rose-700',
        priority: 2,
    },
    auth_error: {
        label: 'Credentials',
        dot: 'bg-rose-500',
        border: 'border-rose-200',
        surface: 'bg-rose-50/65',
        text: 'text-rose-700',
        priority: 3,
    },
    wp_error: {
        label: 'WP error',
        dot: 'bg-amber-500',
        border: 'border-amber-200',
        surface: 'bg-amber-50/65',
        text: 'text-amber-700',
        priority: 3,
    },
    unconfigured: {
        label: 'Not configured',
        dot: 'bg-slate-400',
        border: 'border-slate-200',
        surface: 'bg-slate-50',
        text: 'text-slate-500',
        priority: 5,
    },
};

function statusMeta(status) {
    return STATUS_META[status] || STATUS_META.unconfigured;
}

function formatNumber(value) {
    return Number(value || 0).toLocaleString();
}

function summaryText(summary) {
    if (!summary?.total) return 'No markets configured';
    const healthy = Number(summary.healthy || 0);
    const total = Number(summary.total || 0);
    const down = Number(summary.down || 0);
    const unconfigured = Number(summary.unconfigured || 0);

    return `${healthy}/${total} healthy${down ? ` · ${down} down` : ''}${unconfigured ? ` · ${unconfigured} not configured` : ''}`;
}

function sortMarkets(markets) {
    return [...markets].sort((a, b) => {
        const aMeta = statusMeta(a.health_status);
        const bMeta = statusMeta(b.health_status);
        if (aMeta.priority !== bMeta.priority) return aMeta.priority - bMeta.priority;
        return String(a.name || '').localeCompare(String(b.name || ''));
    });
}

export default function MarketHealthWidget({
    data,
    isLoading = false,
    errorMessage = null,
    onSync,
    onCheckNow,
    syncingId = null,
    checkingId = null,
}) {
    const [expanded, setExpanded] = useState(false);
    const markets = useMemo(() => (
        Array.isArray(data?.markets) ? sortMarkets(data.markets) : []
    ), [data?.markets]);
    const summary = data?.summary || {};
    const hasOverflow = markets.length > INITIAL_VISIBLE_MARKETS;
    const visibleMarkets = expanded ? markets : markets.slice(0, INITIAL_VISIBLE_MARKETS);
    const hiddenCount = Math.max(0, markets.length - visibleMarkets.length);
    const healthActionLabel = Number(summary.down || 0) > 0
        ? `${formatNumber(summary.down)} market${Number(summary.down || 0) === 1 ? '' : 's'} need attention`
        : 'All monitored markets clear';

    return (
        <SectionFrame
            title="Market Health"
            subtitle={`${summaryText(summary)} · probes every 5 minutes`}
            contentClassName="p-0"
            action={data?.sync_queue_available === false ? (
                <span className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-800">
                    Sync queue offline
                </span>
            ) : markets.length > 0 ? (
                <span className={`rounded-md px-2.5 py-1 text-xs font-medium ring-1 ring-inset ${
                    Number(summary.down || 0) > 0
                        ? 'bg-rose-50 text-rose-700 ring-rose-200'
                        : 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                }`}
                >
                    {healthActionLabel}
                </span>
            ) : null}
            footer={!isLoading && hasOverflow ? (
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs font-medium text-slate-500">
                        {expanded
                            ? `Showing all ${formatNumber(markets.length)} markets`
                            : `Showing first ${INITIAL_VISIBLE_MARKETS} by health priority`}
                    </p>
                    <button
                        type="button"
                        onClick={() => setExpanded((current) => !current)}
                        className="inline-flex min-h-9 items-center justify-center rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:border-teal-300 hover:text-teal-700"
                        aria-expanded={expanded}
                    >
                        {expanded ? 'Show first 6' : `Show ${formatNumber(hiddenCount)} more`}
                    </button>
                </div>
            ) : null}
        >
            {errorMessage ? (
                <div className="border-b border-rose-100 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    {errorMessage}
                </div>
            ) : null}

            <div className="grid border-b border-slate-100 bg-slate-50/70 sm:grid-cols-4">
                {[
                    ['Healthy', summary.healthy, 'text-emerald-700'],
                    ['Down', summary.down, 'text-rose-700'],
                    ['Not configured', summary.unconfigured, 'text-slate-600'],
                    ['Markets', summary.total, 'text-slate-900'],
                ].map(([label, value, tone]) => (
                    <div key={label} className="border-b border-slate-100 px-4 py-3 sm:border-b-0 sm:border-r last:sm:border-r-0">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                        <p className={`mt-1 text-xl font-semibold tracking-tight ${tone}`}>{formatNumber(value)}</p>
                    </div>
                ))}
            </div>

            {isLoading ? (
                <div className="divide-y divide-slate-100">
                    {[0, 1, 2].map((item) => (
                        <div key={item} className="px-4 py-4">
                            <div className="h-4 w-40 animate-pulse rounded bg-slate-100" />
                            <div className="mt-3 h-3 w-full max-w-xl animate-pulse rounded bg-slate-100" />
                        </div>
                    ))}
                </div>
            ) : markets.length === 0 ? (
                <div className="px-4 py-8 text-center">
                    <p className="text-sm font-medium text-slate-700">No markets to monitor</p>
                    <p className="mt-1 text-xs text-slate-500">Active markets will appear here after they are configured.</p>
                </div>
            ) : (
                <div className="divide-y divide-slate-100">
                    {visibleMarkets.map((market) => {
                        const meta = statusMeta(market.health_status);
                        const checking = Number(checkingId) === Number(market.id);
                        const syncing = Number(syncingId) === Number(market.id);
                        const canSync = Boolean(market.credentials_ready && market.sync_queue_available);
                        const canCheck = Boolean(market.credentials_ready);

                        return (
                            <article
                                key={market.id}
                                className={`px-4 py-3.5 transition-colors hover:bg-slate-50/70 ${market.is_down ? meta.surface : 'bg-white'}`}
                            >
                                <div className="grid gap-4 xl:grid-cols-[minmax(0,1.2fr)_minmax(260px,0.8fr)_auto] xl:items-center">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={`h-2.5 w-2.5 shrink-0 rounded-full ${meta.dot}`} aria-hidden="true" />
                                            <h4 className="truncate text-sm font-semibold text-slate-900">
                                                {marketLabel({ name: market.name, country: market.country })}
                                            </h4>
                                            <StatusBadge status={market.health_status} label={meta.label} />
                                        </div>
                                        <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                                            {market.url ? (
                                                <a
                                                    href={market.url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="max-w-[260px] truncate font-medium text-slate-600 underline-offset-2 hover:text-teal-700 hover:underline"
                                                >
                                                    {market.url.replace(/^https?:\/\//, '')}
                                                </a>
                                            ) : (
                                                <span>No public URL</span>
                                            )}
                                            <span>Checked {relativeTime(market.health_checked_at)}</span>
                                            {market.health_latency_ms !== null ? <span>{market.health_latency_ms} ms</span> : null}
                                        </div>
                                        {market.health_error && market.is_down ? (
                                            <p className={`mt-2 line-clamp-2 text-xs font-medium ${meta.text}`}>
                                                {market.health_error}
                                            </p>
                                        ) : null}
                                    </div>

                                    <div className="grid grid-cols-2 gap-3 text-sm sm:max-w-md">
                                        <div>
                                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Profiles</p>
                                            <p className="mt-1 font-semibold text-slate-900">{formatNumber(market.profiles_total)}</p>
                                        </div>
                                        <div>
                                            <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-400">Last sync</p>
                                            <p className="mt-1 font-semibold text-slate-900">{relativeTime(market.sync_last_synced_at)}</p>
                                        </div>
                                    </div>

                                    <div className="flex flex-wrap items-center gap-2 xl:justify-end">
                                        <button
                                            type="button"
                                            onClick={() => onCheckNow?.(market.id)}
                                            disabled={!canCheck || checking}
                                            className="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-700 transition hover:border-teal-300 hover:text-teal-700 disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {checking ? 'Checking...' : 'Check now'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => onSync?.(market.id)}
                                            disabled={!canSync || syncing}
                                            className="rounded-md bg-slate-900 px-3 py-2 text-xs font-semibold text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:bg-slate-300"
                                        >
                                            {syncing ? 'Syncing...' : 'Sync'}
                                        </button>
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </SectionFrame>
    );
}
