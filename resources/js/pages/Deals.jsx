import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';

function formatCurrency(amount, currency = 'KES') {
    return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

export default function Deals() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const [searchParams] = useSearchParams();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(searchParams.get('status') || '');

    const [dialog, setDialog] = useState({ type: null, deal: null });
    const [reason, setReason] = useState('Deactivated from Deals page');
    const [extendDays, setExtendDays] = useState('7');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [bulkFeedback, setBulkFeedback] = useState(null);

    const { data, isLoading } = useQuery({
        queryKey: ['deals', page, search, statusFilter],
        queryFn: () =>
            api.get('/crm/deals', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                },
            }).then((r) => r.data),
    });

    const activateMutation = useMutation({
        mutationFn: (dealId) => api.post(`/crm/deals/${dealId}/activate`).then((r) => r.data),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['deals'] }),
    });

    const deactivateMutation = useMutation({
        mutationFn: ({ dealId, deactivateReason }) =>
            api.post(`/crm/deals/${dealId}/deactivate`, { reason: deactivateReason }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            setDialog({ type: null, deal: null });
            setReason('Deactivated from Deals page');
        },
    });

    const extendMutation = useMutation({
        mutationFn: ({ dealId, additionalDays }) =>
            api.post(`/crm/deals/${dealId}/extend`, { additional_days: additionalDays }).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            setDialog({ type: null, deal: null });
            setExtendDays('7');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (dealId) => api.delete(`/crm/deals/${dealId}`).then((r) => r.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            setDialog({ type: null, deal: null });
        },
    });

    const bulkActivateMutation = useMutation({
        mutationFn: async (rowsSelection) => {
            const targets = rowsSelection.filter((row) => row.status === 'pending');
            const skipped = rowsSelection.length - targets.length;

            const results = await Promise.allSettled(
                targets.map((row) => api.post(`/crm/deals/${row.id}/activate`)),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { total: rowsSelection.length, success, failed, skipped };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            setClearSelectionKey((value) => value + 1);
            setBulkFeedback({
                tone: result.failed > 0 ? 'warning' : 'success',
                text: `Bulk activate processed ${result.success}/${result.total}${result.skipped ? ` (${result.skipped} skipped)` : ''}${result.failed ? ` (${result.failed} failed)` : ''}.`,
            });
        },
    });

    const handleSearch = (e) => {
        e.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.data || [];
    const summary = useMemo(() => {
        const active = rows.filter((row) => row.status === 'active').length;
        const pending = rows.filter((row) => row.status === 'pending').length;
        const monthRevenue = rows
            .filter((row) => ['active', 'paid', 'pending'].includes(row.status))
            .reduce((sum, row) => sum + Number(row.amount || 0), 0);

        return { active, pending, monthRevenue };
    }, [rows]);

    const openDialog = (type, deal, event) => {
        event.stopPropagation();
        setDialog({ type, deal });
    };

    const columns = [
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.client?.name || 'Unknown'}</p>
                    <p className="crm-mono text-xs text-slate-500">{row.client?.phone_normalized || ''}</p>
                </div>
            ),
        },
        {
            key: 'plan_type',
            label: 'Plan',
            render: (row) => <span className="capitalize text-sm text-slate-700">{row.plan_type}</span>,
        },
        {
            key: 'product',
            label: 'Product',
            render: (row) => <span className="text-sm text-slate-700">{row.product?.name || '—'}</span>,
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => <span className="text-sm font-semibold text-slate-900">{formatCurrency(row.amount, row.currency || 'KES')}</span>,
        },
        {
            key: 'duration',
            label: 'Duration',
            render: (row) => <span className="text-sm capitalize text-slate-700">{row.duration}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'expires_at',
            label: 'Expires',
            render: (row) => {
                if (!row.expires_at) {
                    return <span className="text-xs text-slate-400">—</span>;
                }

                const date = new Date(row.expires_at);
                const isExpired = date < new Date();

                return (
                    <span className={`text-xs font-medium ${isExpired ? 'text-rose-700' : 'text-slate-600'}`}>
                        {date.toLocaleDateString()}
                    </span>
                );
            },
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex items-center gap-1.5">
                    {row.status === 'pending' ? (
                        <button
                            onClick={(event) => {
                                event.stopPropagation();
                                activateMutation.mutate(row.id);
                            }}
                            className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800"
                        >
                            Activate
                        </button>
                    ) : null}

                    {row.status === 'active' ? (
                        <>
                            <button
                                onClick={(event) => openDialog('extend', row, event)}
                                className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                            >
                                Extend
                            </button>
                            <button
                                onClick={(event) => openDialog('deactivate', row, event)}
                                className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                            >
                                Deactivate
                            </button>
                        </>
                    ) : (
                        <button
                            onClick={(event) => openDialog('delete', row, event)}
                            className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                        >
                            Delete
                        </button>
                    )}
                </div>
            ),
        },
    ];

    const selectedDeal = dialog.deal;
    const bulkActions = [
        {
            key: 'bulk-activate',
            label: 'Activate pending selected',
            loadingLabel: 'Activating...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkActivateMutation.mutateAsync(rowsSelection);
            },
        },
        {
            key: 'bulk-open-first',
            label: 'Open first selected',
            onClick: (rowsSelection) => {
                if (!rowsSelection.length) return;
                const first = rowsSelection[0];
                if (first.client?.id) {
                    navigate(`/clients/${first.client.id}`);
                }
            },
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Deals"
                subtitle={data?.total ? `${data.total.toLocaleString()} deals in current market` : 'Sales deals and activation management'}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Active Deals" value={summary.active.toLocaleString()} tone="success" />
                <MetricCard label="Pending Activation" value={summary.pending.toLocaleString()} tone="warning" />
                <MetricCard label="Pipeline Value" value={formatCurrency(summary.monthRevenue)} tone="accent" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(e) => setSearchInput(e.target.value)}
                                placeholder="Search by client name or phone..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>

                    <select
                        value={statusFilter}
                        onChange={(e) => {
                            setStatusFilter(e.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Statuses</option>
                        <option value="pending">Pending</option>
                        <option value="awaiting_payment">Awaiting payment</option>
                        <option value="paid">Paid</option>
                        <option value="active">Active</option>
                        <option value="expired">Expired</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    {(search || statusFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>
                {bulkFeedback ? (
                    <p className={`mt-2 text-xs font-medium ${bulkFeedback.tone === 'success' ? 'text-emerald-700' : 'text-amber-700'}`}>
                        {bulkFeedback.text}
                    </p>
                ) : null}
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={(row) => row.client && navigate(`/clients/${row.client.id}`)}
                isLoading={isLoading}
                emptyMessage="No deals found."
                compact
                selectable
                bulkActions={bulkActions}
                clearSelectionKey={clearSelectionKey}
            />

            {selectedDeal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setDialog({ type: null, deal: null })}>
                    <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">
                                    {dialog.type === 'extend' ? 'Extend Deal' : dialog.type === 'deactivate' ? 'Deactivate Deal' : 'Delete Deal'}
                                </h3>
                                <p className="crm-panel-subtitle">
                                    {selectedDeal.client?.name || 'Unknown client'} • {selectedDeal.product?.name || selectedDeal.plan_type}
                                </p>
                            </div>
                        </header>

                        <div className="space-y-4 p-4">
                            {dialog.type === 'extend' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="extend-days">Additional days</label>
                                    <input
                                        id="extend-days"
                                        type="number"
                                        min={1}
                                        value={extendDays}
                                        onChange={(e) => setExtendDays(e.target.value)}
                                        className="crm-input"
                                    />
                                </>
                            ) : null}

                            {dialog.type === 'deactivate' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-reason">Reason</label>
                                    <textarea
                                        id="deactivate-reason"
                                        rows={3}
                                        value={reason}
                                        onChange={(e) => setReason(e.target.value)}
                                        className="crm-input"
                                    />
                                </>
                            ) : null}

                            {dialog.type === 'delete' ? (
                                <p className="text-sm text-slate-600">
                                    This deal will be permanently removed. This action cannot be undone.
                                </p>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setDialog({ type: null, deal: null })}>
                                Cancel
                            </button>

                            {dialog.type === 'extend' ? (
                                <button
                                    type="button"
                                    onClick={() => extendMutation.mutate({ dealId: selectedDeal.id, additionalDays: Number(extendDays) })}
                                    disabled={!Number.isInteger(Number(extendDays)) || Number(extendDays) < 1 || extendMutation.isPending}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {extendMutation.isPending ? 'Extending...' : 'Confirm extension'}
                                </button>
                            ) : null}

                            {dialog.type === 'deactivate' ? (
                                <button
                                    type="button"
                                    onClick={() => deactivateMutation.mutate({ dealId: selectedDeal.id, deactivateReason: reason })}
                                    disabled={!reason.trim() || deactivateMutation.isPending}
                                    className="rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {deactivateMutation.isPending ? 'Deactivating...' : 'Confirm deactivation'}
                                </button>
                            ) : null}

                            {dialog.type === 'delete' ? (
                                <button
                                    type="button"
                                    onClick={() => deleteMutation.mutate(selectedDeal.id)}
                                    disabled={deleteMutation.isPending}
                                    className="crm-btn-danger disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {deleteMutation.isPending ? 'Deleting...' : 'Delete deal'}
                                </button>
                            ) : null}
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
