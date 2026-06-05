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
    const [rowPage, setRowPage] = useState(1);
    const [transitionDialog, setTransitionDialog] = useState(null);
    const [reviewDialog, setReviewDialog] = useState(null);
    const [linkDialog, setLinkDialog] = useState(null);
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [paymentId, setPaymentId] = useState('');

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
        queryKey: ['reconcile-batch', selectedBatchId, classificationFilter, reviewFilter, rowPage],
        queryFn: () => api.get(`/crm/payments/reconcile/batches/${selectedBatchId}`, {
            params: {
                page: rowPage,
                per_page: 50,
                ...(classificationFilter ? { classification: classificationFilter } : {}),
                ...(reviewFilter ? { review_status: reviewFilter } : {}),
            },
        }).then((response) => response.data),
        enabled: Boolean(selectedBatchId),
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

    const transitionMutation = useMutation({
        mutationFn: ({ batch, action, reason: transitionReason }) =>
            api.post(`/crm/payments/reconcile/batches/${batch.id}/${action}`, { reason: transitionReason }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['reconcile-batches'] });
            queryClient.invalidateQueries({ queryKey: ['reconcile-batch', selectedBatchId] });
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
            queryClient.invalidateQueries({ queryKey: ['reconcile-batches'] });
            queryClient.invalidateQueries({ queryKey: ['reconcile-batch', selectedBatchId] });
            setReviewDialog(null);
            setReason('');
            setNote('');
            toast.success('Review status updated.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Review update failed.'),
    });

    const linkMutation = useMutation({
        mutationFn: ({ row, selectedPaymentId, reason: linkReason, note: linkNote }) =>
            api.post(`/crm/payments/reconcile/rows/${row.id}/link`, {
                payment_id: selectedPaymentId,
                reason: linkReason,
                note: linkNote,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['reconcile-batches'] });
            queryClient.invalidateQueries({ queryKey: ['reconcile-batch', selectedBatchId] });
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setLinkDialog(null);
            setReason('');
            setNote('');
            setPaymentId('');
            toast.success('Row linked to payment.');
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Link failed.'),
    });

    const metrics = useMemo(() => ([
        ['matched', 'Matched', summary.matched_rows || 0],
        ['amount_mismatch', 'Amount mismatch', summary.mismatch_rows || 0],
        ['missing', 'Missing from CRM', summary.missing_rows || 0],
        ['unverifiable', 'Unverifiable', summary.unverifiable_rows || 0],
        ['duplicate_in_file', 'Duplicate', summary.duplicate_rows || 0],
    ]), [summary]);

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
                <CurrencyAmount scalarAmount={row.external_amount || 0} fallbackCurrency={row.external_currency || selectedBatch?.platform?.currency_code || 'KES'} className="text-sm font-semibold text-slate-800" />
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
            render: (row) => <StatusBadge status={row.classification} />,
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
                    {row.confirmed_by ? <p className="mt-1 text-xs text-slate-500">Recorded by {row.confirmed_by.name}</p> : null}
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
                    <button type="button" disabled={isClosed} onClick={() => { setReviewDialog({ row, status: 'reviewing' }); setReason('Reviewing fraud audit row'); }} className="crm-btn-secondary px-2 py-1 text-xs disabled:opacity-50">
                        Review
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

    return (
        <section className="space-y-4">
            <section className="rounded-xl border border-slate-200 bg-white px-4 py-4 shadow-sm">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-semibold text-slate-900">Fraud audit</h2>
                        <p className="mt-1 text-xs text-slate-500">Cross-check external collection records against CRM payments.</p>
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
                        {batches.map((batch) => (
                            <button
                                key={batch.id}
                                type="button"
                                onClick={() => { setSelectedBatchId(batch.id); setClassificationFilter(''); setReviewFilter(''); setRowPage(1); }}
                                className={`mb-1 w-full rounded-lg px-3 py-2 text-left transition ${selectedBatchId === batch.id ? 'bg-teal-50 text-teal-900 ring-1 ring-teal-200' : 'hover:bg-slate-50'}`}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-semibold">Batch #{batch.id}</span>
                                    <StatusBadge status={batch.status} />
                                </div>
                                <p className="mt-1 truncate text-xs text-slate-500">{batch.platform?.name || 'Market'} • {formatDateTime(batch.created_at)}</p>
                            </button>
                        ))}
                    </div>
                </aside>

                <div className="space-y-4">
                    {selectedBatch ? (
                        <>
                            <section className="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
                                {metrics.map(([key, label, value]) => (
                                    <button
                                        key={key}
                                        type="button"
                                        onClick={() => { setClassificationFilter(classificationFilter === key ? '' : key); setReviewFilter(''); setRowPage(1); }}
                                        className="rounded-lg border border-slate-200 bg-white px-3 py-3 text-left shadow-sm transition hover:border-teal-200 hover:bg-teal-50/30"
                                    >
                                        <p className="text-xs font-semibold text-slate-500">{label}</p>
                                        <p className="mt-2 text-2xl font-semibold text-slate-900">{Number(value).toLocaleString()}</p>
                                    </button>
                                ))}
                                <button type="button" onClick={() => { setReviewFilter(reviewFilter === 'resolved' ? '' : 'resolved'); setClassificationFilter(''); setRowPage(1); }} className="rounded-lg border border-slate-200 bg-white px-3 py-3 text-left shadow-sm transition hover:border-teal-200 hover:bg-teal-50/30">
                                    <p className="text-xs font-semibold text-slate-500">Resolved</p>
                                    <p className="mt-2 text-2xl font-semibold text-slate-900">{Number(summary.resolved_rows || 0).toLocaleString()}</p>
                                </button>
                            </section>

                            <section className="rounded-xl border border-slate-200 bg-white px-4 py-3 shadow-sm">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-900">Batch #{selectedBatch.id}</h3>
                                        <p className="mt-1 text-xs text-slate-500">
                                            {selectedBatch.platform?.name || 'Market'} • {selectedBatch.file_name || selectedBatch.source_type} • {summary.total_rows || 0} rows
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap gap-2">
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
                    setRowPage(1);
                }}
            />

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
                onCancel={() => setReviewDialog(null)}
                onConfirm={() => reviewMutation.mutate({ ...reviewDialog, reason, note })}
                confirmDisabled={!reason.trim()}
                isPending={reviewMutation.isPending}
            >
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={2} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Review note" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>

            <ConfirmDialog
                open={Boolean(linkDialog)}
                title="Link row to payment"
                message="Linking flags the payment for manual review."
                confirmLabel="Link payment"
                onCancel={() => setLinkDialog(null)}
                onConfirm={() => linkMutation.mutate({ row: linkDialog.row, selectedPaymentId: paymentId, reason, note })}
                confirmDisabled={!paymentId || !reason.trim()}
                isPending={linkMutation.isPending}
            >
                <input value={paymentId} onChange={(event) => setPaymentId(event.target.value)} placeholder="Payment ID" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <div className="max-h-40 overflow-y-auto rounded-md border border-slate-200">
                    {candidatesQuery.isLoading ? <p className="p-3 text-xs text-slate-500">Loading candidates...</p> : null}
                    {(candidatesQuery.data?.data || []).map((candidate) => (
                        <button key={candidate.payment_id} type="button" onClick={() => setPaymentId(String(candidate.payment_id))} className="block w-full border-b border-slate-100 px-3 py-2 text-left text-xs hover:bg-slate-50">
                            <span className="font-semibold text-slate-800">#{candidate.payment_id}</span>
                            <span className="ml-2 text-slate-500">{candidate.client_name || 'No client'} • {candidate.transaction_reference || 'No ref'} • {candidate.basis}</span>
                        </button>
                    ))}
                </div>
                <textarea value={reason} onChange={(event) => setReason(event.target.value)} rows={2} className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
                <textarea value={note} onChange={(event) => setNote(event.target.value)} rows={3} placeholder="Link note" className="w-full rounded-md border border-slate-300 px-3 py-2 text-sm" />
            </ConfirmDialog>
        </section>
    );
}
