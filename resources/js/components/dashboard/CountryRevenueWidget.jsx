import React, { useMemo, useState } from 'react';
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

function TrendArrow({ trend }) {
    if (trend === null || trend === undefined) {
        return <span className="text-xs text-slate-400">&mdash;</span>;
    }

    if (trend === 0) {
        return <span className="text-xs font-medium text-slate-500">0%</span>;
    }

    if (trend > 0) {
        return (
            <span className="inline-flex items-center gap-0.5 text-xs font-medium text-emerald-700">
                <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M5 15l7-7 7 7" />
                </svg>
                {Math.abs(trend)}%
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-0.5 text-xs font-medium text-rose-700">
            <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
            </svg>
            {Math.abs(trend)}%
        </span>
    );
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

function PeriodToggle({ rangeMode, onSetWeek, onSetMonth }) {
    return (
        <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
            {['week', 'month', 'custom'].map((option) => (
                <button
                    key={option}
                    type="button"
                    onClick={option === 'week' ? onSetWeek : option === 'month' ? onSetMonth : undefined}
                    className={`rounded-md px-3 py-1 text-xs font-semibold capitalize transition ${
                        rangeMode === option
                            ? 'bg-white text-slate-900 shadow-sm'
                            : 'text-slate-500 hover:text-slate-700'
                    }`}
                    disabled={option === 'custom'}
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
                <FxNormalizationNotice meta={normalizationMeta} className="mt-1" />
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
            {currencyMode === 'flat' ? <FxNormalizationNotice meta={normalizationMeta} className="mt-1" /> : null}
        </>
    );
}

function InsightChip({ label, value, tone = 'default' }) {
    const toneClass = tone === 'positive'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : tone === 'negative'
            ? 'border-rose-200 bg-rose-50 text-rose-800'
            : 'border-slate-200 bg-slate-50 text-slate-700';

    return (
        <div className={`rounded-2xl border px-3.5 py-3 ${toneClass}`}>
            <p className="text-[10px] font-semibold uppercase tracking-[0.12em]">{label}</p>
            <p className="mt-1 text-sm font-semibold">{value}</p>
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
            <div className="grid gap-4 rounded-[24px] border border-slate-200 bg-slate-50/70 p-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(300px,0.95fr)]">
                <div className="h-72 animate-pulse rounded-[20px] bg-white" />
                <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, index) => (
                        <div key={index} className="h-16 animate-pulse rounded-[18px] bg-white" />
                    ))}
                </div>
            </div>
        );
    }

    if (detailQuery.error) {
        return (
            <div className="rounded-[24px] border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">
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

    return (
        <div className="grid gap-4 rounded-[24px] border border-slate-200 bg-[linear-gradient(180deg,#ffffff_0%,#f8fafc_100%)] p-4 xl:grid-cols-[minmax(0,1.35fr)_minmax(320px,0.95fr)]">
            <div className="space-y-4">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Revenue Trend</p>
                    <p className="mt-1 text-sm text-slate-500">Momentum across the selected dashboard window.</p>
                </div>
                {chartRows.length > 0 ? (
                    <div className="h-72 rounded-[22px] border border-slate-200 bg-white px-3 py-4">
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
                                    contentStyle={{ borderRadius: 16, borderColor: '#cbd5e1' }}
                                    formatter={(value) => [Number(value || 0).toLocaleString(), currencyMode === 'flat' ? targetCurrency : 'Revenue']}
                                    labelFormatter={(label, payload) => payload?.[0]?.payload?.bucket_start || label}
                                />
                                <Area
                                    type="monotone"
                                    dataKey={currencyMode === 'flat' ? 'normalized_total' : 'chart_value'}
                                    stroke="#0f766e"
                                    fill="url(#countryRevenueGradient)"
                                    strokeWidth={2.75}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                ) : (
                    <div className="rounded-[22px] border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                        Revenue points will appear here once payments exist inside the selected window.
                    </div>
                )}
            </div>

            <div className="space-y-4">
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="rounded-[20px] border border-slate-200 bg-white px-4 py-3">
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
                                emphasisClass="crm-mono text-lg font-semibold text-slate-900"
                            />
                        </div>
                    </div>
                    <div className="rounded-[20px] border border-slate-200 bg-white px-4 py-3">
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
                                emphasisClass="crm-mono text-lg font-semibold text-slate-900"
                            />
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <InsightChip
                        label="Revenue Momentum"
                        value={detail?.insights?.momentum?.label || 'No recent movement yet'}
                        tone={detail?.insights?.momentum?.direction === 'up' ? 'positive' : detail?.insights?.momentum?.direction === 'down' ? 'negative' : 'default'}
                    />
                    <InsightChip
                        label="Recent Move"
                        value={detail?.insights?.recent_movement?.delta_percent === null || detail?.insights?.recent_movement?.delta_percent === undefined
                            ? 'No prior comparison'
                            : `${formatPercent(detail.insights.recent_movement.delta_percent)} vs prior`}
                        tone={detail?.insights?.recent_movement?.direction === 'up' ? 'positive' : detail?.insights?.recent_movement?.direction === 'down' ? 'negative' : 'default'}
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

                <div className="rounded-[22px] border border-slate-200 bg-white px-4 py-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">User Summary</p>
                            <p className="mt-1 text-sm text-slate-500">Enough market context to judge performance without leaving the dashboard.</p>
                        </div>
                        <div className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-sm font-semibold text-slate-700">
                            {Number(detail?.user_summary?.active_users || 0).toLocaleString()} active users
                        </div>
                    </div>

                    {detail?.availability?.engagement ? (
                        <div className="mt-4 space-y-4">
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
                                    tone={engagement.health === 'above_market' ? 'positive' : engagement.health === 'below_market' ? 'negative' : 'default'}
                                />
                            </div>

                            <div className="space-y-3">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">Contact Preference Mix</p>
                                {contactMix.length > 0 ? (
                                    contactMix.map((channel) => (
                                        <div key={channel.key} className="space-y-1.5">
                                            <div className="flex items-center justify-between gap-3 text-sm">
                                                <p className="font-semibold text-slate-800">{channel.label}</p>
                                                <p className="font-medium text-slate-500">{channel.total.toLocaleString()} · {channel.percent.toFixed(1)}%</p>
                                            </div>
                                            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                                <div className="h-full rounded-full bg-teal-500 transition-all duration-500" style={{ width: `${Math.max(channel.percent, channel.total > 0 ? 6 : 0)}%` }} />
                                            </div>
                                        </div>
                                    ))
                                ) : (
                                    <p className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3 py-3 text-sm text-slate-500">
                                        Contact preference data has not been captured yet for this market.
                                    </p>
                                )}
                            </div>
                        </div>
                    ) : (
                        <div className="mt-4 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-500">
                            {engagement.message || 'Profile engagement analytics are currently unavailable for this market.'}
                        </div>
                    )}
                </div>
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
    isLoading,
    errorMessage = null,
    currencyMode = 'native',
    targetCurrency = 'USD',
}) {
    const [expandedPlatformId, setExpandedPlatformId] = useState(null);
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

    return (
        <SectionFrame
            title="Top Performing Countries"
            subtitle={subtitle}
            className="overflow-hidden"
            action={<PeriodToggle rangeMode={rangeMode} onSetWeek={onSetWeek} onSetMonth={onSetMonth} />}
        >
            {isLoading ? (
                <div className="space-y-3">
                    {[1, 2, 3].map((item) => (
                        <div key={item} className="h-24 animate-pulse rounded-[22px] bg-slate-100" />
                    ))}
                </div>
            ) : errorMessage ? (
                <div className="rounded-md border border-dashed border-rose-200 bg-rose-50 px-4 py-8 text-center text-sm text-rose-700">
                    {errorMessage}
                </div>
            ) : data.length > 0 ? (
                <div className="space-y-3">
                    {data.map((market, index) => {
                        const isExpanded = expandedPlatformId === market.platform_id;
                        const leadingValue = currencyMode === 'flat' && market.current_revenue_normalized !== null && market.current_revenue_normalized !== undefined
                            ? Number(market.current_revenue_normalized)
                            : (market.current_revenue ?? Object.values(market.current_revenue_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0));
                        const topValue = Math.max(...data.map((row) => (
                            currencyMode === 'flat' && row.current_revenue_normalized !== null && row.current_revenue_normalized !== undefined
                                ? Number(row.current_revenue_normalized)
                                : (row.current_revenue ?? Object.values(row.current_revenue_breakdown || {}).reduce((sum, amount) => sum + Number(amount || 0), 0))
                        )), 0);
                        const barWidth = topValue > 0 ? (leadingValue / topValue) * 100 : 0;
                        const trendValue = currencyMode === 'flat' ? market.normalized_trend : market.trend;

                        return (
                            <div
                                key={market.platform_id}
                                className={`rounded-[24px] border transition-all duration-200 ${
                                    isExpanded
                                        ? 'border-slate-300 bg-slate-50/70 shadow-[0_18px_40px_rgba(15,23,42,0.08)]'
                                        : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-[0_12px_28px_rgba(15,23,42,0.06)]'
                                }`}
                            >
                                <button
                                    type="button"
                                    onClick={() => setExpandedPlatformId((current) => current === market.platform_id ? null : market.platform_id)}
                                    className="w-full px-4 py-4 text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                                >
                                    <div className="flex flex-col gap-4 xl:flex-row xl:items-center xl:justify-between">
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start gap-3">
                                                <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-slate-200 bg-white text-lg font-semibold text-slate-700">
                                                    {index + 1}
                                                </div>
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex items-center gap-3">
                                                        <span className="text-2xl" aria-hidden="true">{getCountryFlag(market.country)}</span>
                                                        <div className="min-w-0">
                                                            <p className="truncate text-base font-semibold tracking-tight text-slate-900">{market.country || market.name}</p>
                                                            <p className="truncate text-sm text-slate-500">{market.name}</p>
                                                        </div>
                                                    </div>
                                                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-200">
                                                        <div className="h-full rounded-full bg-[linear-gradient(90deg,#14b8a6_0%,#0f766e_100%)] transition-all duration-500" style={{ width: `${Math.max(barWidth, leadingValue > 0 ? 8 : 0)}%` }} />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="flex items-start justify-between gap-4 xl:min-w-[260px]">
                                            <div className="text-left xl:text-right">
                                                <MoneyStack
                                                    breakdown={market.current_revenue_breakdown}
                                                    scalarAmount={market.current_revenue}
                                                    fallbackCurrency={market.currency}
                                                    normalizedTotal={market.current_revenue_normalized}
                                                    normalizedDisplay={market.current_revenue_normalized_display}
                                                    normalizationMeta={market.current_revenue_normalization_meta}
                                                    currencyMode={currencyMode}
                                                    targetCurrency={targetCurrency}
                                                />
                                                <div className="mt-2">
                                                    <TrendArrow trend={trendValue} />
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
                </div>
            ) : (
                <div className="rounded-md border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                    No markets in scope for this view.
                </div>
            )}
        </SectionFrame>
    );
}
