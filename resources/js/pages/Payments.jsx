import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from '../components/DataTable';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';

function formatCurrency(amount, currency = 'KES') {
    return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

function normalizePhone(phone) {
    if (!phone) return '';
    const cleaned = String(phone).replace(/[^\d+]/g, '').replace(/^\+/, '');
    if (cleaned.startsWith('0')) return `254${cleaned.slice(1)}`;
    return cleaned;
}

function candidateScore(payment, candidate) {
    let score = 45;
    const paymentPhone = normalizePhone(payment?.phone);
    const candidatePhone = normalizePhone(candidate?.phone_normalized);

    if (paymentPhone && candidatePhone && paymentPhone === candidatePhone) {
        score = 85;
    }

    if (candidate?.profile_status === 'publish') {
        score += 8;
    }

    if (candidate?.verified) {
        score += 7;
    }

    return Math.min(99, score);
}

function scoreTone(score) {
    if (score >= 85) return 'high';
    if (score >= 65) return 'medium';
    return 'low';
}

function toneClasses(tone) {
    if (tone === 'high') {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (tone === 'medium') {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    return 'bg-slate-100 text-slate-600 ring-slate-200';
}

export default function Payments() {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [page, setPage] = useState(1);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState('');
    const [matchFilter, setMatchFilter] = useState('');
    const [selectedPayment, setSelectedPayment] = useState(null);
    const [selectedClientId, setSelectedClientId] = useState('');
    const [confirmReason, setConfirmReason] = useState('Manual payment match from queue');
    const [candidateSearchInput, setCandidateSearchInput] = useState('');
    const [candidateSearch, setCandidateSearch] = useState('');
    const [selectedRows, setSelectedRows] = useState([]);
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [queueAutoMatchDialog, setQueueAutoMatchDialog] = useState({
        open: false,
        reason: 'Batch auto-match from payment queue',
    });
    const [retryStkDialog, setRetryStkDialog] = useState({ open: false, payment: null, reason: 'Retry STK from payment queue' });
    const [sendLinkDialog, setSendLinkDialog] = useState({ open: false, payment: null, channel: 'sms', phone: '', reason: 'Send payment link from CRM' });
    const [createSubDialog, setCreateSubDialog] = useState({ open: false, payment: null, reason: 'Create subscription from matched payment' });

    const { data, isLoading } = useQuery({
        queryKey: ['payments', page, search, statusFilter, matchFilter],
        queryFn: () =>
            api.get('/crm/payments', {
                params: {
                    page,
                    per_page: 25,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(matchFilter && { matched: matchFilter }),
                },
            }).then((response) => response.data),
    });

    const { data: candidatesData, isLoading: candidatesLoading } = useQuery({
        queryKey: ['payment-candidates', selectedPayment?.id, candidateSearch],
        queryFn: () =>
            api.get(`/crm/payments/${selectedPayment.id}/candidates`, {
                params: {
                    ...(candidateSearch ? { search: candidateSearch } : {}),
                },
            }).then((response) => response.data),
        enabled: !!selectedPayment?.id,
    });

    const autoMatchMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/auto-match`).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            if (selectedPayment?.id) {
                queryClient.invalidateQueries({ queryKey: ['payment-candidates', selectedPayment.id] });
            }
            toast.success('Auto-match completed for payment.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Auto-match failed for payment.');
        },
    });

    const confirmMatchMutation = useMutation({
        mutationFn: ({ paymentId, clientId, reason }) =>
            api.post(`/crm/payments/${paymentId}/confirm-match`, {
                client_id: clientId,
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setSelectedPayment(null);
            setSelectedClientId('');
            setConfirmReason('Manual payment match from queue');
            toast.success('Payment match confirmed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual payment match failed.');
        },
    });

    const batchMatchMutation = useMutation({
        mutationFn: (reason) =>
            api.post('/crm/payments/batch-match', {
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue' });
            toast.success('Queue auto-match completed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Queue auto-match failed.');
        },
    });

    const retryStkMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/retry-stk`, { reason: reason || undefined }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setRetryStkDialog({ open: false, payment: null, reason: 'Retry STK from payment queue' });
            toast.success('STK push sent. Customer should complete the request on their phone.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Retry STK failed.');
        },
    });

    const sendPaymentLinkMutation = useMutation({
        mutationFn: ({ paymentId, channel, phone, reason }) =>
            api.post(`/crm/payments/${paymentId}/send-payment-link`, {
                channel,
                ...(phone && { phone: phone.trim() }),
                reason: reason || undefined,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setSendLinkDialog({ open: false, payment: null, channel: 'sms', phone: '', reason: 'Send payment link from CRM' });
            toast.success('Payment link sent by SMS.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Send payment link failed.');
        },
    });

    const createSubscriptionMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/create-subscription`, { reason: reason || undefined }).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setCreateSubDialog({ open: false, payment: null, reason: 'Create subscription from matched payment' });
            toast.success(`Subscription created (Deal #${result.deal?.id}). Expires ${new Date(result.deal?.expires_at).toLocaleDateString()}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Create subscription failed.');
        },
    });

    const bulkAutoMatchMutation = useMutation({
        mutationFn: async (rows) => {
            const targets = rows.filter((row) => row.status !== 'failed');
            const results = await Promise.allSettled(
                targets.map((row) => api.post(`/crm/payments/${row.id}/auto-match`)),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { success, failed, total: targets.length };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Auto-match processed ${result.success}/${result.total} selected payments (${result.failed} failed).`);
                return;
            }
            toast.success(`Auto-match processed ${result.success}/${result.total} selected payments.`);
        },
    });

    const bulkConfirmMutation = useMutation({
        mutationFn: async (rows) => {
            let confirmed = 0;
            let autoMatched = 0;
            let skipped = 0;
            let failed = 0;

            for (const row of rows) {
                try {
                    if (row.client_id) {
                        skipped += 1;
                        continue;
                    }

                    const candidateResponse = await api.get(`/crm/payments/${row.id}/candidates`);
                    const candidates = candidateResponse.data?.data || [];

                    if (candidates.length === 1) {
                        await api.post(`/crm/payments/${row.id}/confirm-match`, {
                            client_id: candidates[0].id,
                            reason: 'Bulk confirm from payment queue',
                        });
                        confirmed += 1;
                    } else {
                        await api.post(`/crm/payments/${row.id}/auto-match`);
                        autoMatched += 1;
                    }
                } catch (error) {
                    failed += 1;
                }
            }

            return { confirmed, autoMatched, skipped, failed, total: rows.length };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setClearSelectionKey((value) => value + 1);
            if (result.failed > 0) {
                toast.warning(`Bulk confirm completed with issues: ${result.confirmed} direct, ${result.autoMatched} auto-match, ${result.failed} failed.`);
                return;
            }
            toast.success(`Bulk confirm done: ${result.confirmed} direct, ${result.autoMatched} auto-match, ${result.skipped} skipped.`);
        },
    });

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const openManualMatch = (paymentRow) => {
        setSelectedPayment(paymentRow);
        setSelectedClientId('');
        setConfirmReason('Manual payment match from queue');
        setCandidateSearch('');
        setCandidateSearchInput('');
    };

    const rows = data?.data || [];

    const summary = useMemo(() => {
        if (data?.stats) {
            return {
                pending: Number(data.stats.pending || 0),
                confirmed: Number(data.stats.confirmed || 0),
                unmatched: Number((data.stats.unmatched_review ?? data.stats.unmatched) || 0),
            };
        }

        const pending = rows.filter((row) => row.status === 'initiated').length;
        const confirmed = rows.filter((row) => row.status === 'completed').length;
        const unmatched = rows.filter((row) => !row.client_id).length;

        return { pending, confirmed, unmatched };
    }, [data?.stats, rows]);

    const modalCandidates = useMemo(() => {
        const candidates = candidatesData?.data || [];
        return candidates
            .map((candidate) => {
                const score = candidateScore(selectedPayment, candidate);
                return {
                    ...candidate,
                    score,
                    tone: scoreTone(score),
                };
            })
            .sort((left, right) => right.score - left.score);
    }, [candidatesData, selectedPayment]);

    const bulkActions = [
        {
            key: 'bulk-confirm',
            label: 'Confirm selected',
            loadingLabel: 'Confirming...',
            variant: 'primary',
            onClick: async (rowsSelection) => {
                await bulkConfirmMutation.mutateAsync(rowsSelection);
            },
        },
        {
            key: 'bulk-auto',
            label: 'Auto-match selected',
            loadingLabel: 'Auto-matching...',
            onClick: async (rowsSelection) => {
                await bulkAutoMatchMutation.mutateAsync(rowsSelection);
            },
        },
        {
            key: 'bulk-open-first',
            label: 'Open first selected',
            onClick: (rowsSelection) => {
                if (!rowsSelection.length) return;
                openManualMatch(rowsSelection[0]);
            },
        },
    ];

    useEffect(() => {
        const listener = (event) => {
            const isConfirmShortcut = (event.ctrlKey || event.metaKey) && event.key === 'Enter';
            if (!isConfirmShortcut || selectedRows.length === 0) {
                return;
            }

            event.preventDefault();
            if (!bulkConfirmMutation.isPending) {
                bulkConfirmMutation.mutate(selectedRows);
            }
        };

        window.addEventListener('keydown', listener);
        return () => window.removeEventListener('keydown', listener);
    }, [selectedRows, bulkConfirmMutation]);

    const columns = [
        {
            key: 'phone',
            label: 'Phone',
            render: (row) => <span className="crm-mono text-xs text-slate-600">{row.phone || '—'}</span>,
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => <span className="text-sm font-semibold text-slate-900">{formatCurrency(row.amount, row.currency || 'KES')}</span>,
        },
        {
            key: 'product',
            label: 'Product',
            render: (row) => <span className="text-sm text-slate-700">{row.product?.name || '—'}</span>,
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => <StatusBadge status={row.status} />,
        },
        {
            key: 'match_confidence',
            label: 'Match',
            render: (row) => row.match_confidence ? <StatusBadge status={row.match_confidence} /> : <span className="text-xs text-slate-400">—</span>,
        },
        {
            key: 'client',
            label: 'Matched Client',
            render: (row) => (
                row.client
                    ? <span className="text-xs text-slate-700">{row.client.name || `Client #${row.client.id}`}</span>
                    : <span className="text-xs text-slate-400">Unmatched</span>
            ),
        },
        {
            key: 'transaction_reference',
            label: 'Reference',
            render: (row) => <span className="crm-mono text-xs text-slate-500">{row.transaction_reference || '—'}</span>,
        },
        {
            key: 'created_at',
            label: 'Date',
            render: (row) => <span className="text-xs text-slate-500">{new Date(row.created_at).toLocaleDateString()}</span>,
        },
        {
            key: 'actions',
            label: 'Actions',
            render: (row) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    {(row.status === 'failed' || row.status === 'initiated') && (
                        <>
                            <button
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setRetryStkDialog({ open: true, payment: row, reason: 'Retry STK from payment queue' });
                                }}
                                className="rounded-md bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-amber-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                            >
                                Retry STK
                            </button>
                            <button
                                onClick={(event) => {
                                    event.stopPropagation();
                                    setSendLinkDialog({
                                        open: true,
                                        payment: row,
                                        channel: 'sms',
                                        phone: row.phone || '',
                                        reason: 'Send payment link from CRM',
                                    });
                                }}
                                className="rounded-md bg-slate-600 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-500"
                            >
                                Send link
                            </button>
                        </>
                    )}
                    {row.status === 'completed' && !row.client_id && (
                        <button
                            onClick={(event) => {
                                event.stopPropagation();
                                autoMatchMutation.mutate(row.id);
                            }}
                            className="rounded-md bg-teal-700 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-600"
                        >
                            Auto-match
                        </button>
                    )}
                    {!row.client_id && (
                        <button
                            onClick={(event) => {
                                event.stopPropagation();
                                openManualMatch(row);
                            }}
                            className="rounded-md border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                        >
                            Match manually
                        </button>
                    )}
                    {row.status === 'completed' && row.client_id && !row.deal_id && (
                        <button
                            onClick={(event) => {
                                event.stopPropagation();
                                setCreateSubDialog({ open: true, payment: row, reason: 'Create subscription from matched payment' });
                            }}
                            className="rounded-md border border-emerald-400 bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500"
                        >
                            Create Sub
                        </button>
                    )}
                    {row.client_id && (
                        <span className="text-[11px] font-medium text-slate-500">Matched</span>
                    )}
                </div>
            ),
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Payments"
                subtitle={data?.total ? `${data.total.toLocaleString()} payment records` : 'Incoming payments and match queue'}
                actions={(
                    <button
                        onClick={() => setQueueAutoMatchDialog({ open: true, reason: 'Batch auto-match from payment queue' })}
                        disabled={batchMatchMutation.isPending}
                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {batchMatchMutation.isPending ? 'Matching...' : 'Auto-match queue'}
                    </button>
                )}
            />

            <section className="grid gap-4 md:grid-cols-3">
                <MetricCard label="Pending" value={summary.pending.toLocaleString()} meta="initiated" tone="warning" />
                <MetricCard label="Confirmed" value={summary.confirmed.toLocaleString()} meta="completed" tone="success" />
                <MetricCard label="Unmatched" value={summary.unmatched.toLocaleString()} meta="completed, no client linked" tone="danger" />
            </section>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-center gap-3">
                    <form onSubmit={handleSearch} className="min-w-[240px] flex-1">
                        <div className="relative">
                            <input
                                type="text"
                                value={searchInput}
                                onChange={(event) => setSearchInput(event.target.value)}
                                placeholder="Search by phone or reference..."
                                className="crm-input pr-10"
                            />
                            <button type="submit" aria-label="Run payment search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                </svg>
                            </button>
                        </div>
                    </form>

                    <select
                        value={statusFilter}
                        onChange={(event) => {
                            setStatusFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Statuses</option>
                        <option value="completed">Completed</option>
                        <option value="initiated">Initiated</option>
                        <option value="failed">Failed</option>
                    </select>

                    <select
                        value={matchFilter}
                        onChange={(event) => {
                            setMatchFilter(event.target.value);
                            setPage(1);
                        }}
                        className="crm-select"
                    >
                        <option value="">All Matches</option>
                        <option value="matched">Matched</option>
                        <option value="unmatched">Unmatched</option>
                    </select>

                    {(search || statusFilter || matchFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setMatchFilter('');
                                setPage(1);
                            }}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Reset
                        </button>
                    ) : null}
                </div>

                <div className="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <p className="text-xs text-slate-500">Bulk shortcut: press <span className="crm-mono">Ctrl/Cmd + Enter</span> to confirm selected rows.</p>
                    <span className="text-xs text-slate-500">Auto-match attempts phone-based matching. Manual match lets you search and pick any client in scope.</span>
                </div>
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                isLoading={isLoading}
                emptyMessage="No payments found."
                compact
                selectable
                bulkActions={bulkActions}
                onSelectionChange={setSelectedRows}
                clearSelectionKey={clearSelectionKey}
            />

            {selectedPayment ? (
                <div className="fixed inset-0 z-50 flex bg-slate-900/45" onClick={() => setSelectedPayment(null)}>
                    <aside
                        className="ml-auto h-full w-full max-w-md border-l border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header sticky top-0 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Manual Match</h3>
                                <p className="crm-panel-subtitle">
                                    Payment #{selectedPayment.id} • {selectedPayment.phone || 'No phone'} • {formatCurrency(selectedPayment.amount, selectedPayment.currency || 'KES')}
                                </p>
                            </div>
                        </header>

                        <div className="h-[calc(100%-132px)] overflow-y-auto p-4">
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setCandidateSearch(candidateSearchInput.trim());
                                }}
                                className="mb-3"
                            >
                                <div className="relative">
                                    <input
                                        value={candidateSearchInput}
                                        onChange={(event) => setCandidateSearchInput(event.target.value)}
                                        placeholder="Search client by name, phone, CRM/WP IDs..."
                                        className="crm-input pr-10"
                                    />
                                    <button type="submit" aria-label="Search client candidates" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                        <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </button>
                                </div>
                                {candidateSearch ? (
                                    <button
                                        type="button"
                                        onClick={() => {
                                            setCandidateSearch('');
                                            setCandidateSearchInput('');
                                        }}
                                        className="mt-2 text-xs font-semibold text-teal-700 underline decoration-teal-200 underline-offset-2 hover:text-teal-800"
                                    >
                                        Clear candidate search
                                    </button>
                                ) : null}
                            </form>

                            {candidatesLoading ? (
                                <p className="text-sm text-slate-500">Loading candidate clients...</p>
                            ) : modalCandidates.length === 0 ? (
                                <p className="text-sm text-slate-500">No candidates found. Try searching by client name, phone, or WP IDs.</p>
                            ) : (
                                <div className="space-y-2">
                                    {modalCandidates.map((client) => {
                                        const tone = toneClasses(client.tone);

                                        return (
                                            <label
                                                key={client.id}
                                                className={`flex cursor-pointer items-start justify-between gap-3 rounded-md border px-3 py-2.5 text-sm transition ${selectedClientId === String(client.id) ? 'border-teal-600 bg-teal-50/60' : 'border-slate-200 hover:bg-slate-50'}`}
                                            >
                                                <span className="min-w-0 flex-1">
                                                    <span className="block truncate font-semibold text-slate-900">{client.name || `Client #${client.id}`}</span>
                                                    <span className="mt-0.5 block truncate text-xs text-slate-500">{client.phone_normalized || 'No phone'} • {client.profile_status}</span>
                                                    <span className={`mt-1 inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${tone}`}>
                                                        Match score {client.score}%
                                                    </span>
                                                </span>
                                                <input
                                                    type="radio"
                                                    name="client"
                                                    value={client.id}
                                                    checked={selectedClientId === String(client.id)}
                                                    onChange={(event) => setSelectedClientId(event.target.value)}
                                                    className="mt-1 h-4 w-4 border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                            </label>
                                        );
                                    })}
                                </div>
                            )}

                            <div className="mt-4">
                                <label htmlFor="confirm-reason" className="mb-1 block text-xs font-semibold uppercase tracking-[0.1em] text-slate-500">
                                    Reason
                                </label>
                                <textarea
                                    id="confirm-reason"
                                    rows={3}
                                    value={confirmReason}
                                    onChange={(event) => setConfirmReason(event.target.value)}
                                    className="crm-input"
                                />
                            </div>
                        </div>

                        <footer className="flex items-center justify-end gap-2 border-t border-slate-100 p-4">
                            <button type="button" onClick={() => setSelectedPayment(null)} className="crm-btn-secondary">
                                Cancel
                            </button>
                            <button
                                type="button"
                                disabled={!selectedClientId || !confirmReason.trim() || confirmMatchMutation.isPending}
                                onClick={() =>
                                    confirmMatchMutation.mutate({
                                        paymentId: selectedPayment.id,
                                        clientId: Number(selectedClientId),
                                        reason: confirmReason.trim(),
                                    })
                                }
                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {confirmMatchMutation.isPending ? 'Saving...' : 'Confirm match'}
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}

            <ConfirmDialog
                open={queueAutoMatchDialog.open}
                title="Auto-match Review Queue"
                message="This runs auto-match on unmatched completed payments in your accessible markets."
                confirmLabel="Run auto-match"
                onCancel={() => setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue' })}
                onConfirm={() => batchMatchMutation.mutate(queueAutoMatchDialog.reason.trim())}
                confirmDisabled={!queueAutoMatchDialog.reason.trim() || batchMatchMutation.isPending}
                isPending={batchMatchMutation.isPending}
            >
                <label htmlFor="queue-auto-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    id="queue-auto-reason"
                    rows={3}
                    value={queueAutoMatchDialog.reason}
                    onChange={(event) => setQueueAutoMatchDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={retryStkDialog.open && !!retryStkDialog.payment}
                title="Retry STK push"
                message={retryStkDialog.payment
                    ? `Send another M-Pesa STK push for payment #${retryStkDialog.payment.id} (${formatCurrency(retryStkDialog.payment.amount, retryStkDialog.payment.currency || 'KES')} to ${retryStkDialog.payment.phone || 'customer'}).`
                    : ''}
                confirmLabel="Send STK"
                onCancel={() => setRetryStkDialog({ open: false, payment: null, reason: 'Retry STK from payment queue' })}
                onConfirm={() => {
                    if (retryStkDialog.payment) {
                        retryStkMutation.mutate({
                            paymentId: retryStkDialog.payment.id,
                            reason: retryStkDialog.reason.trim() || undefined,
                        });
                    }
                }}
                confirmDisabled={retryStkMutation.isPending}
                isPending={retryStkMutation.isPending}
            >
                <label htmlFor="retry-stk-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                <textarea
                    id="retry-stk-reason"
                    rows={2}
                    value={retryStkDialog.reason}
                    onChange={(event) => setRetryStkDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                    placeholder="e.g. Retry STK from payment queue"
                />
            </ConfirmDialog>

            <ConfirmDialog
                open={sendLinkDialog.open && !!sendLinkDialog.payment}
                title="Send payment link"
                message={sendLinkDialog.payment
                    ? `Send a payment page link by SMS for payment #${sendLinkDialog.payment.id} (${formatCurrency(sendLinkDialog.payment.amount, sendLinkDialog.payment.currency || 'KES')}).`
                    : ''}
                confirmLabel="Send SMS"
                onCancel={() => setSendLinkDialog({ open: false, payment: null, channel: 'sms', phone: '', reason: 'Send payment link from CRM' })}
                onConfirm={() => {
                    if (sendLinkDialog.payment) {
                        sendPaymentLinkMutation.mutate({
                            paymentId: sendLinkDialog.payment.id,
                            channel: sendLinkDialog.channel,
                            phone: sendLinkDialog.phone.trim() || undefined,
                            reason: sendLinkDialog.reason.trim() || undefined,
                        });
                    }
                }}
                confirmDisabled={sendPaymentLinkMutation.isPending}
                isPending={sendPaymentLinkMutation.isPending}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="send-link-phone" className="mb-1 block text-sm font-medium text-slate-700">Phone number (optional)</label>
                        <input
                            id="send-link-phone"
                            type="text"
                            value={sendLinkDialog.phone}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, phone: event.target.value }))}
                            className="crm-input"
                            placeholder="Leave empty to use payment phone"
                        />
                    </div>
                    <div>
                        <label htmlFor="send-link-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                        <input
                            id="send-link-reason"
                            type="text"
                            value={sendLinkDialog.reason}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                            placeholder="e.g. Send payment link from CRM"
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={createSubDialog.open && !!createSubDialog.payment}
                title="Create subscription"
                message={createSubDialog.payment
                    ? `Activate a subscription for payment #${createSubDialog.payment.id} (${formatCurrency(createSubDialog.payment.amount, createSubDialog.payment.currency || 'KES')}) matched to ${createSubDialog.payment.client?.name || 'client'}.`
                    : ''}
                confirmLabel="Create subscription"
                onCancel={() => setCreateSubDialog({ open: false, payment: null, reason: 'Create subscription from matched payment' })}
                onConfirm={() => {
                    if (createSubDialog.payment) {
                        createSubscriptionMutation.mutate({
                            paymentId: createSubDialog.payment.id,
                            reason: createSubDialog.reason.trim() || undefined,
                        });
                    }
                }}
                confirmDisabled={createSubscriptionMutation.isPending}
                isPending={createSubscriptionMutation.isPending}
            >
                <label htmlFor="create-sub-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason (optional)</label>
                <textarea
                    id="create-sub-reason"
                    rows={2}
                    value={createSubDialog.reason}
                    onChange={(event) => setCreateSubDialog((current) => ({ ...current, reason: event.target.value }))}
                    className="crm-input"
                    placeholder="e.g. Create subscription from matched payment"
                />
            </ConfirmDialog>
        </div>
    );
}
