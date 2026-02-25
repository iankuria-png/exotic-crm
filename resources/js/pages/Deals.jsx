import React, { useEffect, useMemo, useState } from 'react';
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
    const [renewReason, setRenewReason] = useState('Renewed from subscriptions page');
    const [renewDays, setRenewDays] = useState('30');
    const [paymentMethod, setPaymentMethod] = useState('manual');
    const [paymentReference, setPaymentReference] = useState('');
    const [approvedBy, setApprovedBy] = useState('');
    const [notifyClient, setNotifyClient] = useState(false);
    const [notificationTemplateId, setNotificationTemplateId] = useState('');
    const [notificationMessage, setNotificationMessage] = useState('');
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

    const selectedDeal = dialog.deal;
    const selectedClientId = selectedDeal?.client?.id || selectedDeal?.client_id || null;

    const { data: selectedClientData, isLoading: selectedClientLoading } = useQuery({
        queryKey: ['deal-dialog-client', selectedClientId],
        queryFn: () => api.get(`/crm/clients/${selectedClientId}`).then((response) => response.data),
        enabled: Boolean(selectedClientId),
    });

    const { data: templatesData } = useQuery({
        queryKey: ['settings-templates', 'deals'],
        queryFn: () => api.get('/crm/settings/templates').then((response) => response.data),
        enabled: dialog.type === 'deactivate',
    });

    const activateMutation = useMutation({
        mutationFn: ({ dealId, activationReason, selectedPaymentMethod, referenceValue, approvedByValue }) =>
            api.post(`/crm/deals/${dealId}/activate`, {
                reason: activationReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { approved_by: approvedByValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setActivateReason('Activated from subscriptions page');
            setPaymentMethod('manual');
            setPaymentReference('');
            setApprovedBy('');
            toast.success('Subscription activated successfully.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription activation failed.');
        },
    });

    const deactivateMutation = useMutation({
        mutationFn: ({ dealId, deactivateReason, shouldNotify, templateId, message }) =>
            api.post(`/crm/deals/${dealId}/deactivate`, {
                reason: deactivateReason,
                notify_client: Boolean(shouldNotify),
                notification_template_id: templateId || null,
                notification_message: message || null,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setReason('Deactivated from subscriptions page');
            setNotifyClient(false);
            setNotificationTemplateId('');
            setNotificationMessage('');
            toast.success('Subscription deactivated.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription deactivation failed.');
        },
    });

    const extendMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, extensionReason, selectedPaymentMethod, referenceValue, approvedByValue }) =>
            api.post(`/crm/deals/${dealId}/extend`, {
                additional_days: additionalDays,
                reason: extensionReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { approved_by: approvedByValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setExtendDays('7');
            setExtendReason('Extended from subscriptions page');
            setPaymentMethod('manual');
            setPaymentReference('');
            setApprovedBy('');
            toast.success('Subscription extension saved.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription extension failed.');
        },
    });

    const renewMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, renewalReason, selectedPaymentMethod, referenceValue, approvedByValue }) =>
            api.post(`/crm/deals/${dealId}/renew`, {
                additional_days: additionalDays,
                reason: renewalReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { approved_by: approvedByValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setRenewDays('30');
            setRenewReason('Renewed from subscriptions page');
            setPaymentMethod('manual');
            setPaymentReference('');
            setApprovedBy('');
            toast.success('Subscription renewed successfully.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription renewal failed.');
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
                        payment_method: 'free_trial',
                        approved_by: 'Bulk activation',
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
                expired: Number(data.summary.expired_deals || 0),
            };
        }

        return { active: 0, modernActive: 0, legacyActive: 0, pending: 0, risk: 0, expired: 0 };
    }, [data?.summary]);

    const activeMetric = useMemo(() => {
        if (bucket === 'active') return 'warehouse';
        if (bucket === 'risk') return 'risk';
        if (bucket === 'pending') return 'pipeline';
        if (bucket === 'expired') return 'expired';
        return '';
    }, [bucket]);

    const applyMetricFilter = (metricKey) => {
        const metricBucketMap = {
            warehouse: 'active',
            risk: 'risk',
            pipeline: 'pending',
            expired: 'expired',
        };

        const nextBucket = metricBucketMap[metricKey] || 'all';
        setBucket((current) => (current === nextBucket ? 'all' : nextBucket));
        setStatusFilter('');
        setPage(1);
    };

    const selectedClientPhone = selectedClientData?.phone_normalized || selectedDeal?.client?.phone_normalized || '';

    const smsTemplates = useMemo(() => {
        const templates = templatesData?.templates || templatesData?.data || [];
        return templates.filter((template) => template.channel === 'sms' && template.status === 'active');
    }, [templatesData]);

    const openDialog = (type, deal, event) => {
        event.stopPropagation();
        setDialog({ type, deal });
        setPaymentMethod('manual');
        setPaymentReference('');
        setApprovedBy('');
        if (type === 'activate') {
            setActivateReason('Activated from subscriptions page');
        }
        if (type === 'extend') {
            setExtendReason('Extended from subscriptions page');
            setExtendDays('7');
        }
        if (type === 'renew') {
            setRenewReason('Renewed from subscriptions page');
            setRenewDays('30');
        }
        if (type === 'deactivate') {
            setReason('Deactivated from subscriptions page');
            setNotifyClient(false);
            setNotificationTemplateId('');
            setNotificationMessage('');
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
            render: (row) => {
                const value = row.plan_type || row.inferred_plan_type || null;
                if (!value) {
                    return <span className="text-sm text-slate-400">—</span>;
                }

                return (
                    <span className="inline-flex items-center gap-1 text-sm text-slate-700">
                        <span className="capitalize">{value}</span>
                        {!row.plan_type && row.inferred_plan_type ? (
                            <span className="inline-flex items-center rounded-sm bg-slate-100 px-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600 ring-1 ring-inset ring-slate-200">
                                Legacy
                            </span>
                        ) : null}
                    </span>
                );
            },
        },
        {
            key: 'product',
            label: 'Product',
            render: (row) => <span className="text-sm text-slate-700">{row.product?.name || row.inferred_product_name || '—'}</span>,
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
                    {row.is_virtual ? (
                        <span className="text-[11px] text-slate-500">Legacy record</span>
                    ) : null}

                    {!row.is_virtual && row.status === 'pending' ? (
                        <button
                            onClick={(event) => openDialog('activate', row, event)}
                            className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800"
                        >
                            Activate
                        </button>
                    ) : null}

                    {!row.is_virtual && row.status === 'active' ? (
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

                    {!row.is_virtual && !['pending', 'active'].includes(row.status) ? (
                        <>
                            <button
                                onClick={(event) => openDialog('renew', row, event)}
                                className="rounded-md border border-teal-200 bg-teal-50 px-2.5 py-1 text-xs font-semibold text-teal-700 transition hover:bg-teal-100"
                            >
                                Renew
                            </button>
                            <button
                                onClick={(event) => openDialog('delete', row, event)}
                                className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700 transition hover:bg-rose-100"
                            >
                                Delete
                            </button>
                        </>
                    ) : null}

                </div>
            ),
        },
    ];

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

    useEffect(() => {
        if (dialog.type === 'extend' && !['manual', 'free_trial'].includes(paymentMethod)) {
            setPaymentMethod('manual');
        }
    }, [dialog.type, paymentMethod]);

    const needsPaymentVerification = ['activate', 'extend', 'renew'].includes(dialog.type || '');
    const requiresPaymentReference = needsPaymentVerification && paymentMethod === 'manual';
    const requiresApprovedBy = needsPaymentVerification && paymentMethod === 'free_trial';
    const paymentReady = !needsPaymentVerification
        || ((!requiresPaymentReference || paymentReference.trim() !== '')
            && (!requiresApprovedBy || approvedBy.trim() !== ''));

    return (
        <div className="space-y-4">
            <PageHeader
                title="Subscriptions"
                subtitle={data?.targets?.total ? `${summary.active.toLocaleString()} active records out of ${data.targets.total.toLocaleString()} total targets in scope` : 'Subscription activation and lifecycle management'}
            />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard
                    label="Active Warehouse"
                    value={`${summary.active.toLocaleString()} / ${data?.targets?.total?.toLocaleString() || 0}`}
                    meta={`${summary.modernActive} Modern + ${summary.legacyActive} Legacy`}
                    tone="success"
                    onClick={() => applyMetricFilter('warehouse')}
                    active={activeMetric === 'warehouse'}
                />
                <MetricCard
                    label="Immediate Risk"
                    value={summary.risk.toLocaleString()}
                    meta="Expiries in next 72 hours"
                    tone="warning"
                    onClick={() => applyMetricFilter('risk')}
                    active={activeMetric === 'risk'}
                />
                <MetricCard
                    label="Renewal Pipeline"
                    value={summary.pending.toLocaleString()}
                    meta="Expiries in 4-14 days"
                    tone="accent"
                    onClick={() => applyMetricFilter('pipeline')}
                    active={activeMetric === 'pipeline'}
                />
                <MetricCard
                    label="Recently Expired"
                    value={summary.expired.toLocaleString()}
                    meta="Expired in last 14 days"
                    tone="danger"
                    onClick={() => applyMetricFilter('expired')}
                    active={activeMetric === 'expired'}
                />
            </section>

            <p className="px-1 text-xs text-slate-500">Click a metric card to filter this table. Click it again to clear.</p>

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
                        <option value="stable">Stable ({'>'}14d)</option>
                        <option value="expired">Recently Expired</option>
                        <option value="lapsed">Lapsed (Legacy)</option>
                        <option value="paused">Paused Reminders</option>
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
                        <option value="renewed">Renewed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>

                    {(search || statusFilter || bucket !== 'all') ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setBucket('all');
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
                                            : dialog.type === 'renew'
                                                ? 'Renew Subscription'
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
                            {['activate', 'extend', 'renew'].includes(dialog.type || '') ? (
                                <div className="space-y-3 rounded-md border border-slate-200 bg-slate-50 p-3">
                                    <p className="text-sm font-semibold text-slate-800">Payment Method</p>
                                    <div className="grid gap-2 sm:grid-cols-2">
                                        {(dialog.type === 'extend'
                                            ? ['manual', 'free_trial']
                                            : ['manual', 'stk', 'link', 'free_trial']
                                        ).map((method) => (
                                            <button
                                                key={method}
                                                type="button"
                                                onClick={() => setPaymentMethod(method)}
                                                className={`rounded-md border px-3 py-2 text-xs font-semibold uppercase tracking-wide transition ${
                                                    paymentMethod === method
                                                        ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                        : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'
                                                }`}
                                            >
                                                {method === 'manual'
                                                    ? 'Manual Payment'
                                                    : method === 'stk'
                                                        ? 'STK Push'
                                                        : method === 'link'
                                                            ? 'Payment Link'
                                                            : 'Free Trial'}
                                            </button>
                                        ))}
                                    </div>

                                    {paymentMethod === 'manual' ? (
                                        <div>
                                            <label htmlFor="payment-reference" className="mb-1 block text-sm font-medium text-slate-700">
                                                MPESA / Transaction Reference
                                            </label>
                                            <input
                                                id="payment-reference"
                                                type="text"
                                                value={paymentReference}
                                                onChange={(event) => setPaymentReference(event.target.value)}
                                                className="crm-input"
                                                placeholder="e.g. MPESA123ABC"
                                            />
                                        </div>
                                    ) : null}

                                    {paymentMethod === 'free_trial' ? (
                                        <div>
                                            <label htmlFor="approved-by" className="mb-1 block text-sm font-medium text-slate-700">
                                                Approved By
                                            </label>
                                            <input
                                                id="approved-by"
                                                type="text"
                                                value={approvedBy}
                                                onChange={(event) => setApprovedBy(event.target.value)}
                                                className="crm-input"
                                                placeholder="Manager or approver name"
                                            />
                                        </div>
                                    ) : null}

                                    {(paymentMethod === 'stk' || paymentMethod === 'link') ? (
                                        <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                            {paymentMethod === 'stk'
                                                ? 'An STK push will be sent to the client phone. Subscription will activate after payment confirmation.'
                                                : 'A payment link will be sent to the client phone. Subscription will activate after payment confirmation.'}
                                            <span className="mt-1 block crm-mono text-[11px] text-slate-500">
                                                Target phone: {selectedClientLoading ? 'Loading...' : (selectedClientPhone || 'Unavailable')}
                                            </span>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

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

                            {dialog.type === 'renew' ? (
                                <>
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="renew-days">Additional days</label>
                                    <input
                                        id="renew-days"
                                        type="number"
                                        min={1}
                                        value={renewDays}
                                        onChange={(event) => setRenewDays(event.target.value)}
                                        className="crm-input"
                                    />

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="renew-reason">Reason</label>
                                    <textarea
                                        id="renew-reason"
                                        rows={3}
                                        value={renewReason}
                                        onChange={(event) => setRenewReason(event.target.value)}
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

                                    <div className="space-y-2 rounded-md border border-slate-200 bg-slate-50 p-3">
                                        <label className="flex items-center gap-2 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={notifyClient}
                                                onChange={(event) => setNotifyClient(event.target.checked)}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            Notify client via SMS
                                        </label>

                                        {notifyClient ? (
                                            <>
                                                <select
                                                    value={notificationTemplateId}
                                                    onChange={(event) => setNotificationTemplateId(event.target.value)}
                                                    className="crm-select"
                                                >
                                                    <option value="">Choose SMS template (optional)</option>
                                                    {smsTemplates.map((template) => (
                                                        <option key={template.id} value={template.id}>
                                                            {template.title}
                                                        </option>
                                                    ))}
                                                </select>

                                                <textarea
                                                    rows={3}
                                                    value={notificationMessage}
                                                    onChange={(event) => setNotificationMessage(event.target.value)}
                                                    className="crm-input"
                                                    placeholder="Optional custom SMS message override"
                                                />
                                            </>
                                        ) : null}
                                    </div>
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
                                        selectedPaymentMethod: paymentMethod,
                                        referenceValue: paymentReference.trim(),
                                        approvedByValue: approvedBy.trim(),
                                    })}
                                    disabled={!activateReason.trim() || !paymentReady || activateMutation.isPending}
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
                                        selectedPaymentMethod: paymentMethod,
                                        referenceValue: paymentReference.trim(),
                                        approvedByValue: approvedBy.trim(),
                                    })}
                                    disabled={!Number.isInteger(Number(extendDays)) || Number(extendDays) < 1 || !extendReason.trim() || !paymentReady || extendMutation.isPending}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {extendMutation.isPending ? 'Extending...' : 'Confirm extension'}
                                </button>
                            ) : null}

                            {dialog.type === 'renew' ? (
                                <button
                                    type="button"
                                    onClick={() => renewMutation.mutate({
                                        dealId: selectedDeal.id,
                                        additionalDays: Number(renewDays),
                                        renewalReason: renewReason,
                                        selectedPaymentMethod: paymentMethod,
                                        referenceValue: paymentReference.trim(),
                                        approvedByValue: approvedBy.trim(),
                                    })}
                                    disabled={!Number.isInteger(Number(renewDays)) || Number(renewDays) < 1 || !renewReason.trim() || !paymentReady || renewMutation.isPending}
                                    className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {renewMutation.isPending ? 'Renewing...' : 'Confirm renewal'}
                                </button>
                            ) : null}

                            {dialog.type === 'deactivate' ? (
                                <button
                                    type="button"
                                    onClick={() => deactivateMutation.mutate({
                                        dealId: selectedDeal.id,
                                        deactivateReason: reason,
                                        shouldNotify: notifyClient,
                                        templateId: notificationTemplateId ? Number(notificationTemplateId) : null,
                                        message: notificationMessage.trim(),
                                    })}
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
