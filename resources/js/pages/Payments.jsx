import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import RowActionMenu from '../components/RowActionMenu';
import StatusBadge from '../components/StatusBadge';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';
import { normalizePhone } from '../utils/phone';

const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';

function formatCurrency(amount, currency = 'KES') {
    return `${currency} ${Number(amount || 0).toLocaleString()}`;
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

function candidateScore(payment, candidate) {
    let score = 45;
    const phonePrefix = payment?.platform?.phone_prefix || candidate?.platform?.phone_prefix || '254';
    const paymentPhone = normalizePhone(payment?.phone, phonePrefix);
    const candidatePhone = normalizePhone(candidate?.phone_normalized, phonePrefix);

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

function toAmount(value) {
    const amount = Number(value || 0);
    return Number.isFinite(amount) ? amount : 0;
}

function hoursSince(timestamp) {
    if (!timestamp) return Infinity;
    const parsed = new Date(timestamp).getTime();
    if (Number.isNaN(parsed)) return Infinity;
    const diffMs = Date.now() - parsed;
    return diffMs > 0 ? diffMs / (1000 * 60 * 60) : 0;
}

function pendingRecommendation(payment) {
    if (!['initiated', 'pending'].includes(payment?.status)) {
        return null;
    }

    const ageHours = hoursSince(payment.created_at);
    if (ageHours < 1) {
        return { label: 'Wait for callback', tone: 'default' };
    }
    if (ageHours < 24) {
        return { label: 'Send payment link now', tone: 'warning' };
    }
    if (ageHours < 72) {
        return { label: 'Retry STK then follow up', tone: 'warning' };
    }

    return { label: 'Escalate and follow up manually', tone: 'danger' };
}

function recommendationClass(tone) {
    if (tone === 'danger') {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }
    if (tone === 'warning') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }
    return 'border-slate-200 bg-slate-100 text-slate-600';
}

function recommendationDotClass(tone) {
    if (tone === 'danger') {
        return 'bg-rose-500';
    }
    if (tone === 'warning') {
        return 'bg-amber-500';
    }
    return 'bg-slate-400';
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return date.toLocaleString();
}

function titleize(value) {
    if (!value) return '—';
    return String(value)
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

export default function Payments() {
    const allowedStatuses = new Set(['awaiting_payment', 'completed', 'initiated', 'pending', 'failed', 'recovery_queue']);
    const allowedMatchFilters = new Set(['matched', 'unmatched']);
    const allowedSourceFilters = new Set(['gateway', 'excel_import']);
    const allowedConfidenceFilters = new Set(['high', 'medium', 'low']);
    const allowedReviewStateFilters = new Set(['open', 'manual_review', 'resolved']);
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const [searchParams] = useSearchParams();
    const toast = useToast();
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(() => {
        const requested = (searchParams.get('status') || '').trim();
        return allowedStatuses.has(requested) ? requested : '';
    });
    const [matchFilter, setMatchFilter] = useState(() => {
        const requested = (searchParams.get('matched') || '').trim();
        return allowedMatchFilters.has(requested) ? requested : '';
    });
    const [sourceFilter, setSourceFilter] = useState(() => {
        const requested = (searchParams.get('source') || '').trim();
        return allowedSourceFilters.has(requested) ? requested : '';
    });
    const [confidenceFilter, setConfidenceFilter] = useState(() => {
        const requested = (searchParams.get('match_confidence') || '').trim();
        return allowedConfidenceFilters.has(requested) ? requested : '';
    });
    const [reviewStateFilter, setReviewStateFilter] = useState(() => {
        const requested = (searchParams.get('review_state') || '').trim();
        return allowedReviewStateFilters.has(requested) ? requested : '';
    });
    const [platformFilter, setPlatformFilter] = useState(() => {
        const requested = normalizePlatformFilter(searchParams.get('platform_id'));
        if (requested) {
            return requested;
        }

        if (typeof window === 'undefined') {
            return '';
        }

        return normalizePlatformFilter(window.localStorage.getItem(DASHBOARD_MARKET_STORAGE_KEY));
    });
    const [showAdvancedFilters, setShowAdvancedFilters] = useState(() => !!(sourceFilter || confidenceFilter || reviewStateFilter));
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
        preview: null,
        step: 'reason', // 'reason' | 'preview'
    });
    const [retryStkDialog, setRetryStkDialog] = useState({ open: false, payment: null, reason: 'Retry STK from payment queue' });
    const [sendLinkDialog, setSendLinkDialog] = useState({
        open: false,
        payment: null,
        channel: 'sms',
        provider: '',
        phone: '',
        reason: 'Send payment link from CRM',
    });
    const [createSubDialog, setCreateSubDialog] = useState({ open: false, payment: null, reason: 'Create subscription from matched payment' });
    const [diagnosticsDrawer, setDiagnosticsDrawer] = useState({ open: false, payment: null });
    const [manualCloseDialog, setManualCloseDialog] = useState({
        open: false,
        payment: null,
        category: 'timeout',
        reason: '',
    });
    const [importDialog, setImportDialog] = useState({
        open: false,
        step: 'upload', // 'upload' | 'preview' | 'committed'
        file: null,
        platformId: '',
        reason: 'Payment import from CRM',
        preview: null,
        batchId: null,
        commitResult: null,
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'payments-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const platformOptions = integrationData?.platforms || [];
    const selectedPlatformCurrency = useMemo(() => {
        if (!platformFilter) {
            return '';
        }

        const selected = platformOptions.find((platform) => String(platform.platform_id) === String(platformFilter));
        return selected?.currency || '';
    }, [platformFilter, platformOptions]);

    const resolveCurrency = (currencyCode) => currencyCode || selectedPlatformCurrency || 'KES';

    const { data, isLoading } = useQuery({
        queryKey: ['payments', page, perPage, search, statusFilter, matchFilter, platformFilter, sourceFilter, confidenceFilter, reviewStateFilter],
        queryFn: () =>
            api.get('/crm/payments', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    ...(matchFilter && { matched: matchFilter }),
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                    ...(sourceFilter && { source: sourceFilter }),
                    ...(confidenceFilter && { match_confidence: confidenceFilter }),
                    ...(reviewStateFilter && { review_state: reviewStateFilter }),
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

    const {
        data: diagnosticsData,
        isLoading: diagnosticsLoading,
        error: diagnosticsError,
    } = useQuery({
        queryKey: ['payment-diagnostics', diagnosticsDrawer.payment?.id],
        queryFn: () => api.get(`/crm/payments/${diagnosticsDrawer.payment.id}/diagnostics`).then((response) => response.data),
        enabled: diagnosticsDrawer.open && !!diagnosticsDrawer.payment?.id,
    });

    const autoMatchMutation = useMutation({
        mutationFn: (paymentId) => api.post(`/crm/payments/${paymentId}/auto-match`).then((response) => response.data),
        onSuccess: (_, paymentId) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', paymentId] });
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
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setSelectedPayment(null);
            setSelectedClientId('');
            setConfirmReason('Manual payment match from queue');
            toast.success('Payment match confirmed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual payment match failed.');
        },
    });

    const batchMatchDryRunMutation = useMutation({
        mutationFn: (reason) =>
            api.post('/crm/payments/batch-match', {
                reason,
                dry_run: true,
            }).then((response) => response.data),
        onSuccess: (result) => {
            setQueueAutoMatchDialog((d) => ({ ...d, preview: result, step: 'preview' }));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Auto-match preview failed.');
            setQueueAutoMatchDialog((d) => ({ ...d, open: false }));
        },
    });

    const batchMatchMutation = useMutation({
        mutationFn: (reason) =>
            api.post('/crm/payments/batch-match', {
                reason,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' });
            toast.success('Queue auto-match completed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Queue auto-match failed.');
        },
    });

    const retryStkMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/retry-stk`, { reason: reason || undefined }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setRetryStkDialog({ open: false, payment: null, reason: 'Retry STK from payment queue' });
            toast.success('STK push sent. Customer should complete the request on their phone.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Retry STK failed.');
        },
    });

    const sendPaymentLinkMutation = useMutation({
        mutationFn: ({ paymentId, channel, provider, phone, reason }) =>
            api.post(`/crm/payments/${paymentId}/send-payment-link`, {
                channel,
                ...(provider ? { provider } : {}),
                ...(phone && { phone: phone.trim() }),
                reason: reason || undefined,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setSendLinkDialog({ open: false, payment: null, channel: 'sms', provider: '', phone: '', reason: 'Send payment link from CRM' });
            toast.success('Payment link sent by SMS.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Send payment link failed.');
        },
    });

    const createSubscriptionMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/create-subscription`, { reason: reason || undefined }).then((response) => response.data),
        onSuccess: (result, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setCreateSubDialog({ open: false, payment: null, reason: 'Create subscription from matched payment' });
            toast.success(`Subscription created (Deal #${result.deal?.id}). Expires ${new Date(result.deal?.expires_at).toLocaleDateString()}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Create subscription failed.');
        },
    });

    const manualCloseMutation = useMutation({
        mutationFn: ({ paymentId, category, reason }) =>
            api.post(`/crm/payments/${paymentId}/manual-close`, { category, reason }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            setManualCloseDialog({ open: false, payment: null, category: 'timeout', reason: '' });
            toast.success('Payment closed manually.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Manual payment closure failed.');
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

    const reviewStateMutation = useMutation({
        mutationFn: ({ paymentId, state, reason }) =>
            api.post(`/crm/payments/${paymentId}/review-state`, {
                state,
                reason,
            }).then((response) => response.data),
        onSuccess: (_, variables) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });
            toast.success(variables.state === 'resolved' ? 'Payment review marked resolved.' : 'Payment moved to manual review.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Updating review state failed.');
        },
    });

    const importPreviewMutation = useMutation({
        mutationFn: async ({ file, platformId, reason }) => {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('platform_id', platformId);
            formData.append('reason', reason);
            formData.append('has_header', '1');
            const response = await api.post('/crm/payments/import/preview', formData, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            return response.data;
        },
        onSuccess: (result) => {
            setImportDialog((d) => ({
                ...d,
                step: 'preview',
                preview: result,
                batchId: result.batch_id,
            }));
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Import preview failed.');
        },
    });

    const importCommitMutation = useMutation({
        mutationFn: async ({ batchId, reason }) => {
            const response = await api.post('/crm/payments/import/commit', {
                batch_id: batchId,
                reason,
            });
            return response.data;
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            setImportDialog((d) => ({ ...d, step: 'committed', commitResult: result }));
            toast.success('Payment import committed.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Import commit failed.');
        },
    });

    const handleImportConfirm = () => {
        if (importDialog.step === 'upload') {
            importPreviewMutation.mutate({
                file: importDialog.file,
                platformId: importDialog.platformId,
                reason: importDialog.reason,
            });
        } else if (importDialog.step === 'preview') {
            importCommitMutation.mutate({
                batchId: importDialog.batchId,
                reason: importDialog.reason,
            });
        } else {
            setImportDialog({
                open: false,
                step: 'upload',
                file: null,
                platformId: '',
                reason: 'Payment import from CRM',
                preview: null,
                batchId: null,
                commitResult: null,
            });
        }
    };

    const handleSearch = (event) => {
        event.preventDefault();
        setSearch(searchInput.trim());
        setPage(1);
    };

    const openImportTemplate = () => {
        if (typeof window === 'undefined') {
            return;
        }

        window.open('/api/crm/payments/import/template', '_blank', 'noopener,noreferrer');
    };

    const openManualMatch = (paymentRow) => {
        setSelectedPayment(paymentRow);
        setSelectedClientId('');
        setConfirmReason('Manual payment match from queue');
        setCandidateSearch('');
        setCandidateSearchInput('');
    };

    const openDiagnostics = (paymentRow) => {
        setDiagnosticsDrawer({ open: true, payment: paymentRow });
    };

    const closeDiagnostics = () => {
        setDiagnosticsDrawer({ open: false, payment: null });
    };

    const triggerRecommendation = (actionKey, paymentRow) => {
        if (!paymentRow) return;

        if (actionKey === 'retry_stk') {
            closeDiagnostics();
            setRetryStkDialog({ open: true, payment: paymentRow, reason: 'Retry STK from diagnostics drawer' });
            return;
        }

        if (actionKey === 'send_link') {
            closeDiagnostics();
            setSendLinkDialog({
                open: true,
                payment: paymentRow,
                channel: 'sms',
                provider: '',
                phone: paymentRow.phone || '',
                reason: 'Send payment link from diagnostics drawer',
            });
            return;
        }

        if (actionKey === 'auto_match') {
            autoMatchMutation.mutate(paymentRow.id);
            return;
        }

        if (actionKey === 'manual_match') {
            closeDiagnostics();
            openManualMatch(paymentRow);
            return;
        }

        if (actionKey === 'create_subscription') {
            closeDiagnostics();
            setCreateSubDialog({ open: true, payment: paymentRow, reason: 'Create subscription from diagnostics drawer' });
            return;
        }

        if (actionKey === 'manual_close') {
            closeDiagnostics();
            setManualCloseDialog({ open: true, payment: paymentRow, category: 'timeout', reason: '' });
            return;
        }

        if (actionKey === 'manual_review') {
            reviewStateMutation.mutate({
                paymentId: paymentRow.id,
                state: 'manual_review',
                reason: 'Marked for manual review from diagnostics drawer',
            });
            return;
        }

        if (actionKey === 'wait_callback') {
            toast.info('Keep monitoring callback updates for this payment before retrying.');
        }
    };

    const rows = data?.data || [];

    const summary = useMemo(() => {
        if (data?.stats) {
            return {
                awaitingCount: Number(data.stats.pending || 0),
                awaitingAmount: toAmount(data.stats.pending_amount),
                confirmedCount: Number(data.stats.confirmed || 0),
                confirmedAmount: toAmount(data.stats.confirmed_amount),
                unmatchedCount: Number((data.stats.unmatched_review ?? data.stats.unmatched) || 0),
                unmatchedAmount: toAmount(data.stats.unmatched_review_amount),
                failedCount: Number(data.stats.failed || 0),
                failedAmount: toAmount(data.stats.failed_amount),
            };
        }

        const awaitingRows = rows.filter((row) => ['initiated', 'pending'].includes(row.status));
        const completedRows = rows.filter((row) => row.status === 'completed');
        const unmatchedRows = completedRows.filter((row) => !row.client_id);
        const failedRows = rows.filter((row) => row.status === 'failed');
        const sumAmount = (list) => list.reduce((sum, row) => sum + toAmount(row.amount), 0);

        return {
            awaitingCount: awaitingRows.length,
            awaitingAmount: sumAmount(awaitingRows),
            confirmedCount: completedRows.length,
            confirmedAmount: sumAmount(completedRows),
            unmatchedCount: unmatchedRows.length,
            unmatchedAmount: sumAmount(unmatchedRows),
            failedCount: failedRows.length,
            failedAmount: sumAmount(failedRows),
        };
    }, [data?.stats, rows]);

    const activeMetric = useMemo(() => {
        if (statusFilter === 'awaiting_payment') return 'awaiting';
        if (statusFilter === 'completed' && matchFilter === '') return 'confirmed';
        if (statusFilter === 'completed' && matchFilter === 'unmatched') return 'unmatched';
        if (statusFilter === 'failed') return 'failed';
        return '';
    }, [statusFilter, matchFilter]);

    const applyMetricFilter = (metricKey) => {
        if (metricKey === 'awaiting') {
            setStatusFilter('awaiting_payment');
            setMatchFilter('');
        } else if (metricKey === 'confirmed') {
            setStatusFilter('completed');
            setMatchFilter('');
        } else if (metricKey === 'unmatched') {
            setStatusFilter('completed');
            setMatchFilter('unmatched');
        } else if (metricKey === 'failed') {
            setStatusFilter('failed');
            setMatchFilter('');
        }
        setPage(1);
    };

    const diagnosticsPayment = diagnosticsData?.payment || diagnosticsDrawer.payment;

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
        if (typeof window === 'undefined') {
            return;
        }

        if (platformFilter) {
            window.localStorage.setItem(DASHBOARD_MARKET_STORAGE_KEY, platformFilter);
            return;
        }

        window.localStorage.removeItem(DASHBOARD_MARKET_STORAGE_KEY);
    }, [platformFilter]);

    useEffect(() => {
        if (!platformFilter || !platformOptions.length) {
            return;
        }

        const platformStillAccessible = platformOptions.some(
            (platform) => String(platform.platform_id) === String(platformFilter),
        );

        if (!platformStillAccessible) {
            setPlatformFilter('');
            setPage(1);
        }
    }, [platformFilter, platformOptions]);

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
            render: (row) => <span className="text-sm font-semibold text-slate-900">{formatCurrency(row.amount, resolveCurrency(row.currency))}</span>,
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
            render: (row) => row.reconciliation_confidence ? <StatusBadge status={row.reconciliation_confidence} /> : <span className="text-xs text-slate-400">—</span>,
        },
        {
            key: 'review_state',
            label: 'Review',
            render: (row) => row.reconciliation_state ? <StatusBadge status={row.reconciliation_state} /> : <span className="text-xs text-slate-400">—</span>,
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
            label: '',
            render: (row) => {
                const isFailed = row.status === 'failed' || row.status === 'initiated' || row.status === 'pending';
                const isCompletedUnmatched = row.status === 'completed' && !row.client_id;
                const isMatchedNoDeal = row.status === 'completed' && row.client_id && !row.deal_id;
                const isLowConfidence = row.status === 'completed' && row.reconciliation_confidence === 'low' && row.reconciliation_state !== 'manual_review';
                const isManualReview = row.reconciliation_state === 'manual_review';

                let primary = null;
                if (isManualReview) {
                    primary = { label: 'Resolve', variant: 'success', onClick: () => reviewStateMutation.mutate({ paymentId: row.id, state: 'resolved', reason: 'Manual review resolved from payment queue' }) };
                } else if (isFailed) {
                    primary = { label: 'Retry STK', variant: 'warning', onClick: () => setRetryStkDialog({ open: true, payment: row, reason: 'Retry STK from payment queue' }) };
                } else if (isCompletedUnmatched) {
                    primary = { label: 'Auto-match', variant: 'primary', onClick: () => autoMatchMutation.mutate(row.id) };
                } else if (isMatchedNoDeal) {
                    primary = {
                        label: 'Create Sub',
                        variant: 'success',
                        onClick: () => {
                            if (row.reconciliation_confidence !== 'high') {
                                toast.warning('Subscription creation is limited to high-confidence reconciled payments.');
                                return;
                            }
                            setCreateSubDialog({ open: true, payment: row, reason: 'Create subscription from matched payment' });
                        },
                    };
                } else if (isLowConfidence) {
                    primary = { label: 'Mark review', variant: 'warning', onClick: () => reviewStateMutation.mutate({ paymentId: row.id, state: 'manual_review', reason: 'Marked for manual review from payment queue' }) };
                }

                const overflow = [
                    isFailed && { key: 'send-link', label: 'Send payment link', onClick: () => setSendLinkDialog({ open: true, payment: row, channel: 'sms', provider: '', phone: row.phone || '', reason: 'Send payment link from CRM' }) },
                    isCompletedUnmatched && { key: 'manual-match', label: 'Match manually', onClick: () => openManualMatch(row) },
                    { key: 'diagnose', label: 'Diagnose', onClick: () => openDiagnostics(row) },
                ].filter(Boolean);

                return (
                    <RowActionMenu
                        primaryAction={primary}
                        actions={overflow}
                        badge={row.client_id ? 'Matched' : null}
                    />
                );
            },
        },
    ];

    return (
        <div className="space-y-4">
            <PageHeader
                title="Payments"
                subtitle={data?.total ? `${data.total.toLocaleString()} payment records` : 'Incoming payments and match queue'}
                actions={(
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            onClick={openImportTemplate}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Download import template
                        </button>
                        <button
                            type="button"
                            onClick={() => setImportDialog({
                                open: true,
                                step: 'upload',
                                file: null,
                                platformId: platformFilter || '',
                                reason: 'Payment import from CRM',
                                preview: null,
                                batchId: null,
                                commitResult: null,
                            })}
                            className="crm-btn-secondary px-3 py-2"
                        >
                            Upload payments
                        </button>
                        <button
                            onClick={() => setQueueAutoMatchDialog({ open: true, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' })}
                            disabled={batchMatchMutation.isPending}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            {batchMatchMutation.isPending ? 'Matching...' : 'Auto-match queue'}
                        </button>
                    </div>
                )}
            />

            <section className="grid gap-4 md:grid-cols-4">
                <button
                    type="button"
                    onClick={() => applyMetricFilter('awaiting')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'awaiting'
                            ? 'border-amber-300 bg-amber-50/60'
                            : 'border-slate-200 bg-white hover:border-amber-200 hover:bg-amber-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-amber-500" />
                        <p className="text-sm font-semibold text-slate-700">Awaiting Payment</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.awaitingCount.toLocaleString()}</p>
                    <p className="mt-1.5 text-sm font-semibold text-slate-700">{formatCurrency(summary.awaitingAmount, resolveCurrency(null))}</p>
                    <p className="mt-1 text-xs text-slate-500">Initiated + pending transactions</p>
                </button>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('confirmed')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'confirmed'
                            ? 'border-emerald-300 bg-emerald-50/60'
                            : 'border-slate-200 bg-white hover:border-emerald-200 hover:bg-emerald-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                        <p className="text-sm font-semibold text-slate-700">Confirmed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.confirmedCount.toLocaleString()}</p>
                    <p className="mt-1.5 text-sm font-semibold text-slate-700">{formatCurrency(summary.confirmedAmount, resolveCurrency(null))}</p>
                    <p className="mt-1 text-xs text-slate-500">Completed payments</p>
                </button>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('unmatched')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'unmatched'
                            ? 'border-sky-300 bg-sky-50/60'
                            : 'border-slate-200 bg-white hover:border-sky-200 hover:bg-sky-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-sky-500" />
                        <p className="text-sm font-semibold text-slate-700">Unmatched Confirmed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.unmatchedCount.toLocaleString()}</p>
                    <p className="mt-1.5 text-sm font-semibold text-slate-700">{formatCurrency(summary.unmatchedAmount, resolveCurrency(null))}</p>
                    <p className="mt-1 text-xs text-slate-500">Completed, no client linked</p>
                </button>

                <button
                    type="button"
                    onClick={() => applyMetricFilter('failed')}
                    className={`h-full rounded-xl border px-4 py-4 text-left shadow-sm transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        activeMetric === 'failed'
                            ? 'border-rose-300 bg-rose-50/70'
                            : 'border-slate-200 bg-white hover:border-rose-200 hover:bg-rose-50/30'
                    }`}
                >
                    <div className="flex items-center gap-2">
                        <span aria-hidden="true" className="h-2.5 w-2.5 rounded-full bg-rose-500" />
                        <p className="text-sm font-semibold text-slate-700">Failed</p>
                    </div>
                    <p className="mt-2 text-[1.7rem] leading-none font-semibold tracking-tight text-slate-900">{summary.failedCount.toLocaleString()}</p>
                    <p className="mt-1.5 text-sm font-semibold text-slate-700">{formatCurrency(summary.failedAmount, resolveCurrency(null))}</p>
                    <p className="mt-1 text-xs text-slate-500">Needs retry or follow-up</p>
                </button>
            </section>

            <section className="crm-filter-row space-y-3">
                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={handleSearch} className="min-w-[220px] flex-1">
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Phone or reference..."
                                    className="crm-input pr-10"
                                />
                                <button type="submit" aria-label="Run payment search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </form>

                    <FilterSelect
                        label="Market"
                        value={platformFilter}
                        onChange={(event) => { setPlatformFilter(event.target.value); setPage(1); }}
                        options={[{ value: '', label: 'All markets' }, ...platformOptions.map((p) => ({ value: p.platform_id, label: p.platform_name }))]}
                    />

                    <FilterSelect
                        label="Status"
                        value={statusFilter}
                        onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All statuses' },
                            { value: 'awaiting_payment', label: 'Awaiting payment' },
                            { value: 'recovery_queue', label: 'Recovery queue' },
                            { value: 'completed', label: 'Completed' },
                            { value: 'initiated', label: 'Initiated' },
                            { value: 'pending', label: 'Pending' },
                            { value: 'failed', label: 'Failed' },
                        ]}
                    />

                    <FilterSelect
                        label="Match"
                        value={matchFilter}
                        onChange={(event) => { setMatchFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All' },
                            { value: 'matched', label: 'Matched' },
                            { value: 'unmatched', label: 'Unmatched' },
                        ]}
                    />

                    {(sourceFilter || confidenceFilter || reviewStateFilter) || showAdvancedFilters ? (
                        <>
                            <FilterSelect
                                label="Source"
                                value={sourceFilter}
                                onChange={(event) => { setSourceFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All sources' },
                                    { value: 'gateway', label: 'Gateway/API' },
                                    { value: 'excel_import', label: 'Excel import' },
                                ]}
                            />

                            <FilterSelect
                                label="Confidence"
                                value={confidenceFilter}
                                onChange={(event) => { setConfidenceFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All' },
                                    { value: 'high', label: 'High' },
                                    { value: 'medium', label: 'Medium' },
                                    { value: 'low', label: 'Low' },
                                ]}
                            />

                            <FilterSelect
                                label="Review"
                                value={reviewStateFilter}
                                onChange={(event) => { setReviewStateFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All states' },
                                    { value: 'open', label: 'Open' },
                                    { value: 'manual_review', label: 'Manual review' },
                                    { value: 'resolved', label: 'Resolved' },
                                ]}
                            />
                        </>
                    ) : (
                        <button
                            type="button"
                            onClick={() => setShowAdvancedFilters(true)}
                            className="mb-0.5 flex items-center gap-1 rounded-lg border border-dashed border-slate-300 px-3 py-2 text-xs font-medium text-slate-500 transition hover:border-slate-400 hover:text-slate-700"
                        >
                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            More filters
                        </button>
                    )}

                    {(search || statusFilter || matchFilter || platformFilter || sourceFilter || confidenceFilter || reviewStateFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setMatchFilter('');
                                setPlatformFilter('');
                                setSourceFilter('');
                                setConfidenceFilter('');
                                setReviewStateFilter('');
                                setShowAdvancedFilters(false);
                                setPage(1);
                            }}
                            className="mb-0.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        >
                            Reset all
                        </button>
                    ) : null}
                </div>

                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2">
                    <p className="text-xs text-slate-400">
                        <span className="crm-mono">Ctrl/Cmd+Enter</span> to confirm selected &middot; Import fields: <span className="crm-mono">payment_date</span>, <span className="crm-mono">amount</span>, + identifier
                    </p>
                    <p className="inline-flex items-center gap-1.5 rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800">
                        <span aria-hidden="true" className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Triage: link within 1h, retry STK within 24h, escalate after 72h
                    </p>
                </div>
            </section>

            <DataTable
                columns={columns}
                data={data?.data}
                pagination={data}
                onPageChange={setPage}
                onRowClick={openDiagnostics}
                isLoading={isLoading}
                emptyMessage="No payments found."
                compact
                selectable
                bulkActions={bulkActions}
                onSelectionChange={setSelectedRows}
                clearSelectionKey={clearSelectionKey}
                perPage={perPage}
                onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
            />

            {diagnosticsDrawer.open ? (
                <div className="fixed inset-0 z-40 flex bg-slate-900/45" onClick={closeDiagnostics}>
                    <aside
                        className="ml-auto h-full w-full max-w-xl border-l border-slate-200 bg-white shadow-xl"
                        onClick={(event) => event.stopPropagation()}
                    >
                        <header className="crm-panel-header sticky top-0 z-10 bg-white">
                            <div>
                                <h3 className="crm-panel-title">Payment Diagnostics</h3>
                                <p className="crm-panel-subtitle">
                                    Payment #{diagnosticsPayment?.id || '--'} • {diagnosticsPayment?.phone || 'No phone'}
                                </p>
                            </div>
                        </header>

                        <div className="h-[calc(100%-132px)] space-y-4 overflow-y-auto p-4">
                            {diagnosticsLoading ? (
                                <p className="text-sm text-slate-500">Loading payment diagnostics...</p>
                            ) : diagnosticsError ? (
                                <p className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                                    Diagnostics could not be loaded for this payment.
                                </p>
                            ) : diagnosticsData ? (
                                <>
                                    <section className="grid gap-3 sm:grid-cols-2">
                                        <article className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Status</p>
                                            <div className="mt-1"><StatusBadge status={diagnosticsData.payment?.status} /></div>
                                        </article>
                                        <article className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Amount</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatCurrency(diagnosticsData.payment?.amount, resolveCurrency(diagnosticsData.payment?.currency))}</p>
                                        </article>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Failure Point</h4>
                                        <p className="mt-1 text-sm text-slate-600"><span className="font-semibold text-slate-800">Stage:</span> {titleize(diagnosticsData.failure?.stage)}</p>
                                        <p className="mt-1 text-sm text-slate-600"><span className="font-semibold text-slate-800">Reason:</span> {diagnosticsData.failure?.reason || 'Not provided'}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Error code: {diagnosticsData.failure?.error_code || '—'} • HTTP: {diagnosticsData.failure?.http_status || '—'}
                                        </p>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">API Performance</h4>
                                        <div className="mt-2 grid gap-2 sm:grid-cols-3">
                                            <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Attempts:</span> {diagnosticsData.performance?.attempt_count ?? 0}</p>
                                            <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Avg:</span> {diagnosticsData.performance?.avg_latency_ms ?? '—'} ms</p>
                                            <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">P95:</span> {diagnosticsData.performance?.p95_latency_ms ?? '—'} ms</p>
                                        </div>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Browser & Request Context</h4>
                                        <p className="mt-1 text-xs text-slate-600">Origin: {diagnosticsData.browser_meta?.origin_url || '—'}</p>
                                        <p className="mt-1 text-xs text-slate-600">Referrer: {diagnosticsData.browser_meta?.referrer || '—'}</p>
                                        <p className="mt-1 text-xs text-slate-600">Browser: {diagnosticsData.browser_meta?.user_agent_family || '—'} • Device: {diagnosticsData.browser_meta?.device_type || '—'}</p>
                                        <p className="mt-1 text-xs text-slate-500">IP hash: {diagnosticsData.browser_meta?.ip_hash || '—'}</p>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Recommended Actions</h4>
                                        {(diagnosticsData.recommendations || []).length === 0 ? (
                                            <p className="mt-1 text-sm text-slate-500">No action recommendations for the current payment state.</p>
                                        ) : (
                                            <div className="mt-2 flex flex-wrap gap-2">
                                                {diagnosticsData.recommendations.map((item) => (
                                                    <button
                                                        key={item.key}
                                                        type="button"
                                                        onClick={() => triggerRecommendation(item.key, diagnosticsPayment)}
                                                        className={`rounded-md px-2.5 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                                            item.recommended
                                                                ? 'bg-teal-700 text-white hover:bg-teal-800'
                                                                : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                                        }`}
                                                        title={item.description}
                                                    >
                                                        {item.label}
                                                    </button>
                                                ))}
                                            </div>
                                        )}
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Recent Attempts</h4>
                                        {(diagnosticsData.attempts || []).length === 0 ? (
                                            <p className="mt-1 text-sm text-slate-500">No telemetry attempts recorded for this payment yet.</p>
                                        ) : (
                                            <div className="mt-2 space-y-2">
                                                {diagnosticsData.attempts.slice(0, 8).map((attempt) => (
                                                    <article key={attempt.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                        <p className="text-xs font-semibold text-slate-800">{titleize(attempt.attempt_type)} • {titleize(attempt.status)}</p>
                                                        <p className="mt-1 text-xs text-slate-600">Provider: {attempt.provider || '—'} • HTTP: {attempt.http_status || '—'} • Latency: {attempt.latency_ms ?? '—'} ms</p>
                                                        <p className="mt-1 text-xs text-slate-600">Reason: {attempt.error_message || '—'}</p>
                                                        <p className="mt-1 text-[11px] text-slate-500">{formatDateTime(attempt.created_at)}</p>
                                                    </article>
                                                ))}
                                            </div>
                                        )}
                                    </section>
                                </>
                            ) : (
                                <p className="text-sm text-slate-500">Select a payment to review diagnostics.</p>
                            )}
                        </div>

                        <footer className="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 p-4">
                            {['initiated', 'pending'].includes(diagnosticsPayment?.status) ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        closeDiagnostics();
                                        setManualCloseDialog({ open: true, payment: diagnosticsPayment, category: 'timeout', reason: '' });
                                    }}
                                    className="crm-btn-danger"
                                >
                                    Close manually
                                </button>
                            ) : null}
                            <button type="button" onClick={closeDiagnostics} className="crm-btn-secondary">
                                Close
                            </button>
                        </footer>
                    </aside>
                </div>
            ) : null}

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
                                    Payment #{selectedPayment.id} • {selectedPayment.phone || 'No phone'} • {formatCurrency(selectedPayment.amount, resolveCurrency(selectedPayment.currency))}
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
                title={queueAutoMatchDialog.step === 'preview' ? 'Auto-match Preview' : 'Auto-match Review Queue'}
                message={
                    queueAutoMatchDialog.step === 'preview' && queueAutoMatchDialog.preview
                        ? `${queueAutoMatchDialog.preview.matched} high-confidence, ${queueAutoMatchDialog.preview.low_confidence} low-confidence, ${queueAutoMatchDialog.preview.unmatched} unmatched.`
                        : 'Enter a reason and preview matches before applying.'
                }
                confirmLabel={queueAutoMatchDialog.step === 'preview' ? 'Apply Matches' : 'Preview Matches'}
                onCancel={() => setQueueAutoMatchDialog({ open: false, reason: 'Batch auto-match from payment queue', preview: null, step: 'reason' })}
                onConfirm={() => {
                    if (queueAutoMatchDialog.step === 'reason') {
                        batchMatchDryRunMutation.mutate(queueAutoMatchDialog.reason.trim());
                    } else {
                        batchMatchMutation.mutate(queueAutoMatchDialog.reason.trim());
                    }
                }}
                confirmDisabled={
                    !queueAutoMatchDialog.reason.trim()
                    || batchMatchMutation.isPending
                    || batchMatchDryRunMutation.isPending
                    || (queueAutoMatchDialog.step === 'preview' && queueAutoMatchDialog.preview && queueAutoMatchDialog.preview.matched === 0 && queueAutoMatchDialog.preview.low_confidence === 0)
                }
                isPending={batchMatchMutation.isPending || batchMatchDryRunMutation.isPending}
            >
                {queueAutoMatchDialog.step === 'reason' ? (
                    <>
                        <label htmlFor="queue-auto-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            id="queue-auto-reason"
                            rows={3}
                            value={queueAutoMatchDialog.reason}
                            onChange={(event) => setQueueAutoMatchDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                        />
                    </>
                ) : queueAutoMatchDialog.preview?.proposals?.length ? (
                    <div className="max-h-56 overflow-y-auto rounded border border-slate-200">
                        <table className="w-full text-xs">
                            <thead className="sticky top-0 bg-slate-50">
                                <tr>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Phone</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Amount</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Client</th>
                                    <th className="px-2 py-1.5 text-left font-medium text-slate-600">Confidence</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {queueAutoMatchDialog.preview.proposals.map((p) => (
                                    <tr key={p.payment_id}>
                                        <td className="px-2 py-1.5 text-slate-800">{p.payment_phone || '—'}</td>
                                        <td className="px-2 py-1.5 text-slate-600">{p.payment_amount}</td>
                                        <td className="px-2 py-1.5 text-slate-800">{p.client_name || '—'}</td>
                                        <td className="px-2 py-1.5">
                                            <span className={p.confidence === 'auto_high' ? 'text-emerald-700' : 'text-amber-600'}>
                                                {p.confidence === 'auto_high' ? 'High' : 'Low'}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <p className="text-sm text-slate-500">No matchable payments found.</p>
                )}
            </ConfirmDialog>

            <ConfirmDialog
                open={retryStkDialog.open && !!retryStkDialog.payment}
                title="Retry STK push"
                message={retryStkDialog.payment
                    ? `Send another M-Pesa STK push for payment #${retryStkDialog.payment.id} (${formatCurrency(retryStkDialog.payment.amount, resolveCurrency(retryStkDialog.payment.currency))} to ${retryStkDialog.payment.phone || 'customer'}).`
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
                    ? `Send a payment page link by SMS for payment #${sendLinkDialog.payment.id} (${formatCurrency(sendLinkDialog.payment.amount, resolveCurrency(sendLinkDialog.payment.currency))}).`
                    : ''}
                confirmLabel="Send SMS"
                onCancel={() => setSendLinkDialog({ open: false, payment: null, channel: 'sms', provider: '', phone: '', reason: 'Send payment link from CRM' })}
                onConfirm={() => {
                    if (sendLinkDialog.payment) {
                        sendPaymentLinkMutation.mutate({
                            paymentId: sendLinkDialog.payment.id,
                            channel: sendLinkDialog.channel,
                            provider: sendLinkDialog.provider || undefined,
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
                        <label htmlFor="send-link-provider" className="mb-1 block text-sm font-medium text-slate-700">Payment link provider</label>
                        <select
                            id="send-link-provider"
                            value={sendLinkDialog.provider}
                            onChange={(event) => setSendLinkDialog((current) => ({ ...current, provider: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="">Default configured provider</option>
                            {Object.entries(sendLinkDialog.payment?.platform?.payment_link_providers?.providers || {}).map(([providerKey, providerConfig]) => (
                                <option key={providerKey} value={providerKey}>
                                    {providerConfig?.label || providerKey}
                                </option>
                            ))}
                        </select>
                        <div className="mt-2 flex items-center justify-between gap-2">
                            <p className="text-xs text-slate-500">Need to edit provider URLs or active routing?</p>
                            <button
                                type="button"
                                onClick={() => {
                                    const platformId = sendLinkDialog.payment?.platform_id || sendLinkDialog.payment?.platform?.id;
                                    const query = new URLSearchParams({ integrationArea: 'payment_links' });
                                    if (platformId) {
                                        query.set('platform_id', String(platformId));
                                    }
                                    navigate(`/settings?${query.toString()}`);
                                }}
                                className="text-xs font-semibold text-teal-700 underline decoration-teal-200 underline-offset-2 hover:text-teal-800"
                            >
                                Manage providers
                            </button>
                        </div>
                    </div>
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
                    ? `Activate a subscription for payment #${createSubDialog.payment.id} (${formatCurrency(createSubDialog.payment.amount, resolveCurrency(createSubDialog.payment.currency))}) matched to ${createSubDialog.payment.client?.name || 'client'}.`
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

            <ConfirmDialog
                open={manualCloseDialog.open && !!manualCloseDialog.payment}
                title="Close pending payment manually"
                message={manualCloseDialog.payment
                    ? `Close payment #${manualCloseDialog.payment.id} (${formatCurrency(manualCloseDialog.payment.amount, resolveCurrency(manualCloseDialog.payment.currency))}) and move it out of pending queue.`
                    : ''}
                confirmLabel="Close payment"
                onCancel={() => setManualCloseDialog({ open: false, payment: null, category: 'timeout', reason: '' })}
                onConfirm={() => {
                    if (manualCloseDialog.payment) {
                        manualCloseMutation.mutate({
                            paymentId: manualCloseDialog.payment.id,
                            category: manualCloseDialog.category,
                            reason: manualCloseDialog.reason.trim(),
                        });
                    }
                }}
                confirmDisabled={manualCloseMutation.isPending || !manualCloseDialog.reason.trim()}
                isPending={manualCloseMutation.isPending}
            >
                <div className="space-y-3">
                    <div>
                        <label htmlFor="manual-close-category" className="mb-1 block text-sm font-medium text-slate-700">Closure category</label>
                        <select
                            id="manual-close-category"
                            value={manualCloseDialog.category}
                            onChange={(event) => setManualCloseDialog((current) => ({ ...current, category: event.target.value }))}
                            className="crm-select"
                        >
                            <option value="timeout">Timeout / no callback</option>
                            <option value="customer_cancelled">Customer cancelled</option>
                            <option value="duplicate_request">Duplicate request</option>
                            <option value="fraud_suspected">Fraud suspected</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label htmlFor="manual-close-reason" className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                        <textarea
                            id="manual-close-reason"
                            rows={3}
                            value={manualCloseDialog.reason}
                            onChange={(event) => setManualCloseDialog((current) => ({ ...current, reason: event.target.value }))}
                            className="crm-input"
                            placeholder="Explain why this pending payment is being closed manually."
                        />
                    </div>
                </div>
            </ConfirmDialog>

            <ConfirmDialog
                open={importDialog.open}
                title={
                    importDialog.step === 'committed' ? 'Import Complete'
                        : importDialog.step === 'preview' ? 'Import Preview'
                            : 'Import Payments'
                }
                message={
                    importDialog.step === 'committed' && importDialog.commitResult
                        ? `${importDialog.commitResult.summary?.committed ?? 0} payment(s) imported successfully.`
                        : importDialog.step === 'preview' && importDialog.preview
                            ? `${importDialog.preview.summary?.valid ?? 0} valid, ${importDialog.preview.summary?.invalid ?? 0} invalid, ${importDialog.preview.summary?.duplicate ?? 0} duplicate out of ${importDialog.preview.summary?.total ?? 0} rows.`
                            : 'Upload a CSV or XLSX file with payment records.'
                }
                confirmLabel={
                    importDialog.step === 'committed' ? 'Close'
                        : importDialog.step === 'preview' ? 'Commit Import'
                            : 'Preview Import'
                }
                tone={importDialog.step === 'preview' ? 'warning' : 'default'}
                onCancel={() => setImportDialog((d) => ({ ...d, open: false }))}
                onConfirm={handleImportConfirm}
                confirmDisabled={
                    (importDialog.step === 'upload' && (!importDialog.file || !importDialog.platformId || !importDialog.reason.trim()))
                    || (importDialog.step === 'preview' && (!importDialog.preview || (importDialog.preview.summary?.valid ?? 0) === 0))
                    || importPreviewMutation.isPending
                    || importCommitMutation.isPending
                }
                isPending={importPreviewMutation.isPending || importCommitMutation.isPending}
            >
                {importDialog.step === 'upload' ? (
                    <div className="space-y-3">
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Market</label>
                            <select
                                value={importDialog.platformId}
                                onChange={(e) => setImportDialog((d) => ({ ...d, platformId: e.target.value }))}
                                className="crm-input"
                            >
                                <option value="">Select market...</option>
                                {platformOptions.map((p) => (
                                    <option key={p.platform_id} value={p.platform_id}>{p.name}</option>
                                ))}
                            </select>
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">CSV / XLSX File</label>
                            <input
                                type="file"
                                accept=".csv,.xlsx,.txt"
                                onChange={(e) => setImportDialog((d) => ({ ...d, file: e.target.files?.[0] || null }))}
                                className="block w-full text-sm text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200"
                            />
                        </div>
                        <div>
                            <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                            <textarea
                                rows={2}
                                value={importDialog.reason}
                                onChange={(e) => setImportDialog((d) => ({ ...d, reason: e.target.value }))}
                                className="crm-input"
                            />
                        </div>
                    </div>
                ) : importDialog.step === 'preview' && importDialog.preview ? (
                    <div className="space-y-3">
                        <div className="grid grid-cols-4 gap-2 text-center text-xs">
                            <div className="rounded-md bg-slate-50 p-2">
                                <p className="font-semibold text-slate-900">{importDialog.preview.summary?.total ?? 0}</p>
                                <p className="text-slate-500">Total</p>
                            </div>
                            <div className="rounded-md bg-emerald-50 p-2">
                                <p className="font-semibold text-emerald-700">{importDialog.preview.summary?.valid ?? 0}</p>
                                <p className="text-emerald-600">Valid</p>
                            </div>
                            <div className="rounded-md bg-rose-50 p-2">
                                <p className="font-semibold text-rose-700">{importDialog.preview.summary?.invalid ?? 0}</p>
                                <p className="text-rose-600">Invalid</p>
                            </div>
                            <div className="rounded-md bg-amber-50 p-2">
                                <p className="font-semibold text-amber-700">{importDialog.preview.summary?.duplicate ?? 0}</p>
                                <p className="text-amber-600">Duplicate</p>
                            </div>
                        </div>
                        {importDialog.preview.rows?.length ? (
                            <div className="max-h-48 overflow-y-auto rounded border border-slate-200">
                                <table className="w-full text-xs">
                                    <thead className="sticky top-0 bg-slate-50">
                                        <tr>
                                            <th className="px-2 py-1.5 text-left font-medium text-slate-600">#</th>
                                            <th className="px-2 py-1.5 text-left font-medium text-slate-600">Phone</th>
                                            <th className="px-2 py-1.5 text-left font-medium text-slate-600">Amount</th>
                                            <th className="px-2 py-1.5 text-left font-medium text-slate-600">Reference</th>
                                            <th className="px-2 py-1.5 text-left font-medium text-slate-600">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {importDialog.preview.rows.slice(0, 20).map((row) => (
                                            <tr key={row.row_number} className={row.status === 'invalid' ? 'bg-rose-50/50' : row.status === 'duplicate' ? 'bg-amber-50/50' : ''}>
                                                <td className="px-2 py-1.5 text-slate-500">{row.row_number}</td>
                                                <td className="px-2 py-1.5 text-slate-800">{row.normalized_row?.phone || row.raw_row?.phone || '—'}</td>
                                                <td className="px-2 py-1.5 text-slate-600">{row.normalized_row?.amount || row.raw_row?.amount || '—'}</td>
                                                <td className="px-2 py-1.5 text-slate-600 truncate max-w-[120px]">{row.normalized_row?.transaction_reference || row.raw_row?.transaction_reference || '—'}</td>
                                                <td className="px-2 py-1.5">
                                                    <span className={
                                                        row.status === 'valid' ? 'text-emerald-700'
                                                            : row.status === 'invalid' ? 'text-rose-600'
                                                                : row.status === 'duplicate' ? 'text-amber-600'
                                                                    : 'text-slate-500'
                                                    }>
                                                        {row.status}
                                                    </span>
                                                    {row.validation_errors?.length ? (
                                                        <span className="ml-1 text-rose-500" title={row.validation_errors.join(', ')}>
                                                            ({row.validation_errors.length} error{row.validation_errors.length > 1 ? 's' : ''})
                                                        </span>
                                                    ) : null}
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        ) : null}
                    </div>
                ) : importDialog.step === 'committed' && importDialog.commitResult ? (
                    <div className="text-sm text-slate-700">
                        <p>Batch #{importDialog.batchId} has been committed.</p>
                        {importDialog.commitResult.summary ? (
                            <p className="mt-1 text-slate-500">
                                Committed: {importDialog.commitResult.summary.committed ?? 0} / Skipped: {importDialog.commitResult.summary.skipped ?? 0}
                            </p>
                        ) : null}
                    </div>
                ) : null}
            </ConfirmDialog>
        </div>
    );
}
