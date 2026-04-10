import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import api from '../services/api';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import RowActionMenu from '../components/RowActionMenu';
import StatusBadge from '../components/StatusBadge';
import MetricCard from '../components/MetricCard';
import PageHeader from '../components/PageHeader';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';
import { useAuth } from '../hooks/useAuth';
import { platformOptionsWithFlags } from '../utils/flags';
import { getAllowedCrmPaymentMethods, getWalletAutoRenewPresentation } from '../utils/billingMethodPolicy';
import { getDefaultPaymentLinkProviderKey, getEnabledPaymentLinkProviders } from '../utils/paymentLinkProviders';

const DASHBOARD_MARKET_STORAGE_KEY = 'exoticcrm.dashboard.market_filter';
const DEAL_DEACTIVATION_REASON_OPTIONS = [
    { value: 'payment_reversed', label: 'Payment reversed' },
    { value: 'invalid_reference', label: 'Invalid reference' },
    { value: 'fraud_suspected', label: 'Fraud suspected' },
    { value: 'customer_request', label: 'Customer request' },
    { value: 'duplicate_entry', label: 'Duplicate entry' },
    { value: 'other', label: 'Other' },
];
const LINKED_PAYMENT_ACTION_OPTIONS = [
    { value: 'none', label: 'No payment update' },
    { value: 'reverse', label: 'Mark payment reversed' },
    { value: 'invalidate', label: 'Invalidate payment' },
];
const SHARED_BUNDLE_DURATION_OPTIONS = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'biweekly', label: 'Bi-weekly' },
    { value: 'monthly', label: 'Monthly' },
    { value: 'quarterly', label: 'Quarterly' },
    { value: 'annually', label: 'Annually' },
];

function formatCurrency(amount, currency = 'KES') {
    if (amount === null || amount === undefined || amount === '') {
        return '—';
    }

    return `${currency} ${Number(amount).toLocaleString()}`;
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

function defaultLinkedPaymentAction(reasonCode) {
    if (reasonCode === 'payment_reversed') {
        return 'reverse';
    }

    if (reasonCode === 'invalid_reference') {
        return 'invalidate';
    }

    return 'none';
}

function formatReasonLabel(value) {
    const option = DEAL_DEACTIVATION_REASON_OPTIONS.find((entry) => entry.value === value);
    if (option) {
        return option.label;
    }

    return String(value || '')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase());
}

function createBundleIdempotencyKey() {
    if (typeof window !== 'undefined' && window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    return `bundle-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function toBundleNumber(value) {
    const amount = Number(value || 0);
    return Number.isFinite(amount) ? amount : 0;
}

function resolveProductPrice(product, duration) {
    if (!product?.activePrices?.length) return null;
    return product.activePrices.find((p) => p.duration_key === duration || p.duration_key === duration?.toLowerCase()) || null;
}

function resolveBasePrice(product, duration) {
    const priceRow = resolveProductPrice(product, duration);
    if (priceRow) return Number(priceRow.price || 0);
    const legacyMap = { weekly: 'weekly_price', biweekly: 'biweekly_price', monthly: 'monthly_price' };
    return Number(product?.[legacyMap[duration] || 'monthly_price'] || 0);
}

export default function Deals() {
    const allowedBuckets = new Set(['all', 'active', 'risk', 'pending', 'workload', 'stable', 'expired', 'lapsed', 'paused', 'untracked', 'mpesa_review', 'mpesa_history']);
    const allowedStatuses = new Set(['pending', 'awaiting_payment', 'paid', 'active', 'expired', 'renewed', 'cancelled', 'untracked']);
    const allowedCancellationReasons = new Set(DEAL_DEACTIVATION_REASON_OPTIONS.map((option) => option.value));
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const toast = useToast();
    const { user } = useAuth();
    const [searchParams] = useSearchParams();
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(50);
    const [search, setSearch] = useState('');
    const [searchInput, setSearchInput] = useState('');
    const [statusFilter, setStatusFilter] = useState(() => {
        const requested = (searchParams.get('status') || '').trim();
        return allowedStatuses.has(requested) ? requested : '';
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
    const [highRiskFilter, setHighRiskFilter] = useState(() => searchParams.get('high_risk') === '1' ? '1' : '');
    const [cancellationReasonFilter, setCancellationReasonFilter] = useState(() => {
        const requested = (searchParams.get('cancellation_reason_code') || '').trim();
        return allowedCancellationReasons.has(requested) ? requested : '';
    });

    const [dialog, setDialog] = useState({ type: null, deal: null });
    const [activateReason, setActivateReason] = useState('Activated from subscriptions page');
    const [deactivationReasonCode, setDeactivationReasonCode] = useState('other');
    const [deactivationReasonNotes, setDeactivationReasonNotes] = useState('Deactivated from subscriptions page');
    const [deactivationLinkedPaymentAction, setDeactivationLinkedPaymentAction] = useState('none');
    const [extendReason, setExtendReason] = useState('Extended from subscriptions page');
    const [extendDays, setExtendDays] = useState('7');
    const [renewReason, setRenewReason] = useState('Renewed from subscriptions page');
    const [renewDays, setRenewDays] = useState('30');
    const [paymentMethod, setPaymentMethod] = useState('manual');
    const [paymentReference, setPaymentReference] = useState('');
    const [freeTrialPin, setFreeTrialPin] = useState('');
    const [paymentLinkProvider, setPaymentLinkProvider] = useState('');
    const [notifyClient, setNotifyClient] = useState(false);
    const [notificationTemplateId, setNotificationTemplateId] = useState('');
    const [notificationMessage, setNotificationMessage] = useState('');
    const [clearSelectionKey, setClearSelectionKey] = useState(0);
    const [bulkDeactivateDialog, setBulkDeactivateDialog] = useState({
        open: false,
        selection: [],
        reasonCode: 'other',
        reasonNotes: 'Bulk deactivation from subscriptions page',
        linkedPaymentAction: 'none',
        notifyClient: false,
    });
    const [sharedBundleDialog, setSharedBundleDialog] = useState({
        open: false,
        step: 1,
        platformId: '',
        referenceRoot: '',
        totalAmount: '',
        reason: 'Shared manual payment from subscriptions page',
        discountPin: '',
        items: [],
        preview: null,
        idempotencyKey: createBundleIdempotencyKey(),
        clientSearchInput: '',
        clientSearch: '',
    });

    const [mpesaPlanSelections, setMpesaPlanSelections] = useState({});

    const [bucket, setBucket] = useState(() => {
        const requested = (searchParams.get('bucket') || 'all').trim();
        return allowedBuckets.has(requested) ? requested : 'all';
    });

    const isMpesaBucket = bucket === 'mpesa_review';

    const { data, isLoading } = useQuery({
        queryKey: ['deals', page, perPage, search, statusFilter, bucket, platformFilter, highRiskFilter, cancellationReasonFilter],
        queryFn: () =>
            api.get('/crm/deals', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    bucket,
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                    ...(highRiskFilter === '1' && { high_risk: 1 }),
                    ...(cancellationReasonFilter && { cancellation_reason_code: cancellationReasonFilter }),
                },
            }).then((response) => response.data),
        enabled: !isMpesaBucket,
    });

    const { data: integrationData } = useQuery({
        queryKey: ['settings-integrations', 'deals-filter'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const { data: mpesaReviewData, isLoading: mpesaReviewLoading } = useQuery({
        queryKey: ['mpesa-review', page, perPage, search, platformFilter],
        queryFn: () =>
            api.get('/crm/payments/mpesa-review', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
                },
            }).then((r) => r.data),
        enabled: isMpesaBucket,
    });

    const { data: mpesaCountData } = useQuery({
        queryKey: ['mpesa-review-count', platformFilter],
        queryFn: () =>
            api.get('/crm/payments/mpesa-review', {
                params: { per_page: 1, ...(platformFilter && { platform_id: Number(platformFilter) }) },
            }).then((r) => r.data?.meta?.total_review ?? 0),
        staleTime: 60000,
    });
    const mpesaReviewCount = typeof mpesaCountData === 'number' ? mpesaCountData : 0;

    const platformOptions = integrationData?.platforms || [];
    const selectedPlatformCurrency = useMemo(() => {
        if (!platformFilter) {
            return '';
        }

        const selected = platformOptions.find((platform) => String(platform.platform_id) === String(platformFilter));
        return selected?.currency || '';
    }, [platformFilter, platformOptions]);

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

    const paymentLinkProviderOptions = useMemo(
        () => getEnabledPaymentLinkProviders(selectedClientData?.platform),
        [selectedClientData?.platform],
    );
    const billingPolicySource = selectedClientData?.platform || selectedDeal?.client?.platform || selectedDeal;
    const activationPaymentMethods = useMemo(
        () => getAllowedCrmPaymentMethods(billingPolicySource, 'activation'),
        [billingPolicySource],
    );
    const renewalPaymentMethods = useMemo(
        () => getAllowedCrmPaymentMethods(billingPolicySource, 'renewal'),
        [billingPolicySource],
    );
    const availableDialogPaymentMethods = dialog.type === 'activate'
        ? activationPaymentMethods
        : renewalPaymentMethods;
    const defaultPaymentLinkProvider = useMemo(
        () => getDefaultPaymentLinkProviderKey(selectedClientData?.platform),
        [selectedClientData?.platform],
    );
    const paymentRequiresReference = paymentMethod === 'manual';
    const paymentRequiresFreeTrialPin = paymentMethod === 'free_trial';
    const paymentRequiresProvider = paymentMethod === 'link';
    const canOverridePaymentLinkProvider = ['admin', 'sub_admin'].includes(String(user?.role || ''));

    useEffect(() => {
        if (!dialog.type) {
            return;
        }

        if (!paymentLinkProvider && defaultPaymentLinkProvider) {
            setPaymentLinkProvider(defaultPaymentLinkProvider);
        }
    }, [defaultPaymentLinkProvider, dialog.type, paymentLinkProvider]);

    const activateMutation = useMutation({
        mutationFn: ({ dealId, activationReason, selectedPaymentMethod, referenceValue, freeTrialPinValue, paymentLinkProviderValue }) =>
            api.post(`/crm/deals/${dealId}/activate`, {
                reason: activationReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPinValue } : {}),
                ...(selectedPaymentMethod === 'link' && canOverridePaymentLinkProvider && paymentLinkProviderValue ? { payment_link_provider: paymentLinkProviderValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setActivateReason('Activated from subscriptions page');
            setPaymentMethod(activationPaymentMethods[0] || '');
            setPaymentReference('');
            setFreeTrialPin('');
            setPaymentLinkProvider(defaultPaymentLinkProvider);
            toast.success('Subscription activated successfully.');
        },
        onError: (error) => {
            if (error?.response?.status === 409 && error?.response?.data?.reference_root) {
                toast.warning(`Reference root ${error.response.data.reference_root} is already attached to a shared manual payment bundle.`);
                return;
            }
            toast.error(error?.response?.data?.message || 'Subscription activation failed.');
        },
    });

    const deactivateMutation = useMutation({
        mutationFn: ({ dealId, reasonCode, reasonNotes, linkedPaymentAction, shouldNotify, templateId, message }) =>
            api.post(`/crm/deals/${dealId}/deactivate`, {
                reason_code: reasonCode,
                reason_notes: reasonNotes,
                linked_payment_action: linkedPaymentAction,
                notify_client: Boolean(shouldNotify),
                notification_template_id: templateId || null,
                notification_message: message || null,
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setDeactivationReasonCode('other');
            setDeactivationReasonNotes('Deactivated from subscriptions page');
            setDeactivationLinkedPaymentAction('none');
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
        mutationFn: ({ dealId, additionalDays, extensionReason, selectedPaymentMethod, referenceValue, freeTrialPinValue, paymentLinkProviderValue }) =>
            api.post(`/crm/deals/${dealId}/extend`, {
                additional_days: additionalDays,
                reason: extensionReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPinValue } : {}),
                ...(selectedPaymentMethod === 'link' && paymentLinkProviderValue ? { payment_link_provider: paymentLinkProviderValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setExtendDays('7');
            setExtendReason('Extended from subscriptions page');
            setPaymentMethod(renewalPaymentMethods[0] || '');
            setPaymentReference('');
            setFreeTrialPin('');
            setPaymentLinkProvider(defaultPaymentLinkProvider);
            toast.success('Subscription extension saved.');
        },
        onError: (error) => {
            if (error?.response?.status === 409 && error?.response?.data?.reference_root) {
                toast.warning(`Reference root ${error.response.data.reference_root} is already attached to a shared manual payment bundle.`);
                return;
            }
            toast.error(error?.response?.data?.message || 'Subscription extension failed.');
        },
    });

    const renewMutation = useMutation({
        mutationFn: ({ dealId, additionalDays, renewalReason, selectedPaymentMethod, referenceValue, freeTrialPinValue, paymentLinkProviderValue }) =>
            api.post(`/crm/deals/${dealId}/renew`, {
                additional_days: additionalDays,
                reason: renewalReason,
                payment_method: selectedPaymentMethod,
                ...(selectedPaymentMethod === 'manual' ? { payment_reference: referenceValue } : {}),
                ...(selectedPaymentMethod === 'free_trial' ? { free_trial_pin: freeTrialPinValue } : {}),
                ...(selectedPaymentMethod === 'link' && paymentLinkProviderValue ? { payment_link_provider: paymentLinkProviderValue } : {}),
            }).then((response) => response.data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setDialog({ type: null, deal: null });
            setRenewDays('30');
            setRenewReason('Renewed from subscriptions page');
            setPaymentMethod(renewalPaymentMethods[0] || '');
            setPaymentReference('');
            setFreeTrialPin('');
            setPaymentLinkProvider(defaultPaymentLinkProvider);
            toast.success('Subscription renewed successfully.');
        },
        onError: (error) => {
            if (error?.response?.status === 409 && error?.response?.data?.reference_root) {
                toast.warning(`Reference root ${error.response.data.reference_root} is already attached to a shared manual payment bundle.`);
                return;
            }
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

    const bulkDeactivateMutation = useMutation({
        mutationFn: async ({ selection, reasonCode, reasonNotes, linkedPaymentAction, notifyClient }) => {
            const targets = selection.filter((row) => row.status === 'active' || row.status === 'expired');
            const skipped = selection.length - targets.length;

            const results = await Promise.allSettled(
                targets.map((row) =>
                    api.post(`/crm/deals/${row.id}/deactivate`, {
                        reason_code: reasonCode,
                        reason_notes: reasonNotes,
                        linked_payment_action: linkedPaymentAction,
                        notify_client: notifyClient,
                    }),
                ),
            );

            const success = results.filter((result) => result.status === 'fulfilled').length;
            const failed = results.length - success;

            return { total: selection.length, success, failed, skipped };
        },
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            setBulkDeactivateDialog((d) => ({
                ...d,
                open: false,
                selection: [],
                reasonCode: 'other',
                reasonNotes: 'Bulk deactivation from subscriptions page',
                linkedPaymentAction: 'none',
            }));
            if (result.failed > 0) {
                toast.warning(`Bulk deactivate: ${result.success}/${result.total} succeeded.`);
                return;
            }
            toast.success(`Bulk deactivate: ${result.success}/${result.total} processed.`);
        },
        onError: () => {
            toast.error('Bulk deactivation failed.');
        },
    });

    const mpesaConfirmMutation = useMutation({
        mutationFn: (selections) =>
            api.post('/crm/payments/mpesa-confirm-subscriptions', { selections }).then((r) => r.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['mpesa-review'] });
            queryClient.invalidateQueries({ queryKey: ['mpesa-review-count'] });
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((v) => v + 1);
            setMpesaPlanSelections({});
            if (result.failed > 0) {
                toast.warning(`${result.created} confirmed, ${result.failed} failed.`);
            } else if (result.created_expired > 0) {
                toast.success(
                    `${result.created} subscription(s) confirmed: ${result.created_active} active, ${result.created_expired} historical (expired).`
                );
            } else {
                toast.success(`${result.created} subscription(s) confirmed successfully.`);
            }
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Subscription confirmation failed.');
        },
    });

    const sharedBundlePreviewMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/manual-payment-bundles/preview', payload).then((response) => response.data),
        onSuccess: (result) => {
            setSharedBundleDialog((current) => ({
                ...current,
                step: 3,
                preview: result,
                items: result.items.map((item) => ({
                    ...current.items.find((entry) => Number(entry.client_id) === Number(item.client_id)),
                    client_id: item.client_id,
                    client_name: item.client_name,
                    product_id: item.product_id,
                    product_name: item.product_name,
                    duration: item.duration,
                    allocated_amount: String(item.allocated_amount),
                })),
            }));
        },
        onError: (error) => {
            const conflictRoot = error?.response?.data?.conflict?.reference_root;
            if (error?.response?.status === 409 && conflictRoot) {
                toast.warning(`Reference root ${conflictRoot} is already in use. Re-open the existing shared payment instead.`);
                return;
            }
            toast.error(error?.response?.data?.message || 'Shared payment preview failed.');
        },
    });

    const sharedBundleCommitMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/manual-payment-bundles/commit', payload).then((response) => response.data),
        onSuccess: (result) => {
            queryClient.invalidateQueries({ queryKey: ['deals'] });
            queryClient.invalidateQueries({ queryKey: ['payments'] });
            queryClient.invalidateQueries({ queryKey: ['dashboard'] });
            setClearSelectionKey((value) => value + 1);
            setSharedBundleDialog({
                open: false,
                step: 1,
                platformId: '',
                referenceRoot: '',
                totalAmount: '',
                reason: 'Shared manual payment from subscriptions page',
                discountPin: '',
                items: [],
                preview: null,
                idempotencyKey: createBundleIdempotencyKey(),
                clientSearchInput: '',
                clientSearch: '',
            });
            toast.success(`Shared manual payment committed as bundle #${result.bundle?.id}.`);
        },
        onError: (error) => {
            const conflictRoot = error?.response?.data?.conflict?.reference_root;
            if (error?.response?.status === 409 && conflictRoot) {
                toast.warning(`Reference root ${conflictRoot} is already in use. Use the existing shared payment instead.`);
                return;
            }
            toast.error(error?.response?.data?.message || 'Shared payment commit failed.');
        },
    });

    // Live client search inside the shared bundle modal step 1
    const { data: bundleClientSearchResults, isFetching: bundleClientSearchLoading } = useQuery({
        queryKey: ['bundle-client-search', sharedBundleDialog.platformId, sharedBundleDialog.clientSearch],
        queryFn: () =>
            api.get('/crm/clients', {
                params: {
                    platform_id: Number(sharedBundleDialog.platformId),
                    search: sharedBundleDialog.clientSearch,
                    per_page: 10,
                },
            }).then((response) => response.data),
        enabled: sharedBundleDialog.open
            && sharedBundleDialog.step === 1
            && !!sharedBundleDialog.platformId
            && sharedBundleDialog.clientSearch.trim().length >= 2,
        staleTime: 10_000,
    });

    // Products catalog for step 2 plan assignment
    const { data: bundleProductsData } = useQuery({
        queryKey: ['bundle-products', sharedBundleDialog.platformId],
        queryFn: () =>
            api.get('/crm/products', {
                params: { platform_id: Number(sharedBundleDialog.platformId) },
            }).then((response) => response.data),
        enabled: sharedBundleDialog.open && sharedBundleDialog.step === 2 && !!sharedBundleDialog.platformId,
        staleTime: 60_000,
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
                untracked: Number(data.summary.untracked_active || 0),
            };
        }

        return { active: 0, modernActive: 0, legacyActive: 0, pending: 0, risk: 0, expired: 0, untracked: 0 };
    }, [data?.summary]);

    const inScopeTotal = Number(data?.summary?.in_scope_total || data?.targets?.total || 0);

    const activeMetric = useMemo(() => {
        if (bucket === 'all' && statusFilter === '') return 'scope';
        if (bucket === 'risk') return 'risk';
        if (bucket === 'pending') return 'pipeline';
        if (bucket === 'expired') return 'expired';
        if (bucket === 'untracked') return 'untracked';
        if (bucket === 'mpesa_review') return 'mpesa';
        if (bucket === 'mpesa_history') return 'mpesa_history';
        return '';
    }, [bucket, statusFilter]);

    const applyMetricFilter = (metricKey) => {
        const metricBucketMap = {
            scope: 'all',
            risk: 'risk',
            pipeline: 'pending',
            expired: 'expired',
            untracked: 'untracked',
            mpesa: 'mpesa_review',
            mpesa_history: 'mpesa_history',
        };

        const nextBucket = metricBucketMap[metricKey] || 'all';
        setBucket((current) => (current === nextBucket ? 'all' : nextBucket));
        setStatusFilter('');
        setPage(1);
    };

    const selectedClientPhone = selectedClientData?.phone_normalized || selectedDeal?.client?.phone_normalized || '';
    const sharedBundleAllocatedTotal = sharedBundleDialog.items.reduce(
        (sum, item) => sum + toBundleNumber(item.allocated_amount),
        0,
    );
    const sharedBundlePaidTotal = toBundleNumber(sharedBundleDialog.totalAmount);
    const sharedBundleShortfall = Math.max(0, sharedBundleAllocatedTotal - sharedBundlePaidTotal);
    const sharedBundleUnallocated = Math.max(0, sharedBundlePaidTotal - sharedBundleAllocatedTotal);
    const sharedBundleItemsValid = sharedBundleDialog.items.length > 0
        && sharedBundleDialog.items.every((item) => item.client_id && item.product_id && item.duration);
    const sharedBundlePayload = sharedBundleDialog.platformId && sharedBundleItemsValid ? {
        platform_id: Number(sharedBundleDialog.platformId),
        reference_root: sharedBundleDialog.referenceRoot.trim(),
        total_amount: sharedBundlePaidTotal,
        reason: sharedBundleDialog.reason.trim(),
        items: sharedBundleDialog.items.map((item) => ({
            client_id: Number(item.client_id),
            product_id: Number(item.product_id),
            duration: item.duration || 'monthly',
            ...(item.product_price_id ? { product_price_id: Number(item.product_price_id) } : {}),
            allocated_amount: toBundleNumber(item.allocated_amount),
        })),
    } : null;

    const smsTemplates = useMemo(() => {
        const templates = templatesData?.templates || templatesData?.data || [];
        return templates.filter((template) => template.channel === 'sms' && template.status === 'active');
    }, [templatesData]);

    const openDialog = (type, deal, event) => {
        event?.stopPropagation();
        setDialog({ type, deal });
        const nextMethods = type === 'activate' ? activationPaymentMethods : renewalPaymentMethods;
        setPaymentMethod(nextMethods[0] || '');
        setPaymentReference('');
        setFreeTrialPin('');
        setPaymentLinkProvider(defaultPaymentLinkProvider);
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
            setDeactivationReasonCode('other');
            setDeactivationReasonNotes('Deactivated from subscriptions page');
            setDeactivationLinkedPaymentAction('none');
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
                        <p className="text-sm font-semibold text-slate-900" title={row.client?.name || 'Unknown'}>
                            {row.client?.name || 'Unknown'}
                        </p>
                        {row.origin_type === 'modern' ? (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-blue-50 px-1 text-[10px] font-bold uppercase tracking-wider text-blue-600 ring-1 ring-inset ring-blue-600/20">Modern</span>
                        ) : row.origin_type === 'mpesa_import' ? (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA</span>
                        ) : row.origin_type === 'untracked' ? (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-amber-50 px-1 text-[10px] font-bold uppercase tracking-wider text-amber-700 ring-1 ring-inset ring-amber-700/20">Untracked</span>
                        ) : (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-slate-50 px-1 text-[10px] font-bold uppercase tracking-wider text-slate-500 ring-1 ring-inset ring-slate-600/10">Legacy</span>
                        )}
                        {row.is_free_trial && (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-violet-50 px-1 text-[10px] font-bold uppercase tracking-wider text-violet-700 ring-1 ring-inset ring-violet-600/20">Free Trial</span>
                        )}
                        {row.client?.is_high_risk && (
                            <span className="inline-flex shrink-0 items-center rounded-sm bg-rose-50 px-1 text-[10px] font-bold uppercase tracking-wider text-rose-700 ring-1 ring-inset ring-rose-200">High Risk</span>
                        )}
                    </div>
                    <p className="crm-mono text-xs text-slate-500" title={row.client?.phone_normalized || ''}>
                        {row.client?.phone_normalized || ''}
                    </p>
                </div>
            ),
        },
        {
            key: 'product_plan',
            label: 'Product',
            render: (row) => {
                const productName = row.product?.name || row.inferred_product_name;
                const planType = row.plan_type || row.inferred_plan_type;
                const isLegacy = !row.plan_type && !!row.inferred_plan_type;

                if (productName) {
                    return (
                        <div>
                            <span className="text-sm text-slate-700">{productName}</span>
                            {planType && planType.toLowerCase() !== productName.toLowerCase() ? (
                                <p className="text-[11px] capitalize text-slate-400">{planType}</p>
                            ) : null}
                        </div>
                    );
                }

                if (planType) {
                    return (
                        <span className="inline-flex items-center gap-1 text-sm text-slate-700">
                            <span className="capitalize">{planType}</span>
                            {isLegacy ? (
                                <span className="inline-flex items-center rounded-sm bg-slate-100 px-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600 ring-1 ring-inset ring-slate-200">
                                    Legacy
                                </span>
                            ) : null}
                        </span>
                    );
                }

                return <span className="text-sm text-slate-400">—</span>;
            },
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => (
                <div className="flex items-center gap-1.5">
                    <span className={`text-sm font-semibold ${row.amount === null || row.amount === undefined || row.amount === '' ? 'text-slate-400' : 'text-slate-900'}`}>
                        {formatCurrency(row.amount, row.currency || selectedPlatformCurrency || 'KES')}
                    </span>
                    {row.amount_is_estimate ? (
                        <span className="inline-flex items-center rounded-sm bg-slate-100 px-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600 ring-1 ring-inset ring-slate-200">
                            Est.
                        </span>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'duration',
            label: 'Duration',
            render: (row) => (
                <div className="flex items-center gap-1.5">
                    {row.duration ? (
                        <span className="text-sm capitalize text-slate-700">{row.duration}</span>
                    ) : (
                        <span className="text-sm text-slate-400">—</span>
                    )}
                    {row.duration_is_estimate ? (
                        <span className="inline-flex items-center rounded-sm bg-slate-100 px-1 text-[10px] font-semibold uppercase tracking-wider text-slate-600 ring-1 ring-inset ring-slate-200">
                            Est.
                        </span>
                    ) : null}
                </div>
            ),
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
                    {row.cancellation_reason_code ? (
                        <span className="inline-flex items-center rounded-sm bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200">
                            {formatReasonLabel(row.cancellation_reason_code)}
                        </span>
                    ) : null}
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
            key: 'wallet_auto_renew_state',
            label: 'Renewal',
            render: (row) => {
                const renewalState = getWalletAutoRenewPresentation(row);

                if (!renewalState) {
                    return <span className="text-xs text-slate-400">—</span>;
                }

                return (
                    <div className="space-y-1">
                        <StatusBadge status={renewalState.status} label={renewalState.label} tone={renewalState.tone} />
                        <p className="max-w-[220px] text-[11px] leading-4 text-slate-500">
                            {renewalState.detail}
                            {renewalState.updatedAt ? ` • ${new Date(renewalState.updatedAt).toLocaleString()}` : ''}
                        </p>
                    </div>
                );
            },
        },
        {
            key: 'actions',
            label: 'Actions',
            headerClassName: 'text-right',
            render: (row) => {
                let primaryAction = null;
                const overflowActions = [];

                if (row.is_virtual) {
                    primaryAction = {
                        label: row.status === 'untracked' ? 'Create subscription' : 'Activate',
                        variant: 'primary',
                        disabled: !row.client_id,
                        onClick: () => {
                            if (!row.client_id) return;
                            const source = row.status === 'untracked' ? 'untracked_row' : 'legacy_row';
                            navigate(`/clients/${row.client_id}?tab=deals&action=new_subscription&source=${source}`);
                        },
                    };
                    overflowActions.push({
                        key: 'open-profile',
                        label: 'Open profile',
                        disabled: !row.client_id,
                        onClick: () => row.client_id && navigate(`/clients/${row.client_id}`),
                    });
                } else if (row.status === 'pending') {
                    primaryAction = { label: 'Activate', variant: 'primary', onClick: () => openDialog('activate', row) };
                } else if (row.status === 'active') {
                    primaryAction = { label: 'Extend', variant: 'default', onClick: () => openDialog('extend', row) };
                    overflowActions.push({ key: 'deactivate', label: 'Deactivate', variant: 'warning', onClick: () => openDialog('deactivate', row) });
                } else {
                    primaryAction = { label: 'Renew', variant: 'success', onClick: () => openDialog('renew', row) };
                    overflowActions.push({ key: 'delete', label: 'Delete', variant: 'danger', onClick: () => openDialog('delete', row) });
                }

                return (
                    <div className="flex justify-end">
                        <RowActionMenu primaryAction={primaryAction} actions={overflowActions} />
                    </div>
                );
            },
        },
    ];

    const bulkActions = [
        {
            key: 'record-shared-manual-payment',
            label: 'Record shared manual payment',
            loadingLabel: 'Preparing...',
            variant: 'primary',
            onClick: (rowsSelection) => {
                if (!platformFilter) {
                    toast.warning('Choose one market first, then select the clients you want to settle together.');
                    return;
                }

                const marketIds = [...new Set(rowsSelection.map((row) => String(row.platform_id || row.client?.platform_id || '')))];
                if (marketIds.length !== 1 || String(marketIds[0]) !== String(platformFilter)) {
                    toast.warning('Shared manual payments can only be recorded for one filtered market at a time.');
                    return;
                }

                // Pre-populate clients from selected rows (product must still be chosen in step 2)
                const preItems = rowsSelection.map((row) => ({
                    client_id: row.client?.id || row.client_id || null,
                    client_name: row.client?.name || 'Unknown client',
                    client_phone: row.client?.phone_normalized || '',
                    product_id: null,
                    product_name: '',
                    product_price_id: null,
                    duration: 'monthly',
                    base_price: 0,
                    allocated_amount: '',
                })).filter((item) => item.client_id);

                if (!preItems.length) {
                    toast.warning('None of the selected rows have a linked client.');
                    return;
                }

                setSharedBundleDialog({
                    open: true,
                    step: 1,
                    platformId: String(platformFilter),
                    referenceRoot: '',
                    totalAmount: '',
                    reason: 'Shared manual payment from subscriptions page',
                    discountPin: '',
                    items: preItems,
                    preview: null,
                    idempotencyKey: createBundleIdempotencyKey(),
                    clientSearchInput: '',
                    clientSearch: '',
                });
            },
        },
        {
            key: 'bulk-deactivate',
            label: 'Deactivate selected',
            loadingLabel: 'Preparing...',
            variant: 'danger',
            onClick: (rowsSelection) => {
                setBulkDeactivateDialog({
                    open: true,
                    selection: rowsSelection,
                    reasonCode: 'other',
                    reasonNotes: 'Bulk deactivation from subscriptions page',
                    linkedPaymentAction: 'none',
                    notifyClient: false,
                });
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

    const mpesaColumns = [
        {
            key: 'client',
            label: 'Client',
            render: (row) => (
                <div>
                    <div className="flex items-center gap-2">
                        <p className="text-sm font-semibold text-slate-900">{row.client?.name || row.sender_name || 'Unknown'}</p>
                        <span className="inline-flex items-center rounded-sm bg-teal-50 px-1 text-[10px] font-bold uppercase tracking-wider text-teal-700 ring-1 ring-inset ring-teal-600/20">MPESA</span>
                    </div>
                    <p className="crm-mono text-xs text-slate-500">{row.client?.phone_normalized || row.phone || ''}</p>
                </div>
            ),
        },
        {
            key: 'amount',
            label: 'Amount',
            render: (row) => (
                <span className="text-sm font-semibold text-slate-900">
                    {formatCurrency(row.amount, row.currency || row.platform?.currency_code || 'KES')}
                </span>
            ),
        },
        {
            key: 'transaction',
            label: 'Transaction',
            render: (row) => (
                <div>
                    <p className="crm-mono text-xs font-semibold text-slate-700">{row.transaction_reference || '—'}</p>
                    <p className="text-[11px] text-slate-400">
                        {row.created_at ? new Date(row.created_at).toLocaleDateString() : '—'}
                    </p>
                </div>
            ),
        },
        {
            key: 'plan_estimate',
            label: 'Estimated Plan',
            render: (row) => {
                const estimates = row.product_estimates || [];
                const selection = mpesaPlanSelections[row.id];

                if (estimates.length === 0) {
                    return <span className="text-xs text-slate-400">No match</span>;
                }

                if (estimates.length === 1) {
                    const est = estimates[0];
                    return (
                        <div className="flex items-center gap-1.5">
                            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ${
                                est.exact_match
                                    ? 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20'
                                    : 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20'
                            }`}>
                                {est.product_name} {est.duration_key}
                            </span>
                            <span className={`h-2 w-2 rounded-full ${
                                est.confidence === 'exact' ? 'bg-emerald-500' : est.confidence === 'high' ? 'bg-amber-500' : 'bg-slate-400'
                            }`} title={`Confidence: ${est.confidence}`} />
                        </div>
                    );
                }

                return (
                    <select
                        value={selection ? `${selection.product_id}:${selection.duration_key}` : ''}
                        onChange={(e) => {
                            const val = e.target.value;
                            if (!val) {
                                setMpesaPlanSelections((prev) => {
                                    const next = { ...prev };
                                    delete next[row.id];
                                    return next;
                                });
                                return;
                            }
                            const [pid, dk] = val.split(':');
                            setMpesaPlanSelections((prev) => ({
                                ...prev,
                                [row.id]: { product_id: Number(pid), duration_key: dk },
                            }));
                        }}
                        className="crm-select text-xs"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <option value="">Select plan...</option>
                        {estimates.map((est) => (
                            <option key={`${est.product_id}:${est.duration_key}`} value={`${est.product_id}:${est.duration_key}`}>
                                {est.product_name} {est.duration_key} — {formatCurrency(est.price, row.currency || 'KES')}
                                {est.exact_match ? ' (exact)' : ''}
                            </option>
                        ))}
                    </select>
                );
            },
        },
        {
            key: 'actions',
            label: '',
            render: (row) => {
                const estimates = row.product_estimates || [];
                const selection = mpesaPlanSelections[row.id];
                const canConfirm = estimates.length === 1 || Boolean(selection);

                return (
                    <div className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={!canConfirm || mpesaConfirmMutation.isPending}
                            onClick={(e) => {
                                e.stopPropagation();
                                const sel = selection || (estimates.length === 1 ? { product_id: estimates[0].product_id, duration_key: estimates[0].duration_key } : null);
                                if (!sel) return;
                                mpesaConfirmMutation.mutate([{
                                    payment_id: row.id,
                                    product_id: sel.product_id,
                                    duration_key: sel.duration_key,
                                }]);
                            }}
                            className="crm-btn-primary text-xs disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Confirm
                        </button>
                        {row.client?.id ? (
                            <button
                                type="button"
                                onClick={(e) => { e.stopPropagation(); navigate(`/clients/${row.client.id}`); }}
                                className="text-xs text-slate-500 hover:text-slate-700"
                            >
                                Profile
                            </button>
                        ) : null}
                    </div>
                );
            },
        },
    ];

    const mpesaBulkActions = [
        {
            key: 'mpesa-bulk-confirm',
            label: 'Confirm subscriptions',
            loadingLabel: 'Confirming...',
            variant: 'primary',
            onClick: (rowsSelection) => {
                const selections = rowsSelection
                    .map((row) => {
                        const estimates = row.product_estimates || [];
                        const sel = mpesaPlanSelections[row.id] || (estimates.length === 1 ? { product_id: estimates[0].product_id, duration_key: estimates[0].duration_key } : null);
                        if (!sel) return null;
                        return { payment_id: row.id, ...sel };
                    })
                    .filter(Boolean);

                if (selections.length === 0) {
                    toast.warning('No confirmable rows selected. Select a plan for rows with multiple candidates.');
                    return;
                }

                mpesaConfirmMutation.mutate(selections);
            },
        },
    ];

    useEffect(() => {
        if (!mpesaReviewData?.data) return;
        setMpesaPlanSelections((prev) => {
            const next = { ...prev };
            mpesaReviewData.data.forEach((row) => {
                if (next[row.id]) return;
                const estimates = row.product_estimates || [];
                if (estimates.length === 1) {
                    next[row.id] = { product_id: estimates[0].product_id, duration_key: estimates[0].duration_key };
                }
            });
            return next;
        });
    }, [mpesaReviewData?.data]);

    useEffect(() => {
        if (!['activate', 'extend', 'renew'].includes(dialog.type || '')) {
            return;
        }

        if (!availableDialogPaymentMethods.includes(paymentMethod)) {
            setPaymentMethod(availableDialogPaymentMethods[0] || '');
        }
    }, [availableDialogPaymentMethods, dialog.type, paymentMethod]);

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

    const needsPaymentVerification = ['activate', 'extend', 'renew'].includes(dialog.type || '');
    const paymentReady = !needsPaymentVerification
        || (availableDialogPaymentMethods.length > 0
            && (!paymentRequiresReference || paymentReference.trim() !== '')
            && (!paymentRequiresFreeTrialPin || freeTrialPin.trim().length >= 4)
            && (!paymentRequiresProvider || paymentLinkProvider !== ''));

    return (
        <div className="space-y-4">
            <PageHeader
                title="Subscriptions"
                subtitle={inScopeTotal
                    ? `${inScopeTotal.toLocaleString()} in-scope profiles`
                    : 'Subscription activation and lifecycle management'}
            />

            <section className={`grid gap-4 ${mpesaReviewCount > 0 ? 'md:grid-cols-3 xl:grid-cols-6' : 'md:grid-cols-5'}`}>
                <MetricCard
                    label="In Scope (All Types)"
                    value={inScopeTotal.toLocaleString()}
                    meta={`${summary.modernActive.toLocaleString()} modern active | ${summary.legacyActive.toLocaleString()} legacy active | ${summary.untracked.toLocaleString()} untracked`}
                    tone="slate"
                    onClick={() => applyMetricFilter('scope')}
                    active={activeMetric === 'scope'}
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
                <MetricCard
                    label="Untracked Active"
                    value={summary.untracked.toLocaleString()}
                    meta="Published profiles with no expiry/deal"
                    tone="warning"
                    onClick={() => applyMetricFilter('untracked')}
                    active={activeMetric === 'untracked'}
                />
                {mpesaReviewCount > 0 ? (
                    <MetricCard
                        label="MPESA Review"
                        value={mpesaReviewCount.toLocaleString()}
                        meta="Imported payments awaiting confirmation"
                        tone="accent"
                        onClick={() => applyMetricFilter('mpesa')}
                        active={activeMetric === 'mpesa'}
                    />
                ) : null}
            </section>

            <p className="px-1 text-xs text-slate-500">Click a metric card to filter this table. Click it again to clear.</p>

            <section className="crm-filter-row">
                <div className="flex flex-wrap items-end gap-3">
                    <form onSubmit={handleSearch} className="min-w-[220px] flex-1">
                        <div className="flex flex-col gap-1">
                            <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">Search</span>
                            <div className="relative">
                                <input
                                    type="text"
                                    value={searchInput}
                                    onChange={(event) => setSearchInput(event.target.value)}
                                    placeholder="Client name or phone..."
                                    className="crm-input pr-10"
                                />
                                <button type="submit" aria-label="Run subscription search" className="absolute right-2 top-1/2 -translate-y-1/2 rounded p-1 text-slate-400 transition hover:text-slate-600">
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
                        label="Bucket"
                        value={bucket}
                        onChange={(event) => { setBucket(event.target.value); setPage(1); }}
                        options={[
                            { value: 'all', label: 'Unified (Non-Lapsed)' },
                            { value: 'active', label: 'Active Only' },
                            { value: 'risk', label: 'At Risk (0-3d)' },
                            { value: 'pending', label: 'Upcoming (4-14d)' },
                            { value: 'workload', label: 'Renewal (0-14d)' },
                            { value: 'stable', label: 'Stable (>14d)' },
                            { value: 'expired', label: 'Recently Expired' },
                            { value: 'untracked', label: 'Untracked Active' },
                            { value: 'lapsed', label: 'Lapsed (Legacy)' },
                            { value: 'paused', label: 'Paused Reminders' },
                            ...(mpesaReviewCount > 0 ? [{ value: 'mpesa_review', label: `MPESA Review (${mpesaReviewCount})` }] : [{ value: 'mpesa_review', label: 'MPESA Review' }]),
                            { value: 'mpesa_history', label: 'MPESA History (Imported)' },
                        ]}
                    />

                    <FilterSelect
                        label="Status"
                        value={statusFilter}
                        onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All statuses' },
                            { value: 'pending', label: 'Pending' },
                            { value: 'awaiting_payment', label: 'Awaiting payment' },
                            { value: 'paid', label: 'Paid' },
                            { value: 'active', label: 'Active' },
                            { value: 'expired', label: 'Expired' },
                            { value: 'untracked', label: 'Untracked' },
                            { value: 'renewed', label: 'Renewed' },
                            { value: 'cancelled', label: 'Cancelled' },
                        ]}
                    />

                    <FilterSelect
                        label="Risk"
                        value={highRiskFilter}
                        onChange={(event) => { setHighRiskFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All clients' },
                            { value: '1', label: 'High risk only' },
                        ]}
                    />

                    <FilterSelect
                        label="Cancellation"
                        value={cancellationReasonFilter}
                        onChange={(event) => { setCancellationReasonFilter(event.target.value); setPage(1); }}
                        options={[
                            { value: '', label: 'All reasons' },
                            ...DEAL_DEACTIVATION_REASON_OPTIONS,
                        ]}
                    />

                    {(search || statusFilter || bucket !== 'all' || platformFilter || highRiskFilter || cancellationReasonFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setBucket('all');
                                setPlatformFilter('');
                                setHighRiskFilter('');
                                setCancellationReasonFilter('');
                                setPage(1);
                            }}
                            className="mb-0.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        >
                            Reset all
                        </button>
                    ) : null}
                </div>

                {platformFilter && !isMpesaBucket ? (
                    <div className="mt-3 flex items-center justify-end border-t border-slate-100 pt-3">
                        <button
                            type="button"
                            onClick={() => {
                                setSharedBundleDialog({
                                    open: true,
                                    step: 1,
                                    selection: [],
                                    platformId: String(platformFilter),
                                    referenceRoot: '',
                                    totalAmount: '',
                                    reason: 'Shared manual payment from subscriptions page',
                                    discountPin: '',
                                    items: [],
                                    preview: null,
                                    idempotencyKey: createBundleIdempotencyKey(),
                                    clientSearchInput: '',
                                    clientSearch: '',
                                });
                            }}
                            className="flex items-center gap-2 rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                            </svg>
                            Record shared manual payment
                        </button>
                    </div>
                ) : null}
            </section>

            {isMpesaBucket ? (
                <>
                    {mpesaReviewData?.data?.length > 0 ? (
                        <div className="rounded-lg border border-teal-200 bg-teal-50/50 px-4 py-2.5">
                            <div className="flex items-center gap-2 text-sm text-teal-800">
                                <svg className="h-4 w-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                <span>
                                    <strong>{mpesaReviewData.meta?.total ?? 0}</strong> MPESA payments matched to clients but not yet confirmed as subscriptions.
                                    Select rows and choose a plan to confirm.
                                </span>
                            </div>
                        </div>
                    ) : null}
                    <DataTable
                        columns={mpesaColumns}
                        data={mpesaReviewData?.data}
                        pagination={mpesaReviewData?.meta ? {
                            current_page: mpesaReviewData.meta.current_page,
                            last_page: mpesaReviewData.meta.last_page,
                            per_page: mpesaReviewData.meta.per_page,
                            total: mpesaReviewData.meta.total,
                        } : undefined}
                        onPageChange={setPage}
                        onRowClick={(row) => row.client?.id && navigate(`/clients/${row.client.id}`)}
                        isLoading={mpesaReviewLoading}
                        emptyMessage="No MPESA payments awaiting review."
                        compact
                        selectable
                        bulkActions={mpesaBulkActions}
                        clearSelectionKey={clearSelectionKey}
                        perPage={perPage}
                        onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
                    />
                </>
            ) : (
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
                    perPage={perPage}
                    onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
                />
            )}

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
                                        {availableDialogPaymentMethods.map((method) => (
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
                                    {availableDialogPaymentMethods.length === 0 ? (
                                        <div className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                                            No CRM payment methods are enabled for this market on this subscription action.
                                        </div>
                                    ) : null}

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

                                    {paymentMethod === 'link' && canOverridePaymentLinkProvider ? (
                                        <div className="space-y-2">
                                            <label htmlFor="deal-payment-link-provider" className="mb-1 block text-sm font-medium text-slate-700">
                                                Payment Link Provider
                                            </label>
                                            <select
                                                id="deal-payment-link-provider"
                                                value={paymentLinkProvider}
                                                onChange={(event) => setPaymentLinkProvider(event.target.value)}
                                                className="crm-select"
                                                disabled={!paymentLinkProviderOptions.length}
                                            >
                                                <option value="">{paymentLinkProviderOptions.length ? 'Choose provider' : 'No enabled provider available'}</option>
                                                {paymentLinkProviderOptions.map((provider) => (
                                                    <option key={provider.key} value={provider.key}>
                                                        {provider.optionLabel}
                                                    </option>
                                                ))}
                                            </select>
                                            <p className="text-xs text-slate-500">
                                                Use the selected provider when generating a market payment link for this activation.
                                            </p>
                                        </div>
                                    ) : null}

                                    {paymentMethod === 'link' && !canOverridePaymentLinkProvider ? (
                                        <div className="space-y-2">
                                            <p className="mb-1 block text-sm font-medium text-slate-700">Payment Link Provider</p>
                                            <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-700">
                                                {paymentLinkProviderOptions.length
                                                    ? 'Billing policy will use the market active provider for this activation.'
                                                    : 'No enabled payment-link provider is configured for this market yet.'}
                                            </div>
                                            <p className="text-xs text-slate-500">
                                                Operators follow the market billing policy. Admins can override the provider in Billing settings.
                                            </p>
                                        </div>
                                    ) : null}

                                    {paymentMethod === 'free_trial' ? (
                                        <div className="space-y-2">
                                            <label htmlFor="free-trial-pin" className="mb-1 block text-sm font-medium text-slate-700">
                                                Free-trial PIN
                                            </label>
                                            <input
                                                id="free-trial-pin"
                                                type="password"
                                                inputMode="numeric"
                                                maxLength={6}
                                                value={freeTrialPin}
                                                onChange={(event) => setFreeTrialPin(event.target.value.replace(/\D/g, '').slice(0, 6))}
                                                className="crm-input"
                                                placeholder="Enter configured PIN"
                                            />
                                            <p className="text-xs text-slate-500">
                                                Redeem the configured global PIN. Approver names are deprecated for this flow.
                                            </p>
                                        </div>
                                    ) : null}

                                    {(paymentMethod === 'stk' || paymentMethod === 'link') ? (
                                        <div className="rounded-md border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                                            {paymentMethod === 'stk'
                                                ? 'An STK push will be sent to the client phone. Subscription will activate after payment confirmation.'
                                                : paymentLinkProviderOptions.length
                                                    ? canOverridePaymentLinkProvider
                                                        ? 'A CRM-managed payment link will be sent to the client phone using the selected provider, and the subscription will activate after payment confirmation.'
                                                        : 'A CRM-managed payment link will be sent to the client phone using the market active provider, and the subscription will activate after payment confirmation.'
                                                    : 'No enabled payment-link provider is configured for this market yet.'}
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
                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-reason-code">Reason code</label>
                                    <select
                                        id="deactivate-reason-code"
                                        value={deactivationReasonCode}
                                        onChange={(event) => {
                                            const nextReasonCode = event.target.value;
                                            setDeactivationReasonCode(nextReasonCode);
                                            setDeactivationLinkedPaymentAction(defaultLinkedPaymentAction(nextReasonCode));
                                        }}
                                        className="crm-select"
                                    >
                                        {DEAL_DEACTIVATION_REASON_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-linked-payment-action">Linked payment action</label>
                                    <select
                                        id="deactivate-linked-payment-action"
                                        value={deactivationLinkedPaymentAction}
                                        onChange={(event) => setDeactivationLinkedPaymentAction(event.target.value)}
                                        className="crm-select"
                                    >
                                        {LINKED_PAYMENT_ACTION_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>

                                    <label className="block text-sm font-medium text-slate-700" htmlFor="deactivate-reason-notes">Notes</label>
                                    <textarea
                                        id="deactivate-reason-notes"
                                        rows={3}
                                        value={deactivationReasonNotes}
                                        onChange={(event) => setDeactivationReasonNotes(event.target.value)}
                                        className="crm-input"
                                        placeholder="Explain why this subscription is being deactivated."
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
                                        freeTrialPinValue: freeTrialPin.trim(),
                                        paymentLinkProviderValue: paymentLinkProvider || undefined,
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
                                        freeTrialPinValue: freeTrialPin.trim(),
                                        paymentLinkProviderValue: paymentLinkProvider || undefined,
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
                                        freeTrialPinValue: freeTrialPin.trim(),
                                        paymentLinkProviderValue: paymentLinkProvider || undefined,
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
                                        reasonCode: deactivationReasonCode,
                                        reasonNotes: deactivationReasonNotes.trim(),
                                        linkedPaymentAction: deactivationLinkedPaymentAction,
                                        shouldNotify: notifyClient,
                                        templateId: notificationTemplateId ? Number(notificationTemplateId) : null,
                                        message: notificationMessage.trim(),
                                    })}
                                    disabled={!deactivationReasonNotes.trim() || deactivateMutation.isPending}
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

            <ConfirmDialog
                open={bulkDeactivateDialog.open}
                title="Bulk Deactivate Subscriptions"
                message={`This will deactivate ${bulkDeactivateDialog.selection.filter((r) => r.status === 'active' || r.status === 'expired').length} eligible subscription(s). WordPress profiles will be set to private.`}
                confirmLabel="Deactivate"
                tone="danger"
                onCancel={() => setBulkDeactivateDialog((d) => ({ ...d, open: false }))}
                onConfirm={() => bulkDeactivateMutation.mutate(bulkDeactivateDialog)}
                confirmDisabled={!bulkDeactivateDialog.reasonNotes.trim() || bulkDeactivateMutation.isPending}
                isPending={bulkDeactivateMutation.isPending}
            >
                <label className="mb-1 block text-sm font-medium text-slate-700">Reason code</label>
                <select
                    value={bulkDeactivateDialog.reasonCode}
                    onChange={(e) => {
                        const nextReasonCode = e.target.value;
                        setBulkDeactivateDialog((d) => ({
                            ...d,
                            reasonCode: nextReasonCode,
                            linkedPaymentAction: defaultLinkedPaymentAction(nextReasonCode),
                        }));
                    }}
                    className="crm-select"
                >
                    {DEAL_DEACTIVATION_REASON_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <label className="mb-1 mt-3 block text-sm font-medium text-slate-700">Linked payment action</label>
                <select
                    value={bulkDeactivateDialog.linkedPaymentAction}
                    onChange={(e) => setBulkDeactivateDialog((d) => ({ ...d, linkedPaymentAction: e.target.value }))}
                    className="crm-select"
                >
                    {LINKED_PAYMENT_ACTION_OPTIONS.map((option) => (
                        <option key={option.value} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <label className="mb-1 mt-3 block text-sm font-medium text-slate-700">Notes</label>
                <textarea
                    rows={2}
                    value={bulkDeactivateDialog.reasonNotes}
                    onChange={(e) => setBulkDeactivateDialog((d) => ({ ...d, reasonNotes: e.target.value }))}
                    className="crm-input"
                />
                <label className="mt-3 flex items-center gap-2 text-sm text-slate-700">
                    <input
                        type="checkbox"
                        checked={bulkDeactivateDialog.notifyClient}
                        onChange={(e) => setBulkDeactivateDialog((d) => ({ ...d, notifyClient: e.target.checked }))}
                    />
                    Notify clients via SMS
                </label>
            </ConfirmDialog>

            {sharedBundleDialog.open ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/45 p-4" onClick={() => setSharedBundleDialog((current) => ({ ...current, open: false }))}>
                    <div className="w-full max-w-4xl rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(event) => event.stopPropagation()}>
                        <header className="crm-panel-header">
                            <div>
                                <h3 className="crm-panel-title">Record Shared Manual Payment</h3>
                                <p className="crm-panel-subtitle">
                                    Step {sharedBundleDialog.step} of 3 • {sharedBundleDialog.items.length} client{sharedBundleDialog.items.length === 1 ? '' : 's'} added
                                </p>
                            </div>
                        </header>

                        <div className="space-y-4 p-4">
                            {sharedBundleDialog.step === 1 ? (
                                <div className="grid gap-4 md:grid-cols-2">
                                    <div className="space-y-3">
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="shared-bundle-reference-root">Reference root</label>
                                            <input
                                                id="shared-bundle-reference-root"
                                                type="text"
                                                value={sharedBundleDialog.referenceRoot}
                                                onChange={(event) => setSharedBundleDialog((current) => ({ ...current, referenceRoot: event.target.value, preview: null }))}
                                                className="crm-input"
                                                placeholder="e.g. UABMDKDB"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="shared-bundle-total-amount">Paid total</label>
                                            <input
                                                id="shared-bundle-total-amount"
                                                type="number"
                                                min="0"
                                                step="0.01"
                                                value={sharedBundleDialog.totalAmount}
                                                onChange={(event) => setSharedBundleDialog((current) => ({ ...current, totalAmount: event.target.value, preview: null }))}
                                                className="crm-input"
                                            />
                                        </div>
                                        <div>
                                            <label className="mb-1 block text-sm font-medium text-slate-700" htmlFor="shared-bundle-reason">Reason</label>
                                            <textarea
                                                id="shared-bundle-reason"
                                                rows={3}
                                                value={sharedBundleDialog.reason}
                                                onChange={(event) => setSharedBundleDialog((current) => ({ ...current, reason: event.target.value }))}
                                                className="crm-input"
                                            />
                                        </div>
                                    </div>

                                    <div className="flex flex-col gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4">
                                        <div>
                                            <p className="text-sm font-semibold text-slate-800">
                                                Add clients
                                                {sharedBundleDialog.items.length > 0 ? (
                                                    <span className="ml-1.5 inline-flex items-center rounded-full bg-teal-100 px-2 py-0.5 text-[11px] font-semibold text-teal-800">
                                                        {sharedBundleDialog.items.length}
                                                    </span>
                                                ) : null}
                                            </p>
                                            <p className="mt-0.5 text-[11px] text-slate-500">Search by name or phone. You'll assign a plan to each client in step 2.</p>
                                        </div>

                                        {/* Client search input */}
                                        <div className="relative">
                                            <input
                                                type="text"
                                                value={sharedBundleDialog.clientSearchInput}
                                                onChange={(event) => {
                                                    const value = event.target.value;
                                                    setSharedBundleDialog((current) => ({
                                                        ...current,
                                                        clientSearchInput: value,
                                                        clientSearch: value.trim().length >= 2 ? value.trim() : '',
                                                    }));
                                                }}
                                                placeholder="Search client name or phone..."
                                                className="crm-input pr-8 text-sm"
                                            />
                                            {bundleClientSearchLoading ? (
                                                <span className="absolute right-2.5 top-1/2 -translate-y-1/2 text-slate-400">
                                                    <svg className="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                                        <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                                        <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z" />
                                                    </svg>
                                                </span>
                                            ) : (
                                                <svg className="absolute right-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                                </svg>
                                            )}
                                        </div>

                                        {/* Client search results */}
                                        {sharedBundleDialog.clientSearch.length >= 2 && bundleClientSearchResults?.data?.length > 0 ? (
                                            <div className="max-h-48 overflow-y-auto rounded-md border border-slate-200 bg-white shadow-sm">
                                                {bundleClientSearchResults.data
                                                    .filter((client) => !sharedBundleDialog.items.some((item) => Number(item.client_id) === Number(client.id)))
                                                    .map((client) => (
                                                        <button
                                                            key={client.id}
                                                            type="button"
                                                            onClick={() => {
                                                                const newItem = {
                                                                    client_id: client.id,
                                                                    client_name: client.name || 'Unknown',
                                                                    client_phone: client.phone_normalized || '',
                                                                    product_id: null,
                                                                    product_name: '',
                                                                    product_price_id: null,
                                                                    duration: 'monthly',
                                                                    base_price: 0,
                                                                    allocated_amount: '',
                                                                };
                                                                setSharedBundleDialog((current) => ({
                                                                    ...current,
                                                                    items: [...current.items, newItem],
                                                                    clientSearchInput: '',
                                                                    clientSearch: '',
                                                                    preview: null,
                                                                }));
                                                            }}
                                                            className="flex w-full items-center justify-between px-3 py-2.5 text-left transition hover:bg-teal-50 focus-visible:bg-teal-50 focus-visible:outline-none"
                                                        >
                                                            <div className="min-w-0">
                                                                <p className="truncate text-sm font-semibold text-slate-900">{client.name}</p>
                                                                <p className="truncate text-xs text-slate-500">{client.phone_normalized}</p>
                                                            </div>
                                                            <svg className="ml-3 h-4 w-4 flex-shrink-0 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 4v16m8-8H4" />
                                                            </svg>
                                                        </button>
                                                    ))}
                                            </div>
                                        ) : sharedBundleDialog.clientSearch.length >= 2 && !bundleClientSearchLoading && bundleClientSearchResults?.data?.length === 0 ? (
                                            <p className="rounded-md border border-slate-100 bg-white px-3 py-2 text-xs text-slate-400">No clients found for that search.</p>
                                        ) : null}

                                        {/* Added clients */}
                                        {sharedBundleDialog.items.length > 0 ? (
                                            <div className="space-y-1.5">
                                                {sharedBundleDialog.items.map((item) => (
                                                    <div key={item.client_id} className="flex items-center justify-between gap-2 rounded-md border border-slate-200 bg-white px-3 py-2">
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm font-semibold text-slate-900">{item.client_name}</p>
                                                            <p className="truncate text-xs text-slate-500">{item.client_phone || 'Plan assigned in step 2'}</p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => setSharedBundleDialog((current) => ({
                                                                ...current,
                                                                items: current.items.filter((i) => i.client_id !== item.client_id),
                                                                preview: null,
                                                            }))}
                                                            className="rounded p-0.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-500 focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-rose-400"
                                                            aria-label={`Remove ${item.client_name}`}
                                                        >
                                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <p className="rounded-md border border-dashed border-slate-200 px-3 py-4 text-center text-xs text-slate-400">
                                                No clients added yet. Search above to add clients.
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ) : null}

                            {sharedBundleDialog.step === 2 ? (
                                <div className="space-y-4">
                                    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                        Assign a plan and amount to each client. The plan dropdown is filtered to this market's active products.
                                    </div>
                                    <div className="space-y-3">
                                        {sharedBundleDialog.items.map((item, index) => {
                                            const products = Array.isArray(bundleProductsData) ? bundleProductsData : [];
                                            const selectedProduct = products.find((p) => Number(p.id) === Number(item.product_id)) || null;
                                            const pricingOptions = selectedProduct?.activePrices?.length
                                                ? selectedProduct.activePrices.map((p) => ({
                                                    value: p.duration_key,
                                                    label: `${SHARED_BUNDLE_DURATION_OPTIONS.find((d) => d.value === p.duration_key)?.label || p.duration_key} — ${formatCurrency(p.price, selectedPlatformCurrency || 'KES')}`,
                                                    price: p.price,
                                                    product_price_id: p.id,
                                                }))
                                                : SHARED_BUNDLE_DURATION_OPTIONS;

                                            return (
                                                <div key={item.client_id} className="rounded-lg border border-slate-200 p-3 space-y-3">
                                                    <div className="flex items-center justify-between">
                                                        <div>
                                                            <p className="text-sm font-semibold text-slate-900">{item.client_name}</p>
                                                            <p className="text-xs text-slate-500">{item.client_phone}</p>
                                                        </div>
                                                        <p className="crm-mono text-xs text-slate-500">
                                                            {sharedBundleDialog.referenceRoot.trim()
                                                                ? `${sharedBundleDialog.referenceRoot.trim().toUpperCase()}-${index + 1}`
                                                                : `REF-${index + 1}`}
                                                        </p>
                                                    </div>
                                                    <div className="grid gap-3 md:grid-cols-3">
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Plan</label>
                                                            <select
                                                                value={item.product_id || ''}
                                                                onChange={(event) => {
                                                                    const pid = Number(event.target.value) || null;
                                                                    const prod = products.find((p) => Number(p.id) === pid) || null;
                                                                    const firstPrice = prod?.activePrices?.[0] || null;
                                                                    const dur = firstPrice?.duration_key || 'monthly';
                                                                    const baseAmt = firstPrice ? Number(firstPrice.price) : resolveBasePrice(prod, dur);
                                                                    setSharedBundleDialog((current) => ({
                                                                        ...current,
                                                                        preview: null,
                                                                        items: current.items.map((entry, i) => i !== index ? entry : {
                                                                            ...entry,
                                                                            product_id: pid,
                                                                            product_name: prod?.display_name || prod?.name || '',
                                                                            duration: dur,
                                                                            product_price_id: firstPrice?.id || null,
                                                                            base_price: baseAmt,
                                                                            allocated_amount: String(baseAmt),
                                                                        }),
                                                                    }));
                                                                }}
                                                                className="crm-input"
                                                            >
                                                                <option value="">Select plan…</option>
                                                                {products.map((p) => (
                                                                    <option key={p.id} value={p.id}>{p.display_name || p.name}</option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Duration</label>
                                                            <select
                                                                value={item.duration || 'monthly'}
                                                                onChange={(event) => {
                                                                    const dur = event.target.value;
                                                                    const priceOpt = pricingOptions.find((p) => p.value === dur);
                                                                    const baseAmt = priceOpt?.price != null ? Number(priceOpt.price) : resolveBasePrice(selectedProduct, dur);
                                                                    setSharedBundleDialog((current) => ({
                                                                        ...current,
                                                                        preview: null,
                                                                        items: current.items.map((entry, i) => i !== index ? entry : {
                                                                            ...entry,
                                                                            duration: dur,
                                                                            product_price_id: priceOpt?.product_price_id || null,
                                                                            base_price: baseAmt,
                                                                            allocated_amount: String(baseAmt),
                                                                        }),
                                                                    }));
                                                                }}
                                                                className="crm-input"
                                                                disabled={!item.product_id}
                                                            >
                                                                {pricingOptions.map((opt) => (
                                                                    <option key={opt.value} value={opt.value}>{opt.label}</option>
                                                                ))}
                                                            </select>
                                                        </div>
                                                        <div>
                                                            <label className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">Allocated</label>
                                                            <input
                                                                type="number"
                                                                min="0"
                                                                step="0.01"
                                                                value={item.allocated_amount}
                                                                onChange={(event) => setSharedBundleDialog((current) => ({
                                                                    ...current,
                                                                    preview: null,
                                                                    items: current.items.map((entry, i) => i !== index
                                                                        ? entry
                                                                        : { ...entry, allocated_amount: event.target.value }),
                                                                }))}
                                                                className="crm-input"
                                                                disabled={!item.product_id}
                                                            />
                                                            {item.base_price > 0 && toBundleNumber(item.allocated_amount) < item.base_price ? (
                                                                <p className="mt-0.5 text-[11px] text-amber-600">
                                                                    {Math.round((1 - toBundleNumber(item.allocated_amount) / item.base_price) * 100)}% discount
                                                                </p>
                                                            ) : null}
                                                        </div>
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            ) : null}

                            {sharedBundleDialog.step === 3 ? (
                                <div className="space-y-4">
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Paid total</p>
                                            <p className="mt-1 text-lg font-semibold text-slate-900">{formatCurrency(sharedBundlePaidTotal, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')}</p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Allocated total</p>
                                            <p className="mt-1 text-lg font-semibold text-slate-900">{formatCurrency(sharedBundleAllocatedTotal, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')}</p>
                                        </div>
                                        <div className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                            <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Reference root</p>
                                            <p className="crm-mono mt-1 text-lg font-semibold text-slate-900">{sharedBundleDialog.preview?.reference_root || sharedBundleDialog.referenceRoot.trim().toUpperCase()}</p>
                                        </div>
                                    </div>

                                    {sharedBundleShortfall > 0 ? (
                                        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
                                            <p className="text-sm font-semibold text-amber-900">Shortfall will be treated as discount.</p>
                                            <p className="mt-1 text-sm text-amber-800">
                                                Allocated total is {formatCurrency(sharedBundleAllocatedTotal, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')}, which is {formatCurrency(sharedBundleShortfall, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')} above the paid total. A single discount PIN is required for this bundle.
                                            </p>
                                            <input
                                                type="password"
                                                inputMode="numeric"
                                                maxLength={6}
                                                value={sharedBundleDialog.discountPin}
                                                onChange={(event) => setSharedBundleDialog((current) => ({ ...current, discountPin: event.target.value.replace(/\D/g, '').slice(0, 6) }))}
                                                className="crm-input mt-3"
                                                placeholder="Discount PIN"
                                            />
                                        </div>
                                    ) : null}

                                    {sharedBundleUnallocated > 0 ? (
                                        <div className="rounded-lg border border-sky-200 bg-sky-50 p-4 text-sm text-sky-800">
                                            This bundle has an unallocated remainder of {formatCurrency(sharedBundleUnallocated, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')}. Finance review will still be required after commit.
                                        </div>
                                    ) : null}

                                    <div className="space-y-2">
                                        {(sharedBundleDialog.preview?.items || []).map((item) => (
                                            <div key={item.client_id} className="flex items-center justify-between rounded-md border border-slate-200 px-3 py-2">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">{item.client_name}</p>
                                                    <p className="text-xs text-slate-500">
                                                        {item.product_name} • {item.duration} • {item.child_reference}
                                                    </p>
                                                </div>
                                                <span className="text-sm font-semibold text-slate-700">
                                                    {formatCurrency(item.allocated_amount, sharedBundleDialog.preview?.currency || selectedPlatformCurrency || 'KES')}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            ) : null}
                        </div>

                        <footer className="flex items-center justify-between gap-3 border-t border-slate-100 p-4">
                            <div className="text-xs text-slate-500">
                                {sharedBundleDialog.step < 3
                                    ? 'Shared manual payment bundles stay out of business KPIs until finance review resolves the child payments.'
                                    : `Idempotency key: ${sharedBundleDialog.idempotencyKey}`}
                            </div>

                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    className="crm-btn-secondary"
                                    onClick={() => setSharedBundleDialog((current) => current.step > 1
                                        ? { ...current, step: current.step - 1 }
                                        : { ...current, open: false })}
                                >
                                    {sharedBundleDialog.step > 1 ? 'Back' : 'Cancel'}
                                </button>

                                {sharedBundleDialog.step === 1 ? (
                                    <button
                                        type="button"
                                        className="crm-btn-primary"
                                        onClick={() => setSharedBundleDialog((current) => ({ ...current, step: 2 }))}
                                        disabled={!sharedBundleDialog.referenceRoot.trim() || sharedBundlePaidTotal <= 0 || !sharedBundleDialog.items.length}
                                    >
                                        Next — assign plans
                                    </button>
                                ) : null}

                                {sharedBundleDialog.step === 2 ? (
                                    <button
                                        type="button"
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        onClick={() => sharedBundlePayload && sharedBundlePreviewMutation.mutate(sharedBundlePayload)}
                                        disabled={!sharedBundlePayload || sharedBundlePreviewMutation.isPending || !sharedBundleDialog.referenceRoot.trim() || sharedBundlePaidTotal <= 0 || !sharedBundleItemsValid}
                                    >
                                        {sharedBundlePreviewMutation.isPending ? 'Reviewing...' : 'Review bundle'}
                                    </button>
                                ) : null}

                                {sharedBundleDialog.step === 3 ? (
                                    <button
                                        type="button"
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-50"
                                        onClick={() => sharedBundlePayload && sharedBundleCommitMutation.mutate({
                                            ...sharedBundlePayload,
                                            idempotency_key: sharedBundleDialog.idempotencyKey,
                                            ...(sharedBundleDialog.discountPin ? { discount_pin: sharedBundleDialog.discountPin } : {}),
                                        })}
                                        disabled={sharedBundleCommitMutation.isPending || !sharedBundleDialog.preview || (sharedBundleShortfall > 0 && sharedBundleDialog.discountPin.trim().length < 4)}
                                    >
                                        {sharedBundleCommitMutation.isPending ? 'Committing...' : 'Commit bundle'}
                                    </button>
                                ) : null}
                            </div>
                        </footer>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
