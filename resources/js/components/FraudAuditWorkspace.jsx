import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from './DataTable';
import StatusBadge from './StatusBadge';
import CurrencyAmount from './CurrencyAmount';
import ConfirmDialog from './ConfirmDialog';
import FraudAuditUploadDrawer from './FraudAuditUploadDrawer';
import { useToast } from './ToastProvider';

function formatDateTime(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString();
}

function rawCell(raw, keys) {
    if (!raw) return '';
    for (const key of keys) {
        if (raw[key] !== undefined && raw[key] !== null && String(raw[key]).trim() !== '') {
            return String(raw[key]);
        }
    }
    return '';
}

export default function FraudAuditWorkspace({ platformOptions, onOpenPayment }) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [uploadOpen, setUploadOpen] = useState(false);
    const [selectedBatchId, setSelectedBatchId] = useState(null);
    const [classificationFilter, setClassificationFilter] = useState('');
    const [reviewFilter, setReviewFilter] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [search, setSearch] = useState('');
    const [rowPage, setRowPage] = useState(1);
    const [transitionDialog, setTransitionDialog] = useState(null);
    const [reviewDialog, setReviewDialog] = useState(null);
    const [bulkDialog, setBulkDialog] = useState(null);
    const [linkDialog, setLinkDialog] = useState(null);
    const [detailRow, setDetailRow] = useState(null);
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [paymentId, setPaymentId] = useState('');

    // Debounce the search box into the query param.
    useEffect(() => {
        const timer = setTimeout(() => {
            setSearch(searchInput.trim());
            setRowPage(1);
        }, 350);
        return () => clearTimeout(timer);
    }, [searchInput]);

    const batchesQuery = useQuery({
        queryKey: ['reconcile-batches'],
        queryFn: () => api.get('/crm/payments/reconcile/batches').then((response) => response.data),
    });

    const batches = batchesQuery.data?.data || [];

    useEffect(() => {
        if (!selectedBatchId && batches.length > 0) {
            setSelectedBatchId(batches[0].id);
        }
    }, [batches, selectedBatchId]);

    const batchQuery = useQuery({
        queryKey: ['reconcile-batch', selectedBatchId, classificationFilter, reviewFilter, search, rowPage],
        queryFn: () => api.get(`/crm/payments/reconcile/batches/${selectedBatchId}`, {
            params: {
                page: rowPage,
                per_page: 50,
                ...(classificationFilter ? { classification: classificationFilter } : {}),
                ...(reviewFilter ? { review_status: reviewFilter } : {}),
                ...(search ? { search } : {}),
            },
        }).then((response) => response.data),
        enabled: Boolean(selectedBatchId),
        keepPreviousData: true,
    });

    const candidatesQuery = useQuery({
        queryKey: ['reconcile-candidates', linkDialog?.row?.id],
        queryFn: () => api.get(`/crm/payments/reconcile/rows/${linkDialog.row.id}/candidates`).then((response) => response.data),
        enabled: Boolean(linkDialog?.row?.id),
    });

    const selectedBatch = batchQuery.data?.batch || batches.find((batch) => batch.id === selectedBatchId);
    const summary = batchQuery.data?.summary || selectedBatch?.summary || {};
    const rows = batchQuery.data?.rows || [];
    const isClosed = selectedBatch?.status === 'closed';
    const amounts = summary.amounts || {};
    const summaryCurrency = summary.summary_currency || selectedBatch?.fallback_currency || null;
    const marketCount = (selectedBatch?.platform_ids || []).length || 1;

    const invalidateBatch = () => {
        queryClient.invalidateQueries({ queryKey: ['reconcile-batches'] });
        queryClient.invalidateQueries({ queryKey: ['reconcile-batch', selectedBatchId] });
    };

    const transitionMutation = useMutation({
        mutationFn: ({ batch, action, reason: transitionReason }) =>
            api.post(`/crm/payments/reconcile/batches/${batch.id}/${action}`, { reason: transitionReason }).then((response) => response.data),
        onSuccess: () => {
            invalidateBatch();
            setTransitionDialog(null);
            setReason('');
            toast.success('Batch status updated.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Batch status update failed.'),
    });

    const reviewMutation = useMutation({
        mutationFn: ({ row, status, reason: reviewReason, note: reviewNote }) =>
            api.post(`/crm/payments/reconcile/rows/${row.id}/review`, { status, reason: reviewReason, note: reviewNote }).then((response) => response.data),
        onSuccess: () => {
            invalidateBatch();
            setReviewDialog(null);
            setReason('');
            setNote('');
            toast.success('Review status updated.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Review update failed.'),
    });

    const bulkReviewMutation = useMutation({
        mutationFn: ({ rowIds, status, reason: bulkReason, note: bulkNote }) =>
            api.post(`/crm/payments/reconcile/batches/${selectedBatchId}/bulk-review`, {
                row_ids: rowIds,
                status,
                reason: bulkReason,
                note: bulkNote,
            }).then((response) => response.data),
        onSuccess: (data) => {
            invalidateBatch();
            bulkDialog?.clearSelection?.();
            setBulkDialog(null);
            setReason('');
            setNote('');
            toast.success(`${data.updated} row(s) updated.`);
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Bulk update failed.'),
    });

    const linkMutation = useMutation({
        mutationFn: ({ row, selectedPaymentId, reason: linkReason, note: linkNote }) =>
            api.post(`/crm/payments/reconcile/rows/${row.id}/link`, {
                payment_id: selectedPaymentId,
                reason: linkReason,
                note: linkNote,
            }).then((response) => response.data),
        onSuccess: () => {
            invalidateBatch();
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setLinkDialog(null);
            setReason('');
            setNote('');
            setPaymentId('');
            toast.success('Row linked to payment.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Link failed.'),
    });

    // Classification metric cards: count + amount-at-risk (when the batch is single-currency).
    const metrics = useMemo(() => ([
        { key: 'matched', label: 'Matched', count: summary.matched_rows || 0, amount: amounts.matched, tone: 'text-emerald-700' },
        { key: 'amount_mismatch', label: 'Amount mismatch', count: summary.mismatch_rows || 0, amount: amounts.mismatch, tone: 'text-amber-700' },
        { key: 'missing', label: 'Missing from CRM', count: summary.missing_rows || 0, amount: amounts.missing, tone: 'text-rose-700', emphasize: true },
        { key: 'unverifiable', label: 'Unverifiable', count: summary.unverifiable_rows || 0, amount: amounts.unverifiable, tone: 'text-slate-700' },
        { key: 'duplicate_in_file', label: 'Duplicate', count: summary.duplicate_rows || 0, amount: amounts.duplicate, tone: 'text-amber-700' },
    ]), [summary, amounts]);

    const rowDisplayCurrency = (row) => row.display_currency || summaryCurrency || row.external_currency || selectedBatch?.platform?.currency_code || 'KES';

    const columns = [
        {
            key: 'external',
            label: 'External record',
            render: (row) => (
                <div>
                    <p className="text-sm font-semibold text-slate-900">{row.external_name || '—'}</p>
                    <p className="mt-1 font-mono text-xs text-slate-500">{row.external_reference_raw || 'No reference'}</p>
                </div>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => (
                <CurrencyAmount scalarAmount={row.external_amount || 0} fallbackCurrency={rowDisplayCurrency(row)} className="text-sm font-semibold text-slate-800" />
            ),
        },
        {
            key: 'date',
            label: 'Date text',
            render: (row) => <span className="text-sm text-slate-600">{row.external_paid_at_text || '—'}</span>,
        },
        {
            key: 'classification',
            label: 'Classification',
            render: (row) => (
                <div className="space-y-1">
                    <StatusBadge status={row.classification} />
                    {row.flags?.amount_delta !== undefined ? (
                        <p className="text-[11px] text-amber-700">Δ {Number(row.flags.amount_delta).toLocaleString()}</p>
                    ) : null}
                    {row.flags?.name_mismatch ? <p className="text-[11px] text-amber-700">Name differs</p> : null}
                </div>
            ),
        },
        {
            key: 'crm',
            label: 'CRM match',
            render: (row) => (
                <div className="max-w-[220px]">
                    <p className="text-sm font-semibold text-slate-900">{row.matched_client?.name || '—'}</p>
                    <p className="mt-1 text-xs text-slate-500">
                        {row.matched_payment_id ? `Payment #${row.matched_payment_id}` : 'No payment linked'}
                    </p>
                    {row.matched_platform?.name ? (
                        <p className="mt-1 text-[11px] font-medium text-teal-700">{row.matched_platform.name}</p>
                    ) : null}
                    {row.confirmed_by ? <p className="mt-0.5 text-xs text-slate-500">Recorded by {row.confirmed_by.name}</p> : null}
                </div>
            ),
        },
        {
            key: 'source_attribution',
            label: 'Sheet attribution',
            render: (row) => {
                const activated = rawCell(row.raw_row, ['activated', 'Activated']);
                const who = rawCell(row.raw_row, ['who_activated', 'Who Activated']);
                return (
                    <div className="text-xs text-slate-600">
                        <p>{activated || '—'}</p>
                        {who ? <p className="mt-1 font-semibold text-slate-700">{who}</p> : null}
                    </div>
                );
            },
        },
        {
            key: 'review',
            label: 'Review',
            render: (row) => <StatusBadge status={row.review_status} />,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex flex-wrap gap-1.5">
                    <button type="button" onClick={() => setDetailRow(row)} className="crm-btn-secondary px-2 py-1 text-xs">
                        Details
                    </button>
                    <button type="button" disabled={isClosed} onClick={() => { setReviewDialog({ row, status: 'confirmed_fraud' }); setReason('Confirmed fraud discrepancy'); }} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">
                        Fraud
                    </button>
                    <button type="button" disabled={isClosed} onClick={() => { setReviewDialog({ row, status: 'cleared' }); setReason('Cleared fraud audit row'); }} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">
                        Clear
                    </button>
                    <button type="button" disabled={isClosed} onClick={() => { setLinkDialog({ row }); setPaymentId(row.matched_payment_id || ''); setReason('Link fraud audit row to CRM payment'); }} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">
                        Link
                    </button>
                    {row.matched_payment_id ? (
                        <button type="button" onClick={() => onOpenPayment?.(row.matched_payment_id)} className="crm-btn-secondary px-2 py-1 text-xs">
                            Open
                        </button>
                    ) : null}
                </div>
            ),
        },
    ];

    const bulkActions = [
        { key: 'reviewing', label: 'Mark reviewing', disabled: isClosed, onClick: (selected, { clearSelection }) => { setBulkDialog({ status: 'reviewing', rows: selected, clearSelection }); setReason('Bulk: reviewing'); } },
        { key: 'confirmed_fraud', label: 'Confirm fraud', disabled: isClosed, onClick: (selected, { clearSelection }) => { setBulkDialog({ status: 'confirmed_fraud', rows: selected, clearSelection }); setReason('Bulk: confirmed fraud'); } },
        { key: 'cleared', label: 'Clear', disabled: isClosed, onClick: (selected, { clearSelection }) => { setBulkDialog({ status: 'cleared', rows: selected, clearSelection }); setReason('Bulk: cleared'); } },
    ];

    return (
        <section className="space-y-4">
            <section className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900">Fraud audit</h2>
                        <p className="mt-1 text-xs text-slate-500">Cross-check external collection records against CRM payments across one or more markets.</p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <button type="button" onClick={() => setUploadOpen(true)} className="crm-btn-primary">Upload / paste sheet</button>
                        <button type="button" onClick={() => window.open('/api/crm/payments/reconcile/template', '_blank', 'noopener,noreferrer')} className="crm-btn-secondary">Download template</button>
                        {selectedBatch ? (
                            <button
                                type="button"
                                onClick={() => { setTransitionDialog({ batch: selectedBatch, action: isClosed ? 'reopen' : 'close' }); setReason(isClosed ? 'Reopen fraud audit batch' : 'Close fraud audit batch'); }}
                                className="crm-btn-secondary"
                            >
                                {isClosed ? 'Reopen batch' : 'Close batch'}
                            </button>
                        ) : null}
                    </div>
                </div>
            </section>

            <section className="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
                <aside className="rounded-xl border border-slate-200 bg-white shadow-sm">
                    <div className="border-b border-slate-100 px-4 py-3">
                        <h3 className="text-sm font-semibold text-slate-900">Batches</h3>
                    </div>
                    <div className="max-h-[520px] overflow-y-auto p-2">
                        {batchesQuery.isLoading ? <p className="p-3 text-sm text-slate-500">Loading batches...</p> : null}
                        {!batchesQuery.isLoading && batches.length === 0 ? <p className="p-3 text-sm text-slate-500">No fraud audit batches yet.</p> : null}
                        {batches.map((batch) => {
                            const marketNames = (batch.markets || []).map((m) => m.name).filter(Boolean);
                            const marketLabel = marketNames.length > 1
                                ? `${marketNames.length} markets`
                                : (marketNames[0] || batch.platform?.name || 'Market');
                            return (
                                <button
                                    key={batch.id}
                                    type="button"
                                    onClick={() => { setSelectedBatchId(batch.id); setClassificationFilter(''); setReviewFilter(''); setSearchInput(''); setSearch(''); setRowPage(1); }}
                                    className={`mb-1 w-full rounded-lg px-3 py-2 text-left transition ${selectedBatchId === batch.id ? 'bg-teal-50 text-teal-900 ring-1 ring-teal-200' : 'hover:bg-slate-50'}`}
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-sm font-semibold">Batch #{batch.id}</span>
                                        <StatusBadge status={batch.status} />
                                    </div>
                                    <p className="mt-1 truncate text-xs text-slate-500" title={marketNames.join(', ')}>{marketLabel} • {formatDateTime(batch.created_at)}</p>
                                </button>
                            );
                        })}
                    </div>
                </aside>

                <div className="space-y-4">
                    {selectedBatch ? (
                        <>
                            <section className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
                                {metrics.map((metric) => (
                                    <button
                                        key={metric.key}
                                        type="button"
                                        onClick={() => { setClassificationFilter(classificationFilter === metric.key ? '' : metric.key); setReviewFilter(''); setRowPage(1); }}
                                        className={`rounded-lg border px-3 py-3 text-left shadow-sm transition hover:bg-teal-50/30 ${
                                            classificationFilter === metric.key ? 'border-teal-300 bg-teal-50/40' : 'border-slate-200 bg-white hover:border-teal-200'
                                        } ${metric.emphasize && metric.count > 0 ? 'ring-1 ring-rose-200' : ''}`}
                                    >
                                        <p className="text-xs font-semibold text-slate-500">{metric.label}</p>
                                        <p className={`mt-2 text-2xl font-semibold ${metric.emphasize && metric.count > 0 ? 'text-rose-700' : 'text-slate-900'}`}>{Number(metric.count).toLocaleString()}</p>
                                        {summaryCurrency && metric.amount ? (
                                            <p className={`mt-1 text-[11px] font-medium ${metric.tone}`}>
                                                <CurrencyAmount scalarAmount={metric.amount} fallbackCurrency={summaryCurrency} />
                                            </p>
                                        ) : null}
                                    </button>
                                ))}
                                <button type="button" onClick={() => { setReviewFilter(reviewFilter === 'resolved' ? '' : 'resolved'); setClassificationFilter(''); setRowPage(1); }} className={`rounded-lg border px-3 py-3 text-left shadow-sm transition hover:bg-teal-50/30 ${reviewFilter === 'resolved' ? 'border-teal-300 bg-teal-50/40' : 'border-slate-200 bg-white hover:border-teal-200'}`}>
                                    <p className="text-xs font-semibold text-slate-500">Resolved</p>
                                    <p className="mt-2 text-2xl font-semibold text-slate-900">{Number(summary.resolved_rows || 0).toLocaleString()}</p>
                                    <p className="mt-1 text-[11px] text-slate-500">of {Number(summary.total_rows || 0).toLocaleString()} rows</p>
                                </button>
                            </section>

                            <section className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-900">Batch #{selectedBatch.id}</h3>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {marketCount > 1 ? `${marketCount} markets` : (selectedBatch.platform?.name || 'Market')}
                                            {' • '}{selectedBatch.file_name || selectedBatch.source_type}
                                            {' • '}{summary.total_rows || 0} rows
                                            {!summaryCurrency ? ' • mixed currencies' : ''}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <input
                                            value={searchInput}
                                            onChange={(event) => setSearchInput(event.target.value)}
                                            placeholder="Search name or code"
                                            className="rounded-md border border-slate-300 px-3 py-2 text-xs focus:border-teal-500 focus:outline-none focus:ring-2 focus:ring-teal-100"
                                        />
                                        <select value={classificationFilter} onChange={(event) => { setClassificationFilter(event.target.value); setRowPage(1); }} className="rounded-md border border-slate-300 px-3 py-2 text-xs">
                                            <option value="">All classifications</option>
                                            <option value="matched">Matched</option>
                                            <option value="amount_mismatch">Amount mismatch</option>
                                            <option value="missing">Missing</option>
                                            <option value="unverifiable">Unverifiable</option>
                                            <option value="duplicate_in_file">Duplicate in file</option>
                                            <option value="duplicate_in_crm">Duplicate in CRM</option>
                                        </select>
                                        <select value={reviewFilter} onChange={(event) => { setReviewFilter(event.target.value); setRowPage(1); }} className="rounded-md border border-slate-300 px-3 py-2 text-xs">
                                            <option value="">All reviews</option>
                                            <option value="pending">Pending</option>
                                            <option value="reviewing">Reviewing</option>
                                            <option value="confirmed_fraud">Confirmed fraud</option>
                                            <option value="cleared">Cleared</option>
                                            <option value="linked">Linked</option>
                                            <option value="resolved">Resolved</option>
                                        </select>
                                    </div>
                                </div>
                            </section>

                            <DataTable
                                columns={columns}
                                data={rows}
                                pagination={batchQuery.data?.meta}
                                onPageChange={setRowPage}
                                isLoading={batchQuery.isLoading}
                                emptyMessage="No reconciliation rows found."
                                compact
                                rowIdKey="id"
                                selectable={!isClosed}
                                bulkActions={bulkActions}
                                clearSelectionKey={`${selectedBatchId}|${classificationFilter}|${reviewFilter}|${search}|${rowPage}`}
                            />
                        </>
                    ) : (
                        <section className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500 shadow-sm">
                            Upload or paste an external payment sheet to start a fraud audit queue.
                        </section>
                    )}
                </div>
            </section>

            <FraudAuditUploadDrawer
                open={uploadOpen}
                onClose={() => setUploadOpen(false)}
                platformOptions={platformOptions}
                onPreviewSaved={(result) => {
                    queryClient.invalidateQueries({ queryKey: ['reconcile-batches'] });
                    setSelectedBatchId(result.batch_id);
                    setClassificationFilter('');
                    setReviewFilter('');
                    setSearchInput('');
                    setSearch('');
                    setRowPage(1);
                }}
            />

            {detailRow ? (
                <RowDetailDrawer
                    row={detailRow}
                    currency={rowDisplayCurrency(detailRow)}
                    onClose={() => setDetailRow(null)}
                    onOpenPayment={onOpenPayment}
                />
            ) : null}

            <ConfirmDialog
                open={Boolean(transitionDialog)}
                title={transitionDialog?.action === 'reopen' ? 'Reopen batch' : 'Close batch'}
                message="Batch transitions are audit logged."
                confirmLabel={transitionDialog?.action === 'reopen' ? 'Reopen' : 'Close'}
                onCancel={() => setTransitionDialog(null)}
                onConfirm={() => transitionMutation.mutate({ ...transitionDialog, reason })}
                confirmDisabled={!reason.trim()}
                isPending={transitionMutation.isPending}
            >
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={3} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(reviewDialog)}
                title="Update row review"
                message={reviewDialog?.status ? `Set status to ${reviewDialog.status.replace(/_/g, ' ')}.` : ''}
                confirmLabel="Save review"
                tone={reviewDialog?.status === 'confirmed_fraud' ? 'danger' : 'default'}
                onCancel={() => { setReviewDialog(null); setNote(''); }}
                onConfirm={() => reviewMutation.mutate({ ...reviewDialog, reason, note })}
                confirmDisabled={!reason.trim()}
                isPending={reviewMutation.isPending}
            >
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={2} placeholder="Reason" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Review note (optional)" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(bulkDialog)}
                title="Bulk review update"
                message={bulkDialog ? `Set ${bulkDialog.rows?.length || 0} row(s) to ${String(bulkDialog.status).replace(/_/g, ' ')}.` : ''}
                confirmLabel="Apply to selected"
                tone={bulkDialog?.status === 'confirmed_fraud' ? 'danger' : 'default'}
                onCancel={() => { setBulkDialog(null); setNote(''); }}
                onConfirm={() => bulkReviewMutation.mutate({ rowIds: (bulkDialog.rows || []).map((r) => r.id), status: bulkDialog.status, reason, note })}
                confirmDisabled={!reason.trim() || !(bulkDialog?.rows || []).length}
                isPending={bulkReviewMutation.isPending}
            >
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={2} placeholder="Reason" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Note (optional)" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(linkDialog)}
                title="Link row to payment"
                message="Linking flags the payment for manual review."
                confirmLabel="Link payment"
                onCancel={() => { setLinkDialog(null); setNote(''); setPaymentId(''); }}
                onConfirm={() => linkMutation.mutate({ row: linkDialog.row, selectedPaymentId: paymentId, reason, note })}
                confirmDisabled={!paymentId || !reason.trim()}
                isPending={linkMutation.isPending}
            >
                <input value={paymentId} onChange={(event) => setPaymentId(event.target.value)} placeholder="Payment ID" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <div className="max-h-40 overflow-y-auto rounded-md border border-slate-200">
                    {candidatesQuery.isLoading ? <p className="p-3 text-xs text-slate-500">Loading candidates...</p> : null}
                    {!candidatesQuery.isLoading && (candidatesQuery.data?.data || []).length === 0 ? <p className="p-3 text-xs text-slate-500">No candidate payments found.</p> : null}
                    {(candidatesQuery.data?.data || []).map((candidate) => (
                        <button key={candidate.payment_id} type="button" onClick={() => setPaymentId(String(candidate.payment_id))} className={`block w-full border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-slate-50 ${String(paymentId) === String(candidate.payment_id) ? 'bg-teal-50' : ''}`}>
                            <span className="font-semibold text-slate-800">#{candidate.payment_id}</span>
                            <span className="ml-2 text-slate-500">{candidate.client_name || 'No client'} • {candidate.transaction_reference || 'No ref'} • {candidate.platform_name || ''} • {candidate.basis}</span>
                        </button>
                    ))}
                </div>
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={2} placeholder="Reason" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Link note (optional)" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>
        </section>
    );
}

function RowDetailDrawer({ row, currency, onClose, onOpenPayment }) {
    const rawEntries = Object.entries(row.raw_row || {});
    return (
        <div className="fixed inset-0 z-[100] flex bg-slate-900/45" onClick={onClose}>
            <aside className="ml-auto flex h-full w-full max-w-md flex-col border-l border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                <header className="flex items-start justify-between gap-3 border-b border-slate-100 px-4 py-3">
                    <div>
                        <h3 className="text-sm font-semibold text-slate-900">Row #{row.row_number} · {row.external_name || 'Unknown'}</h3>
                        <div className="mt-1 flex items-center gap-2">
                            <StatusBadge status={row.classification} />
                            <StatusBadge status={row.review_status} />
                        </div>
                    </div>
                    <button type="button" onClick={onClose} className="crm-btn-secondary px-3 py-1.5 text-xs">Close</button>
                </header>

                <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4 text-sm">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Amount</p>
                        <CurrencyAmount scalarAmount={row.external_amount || 0} fallbackCurrency={currency} className="text-base font-semibold text-slate-900" />
                        <p className="mt-1 text-xs text-slate-500">Date on sheet: {row.external_paid_at_text || '—'}</p>
                        <p className="font-mono text-xs text-slate-500">Ref: {row.external_reference_raw || '—'}</p>
                    </div>

                    <div className="rounded-lg border border-slate-200 p-3">
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">CRM match</p>
                        {row.matched_payment_id ? (
                            <div className="mt-1 space-y-0.5 text-sm">
                                <p>Payment #{row.matched_payment_id} {row.matched_payment?.status ? `· ${row.matched_payment.status}` : ''}</p>
                                <p>Client: {row.matched_client?.name || '—'}</p>
                                <p>Market: {row.matched_platform?.name || '—'}</p>
                                <p>Recorded by: {row.confirmed_by?.name || '—'}</p>
                                <button type="button" onClick={() => onOpenPayment?.(row.matched_payment_id)} className="mt-1 crm-btn-secondary px-2 py-1 text-xs">Open payment</button>
                            </div>
                        ) : <p className="mt-1 text-sm text-slate-500">No CRM payment linked.</p>}
                    </div>

                    {row.flags && Object.keys(row.flags).length ? (
                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-3">
                            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-amber-700">Flags</p>
                            <pre className="mt-1 whitespace-pre-wrap break-words text-xs text-amber-900">{JSON.stringify(row.flags, null, 2)}</pre>
                        </div>
                    ) : null}

                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Source row</p>
                        <dl className="mt-1 divide-y divide-slate-100 rounded-lg border border-slate-200">
                            {rawEntries.length === 0 ? <p className="p-2 text-xs text-slate-500">No raw columns.</p> : null}
                            {rawEntries.map(([key, value]) => (
                                <div key={key} className="flex gap-3 px-3 py-1.5">
                                    <dt className="w-1/3 shrink-0 text-xs font-medium text-slate-500">{key}</dt>
                                    <dd className="flex-1 break-words text-xs text-slate-800">{String(value ?? '')}</dd>
                                </div>
                            ))}
                        </dl>
                    </div>

                    {row.review_note || row.reviewer ? (
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">Review</p>
                            <p className="mt-1 text-xs text-slate-700">{row.review_note || '—'}</p>
                            {row.reviewer ? <p className="mt-1 text-xs text-slate-500">By {row.reviewer.name} · {formatDateTime(row.reviewed_at)}</p> : null}
                        </div>
                    ) : null}
                </div>
            </aside>
        </div>
    );
}
