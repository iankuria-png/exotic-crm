import React, { useMemo } from 'react';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

const PERIOD_OPTIONS = [
    { key: '7d', label: '7d' },
    { key: '30d', label: '30d' },
    { key: '90d', label: '90d' },
];

const CONTENT_EVENT_META = [
    { key: 'gallery_click', label: 'Gallery Clicks', accent: 'bg-indigo-500' },
    { key: 'video_play', label: 'Video Plays', accent: 'bg-fuchsia-500' },
    { key: 'service_click', label: 'Service Clicks', accent: 'bg-amber-500' },
    { key: 'rate_click', label: 'Rate Views', accent: 'bg-sky-500' },
];

const CONTACT_EVENT_META = [
    { key: 'whatsapp_click', label: 'WhatsApp', accent: 'bg-emerald-500', text: 'text-emerald-700' },
    { key: 'phone_click', label: 'Phone', accent: 'bg-cyan-500', text: 'text-cyan-700' },
    { key: 'viber_click', label: 'Viber', accent: 'bg-violet-500', text: 'text-violet-700' },
];

const PLACEMENT_LABELS = {
    hero: 'Hero Bar',
    sticky_bar: 'Sticky Bar',
    contact_card: 'Contact Card',
    mobile_cta: 'Mobile CTA',
    listing_card: 'Listing Card',
    gallery: 'Gallery',
    services: 'Services',
    rates: 'Rates',
};

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function roundPercent(value) {
    return Number.isFinite(value) ? Math.round(value * 10) / 10 : 0;
}

function formatPercent(value, digits = 1) {
    return `${asNumber(value).toFixed(digits)}%`;
}

function formatRatio(current, benchmark) {
    if (asNumber(benchmark) <= 0) {
        return current > 0 ? 'New activity' : 'No benchmark';
    }

    return `${(asNumber(current) / asNumber(benchmark)).toFixed(1)}x avg`;
}

function formatDuration(seconds) {
    const total = Math.max(0, Math.round(asNumber(seconds)));

    if (total < 60) {
        return `${total}s`;
    }

    const minutes = Math.floor(total / 60);
    const remainder = total % 60;

    if (minutes < 60) {
        return `${minutes}m ${remainder}s`;
    }

    const hours = Math.floor(minutes / 60);
    const restMinutes = minutes % 60;
    return `${hours}h ${restMinutes}m`;
}

function formatChartDate(value) {
    if (!value) {
        return '—';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function toSentenceCase(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (match) => match.toUpperCase()) || 'Unknown';
}

function classifyBenchmark(current, benchmark, percentile) {
    if (asNumber(percentile) >= 90) {
        return 'Top 10%';
    }

    if (asNumber(benchmark) <= 0) {
        return current > 0 ? 'Above avg' : 'Average';
    }

    const ratio = asNumber(current) / asNumber(benchmark);
    if (ratio >= 1.05) {
        return 'Above avg';
    }

    if (ratio <= 0.95) {
        return 'Below avg';
    }

    return 'Average';
}

function EmptyAnalyticsState({ title, message }) {
    return (
        <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
            <p className="text-sm font-semibold text-slate-700">{title}</p>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
        </div>
    );
}

function FunnelStage({ index, label, count, note, uniqueLabel }) {
    return (
        <article className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">
                {index}. {label}
            </p>
            <p className="mt-2 text-3xl font-semibold tracking-tight text-slate-950">{count.toLocaleString()}</p>
            <p className="mt-1 text-sm font-medium text-slate-700">{note}</p>
            <p className="mt-1 text-sm text-slate-500">{uniqueLabel}</p>
        </article>
    );
}

function FunnelArrow({ value, label }) {
    return (
        <div className="hidden xl:flex xl:min-w-[96px] xl:flex-col xl:items-center xl:justify-center">
            <div className="h-px w-full bg-slate-300" />
            <div className="mt-3 rounded-full border border-slate-200 bg-white px-3 py-1 text-center shadow-sm">
                <p className="text-sm font-semibold text-slate-900">{formatPercent(value)}</p>
                <p className="text-[11px] uppercase tracking-[0.12em] text-slate-500">{label}</p>
            </div>
        </div>
    );
}

function ComparisonMetricRow({ label, current, benchmark, currentLabel, benchmarkLabel, ratioLabel, status }) {
    const maxValue = Math.max(asNumber(current), asNumber(benchmark), 1);
    const currentWidth = `${Math.max(8, Math.round((asNumber(current) / maxValue) * 100))}%`;
    const benchmarkWidth = `${Math.max(8, Math.round((asNumber(benchmark) / maxValue) * 100))}%`;

    return (
        <div className="space-y-2">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-semibold text-slate-800">{label}</p>
                    <p className="text-xs text-slate-500">Market avg: {benchmarkLabel}</p>
                </div>
                <div className="text-right">
                    <p className="text-sm font-semibold text-slate-900">{currentLabel}</p>
                    <p className="text-xs text-slate-500">{ratioLabel}</p>
                </div>
            </div>
            <div className="space-y-1.5">
                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full rounded-full bg-teal-600" style={{ width: currentWidth }} />
                </div>
                <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full rounded-full bg-slate-400" style={{ width: benchmarkWidth }} />
                </div>
            </div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{status}</p>
        </div>
    );
}

function BreakdownRow({ label, total, percent, accentClass }) {
    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <span className={`h-2.5 w-2.5 rounded-full ${accentClass}`} aria-hidden="true" />
                    <p className="text-sm font-medium text-slate-700">{label}</p>
                </div>
                <div className="text-right">
                    <p className="text-sm font-semibold text-slate-900">{total.toLocaleString()}</p>
                    <p className="text-xs text-slate-500">{formatPercent(percent)}</p>
                </div>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                    className={`h-full rounded-full ${accentClass}`}
                    style={{ width: `${Math.max(0, Math.min(100, Math.round(percent)))}%` }}
                />
            </div>
        </div>
    );
}

export default function ClientAnalyticsTab({
    client,
    data,
    error,
    isLoading,
    analyticsPeriod,
    onPeriodChange,
}) {
    const totals = data?.totals || {};
    const impressions = asNumber(totals.card_impression?.total);
    const impressionUnique = asNumber(totals.card_impression?.unique);
    const views = asNumber(totals.profile_view?.total);
    const uniqueVisitors = asNumber(totals.profile_view?.unique);
    const contactActions = asNumber(data?.contact_actions_total);
    const contactRate = asNumber(data?.contact_rate_percent);
    const avgSessionDuration = asNumber(data?.avg_session_duration_sec);
    const longSessionPercent = asNumber(data?.sessions_over_120_percent);
    const marketAverages = data?.market_averages || {};
    const marketPercentiles = data?.market_percentiles || {};

    const contentRows = useMemo(() => (
        CONTENT_EVENT_META.map((meta) => ({
            ...meta,
            total: asNumber(totals?.[meta.key]?.total),
        }))
    ), [totals]);

    const contentActions = contentRows.reduce((sum, row) => sum + row.total, 0);
    const viewClickThrough = impressions > 0 ? roundPercent((views / impressions) * 100) : 0;
    const contentEngageRate = views > 0 ? roundPercent((contentActions / views) * 100) : 0;
    const contactFromContentRate = contentActions > 0 ? roundPercent((contactActions / contentActions) * 100) : 0;

    const dailyRows = useMemo(() => (
        Array.isArray(data?.daily)
            ? data.daily.map((row) => ({
                date: row.date,
                label: formatChartDate(row.date),
                views: asNumber(row.profile_view),
                uniqueVisitors: asNumber(row.profile_view_unique),
            }))
            : []
    ), [data?.daily]);

    const contactMix = useMemo(() => {
        const grandTotal = Math.max(1, contactActions);

        return CONTACT_EVENT_META.map((meta) => {
            const total = asNumber(totals?.[meta.key]?.total);
            return {
                ...meta,
                total,
                percent: total > 0 ? roundPercent((total / grandTotal) * 100) : 0,
                placements: data?.placement_breakdown?.[meta.key] || {},
            };
        }).filter((row) => row.total > 0);
    }, [contactActions, data?.placement_breakdown, totals]);

    const placementRows = useMemo(() => {
        const aggregated = {};

        contactMix.forEach((channel) => {
            Object.entries(channel.placements || {}).forEach(([placement, count]) => {
                if (!aggregated[placement]) {
                    aggregated[placement] = 0;
                }

                aggregated[placement] += asNumber(count);
            });
        });

        return Object.entries(aggregated)
            .map(([placement, total]) => ({
                key: placement,
                label: PLACEMENT_LABELS[placement] || toSentenceCase(placement),
                total,
                percent: contactActions > 0 ? roundPercent((total / contactActions) * 100) : 0,
            }))
            .sort((left, right) => right.total - left.total);
    }, [contactActions, contactMix]);

    const dominantContact = contactMix[0] || null;
    const topPlacement = placementRows[0] || null;
    const galleryClicks = asNumber(totals.gallery_click?.total);
    const galleryAverage = asNumber(marketAverages.gallery_click?.total);

    const comparisonRows = [
        {
            label: 'Views',
            current: views,
            benchmark: asNumber(marketAverages.profile_view?.total),
            currentLabel: views.toLocaleString(),
            benchmarkLabel: asNumber(marketAverages.profile_view?.total).toLocaleString(),
            ratioLabel: formatRatio(views, marketAverages.profile_view?.total),
            status: classifyBenchmark(views, marketAverages.profile_view?.total, marketPercentiles.profile_view),
        },
        {
            label: 'Contact Rate',
            current: contactRate,
            benchmark: asNumber(marketAverages.contact_rate_percent),
            currentLabel: formatPercent(contactRate),
            benchmarkLabel: formatPercent(marketAverages.contact_rate_percent),
            ratioLabel: formatRatio(contactRate, marketAverages.contact_rate_percent),
            status: classifyBenchmark(contactRate, marketAverages.contact_rate_percent, marketPercentiles.contact_rate_percent),
        },
        {
            label: 'Avg Duration',
            current: avgSessionDuration,
            benchmark: asNumber(marketAverages.avg_session_duration_sec),
            currentLabel: formatDuration(avgSessionDuration),
            benchmarkLabel: formatDuration(marketAverages.avg_session_duration_sec),
            ratioLabel: formatRatio(avgSessionDuration, marketAverages.avg_session_duration_sec),
            status: classifyBenchmark(avgSessionDuration, marketAverages.avg_session_duration_sec, marketPercentiles.avg_session_duration_sec),
        },
        {
            label: 'Gallery Hits',
            current: galleryClicks,
            benchmark: galleryAverage,
            currentLabel: galleryClicks.toLocaleString(),
            benchmarkLabel: galleryAverage.toLocaleString(),
            ratioLabel: formatRatio(galleryClicks, galleryAverage),
            status: classifyBenchmark(galleryClicks, galleryAverage, marketPercentiles.gallery_click),
        },
    ];

    const insightLines = useMemo(() => {
        const lines = [];
        const marketRate = asNumber(marketAverages.contact_rate_percent);

        if (marketRate > 0 && contactRate > 0) {
            lines.push(`This profile converts visitors to contacts at ${formatRatio(contactRate, marketRate)} the market average.`);
        }

        if (dominantContact) {
            lines.push(`${dominantContact.label} is the preferred contact method (${formatPercent(dominantContact.percent)} of all contact actions).`);
        }

        if (galleryClicks > 0 && galleryAverage > 0 && galleryClicks >= galleryAverage) {
            lines.push('Gallery engagement is strong - visitors are spending time browsing media.');
        } else if (longSessionPercent > 0) {
            lines.push(`${formatPercent(longSessionPercent)} of measured sessions lasted more than 2 minutes.`);
        }

        if (lines.length < 1) {
            lines.push('Analytics are available, but this profile needs more activity in the selected window before meaningful sales guidance appears.');
        }

        return lines.slice(0, 3);
    }, [contactRate, dominantContact, galleryAverage, galleryClicks, longSessionPercent, marketAverages.contact_rate_percent]);

    const totalTrackedActivity = impressions + views + contentActions + contactActions;

    if (!client?.wp_post_id) {
        return (
            <EmptyAnalyticsState
                title="Analytics are not available"
                message="The profile must be published on the website to collect engagement data."
            />
        );
    }

    if (isLoading) {
        return (
            <div className="space-y-4">
                <div className="flex flex-wrap gap-2">
                    {PERIOD_OPTIONS.map((option) => (
                        <span key={option.key} className="inline-block h-9 w-14 animate-pulse rounded-md bg-slate-200" />
                    ))}
                </div>
                <div className="h-56 animate-pulse rounded-xl bg-slate-200" />
                <div className="grid gap-4 xl:grid-cols-2">
                    <div className="h-80 animate-pulse rounded-xl bg-slate-200" />
                    <div className="h-80 animate-pulse rounded-xl bg-slate-200" />
                </div>
            </div>
        );
    }

    if (error) {
        return (
            <EmptyAnalyticsState
                title="Analytics are unavailable"
                message={error?.response?.data?.message || 'The analytics service could not load this profile right now.'}
            />
        );
    }

    if (totalTrackedActivity < 1) {
        return (
            <EmptyAnalyticsState
                title="Analytics are not available"
                message="The profile must be published on the website to collect engagement data."
            />
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <div>
                    <p className="text-sm font-semibold text-slate-800">Analytics</p>
                    <p className="text-sm text-slate-500">Every number needs context. Track discovery, engagement, and contact intent.</p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {PERIOD_OPTIONS.map((option) => (
                        <button
                            key={option.key}
                            type="button"
                            onClick={() => onPeriodChange?.(option.key)}
                            className={`rounded-md px-3 py-2 text-sm font-semibold transition ${
                                analyticsPeriod === option.key
                                    ? 'bg-slate-900 text-white'
                                    : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                            }`}
                        >
                            {option.label}
                        </button>
                    ))}
                </div>
            </div>

            <article className="crm-surface">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Engagement funnel</h3>
                        <p className="crm-panel-subtitle">How visitors discover and interact with this profile.</p>
                    </div>
                </header>
                <div className="space-y-4 p-4">
                    <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)_auto_minmax(0,1fr)]">
                        <FunnelStage
                            index={1}
                            label="Seen"
                            count={impressions}
                            note="Card Impressions"
                            uniqueLabel={`${impressionUnique.toLocaleString()} unique`}
                        />
                        <FunnelArrow value={viewClickThrough} label="click-thru" />
                        <FunnelStage
                            index={2}
                            label="Viewed"
                            count={views}
                            note="Profile Views"
                            uniqueLabel={`${uniqueVisitors.toLocaleString()} unique`}
                        />
                        <FunnelArrow value={contentEngageRate} label="engage rate" />
                        <FunnelStage
                            index={3}
                            label="Engaged"
                            count={contentActions}
                            note="Content Actions"
                            uniqueLabel="gal + vid + svc + rate"
                        />
                        <FunnelArrow value={contactFromContentRate} label="contact rate" />
                        <FunnelStage
                            index={4}
                            label="Contacted"
                            count={contactActions}
                            note="Contacts"
                            uniqueLabel="ph + wa + vb"
                        />
                    </div>

                    <p className="text-sm text-slate-500">
                        Contact = phone + WhatsApp + Viber. Content actions = gallery + video + service + rate totals.
                    </p>
                </div>
            </article>

            <div className="grid gap-4 xl:grid-cols-12">
                <article className="crm-surface xl:col-span-5">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Performance vs market</h3>
                            <p className="crm-panel-subtitle">Market average = all active profiles on this platform for the same period.</p>
                        </div>
                    </header>
                    <div className="space-y-4 p-4">
                        {comparisonRows.map((row) => (
                            <ComparisonMetricRow
                                key={row.label}
                                label={row.label}
                                current={row.current}
                                benchmark={row.benchmark}
                                currentLabel={row.currentLabel}
                                benchmarkLabel={row.benchmarkLabel}
                                ratioLabel={row.ratioLabel}
                                status={row.status}
                            />
                        ))}
                    </div>
                </article>

                <article className="crm-surface xl:col-span-7">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Daily views</h3>
                            <p className="crm-panel-subtitle">Views and unique visitors for the selected period.</p>
                        </div>
                    </header>
                    <div className="p-4">
                        {dailyRows.length > 0 ? (
                            <div className="h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={dailyRows} margin={{ top: 12, right: 12, bottom: 0, left: -18 }}>
                                        <defs>
                                            <linearGradient id="dailyViewsGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#0f766e" stopOpacity={0.24} />
                                                <stop offset="95%" stopColor="#0f766e" stopOpacity={0} />
                                            </linearGradient>
                                            <linearGradient id="dailyUniqueGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#2563eb" stopOpacity={0.22} />
                                                <stop offset="95%" stopColor="#2563eb" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="label" tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} />
                                        <YAxis tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} allowDecimals={false} />
                                        <Tooltip
                                            contentStyle={{ borderRadius: 12, borderColor: '#cbd5e1' }}
                                            formatter={(value, name) => [Number(value).toLocaleString(), name === 'views' ? 'Views' : 'Unique Visitors']}
                                            labelFormatter={(label, payload) => payload?.[0]?.payload?.date || label}
                                        />
                                        <Area type="monotone" dataKey="views" stroke="#0f766e" fill="url(#dailyViewsGradient)" strokeWidth={2.5} />
                                        <Area type="monotone" dataKey="uniqueVisitors" stroke="#2563eb" fill="url(#dailyUniqueGradient)" strokeWidth={2.5} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyAnalyticsState
                                title="No daily trend yet"
                                message="This profile has activity in the selected window, but not enough daily spread to chart yet."
                            />
                        )}
                    </div>
                </article>
            </div>

            <div className="grid gap-4 xl:grid-cols-2">
                <article className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Contact breakdown</h3>
                            <p className="crm-panel-subtitle">Which channel users prefer and where that click happened.</p>
                        </div>
                    </header>
                    <div className="space-y-4 p-4">
                        {contactMix.length > 0 ? (
                            <>
                                <div className="space-y-3">
                                    {contactMix.map((channel) => (
                                        <BreakdownRow
                                            key={channel.key}
                                            label={channel.label}
                                            total={channel.total}
                                            percent={channel.percent}
                                            accentClass={channel.accent}
                                        />
                                    ))}
                                </div>

                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                    <p className="text-sm font-semibold text-slate-800">
                                        Most used: {dominantContact ? `${dominantContact.label} (${formatPercent(dominantContact.percent)})` : '—'}
                                    </p>
                                    <div className="mt-3 space-y-2">
                                        {placementRows.map((row) => (
                                            <div key={row.key} className="flex items-center justify-between gap-3 text-sm">
                                                <p className="font-medium text-slate-700">{row.label}</p>
                                                <p className="font-semibold text-slate-900">{formatPercent(row.percent)}</p>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            </>
                        ) : (
                            <EmptyAnalyticsState
                                title="No contact actions yet"
                                message="Phone, WhatsApp, and Viber clicks will appear here once this profile starts receiving contact intent."
                            />
                        )}
                    </div>
                </article>

                <article className="crm-surface">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Content engagement</h3>
                            <p className="crm-panel-subtitle">What visitors interacted with before contacting this profile.</p>
                        </div>
                    </header>
                    <div className="space-y-4 p-4">
                        <div className="space-y-3">
                            {contentRows.map((row) => (
                                <BreakdownRow
                                    key={row.key}
                                    label={row.label}
                                    total={row.total}
                                    percent={contentActions > 0 ? roundPercent((row.total / contentActions) * 100) : 0}
                                    accentClass={row.accent}
                                />
                            ))}
                        </div>

                        <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 sm:grid-cols-2">
                            <p><span className="font-semibold">Avg Session:</span> {formatDuration(avgSessionDuration)}</p>
                            <p><span className="font-semibold">Sessions &gt; 2min:</span> {formatPercent(longSessionPercent)}</p>
                        </div>
                    </div>
                </article>
            </div>

            <article className="crm-surface">
                <header className="crm-panel-header">
                    <div>
                        <h3 className="crm-panel-title">Sales insight</h3>
                        <p className="crm-panel-subtitle">Auto-generated guidance from the profile’s engagement ratios.</p>
                    </div>
                </header>
                <div className="space-y-2 p-4 text-sm leading-6 text-slate-700">
                    {insightLines.map((line) => (
                        <p key={line}>{line}</p>
                    ))}
                    {topPlacement ? (
                        <p>The strongest contact surface right now is {topPlacement.label} at {formatPercent(topPlacement.percent)} of all contact actions.</p>
                    ) : null}
                </div>
            </article>
        </div>
    );
}
