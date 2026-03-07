import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import DataTable from './DataTable';
import { useToast } from './ToastProvider';
import { flaggedPlatformLabel } from '../utils/flags';
import { candidateScore, scoreTone, toneClasses } from '../utils/scoring';

function formatDateLabel(dateString) {
    if (!dateString) return '—';
    const d = new Date(dateString);
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export default function PaymentImportDrawer({ open, onClose, platformOptions, onCommitSuccess }) {
    const queryClient = useQueryClient();
    const toast = useToast();

    const [step, setStep] = useState('upload');
    const [file, setFile] = useState(null);
    const [platformId, setPlatformId] = useState('');
    const [reason, setReason] = useState('Payment import from CRM');
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');
    const [preview, setPreview] = useState(null);
    const [batchId, setBatchId] = useState(null);
    const [commitResult, setCommitResult] = useState(null);

    // Preview pagination (client-side)
    const [previewPage, setPreviewPage] = useState(1);
    const [previewPerPage, setPreviewPerPage] = useState(50);

    // Status filter (clickable metric cards)
    const [statusFilter, setStatusFilter] = useState(null);

    // Manual match state
    const [matchingRow, setMatchingRow] = useState(null);
    const [matchSearchInput, setMatchSearchInput] = useState('');
    const [matchSearch, setMatchSearch] = useState('');
    const [selectedCandidate, setSelectedCandidate] = useState(null);

    // Reset state when drawer opens/closes
    useEffect(() => {
        if (open) {
            setStep('upload');
            setFile(null);
            setPlatformId('');
            setReason('Payment import from CRM');
            setDateFrom('');
            setDateTo('');
            setPreview(null);
            setBatchId(null);
            setCommitResult(null);
            setPreviewPage(1);
            setPreviewPerPage(50);
            setStatusFilter(null);
            setMatchingRow(null);
            setMatchSearchInput('');
            setMatchSearch('');
            setSelectedCandidate(null);
        }
    }, [open]);

    // Get phone prefix from selected platform
    const selectedPlatform = useMemo(
        () => platformOptions?.find((p) => String(p.platform_id) === String(platformId)),
        [platformOptions, platformId],
    );

    // Preview mutation
    const importPreviewMutation = useMutation({
        mutationFn: async (params) => {
            const formData = new FormData();
            formData.append('file', params.file);
            formData.append('platform_id', params.platformId);
            formData.append('reason', params.reason);
            formData.append('has_header', '1');
            if (params.dateFrom) formData.append('date_from', params.dateFrom);
            if (params.dateTo) formData.append('date_to', params.dateTo);
            const response = await api.post('/crm/payments/import/preview', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return response.data;
        },
        onSuccess: (result) => {
            setStep('preview');
            setPreview(result);
            setBatchId(result.batch_id);
            setPreviewPage(1);
            setStatusFilter(null);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Import preview failed.');
        },
    });

    // Commit mutation
    const importCommitMutation = useMutation({
        mutationFn: async ({ batchId: bid, reason: r }) => {
            const response = await api.post('/crm/payments/import/commit', {
                batch_id: bid,
                reason: r,
            });
            return response.data;
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setStep('committed');
            setCommitResult(result);
            toast.success('Payment import committed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Import commit failed.');
        },
    });

    // Manual match mutation
    const updateRowMatchMutation = useMutation({
        mutationFn: ({ rowId, clientId }) =>
            api.post('/crm/payments/import/row-match', { row_id: rowId, client_id: clientId }).then((r) => r.data),
        onSuccess: (result) => {
            setPreview((prev) => ({
                ...prev,
                rows: prev.rows.map((r) =>
                    r.id === result.row_id ? { ...r, suggested_match: result.suggested_match } : r
                ),
            }));
            setMatchingRow(null);
            setMatchSearchInput('');
            setMatchSearch('');
            setSelectedCandidate(null);
            toast.success('Client matched.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Match failed.');
        },
    });

    // Import candidate search query
    const { data: importCandidatesData, isLoading: candidatesLoading } = useQuery({
        queryKey: ['import-candidates', platformId, matchSearch],
        queryFn: () =>
            api.get('/crm/payments/import/candidates', {
                params: { platform_id: platformId, search: matchSearch },
            }).then((r) => r.data),
        enabled: Boolean(matchingRow) && Boolean(matchSearch) && matchSearch.length >= 2,
    });

    // Client-side date filtering (base set for counts)
    const dateFilteredRows = useMemo(() => {
        if (!preview?.rows) return [];
        return preview.rows.filter((row) => {
            const paidAt = row.normalized_row?.paid_at;
            if (!paidAt) return true;
            const rowDate = paidAt.slice(0, 10);
            if (dateFrom && rowDate < dateFrom) return false;
            if (dateTo && rowDate > dateTo) return false;
            return true;
        });
    }, [preview?.rows, dateFrom, dateTo]);

    // Summary counts + KES amounts (always from date-filtered rows, unaffected by status filter)
    const filteredSummary = useMemo(() => {
        const sumAmount = (rows) => rows.reduce((sum, r) => sum + Number(r.normalized_row?.amount || 0), 0);
        const validRows = dateFilteredRows.filter((r) => r.status === 'valid');
        const invalidRows = dateFilteredRows.filter((r) => r.status === 'invalid');
        const duplicateRows = dateFilteredRows.filter((r) => r.status === 'duplicate');
        const matchedRows = dateFilteredRows.filter((r) => r.status === 'valid' && r.suggested_match?.client_id);
        const unmatchedRows = dateFilteredRows.filter((r) => r.status === 'valid' && !r.suggested_match?.client_id);
        return {
            total: dateFilteredRows.length,       totalAmount: sumAmount(dateFilteredRows),
            valid: validRows.length,              validAmount: sumAmount(validRows),
            invalid: invalidRows.length,          invalidAmount: sumAmount(invalidRows),
            duplicate: duplicateRows.length,      duplicateAmount: sumAmount(duplicateRows),
            matched: matchedRows.length,          matchedAmount: sumAmount(matchedRows),
            unmatched: unmatchedRows.length,       unmatchedAmount: sumAmount(unmatchedRows),
        };
    }, [dateFilteredRows]);

    // Apply status filter on top of date filter
    const filteredRows = useMemo(() => {
        if (!statusFilter) return dateFilteredRows;
        return dateFilteredRows.filter((row) => {
            switch (statusFilter) {
                case 'valid': return row.status === 'valid';
                case 'invalid': return row.status === 'invalid';
                case 'duplicate': return row.status === 'duplicate';
                case 'matched': return row.status === 'valid' && row.suggested_match?.client_id;
                case 'unmatched': return row.status === 'valid' && !row.suggested_match?.client_id;
                default: return true;
            }
        });
    }, [dateFilteredRows, statusFilter]);

    // Pagination
    const paginatedRows = useMemo(() => {
        const start = (previewPage - 1) * previewPerPage;
        return filteredRows.slice(start, start + previewPerPage);
    }, [filteredRows, previewPage, previewPerPage]);

    const previewPagination = useMemo(() => ({
        current_page: previewPage,
        last_page: Math.ceil(filteredRows.length / previewPerPage) || 1,
        total: filteredRows.length,
        per_page: previewPerPage,
    }), [filteredRows.length, previewPage, previewPerPage]);

    // Candidate scoring
    const scoredCandidates = useMemo(() => {
        const candidates = importCandidatesData?.data || [];
        const pseudoPayment = matchingRow
            ? { phone: matchingRow.normalized_row?.phone, platform: { phone_prefix: selectedPlatform?.phone_prefix || '254' } }
            : null;
        return candidates
            .map((c) => {
                const score = candidateScore(pseudoPayment, c);
                return { ...c, score, tone: scoreTone(score) };
            })
            .sort((a, b) => b.score - a.score);
    }, [importCandidatesData, matchingRow, selectedPlatform]);

    // Metric card filter toggle
    const toggleStatusFilter = (filter) => {
        setStatusFilter((current) => current === filter ? null : filter);
        setPreviewPage(1);
    };

    // Handlers
    const handlePreview = () => {
        if (!file || !platformId || !reason.trim()) return;
        importPreviewMutation.mutate({ file, platformId, reason, dateFrom, dateTo });
    };

    const handleReparse = () => {
        if (!file || !platformId) return;
        importPreviewMutation.mutate({ file, platformId, reason, dateFrom, dateTo });
    };

    const handleCommit = () => {
        if (!batchId) return;
        importCommitMutation.mutate({ batchId, reason });
    };

    const handleClose = () => {
        if (importPreviewMutation.isPending || importCommitMutation.isPending) return;
        onClose();
    };

    const openMatchPanel = (row) => {
        setMatchingRow(row);
        setMatchSearchInput(row.normalized_row?.phone || row.normalized_row?.sender_name || '');
        setMatchSearch(row.normalized_row?.phone || '');
        setSelectedCandidate(null);
    };

    const handleConfirmMatch = () => {
        if (!matchingRow || !selectedCandidate) return;
        updateRowMatchMutation.mutate({ rowId: matchingRow.id, clientId: selectedCandidate.id });
    };

    // Columns
    const isMpesa = preview?.source_type === 'mpesa_xml';

    const importColumns = useMemo(() => {
        const cols = [
            { key: 'row_number', label: '#', width: '50px', render: (row) => <span className="text-slate-500">{row.row_number}</span> },
        ];

        if (isMpesa) {
            cols.push({
                key: 'date',
                label: 'Date',
                render: (row) => (
                    <span className="whitespace-nowrap text-slate-600">
                        {row.normalized_row?.paid_at ? new Date(row.normalized_row.paid_at).toLocaleDateString() : '—'}
                    </span>
                ),
            });
        }

        cols.push({
            key: 'phone',
            label: 'Phone',
            render: (row) => <span className="text-slate-800">{row.normalized_row?.phone || '—'}</span>,
        });

        if (isMpesa) {
            cols.push({
                key: 'sender',
                label: 'Sender',
                render: (row) => (
                    <span className="block max-w-[120px] truncate text-slate-600" title={row.normalized_row?.sender_name || ''}>
                        {row.normalized_row?.sender_name || '—'}
                    </span>
                ),
            });
        }

        cols.push({
            key: 'amount',
            label: 'Amount',
            render: (row) => (
                <span className="whitespace-nowrap text-slate-600">
                    {row.normalized_row?.amount ? `KES ${Number(row.normalized_row.amount).toLocaleString()}` : '—'}
                </span>
            ),
        });

        cols.push({
            key: 'reference',
            label: 'Reference',
            render: (row) => (
                <span className="block max-w-[100px] truncate font-mono text-slate-600" title={row.normalized_row?.transaction_reference || ''}>
                    {row.normalized_row?.transaction_reference || '—'}
                </span>
            ),
        });

        if (isMpesa) {
            cols.push({
                key: 'client',
                label: 'Client',
                render: (row) => {
                    if (row.status !== 'valid') return <span className="text-slate-400">—</span>;
                    if (row.suggested_match?.client_id) {
                        return (
                            <span className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200">
                                <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                <span className="max-w-[80px] truncate">{row.suggested_match.client_name || 'Matched'}</span>
                            </span>
                        );
                    }
                    return (
                        <button
                            type="button"
                            onClick={(e) => { e.stopPropagation(); openMatchPanel(row); }}
                            className="rounded-md border border-slate-300 bg-white px-2 py-0.5 text-[11px] font-semibold text-slate-700 transition hover:border-teal-400 hover:bg-teal-50 hover:text-teal-700"
                        >
                            Match
                        </button>
                    );
                },
            });

            cols.push({
                key: 'plan',
                label: 'Plan',
                render: (row) => {
                    if (!row.product_estimates?.length) {
                        return <span className="text-[10px] text-slate-400">Unknown</span>;
                    }
                    const est = row.product_estimates[0];
                    const tone = est.exact_match
                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
                        : 'bg-amber-50 text-amber-700 ring-amber-200';
                    return (
                        <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset ${tone}`}>
                            {est.product_name} {est.duration_key}
                            {row.product_estimates.length > 1 && (
                                <span className="ml-1 text-[9px] opacity-70">+{row.product_estimates.length - 1}</span>
                            )}
                        </span>
                    );
                },
            });
        }

        cols.push({
            key: 'status',
            label: 'Status',
            render: (row) => {
                if (row.status === 'duplicate') {
                    const isExisting = row.duplicate_type?.startsWith('duplicate_existing');
                    const isInFile = row.duplicate_type?.startsWith('duplicate_in_file');
                    return (
                        <span className="flex flex-col gap-0.5">
                            <span className="text-amber-600">duplicate</span>
                            <span className="text-[10px] text-amber-500">
                                {isExisting && row.duplicate_payment_id
                                    ? `Exists as Payment #${row.duplicate_payment_id}`
                                    : isInFile
                                        ? 'Duplicate within this file'
                                        : 'Duplicate detected'}
                            </span>
                        </span>
                    );
                }
                return (
                    <span className="flex items-center gap-1">
                        <span className={
                            row.status === 'valid' ? 'text-emerald-700'
                                : row.status === 'invalid' ? 'text-rose-600'
                                    : 'text-slate-500'
                        }>
                            {row.status}
                        </span>
                        {row.validation_errors?.length ? (
                            <span className="text-rose-500" title={row.validation_errors.join(', ')}>
                                ({row.validation_errors.length})
                            </span>
                        ) : null}
                    </span>
                );
            },
        });

        return cols;
    }, [isMpesa]);

    if (!open) return null;

    const isPending = importPreviewMutation.isPending || importCommitMutation.isPending;
    const canPreview = file && platformId && reason.trim();
    const canCommit = batchId && filteredSummary.valid > 0;

    return (
        <div className="fixed inset-0 z-[110] bg-slate-900/45" onClick={handleClose}>
            <aside
                className="absolute right-0 top-0 flex h-full w-full max-w-5xl flex-col border-l border-slate-200 bg-white shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                {/* ─── Sticky Header ─── */}
                <header className="shrink-0 border-b border-slate-200 bg-white/95 px-5 py-4 backdrop-blur">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                Payment import
                            </p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-900">
                                {step === 'committed' ? 'Import Complete'
                                    : step === 'preview' ? 'Import Preview'
                                        : 'Upload Payment File'}
                            </h3>
                            {step === 'preview' && preview && (
                                <p className="mt-1 text-xs text-slate-500">
                                    Batch #{batchId} &middot; {filteredSummary.valid} valid of {filteredSummary.total} rows
                                    {isMpesa && ` \u00b7 Source: MPESA XML`}
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={handleClose}
                            disabled={isPending}
                            className="rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                        >
                            Close
                        </button>
                    </div>
                </header>

                {/* ─── Scrollable Content ─── */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {step === 'upload' && (
                        <div className="mx-auto max-w-xl space-y-4">
                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                                <select
                                    value={platformId}
                                    onChange={(e) => setPlatformId(e.target.value)}
                                    className="crm-input"
                                >
                                    <option value="">Select market...</option>
                                    {(platformOptions || []).map((p) => (
                                        <option key={p.platform_id} value={p.platform_id}>{flaggedPlatformLabel(p)}</option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">CSV / XLSX / XML File</label>
                                <input
                                    type="file"
                                    accept=".csv,.xlsx,.txt,.xml"
                                    onChange={(e) => setFile(e.target.files?.[0] || null)}
                                    className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Date from</label>
                                    <input
                                        type="date"
                                        value={dateFrom}
                                        onChange={(e) => setDateFrom(e.target.value)}
                                        className="crm-input"
                                    />
                                </div>
                                <div>
                                    <label className="mb-1 block text-sm font-medium text-slate-700">Date to</label>
                                    <input
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) => setDateTo(e.target.value)}
                                        className="crm-input"
                                    />
                                </div>
                            </div>
                            <p className="text-[11px] text-slate-500">
                                Optional. Filters inbound payments by transaction date. Leave blank to include all dates.
                            </p>

                            <div>
                                <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                                <textarea
                                    rows={2}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                    className="crm-input"
                                />
                            </div>
                        </div>
                    )}

                    {step === 'preview' && preview && (
                        <div className="space-y-4">
                            {/* ── Metric Cards ── */}
                            {isMpesa && preview.parse_meta ? (
                                <div className="space-y-2">
                                    {/* Info-only row (parse stats) */}
                                    <div className="grid grid-cols-3 gap-2 text-center text-xs">
                                        <div className="rounded-md bg-slate-50 p-2">
                                            <p className="text-sm font-semibold text-slate-900">{preview.parse_meta.total_sms?.toLocaleString() ?? 0}</p>
                                            <p className="mt-0.5 text-slate-500">Total SMS</p>
                                        </div>
                                        <div className="rounded-md bg-slate-50 p-2">
                                            <p className="text-sm font-semibold text-slate-700">{preview.parse_meta.inbound_parsed?.toLocaleString() ?? 0}</p>
                                            <p className="mt-0.5 text-slate-500">Inbound</p>
                                        </div>
                                        <div className="rounded-md bg-slate-50 p-2" title={`Outbound: ${preview.parse_meta.skipped_outbound ?? 0}, Before cutoff: ${preview.parse_meta.skipped_pre_cutoff ?? 0}, After cutoff: ${preview.parse_meta.skipped_post_cutoff ?? 0}`}>
                                            <p className="text-sm font-semibold text-slate-600">{(preview.parse_meta.skipped_outbound ?? 0) + (preview.parse_meta.skipped_pre_cutoff ?? 0) + (preview.parse_meta.skipped_post_cutoff ?? 0)}</p>
                                            <p className="mt-0.5 text-slate-500">Skipped</p>
                                        </div>
                                    </div>
                                    {/* Clickable filter row */}
                                    <div className="grid grid-cols-4 gap-2 text-center text-xs">
                                        <button
                                            type="button"
                                            onClick={() => toggleStatusFilter('valid')}
                                            className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                                statusFilter === 'valid'
                                                    ? 'ring-2 ring-emerald-400 bg-emerald-50'
                                                    : 'bg-emerald-50/60 hover:bg-emerald-50 hover:ring-1 hover:ring-emerald-300'
                                            }`}
                                        >
                                            <p className="text-base font-semibold text-emerald-700">{filteredSummary.valid}</p>
                                            <p className="text-[10px] font-medium text-emerald-600/70">KES {filteredSummary.validAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-emerald-600">Valid</p>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => toggleStatusFilter('duplicate')}
                                            className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                                statusFilter === 'duplicate'
                                                    ? 'ring-2 ring-amber-400 bg-amber-50'
                                                    : 'bg-amber-50/60 hover:bg-amber-50 hover:ring-1 hover:ring-amber-300'
                                            }`}
                                        >
                                            <p className="text-base font-semibold text-amber-700">{filteredSummary.duplicate}</p>
                                            <p className="text-[10px] font-medium text-amber-600/70">KES {filteredSummary.duplicateAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-amber-600">Duplicate</p>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => toggleStatusFilter('matched')}
                                            className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                                statusFilter === 'matched'
                                                    ? 'ring-2 ring-teal-400 bg-teal-50'
                                                    : 'bg-teal-50/60 hover:bg-teal-50 hover:ring-1 hover:ring-teal-300'
                                            }`}
                                        >
                                            <p className="text-base font-semibold text-teal-700">{filteredSummary.matched}</p>
                                            <p className="text-[10px] font-medium text-teal-600/70">KES {filteredSummary.matchedAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-teal-600">Matched</p>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => toggleStatusFilter('unmatched')}
                                            className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                                statusFilter === 'unmatched'
                                                    ? 'ring-2 ring-slate-400 bg-slate-100'
                                                    : 'bg-slate-50 hover:bg-slate-100 hover:ring-1 hover:ring-slate-300'
                                            }`}
                                        >
                                            <p className="text-base font-semibold text-slate-700">{filteredSummary.unmatched}</p>
                                            <p className="text-[10px] font-medium text-slate-500/70">KES {filteredSummary.unmatchedAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-slate-500">Unmatched</p>
                                        </button>
                                    </div>
                                </div>
                            ) : (
                                <div className="grid grid-cols-4 gap-2 text-center text-xs">
                                    <button
                                        type="button"
                                        onClick={() => setStatusFilter(null)}
                                        className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                            statusFilter === null
                                                ? 'ring-2 ring-slate-400 bg-slate-100'
                                                : 'bg-slate-50 hover:bg-slate-100 hover:ring-1 hover:ring-slate-300'
                                        }`}
                                    >
                                        <p className="text-base font-semibold text-slate-900">{filteredSummary.total}</p>
                                        <p className="text-[10px] font-medium text-slate-500/70">KES {filteredSummary.totalAmount.toLocaleString()}</p>
                                        <p className="mt-0.5 text-slate-500">Total</p>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggleStatusFilter('valid')}
                                        className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                            statusFilter === 'valid'
                                                ? 'ring-2 ring-emerald-400 bg-emerald-50'
                                                : 'bg-emerald-50/60 hover:bg-emerald-50 hover:ring-1 hover:ring-emerald-300'
                                        }`}
                                    >
                                        <p className="text-base font-semibold text-emerald-700">{filteredSummary.valid}</p>
                                        <p className="text-[10px] font-medium text-emerald-600/70">KES {filteredSummary.validAmount.toLocaleString()}</p>
                                        <p className="mt-0.5 text-emerald-600">Valid</p>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggleStatusFilter('invalid')}
                                        className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                            statusFilter === 'invalid'
                                                ? 'ring-2 ring-rose-400 bg-rose-50'
                                                : 'bg-rose-50/60 hover:bg-rose-50 hover:ring-1 hover:ring-rose-300'
                                        }`}
                                    >
                                        <p className="text-base font-semibold text-rose-700">{filteredSummary.invalid}</p>
                                        <p className="text-[10px] font-medium text-rose-600/70">KES {filteredSummary.invalidAmount.toLocaleString()}</p>
                                        <p className="mt-0.5 text-rose-600">Invalid</p>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => toggleStatusFilter('duplicate')}
                                        className={`rounded-md p-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                            statusFilter === 'duplicate'
                                                ? 'ring-2 ring-amber-400 bg-amber-50'
                                                : 'bg-amber-50/60 hover:bg-amber-50 hover:ring-1 hover:ring-amber-300'
                                        }`}
                                    >
                                        <p className="text-base font-semibold text-amber-700">{filteredSummary.duplicate}</p>
                                        <p className="text-[10px] font-medium text-amber-600/70">KES {filteredSummary.duplicateAmount.toLocaleString()}</p>
                                        <p className="mt-0.5 text-amber-600">Duplicate</p>
                                    </button>
                                </div>
                            )}

                            {/* ── Date Range Filter ── */}
                            <div className="flex flex-wrap items-end gap-3 rounded-md border border-slate-200 bg-slate-50/50 px-3 py-2.5">
                                <div className="flex items-center gap-2">
                                    <label className="text-xs font-medium text-slate-600">From</label>
                                    <input
                                        type="date"
                                        value={dateFrom}
                                        onChange={(e) => { setDateFrom(e.target.value); setPreviewPage(1); }}
                                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-1 focus:ring-teal-400"
                                    />
                                </div>
                                <div className="flex items-center gap-2">
                                    <label className="text-xs font-medium text-slate-600">To</label>
                                    <input
                                        type="date"
                                        value={dateTo}
                                        onChange={(e) => { setDateTo(e.target.value); setPreviewPage(1); }}
                                        className="rounded-md border border-slate-300 bg-white px-2 py-1 text-xs text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-1 focus:ring-teal-400"
                                    />
                                </div>
                                {(dateFrom || dateTo) && (
                                    <button
                                        type="button"
                                        onClick={() => { setDateFrom(''); setDateTo(''); setPreviewPage(1); }}
                                        className="text-xs font-medium text-slate-500 hover:text-slate-700"
                                    >
                                        Clear
                                    </button>
                                )}
                                <button
                                    type="button"
                                    onClick={handleReparse}
                                    disabled={importPreviewMutation.isPending || !file}
                                    className="ml-auto rounded-md border border-teal-300 bg-white px-2.5 py-1 text-xs font-semibold text-teal-700 transition hover:bg-teal-50 disabled:opacity-50"
                                >
                                    {importPreviewMutation.isPending ? 'Re-parsing...' : 'Re-parse with dates'}
                                </button>
                            </div>

                            {/* ── Date Range Info ── */}
                            {preview.parse_meta?.date_range_start && (
                                <p className="text-xs text-slate-500">
                                    Parsed date range: {formatDateLabel(preview.parse_meta.date_range_start)} — {formatDateLabel(preview.parse_meta.date_range_end)}
                                    {(dateFrom || dateTo) && (
                                        <span className="ml-2 rounded-md bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-inset ring-amber-200">
                                            Client-side filter active
                                        </span>
                                    )}
                                </p>
                            )}

                            {/* ── Manual Match Panel ── */}
                            {matchingRow && (
                                <section className="rounded-md border border-teal-200 bg-teal-50/30 p-4">
                                    <div className="mb-3 flex items-start justify-between gap-3">
                                        <div>
                                            <p className="text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">Manual match</p>
                                            <p className="text-sm font-semibold text-slate-900">
                                                Row #{matchingRow.row_number} — {matchingRow.normalized_row?.sender_name || matchingRow.normalized_row?.phone || 'Unknown'}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                KES {Number(matchingRow.normalized_row?.amount || 0).toLocaleString()} &middot; {matchingRow.normalized_row?.transaction_reference || 'No ref'}
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => { setMatchingRow(null); setSelectedCandidate(null); }}
                                            className="rounded-md border border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 hover:bg-white"
                                        >
                                            Cancel
                                        </button>
                                    </div>

                                    <form
                                        onSubmit={(e) => { e.preventDefault(); setMatchSearch(matchSearchInput.trim()); }}
                                        className="mb-3 flex gap-2"
                                    >
                                        <input
                                            value={matchSearchInput}
                                            onChange={(e) => setMatchSearchInput(e.target.value)}
                                            placeholder="Search by name, phone, email, or CRM ID..."
                                            className="flex-1 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 focus:border-teal-400 focus:outline-none focus:ring-1 focus:ring-teal-400"
                                        />
                                        <button
                                            type="submit"
                                            disabled={matchSearchInput.trim().length < 2}
                                            className="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50"
                                        >
                                            Search
                                        </button>
                                    </form>

                                    {candidatesLoading && (
                                        <p className="py-4 text-center text-xs text-slate-500">Searching clients...</p>
                                    )}

                                    {!candidatesLoading && scoredCandidates.length > 0 && (
                                        <div className="max-h-52 space-y-1.5 overflow-y-auto">
                                            {scoredCandidates.map((c) => (
                                                <label
                                                    key={c.id}
                                                    className={`flex cursor-pointer items-center gap-3 rounded-md border px-3 py-2 transition ${
                                                        selectedCandidate?.id === c.id
                                                            ? 'border-teal-400 bg-teal-50 ring-1 ring-teal-400'
                                                            : 'border-slate-200 bg-white hover:border-slate-300'
                                                    }`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="import-match-candidate"
                                                        checked={selectedCandidate?.id === c.id}
                                                        onChange={() => setSelectedCandidate(c)}
                                                        className="accent-teal-600"
                                                    />
                                                    <div className="flex-1 min-w-0">
                                                        <p className="truncate text-sm font-medium text-slate-900">{c.name || `Client #${c.id}`}</p>
                                                        <p className="truncate text-xs text-slate-500">
                                                            {c.phone_normalized || 'No phone'} &middot; {c.email || 'No email'} &middot; {c.profile_status}
                                                        </p>
                                                    </div>
                                                    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ${toneClasses(c.tone)}`}>
                                                        {c.score}%
                                                    </span>
                                                </label>
                                            ))}
                                        </div>
                                    )}

                                    {!candidatesLoading && matchSearch && scoredCandidates.length === 0 && (
                                        <p className="py-3 text-center text-xs text-slate-500">No clients found matching &ldquo;{matchSearch}&rdquo;</p>
                                    )}

                                    {selectedCandidate && (
                                        <div className="mt-3 flex justify-end">
                                            <button
                                                type="button"
                                                onClick={handleConfirmMatch}
                                                disabled={updateRowMatchMutation.isPending}
                                                className="rounded-md bg-teal-600 px-4 py-1.5 text-xs font-semibold text-white transition hover:bg-teal-700 disabled:opacity-50"
                                            >
                                                {updateRowMatchMutation.isPending ? 'Matching...' : `Match to ${selectedCandidate.name || `#${selectedCandidate.id}`}`}
                                            </button>
                                        </div>
                                    )}
                                </section>
                            )}

                            {/* ── DataTable ── */}
                            <DataTable
                                columns={importColumns}
                                data={paginatedRows}
                                pagination={previewPagination}
                                onPageChange={setPreviewPage}
                                perPage={previewPerPage}
                                onPerPageChange={(val) => { setPreviewPerPage(val); setPreviewPage(1); }}
                                perPageOptions={[25, 50, 100, 250]}
                                compact
                                rowIdKey="id"
                                emptyMessage="No import rows match the current filters."
                            />

                            {/* ── Commit Impact Preview ── */}
                            {filteredSummary.valid > 0 && (
                                <section className="rounded-md border border-slate-200 bg-slate-50/50 p-3">
                                    <p className="mb-2 text-xs font-semibold uppercase tracking-[0.09em] text-slate-500">
                                        After commit &mdash; Payments page impact
                                    </p>
                                    <div className="grid grid-cols-4 gap-3 text-center text-xs">
                                        <div>
                                            <p className="text-sm font-semibold text-emerald-700">{filteredSummary.matched}</p>
                                            <p className="text-[10px] text-emerald-600/70">KES {filteredSummary.matchedAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-slate-500">Confirmed</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-sky-700">{filteredSummary.unmatched}</p>
                                            <p className="text-[10px] text-sky-600/70">KES {filteredSummary.unmatchedAmount.toLocaleString()}</p>
                                            <p className="mt-0.5 text-slate-500">Unmatched Confirmed</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-slate-400">0</p>
                                            <p className="mt-0.5 text-slate-400">Awaiting Payment</p>
                                        </div>
                                        <div>
                                            <p className="text-sm font-semibold text-slate-400">0</p>
                                            <p className="mt-0.5 text-slate-400">Failed</p>
                                        </div>
                                    </div>
                                    <p className="mt-2 text-[10px] text-slate-400">
                                        MPESA records import as &ldquo;completed&rdquo;. Matched rows go to Confirmed, unmatched rows need manual matching on the Payments page.
                                    </p>
                                </section>
                            )}
                        </div>
                    )}

                    {step === 'committed' && commitResult && (
                        <div className="mx-auto max-w-lg space-y-4 py-8 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100">
                                <svg className="h-6 w-6 text-emerald-600" fill="none" viewBox="0 0 24 24" strokeWidth={2} stroke="currentColor">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                </svg>
                            </div>
                            <h4 className="text-lg font-semibold text-slate-900">Import Complete</h4>
                            <p className="text-sm text-slate-600">Batch #{batchId} has been committed.</p>
                            {commitResult.summary && (
                                <div className="mx-auto grid max-w-xs grid-cols-2 gap-3 text-center text-xs">
                                    <div className="rounded-md bg-emerald-50 p-3">
                                        <p className="text-lg font-semibold text-emerald-700">{commitResult.summary.committed_rows ?? commitResult.summary.committed ?? 0}</p>
                                        <p className="text-emerald-600">Committed</p>
                                    </div>
                                    <div className="rounded-md bg-slate-50 p-3">
                                        <p className="text-lg font-semibold text-slate-600">{commitResult.summary.skipped_rows ?? commitResult.summary.skipped ?? 0}</p>
                                        <p className="text-slate-500">Skipped</p>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* ─── Sticky Footer ─── */}
                <footer className="shrink-0 border-t border-slate-200 bg-white px-5 py-3">
                    <div className="flex items-center justify-between gap-3">
                        <div className="text-xs text-slate-500">
                            {step === 'preview' && (
                                <>
                                    {statusFilter
                                        ? <>{filteredRows.length} row{filteredRows.length !== 1 ? 's' : ''} ({statusFilter}) &middot; <button type="button" onClick={() => setStatusFilter(null)} className="font-medium text-teal-600 hover:text-teal-700">Clear filter</button></>
                                        : <>{filteredSummary.valid} valid ({filteredSummary.matched} matched, {filteredSummary.unmatched} unmatched) &middot; KES {filteredSummary.validAmount.toLocaleString()}</>
                                    }
                                </>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {step === 'upload' && (
                                <button
                                    type="button"
                                    onClick={handlePreview}
                                    disabled={!canPreview || isPending}
                                    className="crm-btn-primary disabled:opacity-50"
                                >
                                    {importPreviewMutation.isPending ? (
                                        <span className="flex items-center gap-2">
                                            <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                            </svg>
                                            Parsing...
                                        </span>
                                    ) : 'Preview Import'}
                                </button>
                            )}
                            {step === 'preview' && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => { setStep('upload'); setPreview(null); setBatchId(null); }}
                                        className="crm-btn-secondary"
                                    >
                                        Back
                                    </button>
                                    <button
                                        type="button"
                                        onClick={handleCommit}
                                        disabled={!canCommit || isPending}
                                        className="crm-btn-primary disabled:opacity-50"
                                    >
                                        {importCommitMutation.isPending ? (
                                            <span className="flex items-center gap-2">
                                                <svg className="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                                                </svg>
                                                Committing...
                                            </span>
                                        ) : 'Commit Import'}
                                    </button>
                                </>
                            )}
                            {step === 'committed' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        if (typeof onCommitSuccess === 'function') onCommitSuccess();
                                        handleClose();
                                    }}
                                    className="crm-btn-primary"
                                >
                                    Done
                                </button>
                            )}
                        </div>
                    </div>
                </footer>
            </aside>
        </div>
    );
}
