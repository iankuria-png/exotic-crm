import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import { useToast } from '../components/ToastProvider';

function formatCurrency(amount, currency = 'KES') {
    return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

export default function Deals() {
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const [searchParams] = useSearchParams();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(searchParams.get('status') || '');

    const [dialog, setDialog] = useState({ type: null, deal: null });
    const [activateReason, setActivateReason] = useState('Activated from subscriptions page');
    const [reason, setReason] = useState('Deactivated from subscriptions page');
    const [extendReason, setExtendReason] = useState('Extended from subscriptions page');
    const [extendDays, setExtendDays] = useState('7');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);

    const [bucket, setBucket] = useState('all');

    const { data, isLoading } = useQuery({
        queryKey: ['deals', page, search, statusFilter, bucket],
        queryFn: () =>
            api.get('/crm/deals', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    bucket,
                },
            }).then((response) => response.data),
    });

    const activateMutation = useMutation({
        mutationFn: ({ dealId, activationReason }) =>
            api.post(`/crm/deals/${dealId}/activate`, {
                reason: activationReason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setActivateReason('Activated from subscriptions page');
            toast.success('Subscription activated successfully.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription activation failed.');
        },
    });

    const deactivateMutation = useMutation({
        mutationFn: ({ dealId, deactivateReason }) =>
            api.post(`/crm/deals/${dealId}/deactivate`, {
                reason: deactivateReason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setReason('Deactivated from subscriptions page');
            toast.success('Subscription deactivated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription deactivation failed.');
        },
    });

    const extendMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, extensionReason }) =>
            api.post(`/crm/deals/${dealId}/extend`, {
                additional_days: additionalDays,
                reason: extensionReason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setExtendDays('7');
            setExtendReason('Extended from subscriptions page');
            toast.success('Subscription extension saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription extension failed.');
        },
    });

    const deleteMutation = useMutation({
        mutationFn: (dealId) => api.delete(`/crm/deals/${dealId}`).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            setDialog({ type: null, deal: null });
            toast.success('Subscription deleted.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription deletion failed.');
        },
    });

    const bulkActivateMutation = useMutation({
        mutationFn: async (rowsSelection) => {
            const targets = rowsSelection.filter((row) => row.status === 'pending');
            const skipped = rowsSelection.length - targets.length;

            const results = await Promise.allSettled(
                targets.map((row) =>
                    api.post(`/crm/deals/${row.id}/activate`, {
                        reason: 'Bulk activation from subscriptions page',
                    }),
                ),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { total: rowsSelection.length, success, failed, skipped };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Bulk activate completed with issues: ${result.success}/${result.total} succeeded.`);
                return;
            }
            toast.success(`Bulk activate processed ${result.success}/${result.total}.`);
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const rows = data?.targets?.data || [];
    const summary = useMemo(() => {
        if (data?.summary) {
            return {
                active: Number(data.summary.active_deals || 0),
                modernActive: Number(data.summary.modern_active_count || 0),
                legacyActive: Number((data.summary.active_deals || 0) - (data.summary.modern_active_count || 0)),
                pending: Number(data.summary.pending || 0),
                risk: Number(data.summary.risk || 0),
                monthRevenue: Number(data.summary.pipeline_value || 0),
                verifiedRevenue: Number(data.summary.verified_revenue || 0),
            };
        }

        return { active: 0, modernActive: 0, legacyActive: 0, pending: 0, risk: 0, monthRevenue: 0, verifiedRevenue: 0 };
    }, [data?.summary]);

    const openDialog = (type, deal, event) => {
        event.stopPropagation();
        setDialog({ type, deal });
        if (type === 'activate') {
            setActivateReason('Activated from subscriptions page');
        }
        if (type === 'extend') {
            setExtendReason('Extended from subscriptions page');
            setExtendDays('7');
        }
        if (type === 'deactivate') {
            setReason('Deactivated from subscriptions page');
        }
    };

    const columns = [
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div>
                    <div className="flex items-center gap-2">
                        <p className="text-sm font-semibold text-slate-900">{row.client?.name || 'Unknown'}</p>
                        {row.origin_type === 'modern' ? (
                            <span className="inline-flex items-center rounded-sm bg-blue-50 px-1 text-[10px] font-bold uppercase tracking-wider text-blue-600 ring-1 ring-inset ring-blue-600/20">Modern</span>
                        ) : (
                            <span className="inline-flex items-center rounded-sm bg-slate-50 px-1 text-[10px] font-bold uppercase tracking-wider text-slate-500 ring-1 ring-inset ring-slate-600/10">Legacy</span>
                        )}
                    </div>
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
            render: (row) => (
                <div className="flex flex-col items-start gap-1">
                    <StatusBadge status={row.status} />
                    {row.payment_status === 'verified' && (
                        <span className="flex items-center gap-0.5 text-[10px] font-medium text-teal-600">
                            <svg className="h-3 w-3" fill="currentColor" viewBox="0 0 20 20">
                                <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                            </svg>
                            Verified
                        </span>
                    )}
                </div>
            ),
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
                            onClick={(event) => openDialog('activate', row, event)}
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
                    ) : null}

                    {!['pending', 'active'].includes(row.status) ? (
                        <button
                            onClick={(event) => openDialog('delete', row, event)}
                            className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                        >
                            Delete
                        </button>
                    ) : null}
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
                title="Subscriptions"
                subtitle={data?.targets?.total ? `${summary.active.toLocaleString()} active records out of ${data.targets.total.toLocaleString()} total targets in scope` : 'Subscription activation and lifecycle management'}
            />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Active Warehouse" value={`${summary.active.toLocaleString()} / ${data?.targets?.total?.toLocaleString() || 0}`} meta={`${summary.modernActive} Modern + ${summary.legacyActive} Legacy`} tone="success" />
                <MetricCard label="Immediate Risk" value={summary.risk.toLocaleString()} meta="Expiries in next 72 hours" tone="warning" />
                <MetricCard label="Renewal Pipeline" value={summary.pending.toLocaleString()} meta="Expiries in 4-14 days" tone="accent" />
                <MetricCard label="Forecasted Revenue" value={formatCurrency(summary.monthRevenue)} meta="Verified + Pending Sum" tone="slate" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search by client name or phone..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" aria-label="Run subscription search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>

                    <select
                        value={bucket}
                        onChange={(event) => {
                            setBucket(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="all">Unified View (Non-Lapsed)</option>
                        <option value="active">Active Only</option>
                        <option value="risk">At Risk (0-3d)</option>
                        <option value="pending">Upcoming (4-14d)</option>
                        <option value="expired">Recently Expired</option>
                        <option value="lapsed">Lapsed (Legacy)</option>
                    </select>

                    <select
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">Legacy Status Filter</option>
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
            </section>

            <DataTable
                columns={columns}
                data={data?.targets?.data}
                pagination={data?.targets}
                onPageChange={setPage}
                onRowClick={(row) => row.client && navigate(`/clients/${row.client.id}`)}
                isLoading={isLoading}
                emptyMessage="No subscriptions found."
                compact
                selectable
                bulkActions={bulkActions}
                clearSelectionKey={clearSelectionKey}
            />

            {selectedDeal ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setDialog({ type: null, deal: null })}>
                    <div className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">
                                    {dialog.type === 'activate'
                                        ? 'Activate Subscription'
                                        : dialog.type === 'extend'
                                            ? 'Extend Subscription'
                                            : dialog.type === 'deactivate'
                                                ? 'Deactivate Subscription'
                                                : 'Delete Subscription'}
                                </h3>
                                <p className="crm-panel-subtitle">
                                    {selectedDeal.client?.name || 'Unknown client'} • {selectedDeal.product?.name || selectedDeal.plan_type}
                                </p>
                            </div>
                        </header>

                        <div className="space-y-4 p-4">
                            {dialog.type === 'activate' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="activate-reason">Reason</label>
                                    <textarea
                                        id="activate-reason"
                                        rows={3}
                                        value={activateReason}
                                        onChange={(event) => setActivateReason(event.target.value)}
                                        className="crm-input"
                                    />
                                </>
                            ) : null}

                            {dialog.type === 'extend' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="extend-days">Additional days</label>
                                    <input
                                        id="extend-days"
                                        type="number"
                                        min={1}
                                        value={extendDays}
                                        onChange={(event) => setExtendDays(event.target.value)}
                                        className="crm-input"
                                    />

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="extend-reason">Reason</label>
                                    <textarea
                                        id="extend-reason"
                                        rows={3}
                                        value={extendReason}
                                        onChange={(event) => setExtendReason(event.target.value)}
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
                                        onChange={(event) => setReason(event.target.value)}
                                        className="crm-input"
                                    />
                                </>
                            ) : null}

                            {dialog.type === 'delete' ? (
                                <p className="text-sm text-slate-600">
                                    This subscription will be permanently removed. This action cannot be undone.
                                </p>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" className="crm-btn-secondary" onClick={() => setDialog({ type: null, deal: null })}>
                                Cancel
                            </button>

                            {dialog.type === 'activate' ? (
                                <button
                                    type="button"
                                    onClick={() => activateMutation.mutate({
                                        dealId: selectedDeal.id,
                                        activationReason: activateReason.trim(),
                                    })}
                                    disabled={!activateReason.trim() || activateMutation.isPending}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {activateMutation.isPending ? 'Activating...' : 'Confirm activation'}
                                </button>
                            ) : null}

                            {dialog.type === 'extend' ? (
                                <button
                                    type="button"
                                    onClick={() => extendMutation.mutate({
                                        dealId: selectedDeal.id,
                                        additionalDays: Number(extendDays),
                                        extensionReason: extendReason,
                                    })}
                                    disabled={!Number.isInteger(Number(extendDays)) || Number(extendDays) < 1 || !extendReason.trim() || extendMutation.isPending}
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
                                    {deleteMutation.isPending ? 'Deleting...' : 'Delete subscription'}
                                </button>
                            ) : null}
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
