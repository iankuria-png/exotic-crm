import React, { useEffect, useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';
import ReportingCurrencyControl from '../components/ReportingCurrencyControl';
import FxNormalizationNotice from '../components/FxNormalizationNotice';
import { useToast } from '../components/ToastProvider';
import { useAuth } from '../hooks/useAuth';
import useReportingCurrency from '../hooks/useReportingCurrency';
import { formatCurrency, asNumber } from '../utils/currency';
import CurrencyAmount from '../components/CurrencyAmount';

const PROFILE_ENGAGEMENT_LABELS = {
    phone_click: 'Phone',
    whatsapp_click: 'WhatsApp',
    viber_click: 'Viber',
};

function formatPercent(value, digits = 1) {
    return `${asNumber(value).toFixed(digits)}%`;
}

function percent(value, total) {
    if (!total) return 0;
    return Math.round((value / total) * 100);
}

function toCsvCell(value) {
    const stringValue = String(value ?? '');
    if (/[",\n]/.test(stringValue)) {
        return `"${stringValue.replace(/"/g, '""')}"`;
    }
    return stringValue;
}

function toCsvRow(values) {
    return values.map((value) => toCsvCell(value)).join(',');
}

function downloadCsv(filename, rows) {
    const csvContent = rows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.setAttribute('download', filename);
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function startCase(value) {
    return String(value || '')
        .replace(/[_-]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .replace(/\b\w/g, (match) => match.toUpperCase()) || 'Unknown';
}

function normalizeLeadSources(rows) {
    const canonicalSources = [
        ['website', 'Website'],
        ['whatsapp', 'WhatsApp'],
        ['referral', 'Referral'],
        ['facebook', 'Facebook'],
        ['import', 'Import'],
        ['outbound', 'Outbound'],
        ['manual', 'Manual'],
        ['scraper', 'Scraper'],
    ];
    const sourceMap = new Map(
        rows.map((row) => [String(row?.source || 'unknown').toLowerCase(), asNumber(row?.value)]),
    );
    const canonicalKeys = new Set(canonicalSources.map(([key]) => key));

    const normalized = canonicalSources.map(([key, label]) => ({
        key,
        source: key,
        label,
        value: asNumber(sourceMap.get(key)),
    }));

    const extras = [...sourceMap.entries()]
        .filter(([key]) => !canonicalKeys.has(key))
        .sort((left, right) => right[1] - left[1])
        .map(([key, value]) => ({
            key,
            source: key,
            label: startCase(key),
            value: asNumber(value),
        }));

    return [...normalized, ...extras];
}

function InsightEmptyState({ title, message }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
            <p className="text-sm font-semibold text-slate-700">{title}</p>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
        </div>
    );
}

function BarList({ rows, colorClass = 'bg-teal-600', minimumPercent = 6 }) {
    const maxValue = rows.reduce((max, row) => Math.max(max, row.value), 0) || 1;

    return (
        <div className="space-y-3">
            {rows.map((row) => (
                <div key={row.label} className="space-y-2">
                    <div className="flex items-center justify-between gap-3">
                        <p className="min-w-0 truncate text-sm font-medium text-slate-700">{row.label}</p>
                        <p className="shrink-0 whitespace-nowrap text-right text-sm font-semibold text-slate-800">{row.formattedValue}</p>
                    </div>
                    <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className={`h-full rounded-full ${colorClass}`}
                            style={{
                                width: `${row.value > 0
                                    ? Math.max(minimumPercent, Math.round((row.value / maxValue) * 100))
                                    : 0}%`,
                            }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

// Used when revenue_trend has multiple currencies in a single month (mixed scope).
// Renders a simple month-by-month breakdown list instead of a bar chart.
function RevenueBreakdownList({ rows }) {
    return (
        <div className="space-y-2">
            {rows.map((row) => (
                <div key={row.month_key} className="flex items-start justify-between gap-4 rounded-lg border border-slate-100 bg-slate-50/60 px-3 py-2 text-sm">
                    <span className="font-medium text-slate-600">{row.label}</span>
                    <div className="text-right">
                        {Object.keys(row.revenue_breakdown ?? {}).length > 0
                            ? Object.entries(row.revenue_breakdown).map(([code, amt]) => (
                                <div key={code} className="font-semibold text-slate-800">{formatCurrency(amt, code)}</div>
                            ))
                            : <span className="text-slate-400">—</span>}
                    </div>
                </div>
            ))}
        </div>
    );
}

function FunnelFlow({ stages, totals }) {
    const maxCount = stages.reduce((max, stage) => Math.max(max, asNumber(stage.count)), 0) || 1;

    return (
        <div className="space-y-3">
            {stages.map((stage, index) => {
                const count = asNumber(stage.count);
                const width = count > 0 ? Math.max(8, Math.round((count / maxCount) * 100)) : 0;

                return (
                    <article key={stage.key || stage.label} className="rounded-lg border border-slate-200 bg-white px-3 py-3">
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-sm font-semibold text-slate-800">{index + 1}. {stage.label}</p>
                            <p className="crm-mono text-sm font-semibold text-slate-700">{count.toLocaleString()}</p>
                        </div>
                        <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div className="h-full rounded-full bg-cyan-600" style={{ width: `${width}%` }} />
                        </div>
                        <div className="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                            <span>{asNumber(stage.share_of_total)}% of pipeline</span>
                            {stage.conversion_from_previous === null
                                ? <span>Entry stage</span>
                                : <span>{asNumber(stage.conversion_from_previous)}% progressed from previous</span>}
                        </div>
                    </article>
                );
            })}
            <div className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700 sm:grid-cols-3">
                <p><span className="font-semibold">Total:</span> {asNumber(totals?.total).toLocaleString()}</p>
                <p><span className="font-semibold">Workable:</span> {asNumber(totals?.workable).toLocaleString()}</p>
                <p><span className="font-semibold">Converted:</span> {asNumber(totals?.converted).toLocaleString()}</p>
            </div>
        </div>
    );
}

function OwnerPerformanceTable({ rows, totals, currency = 'KES', isMixed = false, currencyMode = 'native', targetCurrency = 'USD' }) {
    if (!rows.length) {
        return <InsightEmptyState title="No owner performance data" message="No successful payments were collected in this reporting window." />;
    }

    // For share calculation use the totals breakdown sum or scalar
    const totalsBreakdown = totals?.revenue_breakdown ?? {};
    const revenueTotal = currencyMode === 'flat' && totals?.normalized_revenue !== null && totals?.normalized_revenue !== undefined
        ? asNumber(totals.normalized_revenue)
        : isMixed
        ? Object.values(totalsBreakdown).reduce((sum, v) => sum + v, 0)
        : asNumber(totals?.revenue);

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                        <th className="px-3 py-2">Owner</th>
                        <th className="px-3 py-2">Successful Payments</th>
                        <th className="px-3 py-2">Revenue Mix</th>
                        <th className="px-3 py-2 text-right">Revenue</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {rows.map((row) => {
                        const paymentCount = asNumber(row.payments_count ?? row.deals);
                        const rowBreakdown = row.revenue_breakdown ?? {};
                        const rowTotal = currencyMode === 'flat' && row.normalized_revenue !== null && row.normalized_revenue !== undefined
                            ? asNumber(row.normalized_revenue)
                            : Object.values(rowBreakdown).reduce((sum, v) => sum + v, 0);
                        const share = revenueTotal > 0 ? Math.round((rowTotal / revenueTotal) * 100) : 0;
                        return (
                            <tr key={row.owner}>
                                <td className="px-3 py-3">
                                    <p className="font-semibold text-slate-800">{row.owner}</p>
                                    {isMixed
                                        ? <CurrencyAmount breakdown={row.avg_revenue_per_payment_breakdown ?? row.avg_revenue_breakdown ?? {}} scalarAmount={row.avg_revenue_per_payment ?? row.avg_revenue_per_subscription} fallbackCurrency={currency} className="text-xs text-slate-500" stackClassName="leading-snug" />
                                        : <p className="text-xs text-slate-500">{formatCurrency(row.avg_revenue_per_payment ?? row.avg_revenue_per_subscription, currency)} avg / payment</p>}
                                </td>
                                <td className="px-3 py-3 text-slate-700">
                                    <p className="font-semibold">{paymentCount.toLocaleString()}</p>
                                    <p className="text-xs text-slate-500">Collected in selected range</p>
                                </td>
                                <td className="px-3 py-3">
                                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                                        <div className="h-full rounded-full bg-indigo-600" style={{ width: `${share > 0 ? Math.max(4, share) : 0}%` }} />
                                    </div>
                                    <p className="mt-1 text-xs text-slate-500">{share}% revenue share</p>
                                </td>
                                <td className="px-3 py-3 text-right font-semibold text-slate-800">
                                    {currencyMode === 'flat' && row.normalized_revenue !== null && row.normalized_revenue !== undefined ? (
                                        <div>
                                            <p>{formatCurrency(row.normalized_revenue, row.normalized_currency || targetCurrency)}</p>
                                            <CurrencyAmount breakdown={rowBreakdown} scalarAmount={row.revenue} fallbackCurrency={currency} className="mt-1 text-xs font-medium text-slate-500" stackClassName="text-xs leading-snug font-medium text-slate-500" />
                                        </div>
                                    ) : (
                                        <CurrencyAmount breakdown={rowBreakdown} scalarAmount={row.revenue} fallbackCurrency={currency} stackClassName="leading-snug" />
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function ReportPanel({ title, subtitle, children }) {
    return (
        <section className="crm-surface">
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">{title}</h3>
                    {subtitle ? <p className="crm-panel-subtitle">{subtitle}</p> : null}
                </div>
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

export default function Reports() {
    const navigate = useNavigate();
    const toast = useToast();
    const { user } = useAuth();
    const isMarketing = user?.role === 'marketing';
    const [platformFilter, setPlatformFilter] = useState('');
    const [fromDate, setFromDate] = useState('');
    const [toDate, setToDate] = useState('');
    const [hasInitializedFrom, setHasInitializedFrom] = useState(false);
    const [isExporting, setIsExporting] = useState(false);
    const [engagementPage, setEngagementPage] = useState(1);
    const [engagementSortBy, setEngagementSortBy] = useState('engagement_score');
    const [engagementOrder, setEngagementOrder] = useState('desc');
    const isRangeInvalid = Boolean(fromDate && toDate && fromDate > toDate);
    const reportingCurrency = useReportingCurrency({ preferFlat: !platformFilter });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'reports-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });
    const platforms = integrationData?.platforms || [];
    const selectedPlatform = platforms.find(
        (platform) => String(platform.platform_id) === String(platformFilter),
    ) || null;
    const reportCurrency = selectedPlatform?.currency || 'KES';

    const { data, isLoading } = useQuery({
        queryKey: ['reports-summary', platformFilter, fromDate, toDate, reportingCurrency.displayMode, reportingCurrency.targetCurrency],
        queryFn: () =>
            api.get('/crm/reports/summary', {
                params: {
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                    ...(fromDate ? { from: fromDate } : {}),
                    ...(toDate ? { to: toDate } : {}),
                    ...reportingCurrency.queryParams,
                },
            }).then((response) => response.data),
        enabled: !isRangeInvalid && !isMarketing,
    });
    const {
        data: engagementData,
        isLoading: engagementLoading,
        error: engagementError,
    } = useQuery({
        queryKey: ['reports-profile-engagement', platformFilter, fromDate, toDate, engagementPage, engagementSortBy, engagementOrder],
        queryFn: () =>
            api.get('/crm/reports/profile-engagement', {
                params: {
                    platform_id: Number(platformFilter),
                    ...(fromDate ? { from: fromDate } : {}),
                    ...(toDate ? { to: toDate } : {}),
                    page: engagementPage,
                    per_page: 20,
                    sort_by: engagementSortBy,
                    order: engagementOrder,
                },
            }).then((response) => response.data),
        enabled: !isRangeInvalid && Boolean(platformFilter),
    });

    useEffect(() => {
        if (!hasInitializedFrom && data?.baseline_cutoff) {
            setFromDate(data.baseline_cutoff);
            setHasInitializedFrom(true);
        }
    }, [data?.baseline_cutoff, hasInitializedFrom]);

    useEffect(() => {
        setEngagementPage(1);
    }, [platformFilter, fromDate, toDate]);

    const kpis = data?.kpis || {};
    const normalizedCurrency = kpis.normalized_currency || reportingCurrency.targetCurrency;
    const resolvedReportCurrency = useMemo(() => {
        const totalRevenueCurrencies = Object.keys(kpis.total_revenue_breakdown ?? {});
        if (totalRevenueCurrencies.length === 1) {
            return totalRevenueCurrencies[0];
        }

        const revenueMtdCurrencies = Object.keys(kpis.revenue_mtd_breakdown ?? {});
        if (revenueMtdCurrencies.length === 1) {
            return revenueMtdCurrencies[0];
        }

        return reportCurrency;
    }, [kpis.total_revenue_breakdown, kpis.revenue_mtd_breakdown, reportCurrency]);
    const funnel = data?.lead_funnel || {};
    const funnelStages = (data?.lead_funnel_stages || []).map((stage) => ({
        ...stage,
        count: asNumber(stage.count),
    }));
    const funnelTotals = data?.lead_funnel_totals || {};

    const conversionRate = kpis.conversion_rate ?? percent(asNumber(funnel.converted), Object.values(funnel).reduce((sum, value) => sum + asNumber(value), 0));
    const renewalRate = kpis.renewal_rate ?? 0;

    // Detect mixed-currency scope from the report-wide revenue breakdown.
    // This catches both:
    // - multiple currencies within a single row/month, and
    // - different currencies spread across different rows (for example Mar=KES, Apr=USD).
    const isMixedReport = useMemo(
        () => Object.keys(kpis.total_revenue_breakdown ?? {}).length > 1,
        [kpis.total_revenue_breakdown],
    );

    const revenueTrend = useMemo(
        () => (data?.revenue_trend || []).map((row) => ({
            month_key: row.month_key,
            label: row.label,
            value: asNumber(row.value),
            revenue_breakdown: row.revenue_breakdown ?? {},
            // For BarList bar width use sum across currencies when mixed
            barValue: isMixedReport
                ? Object.values(row.revenue_breakdown ?? {}).reduce((s, v) => s + v, 0)
                : asNumber(row.value),
                formattedValue: isMixedReport
                    ? null  // replaced by RevenueBreakdownList
                : formatCurrency(row.value, resolvedReportCurrency),
        })),
        [data?.revenue_trend, resolvedReportCurrency, isMixedReport],
    );

    const leadSources = useMemo(() => {
        const normalized = normalizeLeadSources(data?.lead_sources || []);
        const total = normalized.reduce((sum, row) => sum + asNumber(row.value), 0) || 1;
        return normalized.map((row) => ({
            label: row.label,
            value: asNumber(row.value),
            formattedValue: `${row.value} (${percent(row.value, total)}%)`,
        }));
    }, [data?.lead_sources]);

    const packageRevenue = useMemo(
        () => (data?.package_revenue || []).map((row) => {
            const breakdown = row.revenue_breakdown ?? {};
            const normalizedValue = row.normalized_total !== null && row.normalized_total !== undefined
                ? asNumber(row.normalized_total)
                : null;
            const totalValue = reportingCurrency.isFlat && normalizedValue !== null
                ? normalizedValue
                : isMixedReport
                ? Object.values(breakdown).reduce((s, v) => s + v, 0)
                : asNumber(row.value);
            return {
                label: row.label,
                value: totalValue,
                formattedValue: reportingCurrency.isFlat && normalizedValue !== null
                    ? (
                        <div className="text-right">
                            <p>{formatCurrency(normalizedValue, row.normalized_currency || normalizedCurrency)}</p>
                            <CurrencyAmount breakdown={breakdown} scalarAmount={row.value} fallbackCurrency={resolvedReportCurrency} className="text-xs font-medium text-slate-500" stackClassName="text-xs leading-snug font-medium text-slate-500" />
                        </div>
                    )
                    : isMixedReport
                    ? <CurrencyAmount breakdown={breakdown} scalarAmount={row.value} fallbackCurrency={resolvedReportCurrency} stackClassName="leading-snug text-right" />
                    : formatCurrency(row.value, resolvedReportCurrency),
            };
        }),
        [data?.package_revenue, resolvedReportCurrency, isMixedReport, normalizedCurrency, reportingCurrency.isFlat],
    );

    const ownerRows = data?.owner_performance || [];
    const ownerTotals = data?.owner_performance_totals || {};
    const topOwner = data?.owner_performance_top_owner;
    const engagementPlatformTotals = engagementData?.platform_totals || {};
    const engagementProfiles = engagementData?.profiles || [];
    const engagementContactMix = useMemo(() => (
        Object.entries(engagementData?.platform_contact_mix || {})
            .map(([eventType, row]) => ({
                label: PROFILE_ENGAGEMENT_LABELS[eventType] || startCase(eventType),
                value: asNumber(row?.total),
                formattedValue: `${asNumber(row?.total)} (${asNumber(row?.percent).toFixed(1)}%)`,
            }))
            .filter((row) => row.value > 0)
    ), [engagementData?.platform_contact_mix]);
    const engagementTopProfile = engagementProfiles[0] || null;

    const rangeLabel = data?.range
        ? `${new Date(data.range.from).toLocaleDateString()} - ${new Date(data.range.to).toLocaleDateString()}`
        : 'Server-backed reporting window';
    const engagementRangeLabel = engagementData?.period
        ? `${new Date(engagementData.period.from).toLocaleDateString()} - ${new Date(engagementData.period.to).toLocaleDateString()}`
        : rangeLabel;

    const exportCsv = () => {
        if (!data && !engagementData) {
            toast.warning('No report data to export yet.');
            return;
        }

        if (isRangeInvalid) {
            toast.error('The "from" date cannot be later than the "to" date.');
            return;
        }

        setIsExporting(true);
        try {
            const rows = [];
            rows.push(toCsvRow(['Section', 'Metric', 'Currency', 'Value']));
            if (data) {
                // KPIs: one row per currency in the breakdown (never a mixed-currency scalar)
                const totalRevBreakdown = kpis.total_revenue_breakdown ?? {};
                if (kpis.total_revenue_normalized !== null && kpis.total_revenue_normalized !== undefined) {
                    rows.push(toCsvRow(['KPI', 'Collected Revenue Normalized', kpis.normalized_currency || normalizedCurrency, kpis.total_revenue_normalized]));
                }
                if (Object.keys(totalRevBreakdown).length > 0) {
                    Object.entries(totalRevBreakdown).forEach(([currency, amount]) =>
                        rows.push(toCsvRow(['KPI', 'Collected Revenue', currency, amount])),
                    );
                } else {
                    rows.push(toCsvRow(['KPI', 'Collected Revenue', resolvedReportCurrency, kpis.total_revenue ?? 0]));
                }
                const revMtdBreakdown = kpis.revenue_mtd_breakdown ?? {};
                if (kpis.revenue_mtd_normalized !== null && kpis.revenue_mtd_normalized !== undefined) {
                    rows.push(toCsvRow(['KPI', 'Revenue MTD Normalized', kpis.normalized_currency || normalizedCurrency, kpis.revenue_mtd_normalized]));
                }
                if (Object.keys(revMtdBreakdown).length > 0) {
                    Object.entries(revMtdBreakdown).forEach(([currency, amount]) =>
                        rows.push(toCsvRow(['KPI', 'Revenue MTD', currency, amount])),
                    );
                } else {
                    rows.push(toCsvRow(['KPI', 'Revenue MTD', resolvedReportCurrency, kpis.revenue_mtd ?? 0]));
                }
                rows.push(toCsvRow(['KPI', 'Active Clients', '', kpis.active_clients ?? 0]));
                rows.push(toCsvRow(['KPI', 'Total Clients', '', kpis.total_clients ?? 0]));
                rows.push(toCsvRow(['KPI', 'Conversion Rate', '', conversionRate]));
                rows.push(toCsvRow(['KPI', 'Renewal Rate', '', renewalRate]));
                rows.push(toCsvRow(['KPI', 'Range From', '', data?.range?.from || '']));
                rows.push(toCsvRow(['KPI', 'Range To', '', data?.range?.to || '']));

                // Revenue trend: one row per (month, currency)
                (data.revenue_trend || []).forEach((row) => {
                    const bd = row.revenue_breakdown ?? {};
                    if (Object.keys(bd).length > 0) {
                        Object.entries(bd).forEach(([currency, amount]) =>
                            rows.push(toCsvRow(['Revenue Trend', row.label, currency, amount])),
                        );
                    } else {
                        rows.push(toCsvRow(['Revenue Trend', row.label, resolvedReportCurrency, row.value ?? 0]));
                    }
                });

                (data.lead_sources || []).forEach((row) => rows.push(toCsvRow(['Lead Source', row.source, '', row.value])));
                (data.lead_funnel_stages || []).forEach((row) => rows.push(toCsvRow(['Lead Funnel', row.label, '', row.count])));

                // Package revenue: one row per (package, currency)
                (data.package_revenue || []).forEach((row) => {
                    const bd = row.revenue_breakdown ?? {};
                    if (row.normalized_total !== null && row.normalized_total !== undefined) {
                        rows.push(toCsvRow(['Package Revenue Normalized', row.label, row.normalized_currency || normalizedCurrency, row.normalized_total]));
                    }
                    if (Object.keys(bd).length > 0) {
                        Object.entries(bd).forEach(([currency, amount]) =>
                            rows.push(toCsvRow(['Package Revenue', row.label, currency, amount])),
                        );
                    } else {
                        rows.push(toCsvRow(['Package Revenue', row.label, resolvedReportCurrency, row.value ?? 0]));
                    }
                });

                // Owner performance: one row per (owner, currency)
                (data.owner_performance || []).forEach((row) => {
                    const paymentCount = row.payments_count ?? row.deals ?? 0;
                    const bd = row.revenue_breakdown ?? {};
                    if (row.normalized_revenue !== null && row.normalized_revenue !== undefined) {
                        rows.push(toCsvRow(['Owner Performance Normalized', row.owner, row.normalized_currency || normalizedCurrency, `${paymentCount} successful payments | ${row.normalized_revenue} revenue`]));
                    }
                    if (Object.keys(bd).length > 0) {
                        Object.entries(bd).forEach(([currency, amount]) =>
                            rows.push(toCsvRow(['Owner Performance', row.owner, currency, `${paymentCount} successful payments | ${amount} revenue`])),
                        );
                    } else {
                        rows.push(toCsvRow(['Owner Performance', row.owner, resolvedReportCurrency, `${paymentCount} successful payments | ${row.revenue ?? 0} revenue`]));
                    }
                });
            }

            if (engagementData) {
                rows.push(toCsvRow(['Profile Engagement', 'Views', engagementPlatformTotals.profile_view?.total ?? 0]));
                rows.push(toCsvRow(['Profile Engagement', 'Unique Visitors', engagementPlatformTotals.unique_visitors?.total ?? 0]));
                rows.push(toCsvRow(['Profile Engagement', 'Contacts', engagementPlatformTotals.contact_actions?.total ?? 0]));
                rows.push(toCsvRow(['Profile Engagement', 'Contact Rate', engagementPlatformTotals.contact_rate_percent?.value ?? 0]));

                engagementContactMix.forEach((row) => rows.push(toCsvRow(['Contact Mix', row.label, row.formattedValue])));
                engagementProfiles.forEach((row) => rows.push(toCsvRow([
                    'Ranking',
                    row.name,
                    `${row.subscription_tier || 'No plan'} | ${row.assigned_agent_name || 'Unassigned'} | ${asNumber(row.totals?.profile_view?.total)} views | ${asNumber(row.contact_actions_total)} contacts | ${asNumber(row.contact_rate_percent).toFixed(1)}%`,
                ])));
            }

            const filename = `crm-reports-${data?.range?.from || engagementData?.period?.from || 'from'}-to-${data?.range?.to || engagementData?.period?.to || 'to'}.csv`;
            downloadCsv(filename, rows);
            toast.success('Report export generated.');
        } finally {
            setIsExporting(false);
        }
    };

    const toggleEngagementSort = (field) => {
        setEngagementPage(1);

        if (engagementSortBy === field) {
            setEngagementOrder((current) => (current === 'desc' ? 'asc' : 'desc'));
            return;
        }

        setEngagementSortBy(field);
        setEngagementOrder('desc');
    };

    return (
        <div className="space-y-4">
            <PageHeader
                title="Reports & Analytics"
                subtitle={`Server-backed metrics for revenue, renewal health, and profile engagement (${engagementRangeLabel}).`}
                actions={(
                    <div className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2 py-2">
                        <ReportingCurrencyControl reporting={reportingCurrency} />
                        <label className="text-xs font-medium text-slate-600" htmlFor="report-market">Market</label>
                        <select
                            id="report-market"
                            value={platformFilter}
                            onChange={(event) => setPlatformFilter(event.target.value)}
                            className="crm-select w-auto min-w-[180px]"
                        >
                            <option value="">All accessible markets</option>
                            {platforms.map((platform) => (
                                <option key={platform.platform_id} value={platform.platform_id}>
                                    {platform.platform_name}
                                </option>
                            ))}
                        </select>
                        <label className="text-xs font-medium text-slate-600" htmlFor="report-from">From</label>
                        <input
                            id="report-from"
                            type="date"
                            value={fromDate}
                            onChange={(event) => setFromDate(event.target.value)}
                            className="crm-input w-auto min-w-[150px]"
                        />
                        <label className="text-xs font-medium text-slate-600" htmlFor="report-to">To</label>
                        <input
                            id="report-to"
                            type="date"
                            value={toDate}
                            onChange={(event) => setToDate(event.target.value)}
                            className="crm-input w-auto min-w-[150px]"
                        />
                        {(platformFilter || fromDate || toDate) ? (
                            <button
                                type="button"
                                onClick={() => {
                                    setPlatformFilter('');
                                    setFromDate('');
                                    setToDate('');
                                }}
                                className="crm-btn-secondary px-3 py-2"
                            >
                                Reset
                            </button>
                        ) : null}
                        <button
                            type="button"
                            onClick={exportCsv}
                            disabled={isExporting || isRangeInvalid}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-70"
                        >
                            {isExporting ? 'Exporting...' : 'Export CSV'}
                        </button>
                    </div>
                )}
            />
            {isRangeInvalid ? (
                <p className="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                    Select a valid reporting range: the start date must be earlier than or equal to the end date.
                </p>
            ) : null}

            {!isMarketing ? (
                <>
                    <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <MetricCard
                            label="Collected Revenue"
                            value={reportingCurrency.isFlat && kpis.total_revenue_normalized !== null && kpis.total_revenue_normalized !== undefined ? (
                                <div>
                                    <p>{kpis.total_revenue_normalized_display || formatCurrency(kpis.total_revenue_normalized, normalizedCurrency)}</p>
                                    <CurrencyAmount breakdown={kpis.total_revenue_breakdown ?? {}} scalarAmount={kpis.total_revenue} fallbackCurrency={resolvedReportCurrency} className="mt-1 text-xs font-medium text-slate-500" stackClassName="text-xs leading-snug font-medium text-slate-500" />
                                    <FxNormalizationNotice meta={kpis.total_revenue_normalization_meta} className="mt-2" />
                                </div>
                            ) : reportingCurrency.isFlat ? (
                                <div>
                                    <CurrencyAmount breakdown={kpis.total_revenue_breakdown ?? {}} scalarAmount={kpis.total_revenue} fallbackCurrency={resolvedReportCurrency} />
                                    <FxNormalizationNotice meta={kpis.total_revenue_normalization_meta} className="mt-2" />
                                </div>
                            ) : (
                                <CurrencyAmount breakdown={kpis.total_revenue_breakdown ?? {}} scalarAmount={kpis.total_revenue} fallbackCurrency={resolvedReportCurrency} />
                            )}
                            meta={reportingCurrency.isFlat ? `selected window in ${normalizedCurrency}` : 'selected reporting window'}
                            tone="accent"
                        />
                        <MetricCard
                            label="Active Clients"
                            value={asNumber(kpis.active_clients).toLocaleString()}
                            meta={`${asNumber(kpis.total_clients).toLocaleString()} total in CRM`}
                            tone="success"
                        />
                        <MetricCard
                            label="Conversion Rate"
                            value={`${conversionRate}%`}
                            meta="lead pipeline to converted"
                            tone="default"
                        />
                        <MetricCard
                            label="Renewal Rate"
                            value={`${renewalRate}%`}
                            meta="active vs at-risk mix"
                            tone="warning"
                        />
                    </section>

                    <section className="grid gap-4 xl:grid-cols-12">
                        <div className="space-y-4 xl:col-span-6">
                            <ReportPanel title="Sales Funnel" subtitle="Lead progression and drop-off through the pipeline">
                                {isLoading ? <p className="text-sm text-slate-500">Loading funnel data...</p> : (
                                    funnelStages.length > 0
                                        ? <FunnelFlow stages={funnelStages} totals={funnelTotals} />
                                        : <InsightEmptyState title="No funnel activity" message="No leads were captured in this reporting window." />
                                )}
                            </ReportPanel>

                            <ReportPanel title="Revenue Trend" subtitle="Successful collected payments by month">
                                {isLoading ? <p className="text-sm text-slate-500">Loading revenue trend...</p> : (
                                    revenueTrend.length > 0
                                        ? isMixedReport
                                            ? <RevenueBreakdownList rows={revenueTrend} />
                                            : <BarList rows={revenueTrend} colorClass="bg-teal-600" />
                                        : <InsightEmptyState title="No payment trend available" message="No successful payments found for this range." />
                                )}
                            </ReportPanel>

                            <ReportPanel title="Lead Sources" subtitle="Pipeline origin by source">
                                {leadSources.length > 0
                                    ? <BarList rows={leadSources} colorClass="bg-cyan-600" minimumPercent={0} />
                                    : <InsightEmptyState title="No lead source data" message="Lead source tracking has no records in this date window." />}
                            </ReportPanel>
                        </div>

                        <div className="space-y-4 xl:col-span-6">
                            <ReportPanel title="Revenue by Package" subtitle="Collected payment revenue grouped by package">
                                {packageRevenue.length > 0
                                    ? <BarList rows={packageRevenue} colorClass="bg-emerald-600" />
                                    : <InsightEmptyState title="No package revenue yet" message="No collected payment revenue has posted in this date window." />}
                            </ReportPanel>

                            <ReportPanel title="Owner Performance" subtitle="Successful payments and collected revenue by owner">
                                <div className="space-y-3">
                                    {topOwner ? (
                                        <div className="rounded-lg border border-teal-100 bg-teal-50/70 px-3 py-2 text-sm text-teal-900">
                                            <span className="font-semibold">Top owner:</span>{' '}
                                            {isMixedReport
                                                ? `${topOwner.owner} (${asNumber(topOwner.payments_count ?? topOwner.deals)} successful payments)`
                                                : `${topOwner.owner} (${asNumber(topOwner.payments_count ?? topOwner.deals)} successful payments, ${formatCurrency(topOwner.revenue, resolvedReportCurrency)})`}
                                        </div>
                                    ) : null}
                                    <OwnerPerformanceTable
                                        rows={ownerRows}
                                        totals={ownerTotals}
                                        currency={resolvedReportCurrency}
                                        isMixed={isMixedReport}
                                        currencyMode={reportingCurrency.displayMode}
                                        targetCurrency={normalizedCurrency}
                                    />
                                </div>
                            </ReportPanel>
                        </div>
                    </section>
                </>
            ) : null}

            <ReportPanel title="Profile Engagement" subtitle="WordPress profile performance for the selected market.">
                {!platformFilter ? (
                    <InsightEmptyState
                        title="Select a market"
                        message="Profile engagement analytics are market-specific. Choose a market above to load the ranking table and contact mix."
                    />
                ) : engagementLoading ? (
                    <p className="text-sm text-slate-500">Loading profile engagement analytics...</p>
                ) : engagementError ? (
                    <InsightEmptyState
                        title="Profile engagement unavailable"
                        message={engagementError?.response?.data?.message || 'The engagement report could not be loaded for this market right now.'}
                    />
                ) : (
                    <div className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                            <MetricCard
                                label="Total Views"
                                value={asNumber(engagementPlatformTotals.profile_view?.total).toLocaleString()}
                                meta={`${asNumber(engagementPlatformTotals.profile_view?.delta_percent).toFixed(1)}% vs previous`}
                                tone="accent"
                            />
                            <MetricCard
                                label="Unique Visitors"
                                value={asNumber(engagementPlatformTotals.unique_visitors?.total).toLocaleString()}
                                meta={`${asNumber(engagementPlatformTotals.unique_visitors?.delta_percent).toFixed(1)}% vs previous`}
                                tone="default"
                            />
                            <MetricCard
                                label="Contact Actions"
                                value={asNumber(engagementPlatformTotals.contact_actions?.total).toLocaleString()}
                                meta={`${asNumber(engagementPlatformTotals.contact_actions?.delta_percent).toFixed(1)}% vs previous`}
                                tone="success"
                            />
                            <MetricCard
                                label="Contact Rate"
                                value={formatPercent(engagementPlatformTotals.contact_rate_percent?.value)}
                                meta={`${asNumber(engagementPlatformTotals.contact_rate_percent?.delta_pp).toFixed(1)}pp vs previous`}
                                tone="warning"
                            />
                        </div>

                        <div className="grid gap-4 xl:grid-cols-12">
                            <div className="space-y-4 xl:col-span-4">
                                <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-4">
                                    <p className="text-sm font-semibold text-slate-800">Contact method mix</p>
                                    <p className="mt-1 text-sm text-slate-500">Which channels dominate contact intent for this market.</p>
                                    <div className="mt-4">
                                        {engagementContactMix.length > 0
                                            ? <BarList rows={engagementContactMix} colorClass="bg-emerald-600" />
                                            : <InsightEmptyState title="No contact mix yet" message="Contact actions have not been tracked in this reporting window." />}
                                    </div>
                                </div>

                                <div className="rounded-lg border border-teal-100 bg-teal-50/70 px-4 py-3 text-sm text-teal-900">
                                    {engagementTopProfile ? (
                                        <>
                                            <span className="font-semibold">Top signal:</span>{' '}
                                            {engagementTopProfile.name} is leading at {formatPercent(engagementTopProfile.contact_rate_percent)} with {asNumber(engagementTopProfile.contact_actions_total).toLocaleString()} contact actions.
                                        </>
                                    ) : (
                                        <>Top profile insight will appear once the ranking response returns profiles.</>
                                    )}
                                </div>
                            </div>

                            <div className="xl:col-span-8">
                                <div className="overflow-x-auto rounded-lg border border-slate-200">
                                    <table className="min-w-full divide-y divide-slate-200 text-sm">
                                        <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            <tr>
                                                <th className="px-3 py-2">Profile</th>
                                                <th className="px-3 py-2">Assigned agent</th>
                                                <th className="px-3 py-2">Plan</th>
                                                <th className="px-3 py-2">
                                                    <button type="button" onClick={() => toggleEngagementSort('profile_view')} className="font-semibold text-slate-500 hover:text-slate-700">
                                                        Views
                                                    </button>
                                                </th>
                                                <th className="px-3 py-2">Unique</th>
                                                <th className="px-3 py-2">
                                                    <button type="button" onClick={() => toggleEngagementSort('contact_total')} className="font-semibold text-slate-500 hover:text-slate-700">
                                                        Contacts
                                                    </button>
                                                </th>
                                                <th className="px-3 py-2">
                                                    <button type="button" onClick={() => toggleEngagementSort('contact_rate')} className="font-semibold text-slate-500 hover:text-slate-700">
                                                        Rate
                                                    </button>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {engagementProfiles.map((profile) => (
                                                <tr key={profile.post_id} className={profile.crm_client_id ? 'cursor-pointer transition hover:bg-slate-50' : ''} onClick={() => profile.crm_client_id && navigate(`/clients/${profile.crm_client_id}?tab=analytics`)}>
                                                    <td className="px-3 py-3">
                                                        <p className="font-semibold text-slate-800">{profile.name}</p>
                                                        <p className="text-xs text-slate-500">{profile.subscription_status || 'Unknown status'}</p>
                                                    </td>
                                                    <td className="px-3 py-3 text-slate-600">{profile.assigned_agent_name || 'Unassigned'}</td>
                                                    <td className="px-3 py-3 text-slate-600">{profile.subscription_tier || 'No plan'}</td>
                                                    <td className="px-3 py-3 font-semibold text-slate-800">{asNumber(profile.totals?.profile_view?.total).toLocaleString()}</td>
                                                    <td className="px-3 py-3 text-slate-600">{asNumber(profile.totals?.profile_view?.unique).toLocaleString()}</td>
                                                    <td className="px-3 py-3 font-semibold text-slate-800">{asNumber(profile.contact_actions_total).toLocaleString()}</td>
                                                    <td className="px-3 py-3 font-semibold text-teal-700">{formatPercent(profile.contact_rate_percent)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>

                                <div className="mt-3 flex items-center justify-between gap-3 text-sm text-slate-600">
                                    <p>
                                        Page {asNumber(engagementData?.page)} of {Math.max(1, asNumber(engagementData?.total_pages))}
                                    </p>
                                    <div className="flex gap-2">
                                        <button
                                            type="button"
                                            onClick={() => setEngagementPage((current) => Math.max(1, current - 1))}
                                            disabled={asNumber(engagementData?.page) <= 1}
                                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Prev
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setEngagementPage((current) => Math.min(Math.max(1, asNumber(engagementData?.total_pages)), current + 1))}
                                            disabled={asNumber(engagementData?.page) >= Math.max(1, asNumber(engagementData?.total_pages))}
                                            className="rounded-md border border-slate-300 bg-white px-3 py-1.5 font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            Next
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </ReportPanel>
        </div>
    );
}
