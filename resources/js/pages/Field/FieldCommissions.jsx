import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import PageHeader from '../../components/PageHeader';
import MetricCard from '../../components/MetricCard';
import DataTable from '../../components/DataTable';
import api from '../../services/api';

function money(value, currency = 'KES') {
    return new Intl.NumberFormat('en-KE', {
        style: 'currency',
        currency,
        maximumFractionDigits: 2,
    }).format(Number(value || 0));
}

function statusClasses(status) {
    if (status === 'paid') return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    if (status === 'void') return 'bg-slate-100 text-slate-600 ring-slate-200';
    return 'bg-amber-50 text-amber-700 ring-amber-200';
}

export default function FieldCommissions() {
    const [page, setPage] = useState(1);
    const query = useQuery({
        queryKey: ['field-commissions', page],
        queryFn: () => api.get('/crm/field/commissions', {
            params: { page, per_page: 25 },
        }).then((response) => response.data),
        keepPreviousData: true,
    });

    const summary = query.data?.summary || {};
    const paginator = query.data?.commissions || query.data?.data || {};
    const meta = paginator.meta || paginator;
    const rows = paginator.data || [];
    const currency = summary.currency || rows[0]?.currency || 'KES';

    const columns = [
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.client?.name || row.client?.phone_normalized || 'Client'}</p>
                    <p className="text-xs text-slate-500">{row.platform?.name || 'Market'}</p>
                </div>
            ),
        },
        {
            key: 'type',
            label: 'Type',
            render: (row) => <span className="text-sm capitalize text-slate-700">{String(row.type || '').replace('_', ' ')}</span>,
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => <span className="text-sm font-semibold text-slate-900">{money(row.amount, row.currency || currency)}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className={`inline-flex rounded-md px-2.5 py-0.5 text-xs font-medium capitalize ring-1 ring-inset ${statusClasses(row.status)}`}>
                    {row.status || 'earned'}
                </span>
            ),
        },
        {
            key: 'earned_at',
            label: 'Earned',
            render: (row) => <span className="text-xs text-slate-500">{row.earned_at ? new Date(row.earned_at).toLocaleString() : '-'}</span>,
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader title="My Commissions" subtitle="Track field activation and renewal commission." />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Earned" value={money(summary.earned || summary.accrued || 0, currency)} meta="awaiting payout" tone="warning" />
                <MetricCard label="Paid" value={money(summary.paid || 0, currency)} meta="settled" tone="success" />
                <MetricCard label="This Month" value={money(summary.this_month || 0, currency)} meta="current period" tone="accent" />
                <MetricCard label="Count" value={Number(meta.total || 0).toLocaleString()} meta="commission records" tone="default" />
            </section>

            <section className="crm-surface overflow-hidden">
                <DataTable
                    columns={columns}
                    data={rows}
                    isLoading={query.isLoading}
                    emptyMessage="No commissions have been recorded yet."
                    pagination={meta}
                    onPageChange={setPage}
                />
            </section>
        </div>
    );
}
