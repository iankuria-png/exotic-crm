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

function monthLabel(date) {
    return new Intl.DateTimeFormat('en-KE', { month: 'long' }).format(date);
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
    const { data: dashboardData, isLoading } = useQuery({
        queryKey: ['reports-dashboard'],
        queryFn: () => api.get('/crm/dashboard').then((response) => response.data),
    });

    const { data: leadsData } = useQuery({
        queryKey: ['reports-leads'],
        queryFn: () => api.get('/crm/leads', { params: { per_page: 200 } }).then((response) => response.data),
    });

    const { data: pipelineData } = useQuery({
        queryKey: ['reports-pipeline'],
        queryFn: () => api.get('/crm/leads/pipeline').then((response) => response.data),
    });

    const { data: paymentsData } = useQuery({
        queryKey: ['reports-payments'],
        queryFn: () => api.get('/crm/payments', { params: { per_page: 200, status: 'completed' } }).then((response) => response.data),
    });

    const { data: dealsData } = useQuery({
        queryKey: ['reports-deals'],
        queryFn: () => api.get('/crm/deals', { params: { per_page: 200 } }).then((response) => response.data),
    });

    const kpis = dashboardData?.kpis || {};
    const leads = leadsData?.data || [];
    const completedPayments = paymentsData?.data || [];
    const deals = dealsData?.data || [];

    const conversionRate = useMemo(() => {
        const converted = asNumber(pipelineData?.converted);
        const total = ['new', 'contacted', 'qualified', 'converted', 'lost']
            .reduce((sum, key) => sum + asNumber(pipelineData?.[key]), 0);
        return percent(converted, total);
    }, [pipelineData]);

    const renewalRate = useMemo(() => {
        const activeDeals = deals.filter((deal) => deal.status === 'active').length;
        const expiringSoon = asNumber(kpis.expiring_soon);
        return percent(activeDeals, activeDeals + expiringSoon);
    }, [deals, kpis.expiring_soon]);

    const revenueTrend = useMemo(() => {
        const map = new Map();

        completedPayments.forEach((payment) => {
            const date = new Date(payment.created_at);
            if (Number.isNaN(date.getTime())) return;

            const key = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
            const label = monthLabel(date);
            const current = map.get(key) || { label, value: 0 };
            current.value += asNumber(payment.amount);
            map.set(key, current);
        });

        return Array.from(map.entries())
            .sort((left, right) => right[0].localeCompare(left[0]))
            .slice(0, 6)
            .map(([, row]) => ({
                ...row,
                formattedValue: formatKes(row.value),
            }));
    }, [completedPayments]);

    const leadSources = useMemo(() => {
        const map = new Map();
        leads.forEach((lead) => {
            const key = lead.source || 'unknown';
            map.set(key, (map.get(key) || 0) + 1);
        });

        const total = leads.length || 1;
        return Array.from(map.entries())
            .map(([label, value]) => ({
                label: label.charAt(0).toUpperCase() + label.slice(1),
                value,
                formattedValue: `${value} (${percent(value, total)}%)`,
            }))
            .sort((left, right) => right.value - left.value)
            .slice(0, 6);
    }, [leads]);

    const packageRevenue = useMemo(() => {
        const map = new Map();
        deals.forEach((deal) => {
            const label = deal.product?.name || deal.plan_type || 'Unknown';
            const current = map.get(label) || 0;
            map.set(label, current + asNumber(deal.amount));
        });

        return Array.from(map.entries())
            .map(([label, value]) => ({
                label,
                value,
                formattedValue: formatKes(value),
            }))
            .sort((left, right) => right.value - left.value)
            .slice(0, 6);
    }, [deals]);

    const adminLeaderboard = useMemo(() => {
        const map = new Map();

        deals.forEach((deal) => {
            const owner = deal.assigned_agent?.name || 'Unassigned';
            const current = map.get(owner) || { label: owner, value: 0 };
            current.value += 1;
            map.set(owner, current);
        });

        return Array.from(map.values())
            .sort((left, right) => right.value - left.value)
            .slice(0, 6)
            .map((row) => ({
                ...row,
                formattedValue: `${row.value} deals`,
            }));
    }, [deals]);

    return (
        <div className="space-y-4">
            <PageHeader
                title="Reports & Analytics"
                subtitle="Track pipeline movement, payment quality, and renewal performance in one view."
                actions={(
                    <div className="flex gap-2">
                        <button type="button" className="crm-btn-secondary">Last 30 days</button>
                        <button type="button" className="crm-btn-secondary">Export PDF</button>
                    </div>
                )}
            />

            <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <MetricCard
                    label="Total Revenue"
                    value={formatKes(kpis.revenue_mtd)}
                    meta="confirmed this month"
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

                    <ReportPanel title="Lead Sources" subtitle="Where pipeline volume is coming from">
                        {leadSources.length > 0 ? <BarList rows={leadSources} colorClass="bg-cyan-600" /> : <p className="text-sm text-slate-500">No lead sources available.</p>}
                    </ReportPanel>
                </div>

                <div className="space-y-4 xl:col-span-6">
                    <ReportPanel title="Revenue by Package" subtitle="Deal value grouped by product tier">
                        {packageRevenue.length > 0 ? <BarList rows={packageRevenue} colorClass="bg-emerald-600" /> : <p className="text-sm text-slate-500">No package revenue data.</p>}
                    </ReportPanel>

                    <ReportPanel title="Admin Performance" subtitle="Deals handled by owner">
                        {adminLeaderboard.length > 0 ? <BarList rows={adminLeaderboard} colorClass="bg-indigo-600" /> : <p className="text-sm text-slate-500">No ownership data found.</p>}
                    </ReportPanel>
                </div>
            </section>
        </div>
    );
}
