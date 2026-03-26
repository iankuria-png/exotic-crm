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

const CONTENT_EVENT_KEYS = ['gallery_click', 'video_play', 'service_click', 'rate_click'];
const CONTACT_EVENT_META = [
    { key: 'whatsapp_click', label: 'WhatsApp', accent: 'bg-emerald-500', text: 'text-emerald-700' },
    { key: 'phone_click', label: 'Phone', accent: 'bg-cyan-500', text: 'text-cyan-700' },
    { key: 'viber_click', label: 'Viber', accent: 'bg-violet-500', text: 'text-violet-700' },
];

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

function toSentenceCase(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (match) => match.toUpperCase()) || 'Unknown';
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

function sumTotals(totals, keys) {
    return keys.reduce((sum, key) => sum + asNumber(totals?.[key]?.total), 0);
}

function StatCard({ label, value, meta }) {
    return (
        <article className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{value}</p>
            {meta ? <p className="mt-1 text-sm text-slate-500">{meta}</p> : null}
        </article>
    );
}

function ComparisonBar({ label, current, benchmark, currentLabel, benchmarkLabel }) {
    const maxValue = Math.max(asNumber(current), asNumber(benchmark), 1);
    const currentWidth = `${Math.max(6, Math.round((asNumber(current) / maxValue) * 100))}%`;
    const benchmarkWidth = `${Math.max(6, Math.round((asNumber(benchmark) / maxValue) * 100))}%`;

    return (
        <div className="space-y-2">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm font-medium text-slate-700">{label}</p>
                <div className="text-right text-xs text-slate-500">
                    <p className="font-semibold text-slate-800">{currentLabel}</p>
                    <p>Market: {benchmarkLabel}</p>
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
        </div>
    );
}

function EmptyAnalyticsState({ title, message }) {
    return (
        <div className="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
            <p className="text-sm font-semibold text-slate-700">{title}</p>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
        </div>
    );
}

export default function ClientAnalyticsTab({
    client,
    data,
    isLoading,
    analyticsPeriod,
    onPeriodChange,
}) {
    const totals = data?.totals || {};
    const views = asNumber(totals.profile_view?.total);
    const uniqueVisitors = asNumber(totals.profile_view?.unique);
    const contentActions = sumTotals(totals, CONTENT_EVENT_KEYS);
    const contactActions = asNumber(data?.contact_actions_total);
    const contactRate = asNumber(data?.contact_rate_percent);
    const avgSessionDuration = asNumber(data?.avg_session_duration_sec);

    const marketAverages = data?.market_averages || {};
    const chartRows = useMemo(() => (
        Array.isArray(data?.daily)
            ? data.daily.map((row) => ({
                date: row.date,
                label: formatChartDate(row.date),
                views: asNumber(row.profile_view),
                contacts: CONTACT_EVENT_META.reduce((sum, meta) => sum + asNumber(row[meta.key]), 0),
            }))
            : []
    ), [data?.daily]);

    const contactMix = useMemo(() => {
        const grandTotal = Math.max(1, contactActions);

        return CONTACT_EVENT_META
            .map((meta) => {
                const total = asNumber(totals?.[meta.key]?.total);
                return {
                    ...meta,
                    total,
                    share: total > 0 ? roundPercent((total / grandTotal) * 100) : 0,
                    placement: data?.placement_breakdown?.[meta.key] || {},
                };
            })
            .filter((row) => row.total > 0);
    }, [contactActions, data?.placement_breakdown, totals]);

    const placementRows = useMemo(() => (
        contactMix.flatMap((channel) => (
            Object.entries(channel.placement || {}).map(([placement, count]) => ({
                id: `${channel.key}-${placement}`,
                channel: channel.label,
                placement: toSentenceCase(placement),
                count: asNumber(count),
            }))
        )).sort((left, right) => right.count - left.count)
    ), [contactMix]);

    const dominantContact = contactMix[0] || null;
    const topPlacement = placementRows[0] || null;
    const viewToContentRate = views > 0 ? roundPercent((contentActions / views) * 100) : 0;
    const contentToContactRate = contentActions > 0 ? roundPercent((contactActions / contentActions) * 100) : 0;

    const marketComparisons = [
        {
            label: 'Views',
            current: views,
            benchmark: asNumber(marketAverages.profile_view?.total),
            currentLabel: views.toLocaleString(),
            benchmarkLabel: asNumber(marketAverages.profile_view?.total).toLocaleString(),
        },
        {
            label: 'Contacts',
            current: contactActions,
            benchmark: asNumber(marketAverages.contact_actions_total),
            currentLabel: contactActions.toLocaleString(),
            benchmarkLabel: asNumber(marketAverages.contact_actions_total).toLocaleString(),
        },
        {
            label: 'Contact rate',
            current: contactRate,
            benchmark: asNumber(marketAverages.contact_rate_percent),
            currentLabel: formatPercent(contactRate),
            benchmarkLabel: formatPercent(marketAverages.contact_rate_percent),
        },
    ];

    const marketRate = asNumber(marketAverages.contact_rate_percent);
    const rateRatio = marketRate > 0 ? contactRate / marketRate : 0;
    const insight = dominantContact
        ? `${dominantContact.label} drives ${dominantContact.share}% of contact actions. `
            + `${contactRate >= marketRate ? 'This profile is outperforming' : 'This profile is trailing'} `
            + `the market rate ${marketRate > 0 ? `at ${rateRatio.toFixed(1)}x` : 'with limited benchmark data'}.`
        : 'Engagement data will become more useful once contact actions begin flowing from the live profile.';

    if (!client?.wp_post_id) {
        return (
            <EmptyAnalyticsState
                title="No WordPress profile linked"
                message="This client does not have a WordPress profile ID yet, so profile engagement analytics are unavailable."
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
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    {Array.from({ length: 4 }).map((_, index) => (
                        <div key={index} className="h-28 animate-pulse rounded-xl bg-slate-200" />
                    ))}
                </div>
                <div className="grid gap-4 xl:grid-cols-12">
                    <div className="xl:col-span-8 h-80 animate-pulse rounded-xl bg-slate-200" />
                    <div className="xl:col-span-4 h-80 animate-pulse rounded-xl bg-slate-200" />
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                <div>
                    <p className="text-sm font-semibold text-slate-800">Profile engagement window</p>
                    <p className="text-sm text-slate-500">Compare views, content actions, and contact intent for this profile.</p>
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

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <StatCard label="Total Views" value={views.toLocaleString()} meta={`${uniqueVisitors.toLocaleString()} unique visitors`} />
                <StatCard label="Content Actions" value={contentActions.toLocaleString()} meta={`${formatPercent(viewToContentRate)} of views engaged with content`} />
                <StatCard label="Contact Actions" value={contactActions.toLocaleString()} meta={`${formatPercent(contentToContactRate)} of engaged actions became contacts`} />
                <StatCard label="Contact Rate" value={formatPercent(contactRate)} meta={`Avg session ${formatDuration(avgSessionDuration)}`} />
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                <article className="crm-surface xl:col-span-8">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Engagement over time</h3>
                            <p className="crm-panel-subtitle">Daily views and contact actions for the selected period.</p>
                        </div>
                    </header>
                    <div className="p-4">
                        {chartRows.length > 0 ? (
                            <div className="h-72">
                                <ResponsiveContainer width="100%" height="100%">
                                    <AreaChart data={chartRows} margin={{ top: 12, right: 12, bottom: 0, left: -18 }}>
                                        <defs>
                                            <linearGradient id="viewsGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#0f766e" stopOpacity={0.25} />
                                                <stop offset="95%" stopColor="#0f766e" stopOpacity={0} />
                                            </linearGradient>
                                            <linearGradient id="contactsGradient" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor="#16a34a" stopOpacity={0.22} />
                                                <stop offset="95%" stopColor="#16a34a" stopOpacity={0} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                                        <XAxis dataKey="label" tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} />
                                        <YAxis tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} allowDecimals={false} />
                                        <Tooltip
                                            contentStyle={{ borderRadius: 12, borderColor: '#cbd5e1' }}
                                            formatter={(value, name) => [Number(value).toLocaleString(), name === 'views' ? 'Views' : 'Contacts']}
                                            labelFormatter={(label, payload) => payload?.[0]?.payload?.date || label}
                                        />
                                        <Area type="monotone" dataKey="views" stroke="#0f766e" fill="url(#viewsGradient)" strokeWidth={2.5} />
                                        <Area type="monotone" dataKey="contacts" stroke="#16a34a" fill="url(#contactsGradient)" strokeWidth={2.5} />
                                    </AreaChart>
                                </ResponsiveContainer>
                            </div>
                        ) : (
                            <EmptyAnalyticsState
                                title="No tracked activity yet"
                                message="The tracking pipeline is live, but this profile has not generated enough activity in the selected window to chart yet."
                            />
                        )}
                    </div>
                </article>

                <article className="crm-surface xl:col-span-4">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Performance vs market</h3>
                            <p className="crm-panel-subtitle">How this profile stacks up against the current market average.</p>
                        </div>
                    </header>
                    <div className="space-y-4 p-4">
                        {marketComparisons.map((row) => (
                            <ComparisonBar
                                key={row.label}
                                label={row.label}
                                current={row.current}
                                benchmark={row.benchmark}
                                currentLabel={row.currentLabel}
                                benchmarkLabel={row.benchmarkLabel}
                            />
                        ))}

                        <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Sales insight</p>
                            <p className="mt-2 text-sm leading-6 text-slate-700">{insight}</p>
                        </div>
                    </div>
                </article>
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                <article className="crm-surface xl:col-span-7">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Funnel snapshot</h3>
                            <p className="crm-panel-subtitle">Viewers who explored content and then reached out.</p>
                        </div>
                    </header>
                    <div className="grid gap-3 p-4 md:grid-cols-3">
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-4">
                            <p className="text-sm font-semibold text-slate-800">1. Viewers</p>
                            <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{views.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-500">{uniqueVisitors.toLocaleString()} unique visitors</p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-4">
                            <p className="text-sm font-semibold text-slate-800">2. Content actions</p>
                            <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{contentActions.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-500">{formatPercent(viewToContentRate)} from profile views</p>
                        </div>
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-4">
                            <p className="text-sm font-semibold text-slate-800">3. Contact actions</p>
                            <p className="mt-2 text-2xl font-semibold tracking-tight text-slate-950">{contactActions.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-500">{formatPercent(contentToContactRate)} from content engagement</p>
                        </div>
                    </div>
                </article>

                <article className="crm-surface xl:col-span-5">
                    <header className="crm-panel-header">
                        <div>
                            <h3 className="crm-panel-title">Contact mix</h3>
                            <p className="crm-panel-subtitle">Channel preference plus the placements driving contact intent.</p>
                        </div>
                    </header>
                    <div className="space-y-4 p-4">
                        {contactMix.length > 0 ? (
                            <>
                                <div className="space-y-3">
                                    {contactMix.map((channel) => (
                                        <div key={channel.key} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <div className="flex items-center gap-2">
                                                    <span className={`h-2.5 w-2.5 rounded-full ${channel.accent}`} aria-hidden="true" />
                                                    <p className="text-sm font-semibold text-slate-800">{channel.label}</p>
                                                </div>
                                                <div className="text-right">
                                                    <p className="text-base font-semibold text-slate-950">{channel.total.toLocaleString()}</p>
                                                    <p className={`text-xs font-medium ${channel.text}`}>{channel.share}% share</p>
                                                </div>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Best-performing placement</p>
                                    <p className="mt-2 text-sm text-slate-700">
                                        {topPlacement
                                            ? `${topPlacement.channel} performs best from ${topPlacement.placement} (${topPlacement.count.toLocaleString()} actions).`
                                            : 'Placement detail will appear after contact events fire from multiple surfaces.'}
                                    </p>
                                </div>

                                {placementRows.length > 0 ? (
                                    <div className="space-y-2">
                                        {placementRows.slice(0, 6).map((row) => (
                                            <div key={row.id} className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-800">{row.placement}</p>
                                                    <p className="text-xs text-slate-500">{row.channel}</p>
                                                </div>
                                                <span className="text-sm font-semibold text-slate-900">{row.count.toLocaleString()}</span>
                                            </div>
                                        ))}
                                    </div>
                                ) : null}
                            </>
                        ) : (
                            <EmptyAnalyticsState
                                title="No contact actions yet"
                                message="Phone, WhatsApp, and Viber clicks will appear here once this profile starts receiving contact intent."
                            />
                        )}
                    </div>
                </article>
            </section>
        </div>
    );
}
