import React, { useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function formatKes(value) {
    return `KES ${asNumber(value).toLocaleString()}`;
}

function percent(value, total) {
    if (!total) return 0;
    return Math.round((value / total) * 100);
}

function BarList({ rows, colorClass = 'bg-teal-600' }) {
    const maxValue = rows.reduce((max, row) => Math.max(max, row.value), 0) || 1;

    return (
        <div className="space-y-3">
            {rows.map((row) => (
                <div key={row.label} className="grid grid-cols-[minmax(120px,1fr)_minmax(160px,2fr)_minmax(90px,auto)] items-center gap-3">
                    <p className="truncate text-sm font-medium text-slate-700">{row.label}</p>
                    <div className="h-3 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className={`h-full rounded-full ${colorClass}`}
                            style={{ width: `${Math.max(6, Math.round((row.value / maxValue) * 100))}%` }}
                        />
                    </div>
                    <p className="text-right text-sm font-semibold text-slate-800">{row.formattedValue}</p>
                </div>
            ))}
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
    const { data, isLoading } = useQuery({
        queryKey: ['reports-summary'],
        queryFn: () => api.get('/crm/reports/summary').then((response) => response.data),
    });

    const kpis = data?.kpis || {};
    const funnel = data?.lead_funnel || {};

    const conversionRate = kpis.conversion_rate ?? percent(asNumber(funnel.converted), Object.values(funnel).reduce((sum, value) => sum + asNumber(value), 0));
    const renewalRate = kpis.renewal_rate ?? 0;

    const revenueTrend = useMemo(
        () => (data?.revenue_trend || []).map((row) => ({
            label: row.label,
            value: asNumber(row.value),
            formattedValue: formatKes(row.value),
        })),
        [data?.revenue_trend],
    );

    const leadSources = useMemo(() => {
        const total = (data?.lead_sources || []).reduce((sum, row) => sum + asNumber(row.value), 0) || 1;
        return (data?.lead_sources || []).map((row) => ({
            label: row.source.charAt(0).toUpperCase() + row.source.slice(1),
            value: asNumber(row.value),
            formattedValue: `${row.value} (${percent(row.value, total)}%)`,
        }));
    }, [data?.lead_sources]);

    const packageRevenue = useMemo(
        () => (data?.package_revenue || []).map((row) => ({
            label: row.label,
            value: asNumber(row.value),
            formattedValue: formatKes(row.value),
        })),
        [data?.package_revenue],
    );

    const ownerLeaderboard = useMemo(
        () => (data?.owner_performance || []).map((row) => ({
            label: row.owner,
            value: asNumber(row.deals),
            formattedValue: `${row.deals} deals`,
        })),
        [data?.owner_performance],
    );

    const rangeLabel = data?.range
        ? `${new Date(data.range.from).toLocaleDateString()} - ${new Date(data.range.to).toLocaleDateString()}`
        : 'Server-backed reporting window';

    return (
        <div className="space-y-4">
            <PageHeader
                title="Reports & Analytics"
                subtitle={`Server-backed metrics for revenue, renewal health, lead funnel, and owner performance (${rangeLabel}).`}
            />

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Total Revenue"
                    value={formatKes(kpis.total_revenue)}
                    meta="selected reporting window"
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
                    <ReportPanel title="Revenue Trend" subtitle="Completed payments by month">
                        {isLoading ? <p className="text-sm text-slate-500">Loading revenue trend...</p> : (
                            revenueTrend.length > 0
                                ? <BarList rows={revenueTrend} colorClass="bg-teal-600" />
                                : <p className="text-sm text-slate-500">No completed payments found.</p>
                        )}
                    </ReportPanel>

                    <ReportPanel title="Lead Sources" subtitle="Pipeline origin by source">
                        {leadSources.length > 0 ? <BarList rows={leadSources} colorClass="bg-cyan-600" /> : <p className="text-sm text-slate-500">No lead source data available.</p>}
                    </ReportPanel>
                </div>

                <div className="space-y-4 xl:col-span-6">
                    <ReportPanel title="Revenue by Package" subtitle="Deal value grouped by package">
                        {packageRevenue.length > 0 ? <BarList rows={packageRevenue} colorClass="bg-emerald-600" /> : <p className="text-sm text-slate-500">No package revenue data.</p>}
                    </ReportPanel>

                    <ReportPanel title="Owner Performance" subtitle="Deals handled by owner">
                        {ownerLeaderboard.length > 0 ? <BarList rows={ownerLeaderboard} colorClass="bg-indigo-600" /> : <p className="text-sm text-slate-500">No ownership data found.</p>}
                    </ReportPanel>
                </div>
            </section>
        </div>
    );
}

