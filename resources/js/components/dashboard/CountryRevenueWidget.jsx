import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import {
    Area,
    AreaChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import api from '../../services/api';
import SectionFrame from '../SectionFrame';
import { getCountryFlag } from '../../utils/flags';
import { formatCurrency } from '../../utils/currency';
import CurrencyAmount from '../CurrencyAmount';
import FxNormalizationNotice from '../FxNormalizationNotice';

function toneClasses(tone) {
    if (tone === 'positive') {
        return {
            dot: 'bg-emerald-500',
            text: 'text-emerald-700',
        };
    }

    if (tone === 'negative') {
        return {
            dot: 'bg-rose-500',
            text: 'text-rose-700',
        };
    }

    return {
        dot: 'bg-slate-400',
        text: 'text-slate-500',
    };
}

function formatPercent(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) {
        return '—';
    }

    if (numeric === 0) {
        return '0%';
    }

    return `${numeric > 0 ? '+' : ''}${numeric.toFixed(1)}%`;
}

function formatDateLabel(value) {
    if (!value) {
        return '--';
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-KE', {
        day: 'numeric',
        month: 'short',
    });
}

function describeWindow(rangeMode, fromDate, toDate) {
    if (rangeMode === 'week') {
        return 'Revenue by market in the selected 7-day window';
    }

    if (rangeMode === 'month') {
        return 'Revenue by market in the selected 30-day window';
    }

    if (!fromDate || !toDate) {
        return 'Revenue by market in the selected custom window';
    }

    return `Revenue by market from ${formatDateLabel(fromDate)} to ${formatDateLabel(toDate)}`;
}

function describeTrendState(trend, hasRevenue) {
    if (!hasRevenue) {
        return {
            tone: 'default',
            label: 'No revenue in this window',
        };
    }

    if (trend === null || trend === undefined) {
        return {
            tone: 'default',
            label: 'No prior comparison yet',
        };
    }

    if (trend === 0) {
        return {
            tone: 'default',
            label: 'Holding flat vs previous window',
        };
    }

    return {
        tone: trend > 0 ? 'positive' : 'negative',
        label: `${formatPercent(trend)} vs previous window`,
    };
}

function PeriodToggle({ rangeMode, onSetWeek, onSetMonth, onSetCustom }) {
    return (
        <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
            {['week', 'month', 'custom'].map((option) => (
                <button
                    key={option}
                    type="button"
                    onClick={option === 'week' ? onSetWeek : option === 'month' ? onSetMonth : onSetCustom}
                    className={`rounded-md px-3 py-1 text-xs font-semibold capitalize transition ${
                        rangeMode === option
                            ? 'bg-white text-slate-900 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700'
                    }`}
                    title={option === 'custom' ? 'Use the dashboard date fields for a custom range.' : undefined}
                >
                    {option}
                </button>
            ))}
        </div>
    );
}

function MoneyStack({
    breakdown,
    scalarAmount,
    fallbackCurrency,
    normalizedTotal,
    normalizedDisplay,
    normalizationMeta,
    currencyMode,
    targetCurrency,
    emphasisClass = 'crm-mono text-sm font-semibold text-slate-900',
    secondaryClass = 'text-xs font-medium text-slate-500',
    showFxNotice = true,
}) {
    if (currencyMode === 'flat' && normalizedTotal !== null && normalizedTotal !== undefined) {
        return (
            <>
                <p className={emphasisClass}>
                    {normalizedDisplay || formatCurrency(normalizedTotal, targetCurrency)}
                </p>
                <CurrencyAmount
                    breakdown={breakdown}
                    scalarAmount={scalarAmount}
                    fallbackCurrency={fallbackCurrency}
                    className={secondaryClass}
                    stackClassName={secondaryClass}
                />
                {showFxNotice ? <FxNormalizationNotice meta={normalizationMeta} className="mt-1" /> : null}
            </>
        );
    }

    return (
        <>
            <CurrencyAmount
                breakdown={breakdown}
                scalarAmount={scalarAmount}
                fallbackCurrency={fallbackCurrency}
                className={emphasisClass}
                stackClassName={emphasisClass}
            />
            {currencyMode === 'flat' && showFxNotice ? <FxNormalizationNotice meta={normalizationMeta} className="mt-1" /> : null}
        </>
    );
}

function InsightChip({ label, value, tone = 'default' }) {
    const toneClass = toneClasses(tone);

    return (
        <div className="rounded-xl border border-slate-200 bg-white px-3 py-2.5">
            <div className="flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${toneClass.dot}`} aria-hidden="true" />
                <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            </div>
            <p className={`mt-1.5 text-sm font-semibold leading-5 ${toneClass.text}`}>{value}</p>
        </div>
    );
}

function StatusLine({ tone, label, align = 'left' }) {
    const classes = toneClasses(tone);

    return (
        <div className={`inline-flex items-center gap-2 text-[11px] font-medium ${classes.text} ${align === 'right' ? 'justify-end' : ''}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${classes.dot}`} aria-hidden="true" />
            <span>{label}</span>
        </div>
    );
}

function CollapsedRevenueValue({ market, currencyMode, targetCurrency }) {
    if (currencyMode === 'flat' && market.current_revenue_normalized !== null && market.current_revenue_normalized !== undefined) {
        return (
            <span className="crm-mono text-base font-semibold text-slate-900">
                {market.current_revenue_normalized_display || formatCurrency(market.current_revenue_normalized, targetCurrency)}
            </span>
        );
    }

    return (
        <CurrencyAmount
            breakdown={market.current_revenue_breakdown}
            scalarAmount={market.current_revenue}
            fallbackCurrency={market.currency}
            className="crm-mono text-base font-semibold text-slate-900"
            stackClassName="crm-mono text-base font-semibold text-slate-900"
        />
    );
}

function CollapsedRevenueMeta({ market, currencyMode }) {
    return (
        <div className="mt-1 flex flex-wrap items-center justify-end gap-x-2 gap-y-1 text-[11px] text-slate-500">
            <CurrencyAmount
                breakdown={market.current_revenue_breakdown}
                scalarAmount={market.current_revenue}
                fallbackCurrency={market.currency}
                className="font-medium text-slate-500"
                stackClassName="font-medium text-slate-500"
            />
            {currencyMode === 'flat' ? (
                <span
                    className="inline-flex items-center gap-1 rounded-full border border-slate-200 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-400"
                    title="Revenue is normalized to the reporting currency using available FX rates."
                >
                    <span className="h-1.5 w-1.5 rounded-full bg-slate-300" aria-hidden="true" />
                    FX
                </span>
            ) : null}
        </div>
    );
}

function renderInsightMoney(point, currencyMode, targetCurrency, fallbackCurrency) {
    if (!point) {
        return '—';
    }

    if (currencyMode === 'flat' && point.normalized_total !== null && point.normalized_total !== undefined) {
        return point.normalized_display || formatCurrency(point.normalized_total, targetCurrency);
    }

    if (point.revenue !== null && point.revenue !== undefined) {
        return formatCurrency(point.revenue, fallbackCurrency);
    }

    const breakdown = point.revenue_breakdown || {};
    const firstEntry = Object.entries(breakdown)[0];

    return firstEntry ? formatCurrency(firstEntry[1], firstEntry[0]) : '—';
}

function DetailPanel({ detail, detailQuery, currencyMode, targetCurrency, fallbackCurrency }) {
    if (detailQuery.isLoading) {
        return (
            <div className="grid gap-4 rounded-xl border border-slate-200 bg-slate-50/70 p-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.95fr)]">
                <div className="h-72 animate-pulse rounded-xl bg-white" />
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, index) => (
                        <div key={index} className="h-16 animate-pulse rounded-lg bg-white" />
                    ))}
                </div>
            </div>
        );
    }

    if (detailQuery.error) {
        return (
            <div className="rounded-xl border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">
                {detailQuery.error?.response?.data?.message || 'Country performance detail is currently unavailable.'}
            </div>
        );
    }

    if (!detail) {
        return null;
    }

    const chartRows = Array.isArray(detail?.trend?.points) ? detail.trend.points : [];
    const strongest = detail?.insights?.strongest_period || null;
    const weakest = detail?.insights?.weakest_period || null;
    const engagement = detail?.user_summary?.engagement || {};
    const contactMix = Array.isArray(detail?.contact_mix) ? detail.contact_mix : [];
    const momentumTone = detail?.insights?.momentum?.direction === 'up' ? 'positive' : detail?.insights?.momentum?.direction === 'down' ? 'negative' : 'default';
    const recentTone = detail?.insights?.recent_movement?.direction === 'up' ? 'positive' : detail?.insights?.recent_movement?.direction === 'down' ? 'negative' : 'default';
    const engagementTone = engagement.health === 'above_market' ? 'positive' : engagement.health === 'below_market' ? 'negative' : 'default';

    return (
        <div className="grid gap-4 rounded-xl border border-slate-200 bg-[linear-gradient(180deg,#ffffff_0%,#f8fafc_100%)] p-4 xl:grid-cols-12">
            <div className="space-y-4 xl:col-span-7">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Revenue Trend</p>
                    <p className="mt-1 text-sm text-slate-500">See when this market accelerated, softened, or went quiet in the current window.</p>
                </div>
                {chartRows.length > 0 ? (
                    <div className="h-72 rounded-xl border border-slate-200 bg-white px-3 py-4">
                        <ResponsiveContainer width="100%" height="100%">
                            <AreaChart data={chartRows} margin={{ top: 12, right: 10, bottom: 0, left: -18 }}>
                                <defs>
                                    <linearGradient id="countryRevenueGradient" x1="0" y1="0" x2="0" y2="1">
                                        <stop offset="5%" stopColor="#0f766e" stopOpacity={0.22} />
                                        <stop offset="95%" stopColor="#0f766e" stopOpacity={0} />
                                    </linearGradient>
                                </defs>
                                <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                                <XAxis dataKey="label" tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} />
                                <YAxis tick={{ fill: '#64748b', fontSize: 12 }} tickLine={false} axisLine={false} />
                                <Tooltip
                                    contentStyle={{ borderRadius: 12, borderColor: '#cbd5e1' }}
                                    formatter={(value) => [Number(value || 0).toLocaleString(), currencyMode === 'flat' ? targetCurrency : 'Revenue']}
                                    labelFormatter={(label, payload) => payload?.[0]?.payload?.bucket_start || label}
                                />
                                <Area
                                    type="monotone"
                                    dataKey={currencyMode === 'flat' ? 'normalized_total' : 'chart_value'}
                                    stroke="#0f766e"
                                    fill="url(#countryRevenueGradient)"
                                    strokeWidth={2.5}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                ) : (
                    <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                        Revenue points will appear here once payments exist inside the selected window.
                    </div>
                )}
            </div>

            <div className="space-y-4 xl:col-span-5">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Current Revenue</p>
                        <div className="mt-2">
                            <MoneyStack
                                breakdown={detail.summary.current_revenue_breakdown}
                                scalarAmount={detail.summary.current_revenue}
                                fallbackCurrency={fallbackCurrency}
                                normalizedTotal={detail.summary.current_revenue_normalized}
                                normalizedDisplay={detail.summary.current_revenue_normalized_display}
                                normalizationMeta={detail.summary.current_revenue_normalization_meta}
                                currencyMode={currencyMode}
                                targetCurrency={targetCurrency}
                                emphasisClass="crm-mono text-base font-semibold text-slate-900"
                                showFxNotice={false}
                            />
                        </div>
                    </div>
                    <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                        <p className="text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500">Previous Window</p>
                        <div className="mt-2">
                            <MoneyStack
                                breakdown={detail.summary.previous_revenue_breakdown}
                                scalarAmount={detail.summary.previous_revenue}
                                fallbackCurrency={fallbackCurrency}
                                normalizedTotal={detail.summary.previous_revenue_normalized}
                                normalizedDisplay={null}
                                normalizationMeta={detail.summary.previous_revenue_normalization_meta}
                                currencyMode={currencyMode}
                                targetCurrency={targetCurrency}
                                emphasisClass="crm-mono text-base font-semibold text-slate-900"
                                showFxNotice={false}
                            />
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <InsightChip
                        label="Revenue Momentum"
                        value={detail?.insights?.momentum?.label || 'No recent movement yet'}
                        tone={momentumTone}
                    />
                    <InsightChip
                        label="Recent Move"
                        value={detail?.insights?.recent_movement?.delta_percent === null || detail?.insights?.recent_movement?.delta_percent === undefined
                            ? 'No prior comparison'
                            : `${formatPercent(detail.insights.recent_movement.delta_percent)} vs prior`}
                        tone={recentTone}
                    />
                    <InsightChip
                        label="Strongest Period"
                        value={strongest ? `${strongest.label} · ${renderInsightMoney(strongest, currencyMode, targetCurrency, fallbackCurrency)}` : '—'}
                        tone="positive"
                    />
                    <InsightChip
                        label="Softest Period"
                        value={weakest ? `${weakest.label} · ${renderInsightMoney(weakest, currencyMode, targetCurrency, fallbackCurrency)}` : '—'}
                        tone="default"
                    />
                </div>
            </div>

            <div className="rounded-xl border border-slate-200 bg-white px-4 py-4 xl:col-span-12">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">User Summary</p>
                        <p className="mt-1 max-w-3xl text-sm text-slate-500">Read whether this market is winning attention, turning that attention into contact intent, and concentrating demand into a preferred channel.</p>
                    </div>
                    <div className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-700">
                        {Number(detail?.user_summary?.active_users || 0).toLocaleString()} active users
                    </div>
                </div>

                {detail?.availability?.engagement ? (
                    <div className="mt-4 space-y-4">
                        <div className="grid gap-4 xl:grid-cols-[minmax(260px,0.9fr)_minmax(0,1.45fr)]">
                            <div className="rounded-lg border border-slate-200 bg-slate-50/70 px-4 py-4">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Executive read</p>
                                <div className="mt-3 space-y-3 text-sm text-slate-600">
                                    <StatusLine
                                        tone={engagementTone}
                                        label={`${engagement.health_label || 'Steady'}${engagement.contact_rate_percent !== null && engagement.contact_rate_percent !== undefined ? ` • ${engagement.contact_rate_percent.toFixed(1)}% contact rate` : ''}`}
                                    />
                                    <p>
                                        {Number(engagement.views || 0).toLocaleString()} views are producing {Number(engagement.contacts || 0).toLocaleString()} contact actions in this market window.
                                    </p>
                                    <p>
                                        Use this block to decide whether revenue is being reinforced by demand quality, or is running ahead of engagement.
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-3 sm:grid-cols-3">
                                <InsightChip
                                    label="Views"
                                    value={`${Number(engagement.views || 0).toLocaleString()}${engagement.views_delta_percent !== null && engagement.views_delta_percent !== undefined ? ` · ${formatPercent(engagement.views_delta_percent)}` : ''}`}
                                />
                                <InsightChip
                                    label="Contacts"
                                    value={`${Number(engagement.contacts || 0).toLocaleString()}${engagement.contacts_delta_percent !== null && engagement.contacts_delta_percent !== undefined ? ` · ${formatPercent(engagement.contacts_delta_percent)}` : ''}`}
                                />
                                <InsightChip
                                    label="Engagement Health"
                                    value={`${engagement.health_label || 'Steady'}${engagement.contact_rate_percent !== null && engagement.contact_rate_percent !== undefined ? ` · ${engagement.contact_rate_percent.toFixed(1)}%` : ''}`}
                                    tone={engagementTone}
                                />
                            </div>
                        </div>

                        <div className="space-y-3 border-t border-slate-100 pt-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Contact Preference Mix</p>
                                <p className="text-xs text-slate-500">Shows which channel is capturing demand most efficiently right now.</p>
                            </div>
                            {contactMix.length > 0 ? (
                                <div className="grid gap-3 lg:grid-cols-3">
                                    {contactMix.map((channel) => (
                                        <div key={channel.key} className="rounded-lg border border-slate-200 bg-slate-50/60 px-4 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <p className="text-sm font-semibold text-slate-800">{channel.label}</p>
                                                <p className="text-sm font-medium text-slate-500">{channel.percent.toFixed(1)}%</p>
                                            </div>
                                            <p className="mt-1 text-sm text-slate-500">{channel.total.toLocaleString()} actions</p>
                                            <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                                <div className="h-full rounded-full bg-[linear-gradient(90deg,#14b8a6_0%,#0f766e_100%)] transition-all duration-500" style={{ width: `${Math.max(channel.percent, channel.total > 0 ? 6 : 0)}%` }} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            ) : (
                                <p className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                    Contact preference data has not been captured yet for this market.
                                </p>
                            )}
                        </div>
                    </div>
                ) : (
                    <div className="mt-4 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                        {engagement.message || 'Profile engagement analytics are currently unavailable for this market.'}
                    </div>
                )}
            </div>
        </div>
    );
}

export default function CountryRevenueWidget({
    data = [],
    fromDate,
    toDate,
    rangeMode = 'month',
    onSetWeek,
    onSetMonth,
    onSetCustom,
    isLoading,
    errorMessage = null,
    currencyMode = 'native',
    targetCurrency = 'USD',
    title = 'Top Performing Countries',
    hideOwnControls = false,
}) {
    const [expandedPlatformId, setExpandedPlatformId] = useState(null);
    const [showAllMarkets, setShowAllMarkets] = useState(false);
    const subtitle = describeWindow(rangeMode, fromDate, toDate);
    const detailParams = useMemo(() => ({
        ...(fromDate ? { from: fromDate } : {}),
        ...(toDate ? { to: toDate } : {}),
        currency_mode: currencyMode,
        reporting_currency: targetCurrency,
    }), [currencyMode, fromDate, targetCurrency, toDate]);
    const detailQuery = useQuery({
        queryKey: ['dashboard-country-performance', expandedPlatformId, detailParams],
        queryFn: () => api.get(`/crm/dashboard/country-performance/${expandedPlatformId}`, { params: detailParams }).then((response) => response.data),
        enabled: Boolean(expandedPlatformId),
        staleTime: 60_000,
    });
    const detailData = detailQuery.data ? {
        ...detailQuery.data,
        trend: {
            ...detailQuery.data.trend,
            points: (detailQuery.data?.trend?.points || []).map((point) => ({
                ...point,
                chart_value: point.revenue ?? point.normalized_total ?? Object.values(point.revenue_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0),
            })),
        },
    } : null;
    const topValue = Math.max(...data.map((row) => (
        currencyMode === 'flat' && row.current_revenue_normalized !== null && row.current_revenue_normalized !== undefined
            ? Number(row.current_revenue_normalized)
            : (row.current_revenue ?? Object.values(row.current_revenue_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0))
    )), 0);
    const visibleMarkets = useMemo(() => (showAllMarkets ? data : data.slice(0, 6)), [data, showAllMarkets]);
    const hiddenCount = Math.max(0, data.length - visibleMarkets.length);

    useEffect(() => {
        if (showAllMarkets || !expandedPlatformId) return;

        const stillVisible = visibleMarkets.some((market) => Number(market.platform_id) === Number(expandedPlatformId));
        if (!stillVisible) {
            setExpandedPlatformId(null);
        }
    }, [expandedPlatformId, showAllMarkets, visibleMarkets]);

    return (
        <SectionFrame
            title={title}
            subtitle={subtitle}
            className="overflow-hidden"
            action={hideOwnControls ? null : <PeriodToggle rangeMode={rangeMode} onSetWeek={onSetWeek} onSetMonth={onSetMonth} onSetCustom={onSetCustom} />}
        >
            {isLoading ? (
                <div className="space-y-3">
                    {[1, 2, 3].map((item) => (
                        <div key={item} className="h-24 animate-pulse rounded-xl bg-slate-100" />
                    ))}
                </div>
            ) : errorMessage ? (
                <div className="rounded-md border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">
                    {errorMessage}
                </div>
            ) : data.length > 0 ? (
                <div className="space-y-3">
                    {visibleMarkets.map((market, index) => {
                        const isExpanded = expandedPlatformId === market.platform_id;
                        const leadingValue = currencyMode === 'flat' && market.current_revenue_normalized !== null && market.current_revenue_normalized !== undefined
                            ? Number(market.current_revenue_normalized)
                            : (market.current_revenue ?? Object.values(market.current_revenue_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0));
                        const target = market.target || null;
                        const targetPercentage = target ? Number(target.percentage || 0) : null;
                        const barWidth = target ? targetPercentage : (topValue > 0 ? (leadingValue / topValue) * 100 : 0);
                        const trendValue = currencyMode === 'flat' ? market.normalized_trend : market.trend;
                        const trendState = describeTrendState(trendValue, leadingValue > 0);

                        return (
                            <div
                                key={market.platform_id}
                                className={`rounded-xl border transition-all duration-200 ${
                                    isExpanded
                                        ? 'border-slate-300 bg-slate-50/70 shadow-[0_18px_40px_rgba(15,23,42,0.08)]'
                                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-[0_12px_28px_rgba(15,23,42,0.06)]'
                                }`}
                            >
                                <button
                                    type="button"
                                    onClick={() => setExpandedPlatformId((current) => (current === market.platform_id ? null : market.platform_id))}
                                    className="w-full px-4 py-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                >
                                    <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-white text-lg font-semibold text-slate-700">
                                                    {index + 1}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-xl" aria-hidden="true">{getCountryFlag(market.country)}</span>
                                                        <div className="min-w-0">
                                                            <p className="truncate text-[1.04rem] font-semibold tracking-tight text-slate-900">{market.country || market.name}</p>
                                                            <p className="truncate text-sm text-slate-500">{market.name}</p>
                                                        </div>
                                                    </div>
                                                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                                        <div className="h-full rounded-full bg-[linear-gradient(90deg,#14b8a6_0%,#0f766e_100%)] transition-all duration-500" style={{ width: `${Math.max(barWidth, leadingValue > 0 ? 8 : 0)}%` }} />
                                                    </div>
                                                    <div className="mt-2 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                                        {target ? (
                                                            <>
                                                                <span className="font-semibold text-teal-700">{target.percentage}% of {target.period === 'weekly' ? 'weekly' : 'monthly'} target</span>
                                                                <span>•</span>
                                                                <span>{target.current_display} / {target.target_display}</span>
                                                            </>
                                                        ) : (
                                                            <span>Ranked by revenue share until a target is set</span>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-start justify-between gap-4 xl:min-w-[320px]">
                                            <div className="min-w-0 flex-1 text-left xl:text-right">
                                                <CollapsedRevenueValue
                                                    market={market}
                                                    currencyMode={currencyMode}
                                                    targetCurrency={targetCurrency}
                                                />
                                                <CollapsedRevenueMeta
                                                    market={market}
                                                    currencyMode={currencyMode}
                                                />
                                                <div className="mt-2 flex xl:justify-end">
                                                    <StatusLine tone={trendState.tone} label={trendState.label} align="right" />
                                                </div>
                                            </div>
                                            <div className={`mt-1 flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-500 transition ${isExpanded ? 'rotate-180' : ''}`}>
                                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.2} d="M19 9l-7 7-7-7" />
                                                </svg>
                                            </div>
                                        </div>
                                    </div>
                                </button>

                                {isExpanded ? (
                                    <div className="px-4 pb-4">
                                        <DetailPanel
                                            detail={detailData}
                                            detailQuery={detailQuery}
                                            currencyMode={currencyMode}
                                            targetCurrency={targetCurrency}
                                            fallbackCurrency={market.currency}
                                        />
                                    </div>
                                ) : null}
                            </div>
                        );
                    })}
                    {data.length > 6 ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3">
                            <div>
                                <p className="text-sm font-semibold text-slate-800">
                                    {showAllMarkets ? `Showing all ${data.length} markets` : `Showing top ${visibleMarkets.length} of ${data.length} markets`}
                                </p>
                                <p className="text-xs text-slate-500">Lower-volume markets stay collapsed so the executive view remains scannable.</p>
                            </div>
                            <button
                                type="button"
                                onClick={() => setShowAllMarkets((current) => !current)}
                                className="min-h-11 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                            >
                                {showAllMarkets ? 'Collapse to top 6' : `Show ${hiddenCount} more`}
                            </button>
                        </div>
                    ) : null}
                </div>
            ) : (
                <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                    No markets in scope for this view.
                </div>
            )}
        </SectionFrame>
    );
}
