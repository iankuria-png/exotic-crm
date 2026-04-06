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

export default function Deals() {
    const allowedBuckets = new Set(['all', 'active', 'risk', 'pending', 'workload', 'stable', 'expired', 'lapsed', 'paused', 'untracked', 'mpesa_review', 'mpesa_history']);
    const allowedStatuses = new Set(['pending', 'awaiting_payment', 'paid', 'active', 'expired', 'renewed', 'cancelled', 'untracked']);
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

    const [dialog, setDialog] = useState({ type: null, deal: null });
    const [activateReason, setActivateReason] = useState('Activated from subscriptions page');
    const [reason, setReason] = useState('Deactivated from subscriptions page');
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
        reason: 'Bulk deactivation from subscriptions page',
        notifyClient: false,
    });

    const [mpesaPlanSelections, setMpesaPlanSelections] = useState({});

    const [bucket, setBucket] = useState(() => {
        const requested = (searchParams.get('bucket') || 'all').trim();
        return allowedBuckets.has(requested) ? requested : 'all';
    });

    const isMpesaBucket = bucket === 'mpesa_review';

    const { data, isLoading } = useQuery({
        queryKey: ['deals', page, perPage, search, statusFilter, bucket, platformFilter],
        queryFn: () =>
            api.get('/crm/deals', {
                params: {
                    page,
                    per_page: perPage,
                    ...(search && { search }),
                    ...(statusFilter && { status: statusFilter }),
                    bucket,
                    ...(platformFilter && { platform_id: Number(platformFilter) }),
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
        mutationFn: async ({ selection, reason, notifyClient }) => {
            const targets = selection.filter((row) => row.status === 'active' || row.status === 'expired');
            const skipped = selection.length - targets.length;

            const results = await Promise.allSettled(
                targets.map((row) =>
                    api.post(`/crm/deals/${row.id}/deactivate`, {
                        reason,
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
            setBulkDeactivateDialog((d) => ({ ...d, open: false, selection: [] }));
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
            key: 'bulk-deactivate',
            label: 'Deactivate selected',
            loadingLabel: 'Preparing...',
            variant: 'danger',
            onClick: (rowsSelection) => {
                setBulkDeactivateDialog({
                    open: true,
                    selection: rowsSelection,
                    reason: 'Bulk deactivation from subscriptions page',
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

                    {(search || statusFilter || bucket !== 'all' || platformFilter) ? (
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setSearchInput('');
                                setStatusFilter('');
                                setBucket('all');
                                setPlatformFilter('');
                                setPage(1);
                            }}
                            className="mb-0.5 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 hover:text-slate-700"
                        >
                            Reset all
                        </button>
                    ) : null}
                </div>
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

            <ConfirmDialog
                open={bulkDeactivateDialog.open}
                title="Bulk Deactivate Subscriptions"
                message={`This will deactivate ${bulkDeactivateDialog.selection.filter((r) => r.status === 'active' || r.status === 'expired').length} eligible subscription(s). WordPress profiles will be set to private.`}
                confirmLabel="Deactivate"
                tone="danger"
                onCancel={() => setBulkDeactivateDialog((d) => ({ ...d, open: false }))}
                onConfirm={() => bulkDeactivateMutation.mutate(bulkDeactivateDialog)}
                confirmDisabled={!bulkDeactivateDialog.reason.trim() || bulkDeactivateMutation.isPending}
                isPending={bulkDeactivateMutation.isPending}
            >
                <label className="mb-1 block text-sm font-medium text-slate-700">Reason</label>
                <textarea
                    rows={2}
                    value={bulkDeactivateDialog.reason}
                    onChange={(e) => setBulkDeactivateDialog((d) => ({ ...d, reason: e.target.value }))}
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
        </div>
    );
}
