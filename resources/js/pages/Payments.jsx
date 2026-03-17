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
import PaymentImportDrawer from '../components/PaymentImportDrawer';
import { useToast } from '../components/ToastProvider';
import { platformOptionsWithFlags } from '../utils/flags';
import { candidateScore, scoreTone, toneClasses } from '../utils/scoring';

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

function isSandboxPayment(payment) {
    return String(payment?.provider_environment || '').toLowerCase() === 'sandbox'
        || Boolean(payment?.payment_data?.test_mode);
}

function sandboxStatusLabel(payment) {
    if (!isSandboxPayment(payment)) {
        return null;
    }

    const status = String(payment?.status || '').toLowerCase();
    const testResult = String(payment?.payment_data?.test_result || '').toLowerCase();

    if (testResult === 'failed' || status === 'failed') {
        return 'Sandbox Failed';
    }

    if (status === 'completed' || testResult === 'completed') {
        return 'Sandbox Completed';
    }

    if (['initiated', 'pending'].includes(status)) {
        return 'Sandbox Pending';
    }

    return `Sandbox ${titleize(status)}`;
}

function renderPaymentStatusBadges(payment) {
    const status = String(payment?.status || '').toLowerCase();
    const customLabel = sandboxStatusLabel(payment);

    return (
        <div className="flex flex-wrap items-center gap-1.5">
            <StatusBadge status={status} label={customLabel} />
            {isSandboxPayment(payment) ? <StatusBadge status="sandbox" label="Sandbox/Test" tone="sandbox" /> : null}
        </div>
    );
}

function paymentLinkModeLabel(mode) {
    return mode === 'proxy_hosted_checkout' ? 'CRM proxy' : 'Static URL';
}

function paymentLinkProviderOptionLabel(providerKey, providerConfig = {}) {
    const baseLabel = providerConfig?.label?.trim() || providerKey;
    return `${baseLabel} (${paymentLinkModeLabel(providerConfig?.mode)})`;
}

function diagnosticToneClasses(status) {
    const normalized = String(status || '').toLowerCase();

    if (['completed', 'success', 'active'].includes(normalized)) {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (['pending', 'opened', 'checkout_initialized', 'rotated'].includes(normalized)) {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    if (['failed', 'expired'].includes(normalized)) {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    return 'border-slate-200 bg-slate-100 text-slate-600';
}

function formatStructuredValue(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    if (Array.isArray(value)) {
        return value.slice(0, 3).join(', ');
    }

    if (typeof value === 'object') {
        return null;
    }

    return String(value);
}

function describeTimelineContent(content) {
    if (!content || typeof content !== 'object') {
        return 'Structured event data recorded.';
    }

    const preferredKeys = ['summary', 'message', 'reason', 'status', 'payment_method', 'transaction_reference', 'amount', 'currency', 'expires_at'];
    const preferred = preferredKeys
        .map((key) => {
            const value = formatStructuredValue(content[key]);
            return value ? `${titleize(key)}: ${value}` : null;
        })
        .filter(Boolean);

    if (preferred.length > 0) {
        return preferred.join(' • ');
    }

    const fallback = Object.entries(content)
        .map(([key, value]) => {
            const normalized = formatStructuredValue(value);
            return normalized ? `${titleize(key)}: ${normalized}` : null;
        })
        .filter(Boolean)
        .slice(0, 4);

    if (fallback.length > 0) {
        return fallback.join(' • ');
    }

    const nestedEntry = Object.entries(content).find(([, value]) => value && typeof value === 'object');
    if (nestedEntry) {
        return `${titleize(nestedEntry[0])} updated`;
    }

    return 'Structured event data recorded.';
}

export default function Payments() {
    const allowedStatuses = new Set(['awaiting_payment', 'completed', 'initiated', 'pending', 'failed', 'recovery_queue']);
    const allowedMatchFilters = new Set(['matched', 'unmatched']);
    const allowedSourceFilters = new Set(['gateway', 'excel_import']);
    const allowedEnvironmentFilters = new Set(['production', 'sandbox']);
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
    const [environmentFilter, setEnvironmentFilter] = useState(() => {
        const requested = (searchParams.get('environment') || '').trim().toLowerCase();
        return allowedEnvironmentFilters.has(requested) ? requested : '';
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
    const [showAdvancedFilters, setShowAdvancedFilters] = useState(() => !!(sourceFilter || environmentFilter || confidenceFilter || reviewStateFilter));
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
    const [providerStatusSnapshot, setProviderStatusSnapshot] = useState(null);
    const [sandboxReconcileSnapshot, setSandboxReconcileSnapshot] = useState(null);
    const [manualCloseDialog, setManualCloseDialog] = useState({
        open: false,
        payment: null,
        category: 'timeout',
        reason: '',
    });
    const [importDrawerOpen, setImportDrawerOpen] = useState(false);

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
        queryKey: ['payments', page, perPage, search, statusFilter, matchFilter, platformFilter, sourceFilter, environmentFilter, confidenceFilter, reviewStateFilter],
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
                    ...(environmentFilter && { environment: environmentFilter }),
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

    const providerStatusMutation = useMutation({
        mutationFn: (paymentId) =>
            api.post(`/crm/payments/${paymentId}/check-provider-status`).then((response) => response.data),
        onSuccess: (result, paymentId) => {
            setProviderStatusSnapshot(result);
            setSandboxReconcileSnapshot(null);
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', paymentId] });
            toast.success(`Provider currently reports this payment as ${titleize(result.status)}.`);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Provider status check failed.');
        },
    });

    const sandboxReconcileMutation = useMutation({
        mutationFn: ({ paymentId, reason }) =>
            api.post(`/crm/payments/${paymentId}/sandbox-reconcile`, {
                reason: reason || undefined,
            }).then((response) => response.data),
        onSuccess: (result, variables) => {
            setSandboxReconcileSnapshot(result);
            setProviderStatusSnapshot(result.provider_snapshot || null);
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['payment-diagnostics', variables.paymentId] });

            if (result.already_reconciled) {
                toast.info(result.message || 'Sandbox payment was already reconciled.');
                return;
            }

            if (result.provider_snapshot?.status === 'failed') {
                toast.warning(result.message || 'Sandbox payment was reconciled as failed.');
                return;
            }

            if (result.provider_snapshot?.status === 'pending') {
                toast.info(result.message || 'Provider still reports this sandbox payment as pending.');
                return;
            }

            toast.success(result.message || 'Sandbox payment reconciled.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Sandbox reconcile failed.');
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
            if (isSandboxPayment(paymentRow)) {
                toast.info('Sandbox payments stay in test mode and cannot create live subscriptions.');
                return;
            }
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

        if (actionKey === 'check_provider_status') {
            providerStatusMutation.mutate(paymentRow.id);
            return;
        }

        if (actionKey === 'sandbox_reconcile') {
            sandboxReconcileMutation.mutate({
                paymentId: paymentRow.id,
                reason: 'Sandbox reconcile from diagnostics drawer',
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
    const linkProxyData = diagnosticsData?.link_proxy || null;
    const providerStatusDisplay = providerStatusSnapshot || linkProxyData?.last_provider_check || null;
    const providerCheckEligible = ['paystack', 'pesapal'].includes(String(diagnosticsPayment?.provider_key || '').toLowerCase())
        && ['initiated', 'pending'].includes(diagnosticsPayment?.status);
    const providerCheckReady = providerCheckEligible && (!linkProxyData || !!linkProxyData.initialized_at || !!linkProxyData.provider_reference);
    const sandboxReconcileEligible = providerCheckReady
        && String(diagnosticsPayment?.source || '').toLowerCase() === 'gateway'
        && String(diagnosticsPayment?.provider_environment || '').toLowerCase() === 'sandbox';
    const providerReference = providerStatusSnapshot?.provider_reference
        || linkProxyData?.provider_reference
        || diagnosticsPayment?.transaction_reference
        || diagnosticsPayment?.reference_number
        || '—';
    const linkProxySteps = useMemo(() => {
        if (!linkProxyData) {
            return [];
        }

        const callbackAt = linkProxyData.callback_at || (diagnosticsPayment?.status === 'completed'
            ? (diagnosticsPayment?.completed_at || diagnosticsPayment?.updated_at)
            : null);

        return [
            {
                key: 'sent',
                label: 'Sent',
                timestamp: linkProxyData.sent_at,
                helper: 'Customer payment link was issued from CRM.',
            },
            {
                key: 'opened',
                label: 'Opened',
                timestamp: linkProxyData.opened_at,
                helper: linkProxyData.open_count
                    ? `Opened ${linkProxyData.open_count} time${linkProxyData.open_count === 1 ? '' : 's'}.`
                    : 'Awaiting first customer open.',
            },
            {
                key: 'initialized',
                label: 'Checkout initialized',
                timestamp: linkProxyData.initialized_at,
                helper: linkProxyData.provider_reference
                    ? `Provider ref ${linkProxyData.provider_reference}`
                    : 'Provider session not started yet.',
            },
            {
                key: 'callback',
                label: 'Provider callback',
                timestamp: callbackAt,
                helper: callbackAt
                    ? 'CRM has provider completion evidence for this payment.'
                    : 'No provider callback recorded yet.',
            },
            {
                key: 'completed',
                label: 'Completed',
                timestamp: diagnosticsPayment?.status === 'completed'
                    ? (diagnosticsPayment?.completed_at || diagnosticsPayment?.updated_at)
                    : null,
                helper: diagnosticsPayment?.status === 'completed'
                    ? 'Payment is marked completed in CRM.'
                    : 'Payment is still awaiting completion.',
            },
        ];
    }, [diagnosticsPayment?.completed_at, diagnosticsPayment?.status, diagnosticsPayment?.updated_at, linkProxyData]);
    const sendLinkProviderEntries = useMemo(() => {
        const providers = sendLinkDialog.payment?.platform?.payment_link_providers?.providers || {};
        return Object.entries(providers).filter(([, providerConfig]) => providerConfig?.enabled !== false);
    }, [sendLinkDialog.payment]);
    const activeSendLinkProviderKey = sendLinkDialog.payment?.platform?.payment_link_providers?.active_provider || '';
    const activeSendLinkProviderEntry = useMemo(
        () => sendLinkProviderEntries.find(([providerKey]) => providerKey === activeSendLinkProviderKey) || null,
        [activeSendLinkProviderKey, sendLinkProviderEntries],
    );
    const effectiveSendLinkProviderEntry = useMemo(() => {
        if (sendLinkDialog.provider) {
            return sendLinkProviderEntries.find(([providerKey]) => providerKey === sendLinkDialog.provider) || null;
        }

        return activeSendLinkProviderEntry;
    }, [activeSendLinkProviderEntry, sendLinkDialog.provider, sendLinkProviderEntries]);

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

    useEffect(() => {
        setProviderStatusSnapshot(null);
        setSandboxReconcileSnapshot(null);
    }, [diagnosticsDrawer.open, diagnosticsDrawer.payment?.id]);

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
            render: (row) => renderPaymentStatusBadges(row),
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
                const sandboxRow = isSandboxPayment(row);
                const isFailed = row.status === 'failed' || row.status === 'initiated' || row.status === 'pending';
                const isCompletedUnmatched = row.status === 'completed' && !row.client_id;
                const isMatchedNoDeal = row.status === 'completed' && row.client_id && !row.deal_id && !sandboxRow;
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
                        badge={sandboxRow ? 'Sandbox' : (row.client_id ? 'Matched' : null)}
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
                            onClick={() => setImportDrawerOpen(true)}
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

            <section className="rounded-xl border border-slate-200 bg-slate-50/80 px-4 py-3 text-xs text-slate-600">
                {environmentFilter === 'sandbox'
                    ? 'Sandbox filter active: summary cards and the table now reflect sandbox/test payments only.'
                    : 'Summary cards stay live-only by default. Sandbox/test rows remain visible in the table unless you filter them out.'}
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
                        options={platformOptionsWithFlags(platformOptions)}
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

                    {(sourceFilter || environmentFilter || confidenceFilter || reviewStateFilter) || showAdvancedFilters ? (
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
                                label="Environment"
                                value={environmentFilter}
                                onChange={(event) => { setEnvironmentFilter(event.target.value); setPage(1); }}
                                options={[
                                    { value: '', label: 'All rows / live KPIs' },
                                    { value: 'production', label: 'Production only' },
                                    { value: 'sandbox', label: 'Sandbox only' },
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

                    {(search || statusFilter || matchFilter || platformFilter || sourceFilter || environmentFilter || confidenceFilter || reviewStateFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setMatchFilter('');
                                setPlatformFilter('');
                                setSourceFilter('');
                                setEnvironmentFilter('');
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
                                            <div className="mt-1">{renderPaymentStatusBadges(diagnosticsData.payment)}</div>
                                        </article>
                                        <article className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.1em] text-slate-500">Amount</p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">{formatCurrency(diagnosticsData.payment?.amount, resolveCurrency(diagnosticsData.payment?.currency))}</p>
                                        </article>
                                    </section>

                                    {isSandboxPayment(diagnosticsData.payment) ? (
                                        <section className="rounded-md border border-sky-200 bg-sky-50 p-3">
                                            <h4 className="text-sm font-semibold text-sky-900">Sandbox/Test Safeguards</h4>
                                            <p className="mt-1 text-sm text-sky-800">
                                                This payment is marked as sandbox-only. Live wallet credits, subscriptions, and profile activation stay disabled.
                                            </p>
                                            <div className="mt-2 grid gap-2 sm:grid-cols-2">
                                                <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Test result:</span> {titleize(diagnosticsData.payment?.payment_data?.test_result || diagnosticsData.payment?.status)}</p>
                                                <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Side effects skipped:</span> {diagnosticsData.payment?.payment_data?.side_effects_skipped ? 'Yes' : 'No'}</p>
                                                <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Verified at:</span> {formatDateTime(diagnosticsData.payment?.payment_data?.verified_at)}</p>
                                                <p className="rounded-md bg-white/70 px-2 py-1 text-xs text-sky-800"><span className="font-semibold">Environment:</span> {titleize(diagnosticsData.payment?.provider_environment || 'sandbox')}</p>
                                            </div>
                                        </section>
                                    ) : null}

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <h4 className="text-sm font-semibold text-slate-900">Failure Point</h4>
                                        <p className="mt-1 text-sm text-slate-600"><span className="font-semibold text-slate-800">Stage:</span> {titleize(diagnosticsData.failure?.stage)}</p>
                                        <p className="mt-1 text-sm text-slate-600"><span className="font-semibold text-slate-800">Reason:</span> {diagnosticsData.failure?.reason || 'Not provided'}</p>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Error code: {diagnosticsData.failure?.error_code || '—'} • HTTP: {diagnosticsData.failure?.http_status || '—'}
                                        </p>
                                    </section>

                                    {providerCheckEligible || providerStatusDisplay ? (
                                        <section className="rounded-md border border-slate-200 bg-white p-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h4 className="text-sm font-semibold text-slate-900">Live Provider Status</h4>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {sandboxReconcileEligible
                                                            ? 'Read-only verification plus sandbox-safe reconcile for hosted checkout tests.'
                                                            : 'Read-only verification against the current Paystack or Pesapal session.'}
                                                    </p>
                                                </div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <button
                                                        type="button"
                                                        onClick={() => diagnosticsPayment?.id && providerStatusMutation.mutate(diagnosticsPayment.id)}
                                                        disabled={!providerCheckReady || providerStatusMutation.isPending}
                                                        className="rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        {providerStatusMutation.isPending ? 'Checking...' : 'Check Provider Status'}
                                                    </button>
                                                    {sandboxReconcileEligible ? (
                                                        <button
                                                            type="button"
                                                            onClick={() => diagnosticsPayment?.id && sandboxReconcileMutation.mutate({
                                                                paymentId: diagnosticsPayment.id,
                                                                reason: 'Sandbox reconcile from diagnostics drawer',
                                                            })}
                                                            disabled={sandboxReconcileMutation.isPending}
                                                            className="rounded-md border border-amber-300 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                                        >
                                                            {sandboxReconcileMutation.isPending ? 'Reconciling...' : 'Sandbox Reconcile'}
                                                        </button>
                                                    ) : null}
                                                </div>
                                            </div>

                                            {!providerCheckReady && providerCheckEligible ? (
                                                <p className="mt-2 text-xs text-amber-700">
                                                    Hosted checkout needs to initialize before CRM can verify provider-side status.
                                                </p>
                                            ) : null}

                                            {sandboxReconcileSnapshot?.message && sandboxReconcileEligible ? (
                                                <p className="mt-2 text-xs text-slate-600">
                                                    {sandboxReconcileSnapshot.message}
                                                </p>
                                            ) : null}

                                            {providerStatusDisplay ? (
                                                <div className="mt-3 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${diagnosticToneClasses(providerStatusDisplay.status)}`}>
                                                            {titleize(providerStatusDisplay.status)}
                                                        </span>
                                                        <span className="text-[11px] text-slate-500">
                                                            Checked {formatDateTime(providerStatusDisplay.checked_at)}
                                                        </span>
                                                    </div>
                                                    <p className="mt-2 text-sm text-slate-700">{providerStatusDisplay.message || 'No provider message returned.'}</p>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        Provider: {titleize(diagnosticsPayment?.provider_key)} • Reference: {providerReference}
                                                    </p>
                                                </div>
                                            ) : null}
                                        </section>
                                    ) : null}

                                    {linkProxyData ? (
                                        <section className="rounded-md border border-slate-200 bg-white p-3">
                                            <div className="flex flex-wrap items-start justify-between gap-3">
                                                <div>
                                                    <h4 className="text-sm font-semibold text-slate-900">Proxy Link Lifecycle</h4>
                                                    <p className="mt-1 text-xs text-slate-500">
                                                        {titleize(linkProxyData.session_status)} • {titleize(linkProxyData.mode)}
                                                    </p>
                                                </div>
                                                <span className={`inline-flex rounded-full border px-2 py-0.5 text-[11px] font-semibold ${diagnosticToneClasses(linkProxyData.token_status)}`}>
                                                    {titleize(linkProxyData.token_status)}
                                                </span>
                                            </div>

                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Sent:</span> {formatDateTime(linkProxyData.sent_at)}</p>
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Opened:</span> {formatDateTime(linkProxyData.opened_at)}</p>
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Open count:</span> {linkProxyData.open_count ?? 0}</p>
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Provider ref:</span> {linkProxyData.provider_reference || '—'}</p>
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Environment:</span> {titleize(linkProxyData.environment)}</p>
                                                <p className="rounded-md bg-slate-50 px-2 py-1 text-xs text-slate-600"><span className="font-semibold text-slate-800">Expires:</span> {formatDateTime(linkProxyData.token_expires_at)}</p>
                                            </div>

                                            <div className="mt-4 space-y-3">
                                                {linkProxySteps.map((step, index) => {
                                                    const firstIncompleteIndex = linkProxySteps.findIndex((item) => !item.timestamp);
                                                    const isComplete = Boolean(step.timestamp);
                                                    const isCurrent = !isComplete && firstIncompleteIndex === index;

                                                    return (
                                                        <div key={step.key} className="flex gap-3">
                                                            <div className="flex flex-col items-center">
                                                                <span
                                                                    aria-hidden="true"
                                                                    className={`mt-1 h-2.5 w-2.5 rounded-full ${
                                                                        isComplete
                                                                            ? 'bg-emerald-500'
                                                                            : (isCurrent ? 'bg-amber-500' : 'bg-slate-300')
                                                                    }`}
                                                                />
                                                                {index < linkProxySteps.length - 1 ? <span className="mt-1 h-8 w-px bg-slate-200" /> : null}
                                                            </div>
                                                            <div className="min-w-0 flex-1">
                                                                <p className={`text-xs font-semibold ${isComplete ? 'text-slate-900' : (isCurrent ? 'text-amber-700' : 'text-slate-500')}`}>
                                                                    {step.label}
                                                                </p>
                                                                <p className="mt-0.5 text-xs text-slate-500">
                                                                    {step.timestamp ? formatDateTime(step.timestamp) : step.helper}
                                                                </p>
                                                            </div>
                                                        </div>
                                                    );
                                                })}
                                            </div>
                                        </section>
                                    ) : null}

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
                                        <details>
                                            <summary className="cursor-pointer list-none text-sm font-semibold text-slate-900">
                                                Audit Trail
                                                <span className="ml-2 text-xs font-medium text-slate-500">
                                                    ({(diagnosticsData.audit_trail || []).length})
                                                </span>
                                            </summary>
                                            {(diagnosticsData.audit_trail || []).length === 0 ? (
                                                <p className="mt-2 text-sm text-slate-500">No audit entries were recorded for this payment yet.</p>
                                            ) : (
                                                <div className="mt-3 space-y-2">
                                                    {diagnosticsData.audit_trail.map((entry) => (
                                                        <article key={entry.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                            <p className="text-xs font-semibold text-slate-800">{titleize(entry.action)}</p>
                                                            <p className="mt-1 text-xs text-slate-600">Actor: {entry.actor?.name || 'System'} • {formatDateTime(entry.created_at)}</p>
                                                            <p className="mt-1 text-xs text-slate-600">Reason: {entry.reason || 'No reason provided'}</p>
                                                        </article>
                                                    ))}
                                                </div>
                                            )}
                                        </details>
                                    </section>

                                    <section className="rounded-md border border-slate-200 bg-white p-3">
                                        <details>
                                            <summary className="cursor-pointer list-none text-sm font-semibold text-slate-900">
                                                Timeline Events
                                                <span className="ml-2 text-xs font-medium text-slate-500">
                                                    ({(diagnosticsData.timeline || []).length})
                                                </span>
                                            </summary>
                                            {(diagnosticsData.timeline || []).length === 0 ? (
                                                <p className="mt-2 text-sm text-slate-500">No payment-linked timeline events have been recorded yet.</p>
                                            ) : (
                                                <div className="mt-3 space-y-2">
                                                    {diagnosticsData.timeline.map((event) => (
                                                        <article key={event.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                                            <p className="text-xs font-semibold text-slate-800">{titleize(event.event_type)}</p>
                                                            <p className="mt-1 text-xs text-slate-600">{describeTimelineContent(event.content)}</p>
                                                            <p className="mt-1 text-[11px] text-slate-500">
                                                                {event.actor?.name || 'System'} • {formatDateTime(event.created_at)}
                                                            </p>
                                                        </article>
                                                    ))}
                                                </div>
                                            )}
                                        </details>
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
                            <option value="">
                                {activeSendLinkProviderEntry
                                    ? `Default configured provider: ${paymentLinkProviderOptionLabel(activeSendLinkProviderEntry[0], activeSendLinkProviderEntry[1])}`
                                    : 'Default configured provider'}
                            </option>
                            {sendLinkProviderEntries.map(([providerKey, providerConfig]) => (
                                <option key={providerKey} value={providerKey}>
                                    {paymentLinkProviderOptionLabel(providerKey, providerConfig)}
                                </option>
                            ))}
                        </select>
                        {effectiveSendLinkProviderEntry ? (
                            <div className="mt-2 rounded-md border border-slate-200 bg-slate-50 px-3 py-2">
                                <p className="text-xs font-semibold text-slate-800">
                                    {sendLinkDialog.provider ? 'Selected route' : 'Current default'}
                                    {' '}
                                    · {paymentLinkProviderOptionLabel(effectiveSendLinkProviderEntry[0], effectiveSendLinkProviderEntry[1])}
                                </p>
                                <p className="mt-1 text-xs text-slate-600">
                                    {effectiveSendLinkProviderEntry[1]?.mode === 'proxy_hosted_checkout'
                                        ? `This sends a CRM-owned link that opens hosted checkout through ${effectiveSendLinkProviderEntry[1]?.label || effectiveSendLinkProviderEntry[0]}${effectiveSendLinkProviderEntry[1]?.environment ? ` (${effectiveSendLinkProviderEntry[1].environment})` : ''}.`
                                        : 'This sends the external pay-page URL configured for this provider.'}
                                </p>
                            </div>
                        ) : (
                            <p className="mt-2 text-xs text-amber-700">No enabled payment-link providers are configured for this market yet.</p>
                        )}
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
                        if (isSandboxPayment(createSubDialog.payment)) {
                            toast.info('Sandbox payments stay in test mode and cannot create live subscriptions.');
                            return;
                        }
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

            <PaymentImportDrawer
                open={importDrawerOpen}
                onClose={() => setImportDrawerOpen(false)}
                platformOptions={platformOptions}
                onCommitSuccess={() => {
                    queryClient.invalidateQueries({ queryKey: ['payments'] });
                }}
            />
        </div>
    );
}
