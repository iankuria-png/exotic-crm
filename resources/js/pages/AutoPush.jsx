import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';
import DataTable from '../components/DataTable';
import { useToast } from '../components/ToastProvider';
import { proxyImageUrl } from '../utils/imageProxy';

const DAY_OPTIONS = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
    { value: 6, label: 'Sat' },
    { value: 7, label: 'Sun' },
];

const BUCKET_TYPE_OPTIONS = [
    { value: 'new_subscriptions', label: 'New subscriptions' },
    { value: 'subscription_tier', label: 'Subscription tier' },
    { value: 'bottom_engagement', label: 'Bottom engagement' },
];

const MESSAGE_MODE_OPTIONS = [
    { value: 'hybrid', label: 'Hybrid' },
    { value: 'ai', label: 'AI' },
    { value: 'seed', label: 'Seed' },
];

const SPILLOVER_OPTIONS = [
    { value: 'next_active_day', label: 'Next active day' },
    { value: 'same_day', label: 'Same day only' },
];

const EMPTY_FORM = {
    id: null,
    name: '',
    platform_id: '',
    enabled: false,
    autopilot: false,
    buckets: [
        { type: 'new_subscriptions', enabled: true, limit: 4, params: {} },
        { type: 'subscription_tier', enabled: true, limit: 2, params: { tiers: ['VIP'] } },
        { type: 'bottom_engagement', enabled: false, limit: 2, params: {} },
    ],
    schedule: {
        active_days: [1, 2, 3, 4, 5],
        window_start: '09:00',
        window_end: '20:00',
        interval_hours: 2,
        max_items_per_day: 6,
        lookahead_days: 2,
        runway_threshold: '',
        count_unapproved_drafts_as_coverage: true,
    },
    message_strategy: {
        mode: 'hybrid',
        seed_phrases: ['Fresh profile just dropped', 'Worth a quick look', 'You might like this one'],
        tone: 'Warm and direct',
        temperament: 'Confident',
        language: 'en',
        max_chars: 120,
    },
    reliability: {
        reserve_multiplier: 1.5,
        max_replacements_per_item: 2,
        exclude_pushed_within_days: 3,
        replacement_spillover: 'next_active_day',
        sms_alerts_enabled: false,
    },
};

function prettyLabel(value) {
    return String(value || 'unknown').replaceAll('_', ' ');
}

function formatDateTime(value) {
    if (!value) return '—';

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

function formatDateTimeLocal(value) {
    if (!value) return '';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return '';
    }

    const year = parsed.getFullYear();
    const month = String(parsed.getMonth() + 1).padStart(2, '0');
    const day = String(parsed.getDate()).padStart(2, '0');
    const hours = String(parsed.getHours()).padStart(2, '0');
    const minutes = String(parsed.getMinutes()).padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

function formatPreviewTime(value) {
    if (!value) return 'Not scheduled';
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return 'Not scheduled';

    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
}

function resolvePreviewImage(item) {
    return proxyImageUrl(item?.profile_image_url || item?.fallback_profile_image_url || '');
}

function platformLabel(platform) {
    return platform?.platform_name || platform?.name || platform?.country || 'Unknown market';
}

function bucketLabel(type) {
    return prettyLabel(type).replace(/\b\w/g, (char) => char.toUpperCase());
}

function deepClone(value) {
    return JSON.parse(JSON.stringify(value));
}

function normalizePlanToForm(plan) {
    if (!plan) {
        return deepClone(EMPTY_FORM);
    }

    return {
        id: plan.id,
        name: plan.name || '',
        platform_id: String(plan.platform_id || ''),
        enabled: Boolean(plan.enabled),
        autopilot: Boolean(plan.autopilot),
        buckets: Array.isArray(plan.buckets) && plan.buckets.length > 0
            ? plan.buckets.map((bucket) => ({
                type: bucket?.type || 'new_subscriptions',
                enabled: bucket?.enabled !== false,
                limit: Number(bucket?.limit || 1),
                params: bucket?.params || {},
            }))
            : deepClone(EMPTY_FORM.buckets),
        schedule: {
            active_days: Array.isArray(plan.schedule?.active_days) ? plan.schedule.active_days.map((day) => Number(day)) : deepClone(EMPTY_FORM.schedule.active_days),
            window_start: plan.schedule?.window_start || '09:00',
            window_end: plan.schedule?.window_end || '20:00',
            interval_hours: Number(plan.schedule?.interval_hours || 2),
            max_items_per_day: Number(plan.schedule?.max_items_per_day || 6),
            lookahead_days: Number(plan.schedule?.lookahead_days || 2),
            runway_threshold: plan.schedule?.runway_threshold ?? '',
            count_unapproved_drafts_as_coverage: plan.schedule?.count_unapproved_drafts_as_coverage !== false,
        },
        message_strategy: {
            mode: plan.message_strategy?.mode || 'hybrid',
            seed_phrases: Array.isArray(plan.message_strategy?.seed_phrases) && plan.message_strategy.seed_phrases.length > 0
                ? plan.message_strategy.seed_phrases
                : deepClone(EMPTY_FORM.message_strategy.seed_phrases),
            tone: plan.message_strategy?.tone || '',
            temperament: plan.message_strategy?.temperament || '',
            language: plan.message_strategy?.language || 'en',
            max_chars: Number(plan.message_strategy?.max_chars || 120),
        },
        reliability: {
            reserve_multiplier: Number(plan.reliability?.reserve_multiplier || 1.5),
            max_replacements_per_item: Number(plan.reliability?.max_replacements_per_item || 2),
            exclude_pushed_within_days: Number(plan.reliability?.exclude_pushed_within_days || 3),
            replacement_spillover: plan.reliability?.replacement_spillover || 'next_active_day',
            sms_alerts_enabled: Boolean(plan.reliability?.sms_alerts_enabled),
        },
    };
}

function planToPayload(form) {
    return {
        name: String(form.name || '').trim(),
        platform_id: Number(form.platform_id),
        enabled: Boolean(form.enabled),
        autopilot: Boolean(form.autopilot),
        buckets: form.buckets.map((bucket) => ({
            type: bucket.type,
            enabled: Boolean(bucket.enabled),
            limit: Number(bucket.limit || 1),
            params: bucket.params || {},
        })),
        schedule: {
            active_days: form.schedule.active_days.map((day) => Number(day)),
            window_start: form.schedule.window_start,
            window_end: form.schedule.window_end,
            interval_hours: Number(form.schedule.interval_hours || 1),
            max_items_per_day: Number(form.schedule.max_items_per_day || 1),
            lookahead_days: Number(form.schedule.lookahead_days || 1),
            runway_threshold: form.schedule.runway_threshold === '' ? null : Number(form.schedule.runway_threshold),
            count_unapproved_drafts_as_coverage: Boolean(form.schedule.count_unapproved_drafts_as_coverage),
        },
        message_strategy: {
            mode: form.message_strategy.mode,
            seed_phrases: form.message_strategy.seed_phrases.map((phrase) => String(phrase).trim()).filter(Boolean),
            tone: String(form.message_strategy.tone || '').trim(),
            temperament: String(form.message_strategy.temperament || '').trim(),
            language: String(form.message_strategy.language || 'en').trim(),
            max_chars: Number(form.message_strategy.max_chars || 120),
        },
        reliability: {
            reserve_multiplier: Number(form.reliability.reserve_multiplier || 1),
            max_replacements_per_item: Number(form.reliability.max_replacements_per_item || 0),
            exclude_pushed_within_days: Number(form.reliability.exclude_pushed_within_days || 0),
            replacement_spillover: form.reliability.replacement_spillover,
            sms_alerts_enabled: Boolean(form.reliability.sms_alerts_enabled),
        },
    };
}

function bucketDescription(bucket) {
    const type = bucket?.type;
    const params = bucket?.params || {};

    if (type === 'subscription_tier') {
        const tiers = Array.isArray(params.tiers) ? params.tiers.filter(Boolean) : [];
        return tiers.length > 0 ? `Tiers: ${tiers.join(', ')}` : 'Tier list not set';
    }

    if (type === 'new_subscriptions') {
        const days = params.within_days ? `within ${params.within_days} day${Number(params.within_days) === 1 ? '' : 's'}` : 'latest cohort';
        return `Targets ${days}`;
    }

    if (type === 'bottom_engagement') {
        const segment = params.segment ? `Segment: ${params.segment}` : 'Low engagement clients';
        return segment;
    }

    return 'Configured bucket';
}

function renderBucketParams(bucket, onChange) {
    if (bucket.type === 'subscription_tier') {
        return (
            <label className="space-y-1">
                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Tiers</span>
                <input
                    className="crm-input"
                    value={Array.isArray(bucket.params?.tiers) ? bucket.params.tiers.join(', ') : ''}
                    onChange={(event) => {
                        const tiers = event.target.value.split(',').map((value) => value.trim()).filter(Boolean);
                        onChange({ ...bucket, params: { ...(bucket.params || {}), tiers } });
                    }}
                    placeholder="VIP, Premium"
                />
            </label>
        );
    }

    if (bucket.type === 'new_subscriptions') {
        return (
            <label className="space-y-1">
                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Within Days</span>
                <input
                    type="number"
                    min="1"
                    max="30"
                    className="crm-input"
                    value={bucket.params?.within_days ?? 7}
                    onChange={(event) => onChange({
                        ...bucket,
                        params: { ...(bucket.params || {}), within_days: Number(event.target.value || 7) },
                    })}
                />
            </label>
        );
    }

    if (bucket.type === 'bottom_engagement') {
        return (
            <label className="space-y-1">
                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Segment</span>
                <input
                    className="crm-input"
                    value={bucket.params?.segment || ''}
                    onChange={(event) => onChange({
                        ...bucket,
                        params: { ...(bucket.params || {}), segment: event.target.value },
                    })}
                    placeholder="Dormant"
                />
            </label>
        );
    }

    return null;
}

function SelectField({ label, value, onChange, children, className = '', tone = 'default' }) {
    return (
        <label className={`space-y-1 ${className}`}>
            {label ? <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">{label}</span> : null}
            <div className="relative">
                <select
                    className={`crm-select-enhanced w-full pr-10 ${tone === 'soft' ? 'border-slate-200 bg-slate-50/70' : ''}`}
                    value={value}
                    onChange={onChange}
                >
                    {children}
                </select>
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-slate-400">
                    <svg className="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" strokeWidth="1.75" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" d="m5 7 5 5 5-5" />
                    </svg>
                </span>
            </div>
        </label>
    );
}

function PreviewNotification({ item, device = 'mobile' }) {
    const imageUrl = resolvePreviewImage(item);
    const title = item?.city ? `${item.name} in ${item.city}` : (item?.name || 'Profile preview');
    const sourceLabel = prettyLabel(item?.message_source || 'seed');

    return (
        <div className={`mx-auto ${device === 'mobile' ? 'w-[320px]' : 'w-full max-w-[560px]'}`}>
            <div className={`rounded-[30px] border border-slate-200 bg-slate-950 p-3 shadow-[0_32px_80px_-38px_rgba(15,23,42,0.75)] ${device === 'mobile' ? '' : 'rounded-[24px]'}`}>
                <div className="rounded-[24px] bg-slate-100 p-3">
                    <div className="mb-3 flex items-center justify-between px-1 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">
                        <div className="flex items-center gap-2">
                            <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-teal-600 text-[10px] font-bold text-white">EO</span>
                            <span>Exotic Push</span>
                        </div>
                        <span>{formatPreviewTime(item?.scheduled_at_market || item?.scheduled_at)}</span>
                    </div>

                    <div className="overflow-hidden rounded-[20px] border border-slate-200 bg-white shadow-sm">
                        <div className="relative aspect-[4/3] bg-slate-200">
                            {imageUrl ? (
                                <img src={imageUrl} alt={item?.name || 'Preview profile'} className="h-full w-full object-cover" />
                            ) : (
                                <div className="flex h-full items-center justify-center bg-gradient-to-br from-slate-200 via-slate-100 to-white text-3xl font-semibold text-slate-500">
                                    {(item?.name || 'E').charAt(0).toUpperCase()}
                                </div>
                            )}
                            <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-slate-950/75 via-slate-950/25 to-transparent px-4 pb-4 pt-10">
                                <div className="flex items-end justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="truncate text-lg font-semibold text-white">{item?.name || 'Preview profile'}</p>
                                        <p className="truncate text-sm text-slate-200">{item?.city || 'Unknown location'}</p>
                                    </div>
                                    <span className="rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-white backdrop-blur">
                                        {sourceLabel}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-3 p-4">
                            <p className="text-base font-semibold leading-6 text-slate-900">{title}</p>
                            <p className="rounded-2xl bg-slate-50 px-3.5 py-3 text-[15px] leading-6 text-slate-700">
                                {item?.message || 'Preview a generated message here.'}
                            </p>
                            <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <span className="rounded-full bg-slate-100 px-2.5 py-1">{formatPreviewTime(item?.scheduled_at_market || item?.scheduled_at)}</span>
                                <span className="rounded-full bg-slate-100 px-2.5 py-1">{item?.city || 'Location pending'}</span>
                                {item?.profile_url ? (
                                    <a
                                        href={item.profile_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-full bg-teal-50 px-2.5 py-1 font-medium text-teal-700 transition hover:bg-teal-100"
                                    >
                                        Open profile
                                    </a>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function AutoPush() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedPlanId, setSelectedPlanId] = useState(null);
    const [isCreatingNew, setIsCreatingNew] = useState(false);
    const [form, setForm] = useState(() => deepClone(EMPTY_FORM));
    const [clonePlatformIds, setClonePlatformIds] = useState([]);
    const [preview, setPreview] = useState(null);
    const [previewItems, setPreviewItems] = useState([]);
    const [activePreviewId, setActivePreviewId] = useState(null);
    const [previewDevice, setPreviewDevice] = useState('mobile');
    const [previewExpanded, setPreviewExpanded] = useState(false);
    const [previewDirty, setPreviewDirty] = useState(false);

    const integrationsQuery = useQuery({
        queryKey: ['settings-integrations', 'auto-push'],
        queryFn: () => api.get('/crm/settings/integrations').then((response) => response.data),
    });

    const plansQuery = useQuery({
        queryKey: ['auto-push-plans'],
        queryFn: () => api.get('/crm/auto-push/plans').then((response) => response.data),
    });

    const plans = plansQuery.data?.data || [];
    const selectedPlan = useMemo(
        () => plans.find((plan) => Number(plan.id) === Number(selectedPlanId)) || null,
        [plans, selectedPlanId],
    );

    useEffect(() => {
        if (plans.length === 0) {
            if (!isCreatingNew) {
                setSelectedPlanId(null);
                setForm(deepClone(EMPTY_FORM));
            }
            return;
        }

        if (isCreatingNew) {
            return;
        }

        if (selectedPlanId && plans.some((plan) => Number(plan.id) === Number(selectedPlanId))) {
            return;
        }

        setSelectedPlanId(plans[0].id);
    }, [isCreatingNew, plans, selectedPlanId]);

    useEffect(() => {
        if (!selectedPlan) {
            return;
        }

        setIsCreatingNew(false);
        setForm(normalizePlanToForm(selectedPlan));
        setPreview(null);
        setPreviewItems([]);
        setActivePreviewId(null);
        setPreviewExpanded(false);
        setPreviewDirty(false);
        setClonePlatformIds([]);
    }, [selectedPlan]);

    const draftPackageQuery = useQuery({
        queryKey: ['auto-push-draft-package', selectedPlanId],
        enabled: Boolean(selectedPlanId) && !isCreatingNew && !previewDirty,
        staleTime: 60_000,
        refetchOnWindowFocus: false,
        queryFn: () => api.get(`/crm/auto-push/plans/${selectedPlanId}/draft-package`).then((response) => response.data),
    });

    useEffect(() => {
        if (!draftPackageQuery.data || isCreatingNew) {
            return;
        }

        const items = Array.isArray(draftPackageQuery.data?.items)
            ? draftPackageQuery.data.items.map((item) => ({
                ...item,
                preview_id: item.preview_id || `slot-${Number(item.slot_index || 0) + 1}`,
                scheduled_at_input: formatDateTimeLocal(item.scheduled_at_market || item.scheduled_at),
                original_city: item.city || '',
                original_message: item.message || '',
                original_scheduled_at_input: formatDateTimeLocal(item.scheduled_at_market || item.scheduled_at),
                original_profile_image_url: item.profile_image_url || '',
            }))
            : [];

        setPreview(draftPackageQuery.data);
        setPreviewItems(items);
        setActivePreviewId(draftPackageQuery.data?.ui?.active_preview_id || items[0]?.preview_id || null);
        setPreviewDevice(draftPackageQuery.data?.ui?.preview_device || 'mobile');
        setPreviewExpanded(false);
        setPreviewDirty(false);
    }, [draftPackageQuery.data, isCreatingNew]);

    const runsQuery = useQuery({
        queryKey: ['auto-push-runs', selectedPlanId],
        enabled: Boolean(selectedPlanId),
        queryFn: () => api.get('/crm/auto-push/runs', {
            params: { plan_id: selectedPlanId },
        }).then((response) => response.data),
    });

    const alertsQuery = useQuery({
        queryKey: ['auto-push-alerts', selectedPlanId],
        enabled: Boolean(selectedPlanId),
        queryFn: () => api.get('/crm/auto-push/alerts', {
            params: { plan_id: selectedPlanId },
        }).then((response) => response.data),
    });

    const invalidateAll = () => {
        queryClient.invalidateQueries({ queryKey: ['auto-push-plans'] });
        queryClient.invalidateQueries({ queryKey: ['auto-push-runs'] });
        queryClient.invalidateQueries({ queryKey: ['auto-push-alerts'] });
        queryClient.invalidateQueries({ queryKey: ['auto-push-draft-package'] });
        queryClient.invalidateQueries({ queryKey: ['push-campaigns-list'] });
        queryClient.invalidateQueries({ queryKey: ['push-campaigns-dashboard'] });
    };

    const savePlanMutation = useMutation({
        mutationFn: (payload) => {
            if (form.id) {
                return api.patch(`/crm/auto-push/plans/${form.id}`, payload).then((response) => response.data);
            }

            return api.post('/crm/auto-push/plans', payload).then((response) => response.data);
        },
        onSuccess: (response) => {
            const nextPlan = response.plan;
            toast.success(form.id ? 'Auto Push plan updated.' : 'Auto Push plan created.');
            setIsCreatingNew(false);
            invalidateAll();
            setSelectedPlanId(nextPlan?.id || null);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save plan.');
        },
    });

    const previewMutation = useMutation({
        mutationFn: (payload) => {
            if (!form.id) {
                return api.post('/crm/auto-push/plans', payload).then((response) => {
                    const planId = response.data?.plan?.id;
                    return api.post(`/crm/auto-push/plans/${planId}/preview`).then((previewResponse) => ({
                        createdPlan: response.data?.plan,
                        preview: previewResponse.data,
                    }));
                });
            }

            return api.post(`/crm/auto-push/plans/${form.id}/preview`, payload).then((response) => ({
                createdPlan: null,
                preview: response.data,
            }));
        },
        onSuccess: ({ createdPlan, preview: previewPayload }) => {
            if (createdPlan?.id) {
                setSelectedPlanId(createdPlan.id);
                invalidateAll();
                queryClient.setQueryData(['auto-push-draft-package', createdPlan.id], previewPayload);
            } else if (selectedPlanId) {
                queryClient.setQueryData(['auto-push-draft-package', selectedPlanId], previewPayload);
            }
            const items = Array.isArray(previewPayload?.items)
                ? previewPayload.items.map((item, index) => ({
                    ...item,
                    preview_id: item.preview_id || `slot-${index + 1}`,
                    scheduled_at_input: formatDateTimeLocal(item.scheduled_at_market || item.scheduled_at),
                    original_city: item.city || '',
                    original_message: item.message || '',
                    original_scheduled_at_input: formatDateTimeLocal(item.scheduled_at_market || item.scheduled_at),
                    original_profile_image_url: item.profile_image_url || '',
                }))
                : [];

            setPreview(previewPayload);
            setPreviewItems(items);
            setActivePreviewId(previewPayload?.ui?.active_preview_id || items[0]?.preview_id || null);
            setPreviewDevice(previewPayload?.ui?.preview_device || 'mobile');
            setPreviewExpanded(false);
            setPreviewDirty(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Preview failed.');
        },
    });

    const saveDraftPackageMutation = useMutation({
        mutationFn: (payload) => api.put(`/crm/auto-push/plans/${form.id}/draft-package`, payload).then((response) => response.data),
        onSuccess: (draftPackage) => {
            queryClient.setQueryData(['auto-push-draft-package', selectedPlanId], draftPackage);
            toast.success('Preview draft saved.');
            setPreviewDirty(false);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to save preview draft.');
        },
    });

    const shuffleDraftPackageMutation = useMutation({
        mutationFn: () => api.post(`/crm/auto-push/plans/${form.id}/draft-package/shuffle`).then((response) => response.data),
        onSuccess: (draftPackage) => {
            queryClient.setQueryData(['auto-push-draft-package', selectedPlanId], draftPackage);
            toast.success('Preview queue shuffled.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to shuffle preview queue.');
        },
    });

    const replacePreviewItemMutation = useMutation({
        mutationFn: ({ previewId, clientId }) => api.post(`/crm/auto-push/plans/${form.id}/draft-package/replace`, {
            preview_id: previewId,
            ...(clientId ? { client_id: clientId } : {}),
        }).then((response) => response.data),
        onSuccess: (draftPackage) => {
            queryClient.setQueryData(['auto-push-draft-package', selectedPlanId], draftPackage);
            toast.success('Preview client replaced.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Failed to replace preview client.');
        },
    });

    const toggleMutation = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-push/plans/${planId}/toggle`).then((response) => response.data),
        onSuccess: () => {
            toast.success('Plan status updated.');
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to update plan.'),
    });

    const autopilotMutation = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-push/plans/${planId}/autopilot`).then((response) => response.data),
        onSuccess: () => {
            toast.success('Autopilot updated.');
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to update autopilot.'),
    });

    const runNowMutation = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-push/plans/${planId}/run-now`).then((response) => response.data),
        onSuccess: (response) => {
            toast.success(response?.campaign?.name ? `Run created: ${response.campaign.name}` : 'Run created.');
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to run plan.'),
    });

    const deleteMutation = useMutation({
        mutationFn: (planId) => api.delete(`/crm/auto-push/plans/${planId}`).then((response) => response.data),
        onSuccess: () => {
            toast.success('Plan deleted.');
            setPreview(null);
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to delete plan.'),
    });

    const cloneMutation = useMutation({
        mutationFn: ({ planId, platform_ids }) => api.post(`/crm/auto-push/plans/${planId}/clone`, { platform_ids }).then((response) => response.data),
        onSuccess: (response) => {
            const count = Array.isArray(response?.plans) ? response.plans.length : 0;
            toast.success(`Cloned to ${count} market${count === 1 ? '' : 's'}.`);
            setClonePlatformIds([]);
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to clone plan.'),
    });

    const resolveAlertMutation = useMutation({
        mutationFn: (alertId) => api.post(`/crm/auto-push/alerts/${alertId}/resolve`).then((response) => response.data),
        onSuccess: () => {
            toast.success('Alert resolved.');
            invalidateAll();
        },
        onError: (error) => toast.error(error?.response?.data?.message || 'Failed to resolve alert.'),
    });

    const platformOptions = integrationsQuery.data?.platforms || [];
    const otherPlatforms = platformOptions.filter((platform) => String(platform.platform_id) !== String(form.platform_id));

    const metrics = useMemo(() => {
        const totalPlans = plans.length;
        const enabledPlans = plans.filter((plan) => plan.enabled).length;
        const dueNow = plans.filter((plan) => plan.due_now).length;
        const openAlerts = (alertsQuery.data?.data || []).filter((alert) => !alert.resolved_at).length;

        return { totalPlans, enabledPlans, dueNow, openAlerts };
    }, [alertsQuery.data?.data, plans]);

    const runRows = runsQuery.data?.data || [];
    const alertRows = alertsQuery.data?.data || [];

    const runColumns = useMemo(() => [
        {
            key: 'created',
            label: 'Started',
            render: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'status',
            label: 'Status',
            render: (row) => (
                <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                    {prettyLabel(row.status)}
                </span>
            ),
        },
        {
            key: 'selected',
            label: 'Selected',
            render: (row) => Number(row.items_created || 0).toLocaleString(),
        },
        {
            key: 'reserve',
            label: 'Reserve',
            render: (row) => Number(row.reserve_count || 0).toLocaleString(),
        },
        {
            key: 'replacements',
            label: 'Replacements',
            render: (row) => Number(row.replacements_made || 0).toLocaleString(),
        },
        {
            key: 'campaign',
            label: 'Campaign',
            render: (row) => row.campaign?.name || '—',
        },
    ], []);

    const alertColumns = useMemo(() => [
        {
            key: 'severity',
            label: 'Severity',
            render: (row) => (
                <span className={`rounded-md px-2 py-0.5 text-xs font-medium ${
                    row.severity === 'critical'
                        ? 'bg-rose-100 text-rose-700'
                        : row.severity === 'warning'
                            ? 'bg-amber-100 text-amber-700'
                            : 'bg-slate-100 text-slate-700'
                }`}>
                    {prettyLabel(row.severity)}
                </span>
            ),
        },
        {
            key: 'title',
            label: 'Alert',
            render: (row) => (
                <div className="min-w-[220px]">
                    <p className="font-medium text-slate-800">{row.title}</p>
                    <p className="text-xs text-slate-500">{row.body}</p>
                </div>
            ),
        },
        {
            key: 'campaign',
            label: 'Campaign',
            render: (row) => row.campaign?.name || '—',
        },
        {
            key: 'raised',
            label: 'Raised',
            render: (row) => formatDateTime(row.created_at),
        },
        {
            key: 'action',
            label: 'Action',
            render: (row) => row.resolved_at ? (
                <span className="text-xs text-emerald-700">Resolved</span>
            ) : (
                <button
                    type="button"
                    onClick={() => resolveAlertMutation.mutate(row.id)}
                    disabled={resolveAlertMutation.isPending}
                    className="crm-btn-secondary px-2 py-1 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                >
                    Resolve
                </button>
            ),
        },
    ], [resolveAlertMutation]);

    const selectedPlanCoverage = selectedPlan?.coverage_count || 0;
    const selectedPlanRunway = selectedPlan?.runway_threshold || 0;
    const selectedPlanMarket = selectedPlan?.platform?.name || selectedPlan?.platform?.country || '—';
    const currentPlatformOption = platformOptions.find((platform) => String(platform.platform_id) === String(form.platform_id)) || null;
    const selectedPreviewItem = previewItems.find((item) => item.preview_id === activePreviewId) || previewItems[0] || null;
    const visiblePreviewItems = previewExpanded ? previewItems : previewItems.slice(0, 5);
    const hiddenPreviewCount = Math.max(0, previewItems.length - visiblePreviewItems.length);

    const handleSave = () => {
        savePlanMutation.mutate(planToPayload(form));
    };

    const handleSavePreviewDraft = () => {
        if (!form.id || previewItems.length === 0) {
            return;
        }

        saveDraftPackageMutation.mutate({
            items: previewItems.map((item) => ({
                preview_id: item.preview_id,
                slot_index: item.slot_index,
                client_id: item.client_id,
                name: item.name,
                city: item.city,
                profile_url: item.profile_url,
                profile_image_url: item.profile_image_url,
                fallback_profile_image_url: item.fallback_profile_image_url,
                message: item.message,
                message_source: item.message_source,
                scheduled_at: item.scheduled_at,
                scheduled_at_market: item.scheduled_at_input
                    ? new Date(item.scheduled_at_input).toISOString()
                    : item.scheduled_at_market,
            })),
            ui: {
                active_preview_id: activePreviewId,
                preview_device: previewDevice,
            },
        });
    };

    const handlePreview = () => {
        if (!form.id) {
            toast.warning('Save the plan first so preview uses a real market-scoped config.');
            return;
        }

        previewMutation.mutate(planToPayload(form));
    };

    const handleNewPlan = () => {
        setIsCreatingNew(true);
        setSelectedPlanId(null);
        setPreview(null);
        setPreviewItems([]);
        setActivePreviewId(null);
        setPreviewExpanded(false);
        setPreviewDirty(false);
        setClonePlatformIds([]);
        setForm({
            ...deepClone(EMPTY_FORM),
            platform_id: platformOptions[0] ? String(platformOptions[0].platform_id) : '',
        });
    };

    const updatePreviewItem = (previewId, updater) => {
        setPreviewItems((current) => current.map((item) => {
            if (item.preview_id !== previewId) {
                return item;
            }

            return typeof updater === 'function' ? updater(item) : { ...item, ...updater };
        }));
        setPreviewDirty(true);
    };

    return (
        <div className="space-y-4">
            <PageHeader
                title="Auto Push"
                subtitle="Shape audience, timing, and copy in one working surface before the next market run goes live."
                actions={(
                    <>
                        <button type="button" onClick={handleNewPlan} className="crm-btn-secondary">
                            New plan
                        </button>
                        <button
                            type="button"
                            onClick={handlePreview}
                            disabled={previewMutation.isPending}
                            className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {previewMutation.isPending ? 'Refreshing preview...' : 'Refresh preview'}
                        </button>
                        <button
                            type="button"
                            onClick={handleSave}
                            disabled={savePlanMutation.isPending}
                            className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {savePlanMutation.isPending ? 'Saving...' : form.id ? 'Save changes' : 'Create plan'}
                        </button>
                    </>
                )}
            />

            <section className="grid gap-4 md:grid-cols-4">
                <MetricCard label="Plans" value={metrics.totalPlans.toLocaleString()} meta="market playbooks" tone="accent" />
                <MetricCard label="Enabled" value={metrics.enabledPlans.toLocaleString()} meta="live in scheduler" tone="success" />
                <MetricCard label="Due Now" value={metrics.dueNow.toLocaleString()} meta="runway under threshold" tone="warning" />
                <MetricCard label="Open Alerts" value={metrics.openAlerts.toLocaleString()} meta="operational follow-up" tone="danger" />
            </section>

            <div className="grid gap-5 xl:grid-cols-[300px_minmax(0,1fr)]">
                <aside className="space-y-4 xl:sticky xl:top-6 self-start">
                    <section className="crm-surface overflow-hidden">
                        <div className="border-b border-slate-200 px-4 py-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">Plans</h3>
                                    <p className="mt-1 text-sm text-slate-500">Select the market workspace you want to tune.</p>
                                </div>
                                <button type="button" onClick={handleNewPlan} className="crm-btn-secondary px-3 py-1.5 text-xs">
                                    Create
                                </button>
                            </div>
                        </div>
                        <div className="max-h-[68vh] space-y-3 overflow-y-auto p-4">
                            {plansQuery.isLoading ? (
                                <div className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">Loading plans...</div>
                            ) : plans.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">No plans yet. Start with a market plan, then shape the audience and preview before you schedule.</div>
                            ) : plans.map((plan) => {
                                const active = Number(plan.id) === Number(selectedPlanId);
                                return (
                                    <button
                                        key={plan.id}
                                        type="button"
                                        onClick={() => setSelectedPlanId(plan.id)}
                                        className={`w-full rounded-2xl border px-4 py-4 text-left transition ${
                                            active
                                                ? 'border-teal-300 bg-teal-50/80 shadow-sm'
                                                : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                        }`}
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div className="min-w-0">
                                                <p className="truncate font-semibold text-slate-900">{plan.name}</p>
                                                <p className="mt-1 truncate text-sm text-slate-500">{platformLabel(plan.platform)}</p>
                                            </div>
                                            <span className={`rounded-full px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] ${
                                                plan.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600'
                                            }`}>
                                                {plan.enabled ? 'Live' : 'Paused'}
                                            </span>
                                        </div>
                                        <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                                            <div className="rounded-xl bg-white/90 px-3 py-2">
                                                <p className="font-semibold text-slate-900">{Number(plan.coverage_count || 0).toLocaleString()}</p>
                                                <p>coverage</p>
                                            </div>
                                            <div className="rounded-xl bg-white/90 px-3 py-2">
                                                <p className="font-semibold text-slate-900">{plan.due_now ? 'Due' : 'Stable'}</p>
                                                <p>runway</p>
                                            </div>
                                        </div>
                                        <div className="mt-3 flex flex-wrap gap-2 text-[11px] text-slate-500">
                                            <span className="rounded-full bg-slate-100 px-2 py-1">{plan.autopilot ? 'autopilot' : 'approval'}</span>
                                            <span className="rounded-full bg-slate-100 px-2 py-1">last run {formatDateTime(plan.last_run_at)}</span>
                                        </div>
                                    </button>
                                );
                            })}
                        </div>
                    </section>

                    <section className="crm-surface p-4">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Current plan</p>
                        <p className="mt-2 text-lg font-semibold text-slate-900">{form.name || 'Untitled plan'}</p>
                        <p className="mt-1 text-sm text-slate-500">{platformLabel(currentPlatformOption)}</p>
                        <div className="mt-4 grid grid-cols-2 gap-2">
                            <div className="rounded-xl bg-slate-50 px-3 py-3">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Mode</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{form.autopilot ? 'Autopilot' : 'Approval'}</p>
                            </div>
                            <div className="rounded-xl bg-slate-50 px-3 py-3">
                                <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Coverage</p>
                                <p className="mt-1 text-sm font-semibold text-slate-900">{selectedPlanCoverage.toLocaleString()}</p>
                            </div>
                        </div>
                    </section>
                </aside>

                <div className="space-y-5">
                    <section className="crm-surface sticky top-0 z-10 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-white/90">
                        <div className="flex flex-wrap gap-2">
                            {[
                                ['overview', 'Overview'],
                                ['audience', 'Audience'],
                                ['schedule', 'Schedule'],
                                ['preview', 'Preview'],
                                ['operations', 'Operations'],
                            ].map(([id, label]) => (
                                <button
                                    key={id}
                                    type="button"
                                    onClick={() => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                                    className="rounded-full border border-slate-200 bg-white px-3 py-1.5 text-sm font-medium text-slate-600 transition hover:border-teal-300 hover:bg-teal-50 hover:text-teal-700"
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </section>

                    <section id="overview" className="crm-surface overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4">
                            <div className="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                                <div>
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Plan overview</p>
                                    <h2 className="mt-2 text-2xl font-semibold tracking-tight text-slate-900">{form.name || 'Draft auto-push plan'}</h2>
                                    <p className="mt-1 text-sm text-slate-500">{platformLabel(currentPlatformOption)} • {form.enabled ? 'Scheduler active' : 'Scheduler paused'} • {form.autopilot ? 'Autopilot enabled' : 'Awaiting approval before send'}</p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {form.id ? (
                                        <>
                                            <button
                                                type="button"
                                                onClick={() => toggleMutation.mutate(form.id)}
                                                disabled={toggleMutation.isPending}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {form.enabled ? 'Pause plan' : 'Enable plan'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => autopilotMutation.mutate(form.id)}
                                                disabled={autopilotMutation.isPending}
                                                className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {form.autopilot ? 'Switch to approval' : 'Switch to autopilot'}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => runNowMutation.mutate(form.id)}
                                                disabled={runNowMutation.isPending}
                                                className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                {runNowMutation.isPending ? 'Running...' : 'Run now'}
                                            </button>
                                        </>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                        <div className="grid gap-4 px-5 py-5 md:grid-cols-3">
                            <MetricCard label="Market" value={selectedPlanMarket} meta="selected workspace" tone="default" />
                            <MetricCard label="Coverage" value={selectedPlanCoverage.toLocaleString()} meta={`threshold ${Number(selectedPlanRunway || 0).toLocaleString()}`} tone={selectedPlanCoverage >= selectedPlanRunway ? 'success' : 'warning'} />
                            <MetricCard label="Preview cards" value={previewItems.length.toLocaleString()} meta="editable mock sends" tone="accent" />
                        </div>
                    </section>

                    <section id="audience" className="crm-surface overflow-hidden">
                        <div className="border-b border-slate-200 px-5 py-4">
                            <h3 className="text-lg font-semibold text-slate-900">Audience + Rules</h3>
                            <p className="mt-1 text-sm text-slate-500">Set the market, enablement mode, and the bucket order that determines who gets pulled into each run.</p>
                        </div>
                        <div className="space-y-6 px-5 py-5">
                            <div className="grid gap-4 md:grid-cols-[minmax(0,1.2fr)_280px]">
                                <label className="space-y-1">
                                    <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Plan name</span>
                                    <input
                                        className="crm-input"
                                        value={form.name}
                                        onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                                        placeholder="Kenya daily runway"
                                    />
                                </label>
                                <SelectField
                                    label="Market"
                                    value={form.platform_id}
                                    onChange={(event) => setForm((current) => ({ ...current, platform_id: event.target.value }))}
                                >
                                    <option value="">Select market</option>
                                    {platformOptions.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {platformLabel(platform)}
                                        </option>
                                    ))}
                                </SelectField>
                            </div>

                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">
                                    <input
                                        type="checkbox"
                                        checked={form.enabled}
                                        onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-800">Enabled</span>
                                        <span className="block text-xs text-slate-500">Allow the scheduler to watch this plan.</span>
                                    </span>
                                </label>
                                <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">
                                    <input
                                        type="checkbox"
                                        checked={form.autopilot}
                                        onChange={(event) => setForm((current) => ({ ...current, autopilot: event.target.checked }))}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-800">Autopilot</span>
                                        <span className="block text-xs text-slate-500">Schedule generated campaigns immediately.</span>
                                    </span>
                                </label>
                                <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">
                                    <input
                                        type="checkbox"
                                        checked={form.schedule.count_unapproved_drafts_as_coverage}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            schedule: {
                                                ...current.schedule,
                                                count_unapproved_drafts_as_coverage: event.target.checked,
                                            },
                                        }))}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-800">Drafts count as coverage</span>
                                        <span className="block text-xs text-slate-500">Avoid duplicate runway while approval is pending.</span>
                                    </span>
                                </label>
                            </div>

                            <div className="space-y-4">
                                <div className="flex items-center justify-between gap-3">
                                    <div>
                                        <h4 className="text-base font-semibold text-slate-900">Selection buckets</h4>
                                        <p className="mt-1 text-sm text-slate-500">Priority order matters. The first matching bucket wins the client slot.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => setForm((current) => ({
                                            ...current,
                                            buckets: [...current.buckets, { type: 'new_subscriptions', enabled: true, limit: 1, params: {} }],
                                        }))}
                                        className="crm-btn-secondary px-3 py-1.5 text-xs"
                                    >
                                        Add bucket
                                    </button>
                                </div>

                                <div className="space-y-4">
                                    {form.buckets.map((bucket, index) => (
                                        <article key={`${bucket.type}-${index}`} className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                                            <div className="grid gap-3 xl:grid-cols-[minmax(0,220px)_120px_140px_auto]">
                                                <SelectField
                                                    label="Type"
                                                    value={bucket.type}
                                                    onChange={(event) => {
                                                        const nextBuckets = [...form.buckets];
                                                        nextBuckets[index] = { ...bucket, type: event.target.value, params: {} };
                                                        setForm((current) => ({ ...current, buckets: nextBuckets }));
                                                    }}
                                                >
                                                    {BUCKET_TYPE_OPTIONS.map((option) => (
                                                        <option key={option.value} value={option.value}>{option.label}</option>
                                                    ))}
                                                </SelectField>
                                                <label className="space-y-1">
                                                    <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Limit</span>
                                                    <input
                                                        type="number"
                                                        min="1"
                                                        max="500"
                                                        className="crm-input"
                                                        value={bucket.limit}
                                                        onChange={(event) => {
                                                            const nextBuckets = [...form.buckets];
                                                            nextBuckets[index] = { ...bucket, limit: Number(event.target.value || 1) };
                                                            setForm((current) => ({ ...current, buckets: nextBuckets }));
                                                        }}
                                                    />
                                                </label>
                                                <label className="flex items-end gap-2 rounded-2xl border border-slate-200 bg-slate-50/70 px-3 py-2.5">
                                                    <input
                                                        type="checkbox"
                                                        checked={bucket.enabled}
                                                        onChange={(event) => {
                                                            const nextBuckets = [...form.buckets];
                                                            nextBuckets[index] = { ...bucket, enabled: event.target.checked };
                                                            setForm((current) => ({ ...current, buckets: nextBuckets }));
                                                        }}
                                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                    />
                                                    <span className="text-sm font-medium text-slate-700">Enabled</span>
                                                </label>
                                                <div className="flex items-end justify-end">
                                                    <button
                                                        type="button"
                                                        onClick={() => setForm((current) => ({
                                                            ...current,
                                                            buckets: current.buckets.filter((_, bucketIndex) => bucketIndex !== index),
                                                        }))}
                                                        className="rounded-2xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                                                    >
                                                        Remove
                                                    </button>
                                                </div>
                                            </div>

                                            <div className="mt-4 grid gap-3 xl:grid-cols-[minmax(0,1fr)_360px]">
                                                {renderBucketParams(bucket, (nextBucket) => {
                                                    const nextBuckets = [...form.buckets];
                                                    nextBuckets[index] = nextBucket;
                                                    setForm((current) => ({ ...current, buckets: nextBuckets }));
                                                })}
                                                <div className="rounded-2xl bg-slate-50 px-4 py-4">
                                                    <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Bucket summary</p>
                                                    <p className="mt-2 text-sm font-medium text-slate-800">{bucketLabel(bucket.type)}</p>
                                                    <p className="mt-1 text-sm text-slate-500">{bucketDescription(bucket)}</p>
                                                </div>
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </section>

                    <section id="schedule" className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-lg font-semibold text-slate-900">Schedule</h3>
                                <p className="mt-1 text-sm text-slate-500">Set the runway window, slot spacing, and active days that the engine can allocate.</p>
                            </div>
                            <div className="space-y-5 px-5 py-5">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Window start</span>
                                        <input
                                            type="time"
                                            className="crm-input"
                                            value={form.schedule.window_start}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, window_start: event.target.value },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Window end</span>
                                        <input
                                            type="time"
                                            className="crm-input"
                                            value={form.schedule.window_end}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, window_end: event.target.value },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Interval hours</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="24"
                                            className="crm-input"
                                            value={form.schedule.interval_hours}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, interval_hours: Number(event.target.value || 1) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Lookahead days</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="14"
                                            className="crm-input"
                                            value={form.schedule.lookahead_days}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, lookahead_days: Number(event.target.value || 1) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max items / day</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="500"
                                            className="crm-input"
                                            value={form.schedule.max_items_per_day}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, max_items_per_day: Number(event.target.value || 1) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Runway threshold</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="500"
                                            className="crm-input"
                                            value={form.schedule.runway_threshold}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                schedule: { ...current.schedule, runway_threshold: event.target.value },
                                            }))}
                                            placeholder="Auto"
                                        />
                                    </label>
                                </div>

                                <div>
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Active days</p>
                                            <p className="mt-1 text-sm text-slate-500">Pick the days where the slot allocator can place sends.</p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        {DAY_OPTIONS.map((day) => {
                                            const active = form.schedule.active_days.includes(day.value);
                                            return (
                                                <button
                                                    key={day.value}
                                                    type="button"
                                                    onClick={() => setForm((current) => {
                                                        const exists = current.schedule.active_days.includes(day.value);
                                                        const nextDays = exists
                                                            ? current.schedule.active_days.filter((value) => value !== day.value)
                                                            : [...current.schedule.active_days, day.value].sort((a, b) => a - b);

                                                        return {
                                                            ...current,
                                                            schedule: {
                                                                ...current.schedule,
                                                                active_days: nextDays.length > 0 ? nextDays : current.schedule.active_days,
                                                            },
                                                        };
                                                    })}
                                                    className={`min-w-[60px] rounded-2xl border px-3 py-2 text-sm font-medium transition ${
                                                        active
                                                            ? 'border-teal-300 bg-teal-50 text-teal-700 shadow-sm'
                                                            : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                                    }`}
                                                >
                                                    {day.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </section>

                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-lg font-semibold text-slate-900">Copy + Reliability</h3>
                                <p className="mt-1 text-sm text-slate-500">Shape how the message sounds and how much recovery runway the plan keeps in reserve.</p>
                            </div>
                            <div className="space-y-5 px-5 py-5">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <SelectField
                                        label="Message mode"
                                        value={form.message_strategy.mode}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            message_strategy: { ...current.message_strategy, mode: event.target.value },
                                        }))}
                                    >
                                        {MESSAGE_MODE_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </SelectField>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max characters</span>
                                        <input
                                            type="number"
                                            min="40"
                                            max="255"
                                            className="crm-input"
                                            value={form.message_strategy.max_chars}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                message_strategy: { ...current.message_strategy, max_chars: Number(event.target.value || 120) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Tone</span>
                                        <input
                                            className="crm-input"
                                            value={form.message_strategy.tone}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                message_strategy: { ...current.message_strategy, tone: event.target.value },
                                            }))}
                                            placeholder="Warm and direct"
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Temperament</span>
                                        <input
                                            className="crm-input"
                                            value={form.message_strategy.temperament}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                message_strategy: { ...current.message_strategy, temperament: event.target.value },
                                            }))}
                                            placeholder="Confident"
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Language</span>
                                        <input
                                            className="crm-input"
                                            value={form.message_strategy.language}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                message_strategy: { ...current.message_strategy, language: event.target.value },
                                            }))}
                                            placeholder="en"
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Reserve multiplier</span>
                                        <input
                                            type="number"
                                            min="1"
                                            max="10"
                                            step="0.1"
                                            className="crm-input"
                                            value={form.reliability.reserve_multiplier}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                reliability: { ...current.reliability, reserve_multiplier: Number(event.target.value || 1) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max replacements</span>
                                        <input
                                            type="number"
                                            min="0"
                                            max="10"
                                            className="crm-input"
                                            value={form.reliability.max_replacements_per_item}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                reliability: { ...current.reliability, max_replacements_per_item: Number(event.target.value || 0) },
                                            }))}
                                        />
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Exclude pushed within days</span>
                                        <input
                                            type="number"
                                            min="0"
                                            max="30"
                                            className="crm-input"
                                            value={form.reliability.exclude_pushed_within_days}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                reliability: { ...current.reliability, exclude_pushed_within_days: Number(event.target.value || 0) },
                                            }))}
                                        />
                                    </label>
                                    <SelectField
                                        label="Replacement spillover"
                                        value={form.reliability.replacement_spillover}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            reliability: { ...current.reliability, replacement_spillover: event.target.value },
                                        }))}
                                    >
                                        {SPILLOVER_OPTIONS.map((option) => (
                                            <option key={option.value} value={option.value}>{option.label}</option>
                                        ))}
                                    </SelectField>
                                </div>

                                <label className="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 px-4 py-3.5">
                                    <input
                                        type="checkbox"
                                        checked={form.reliability.sms_alerts_enabled}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            reliability: { ...current.reliability, sms_alerts_enabled: event.target.checked },
                                        }))}
                                        className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                    />
                                    <span>
                                        <span className="block text-sm font-medium text-slate-800">SMS alerts</span>
                                        <span className="block text-xs text-slate-500">Keep operational intent visible in the plan configuration.</span>
                                    </span>
                                </label>

                                <label className="space-y-1">
                                    <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Seed phrases</span>
                                    <textarea
                                        className="crm-input min-h-[150px] resize-y"
                                        value={form.message_strategy.seed_phrases.join('\n')}
                                        onChange={(event) => setForm((current) => ({
                                            ...current,
                                            message_strategy: {
                                                ...current.message_strategy,
                                                seed_phrases: event.target.value.split('\n').map((line) => line.trim()).filter(Boolean),
                                            },
                                        }))}
                                        placeholder="One starter line per row"
                                    />
                                </label>
                            </div>
                        </section>
                    </section>

                    {form.id ? (
                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold text-slate-900">Market cloning</h3>
                                        <p className="mt-1 text-sm text-slate-500">Copy this plan structure into other accessible markets, then tune market-specific details after cloning.</p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => cloneMutation.mutate({ planId: form.id, platform_ids: clonePlatformIds.map(Number) })}
                                        disabled={clonePlatformIds.length === 0 || cloneMutation.isPending}
                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {cloneMutation.isPending ? 'Cloning...' : 'Clone selected'}
                                    </button>
                                </div>
                            </div>
                            <div className="grid gap-3 px-5 py-5 md:grid-cols-2 xl:grid-cols-3">
                                {otherPlatforms.length === 0 ? (
                                    <p className="text-sm text-slate-500">No other accessible markets available.</p>
                                ) : otherPlatforms.map((platform) => {
                                    const checked = clonePlatformIds.includes(String(platform.platform_id));
                                    return (
                                        <label key={platform.platform_id} className={`flex items-center gap-3 rounded-2xl border px-4 py-3 transition ${
                                            checked ? 'border-teal-300 bg-teal-50' : 'border-slate-200 hover:bg-slate-50'
                                        }`}>
                                            <input
                                                type="checkbox"
                                                checked={checked}
                                                onChange={(event) => setClonePlatformIds((current) => event.target.checked
                                                    ? [...current, String(platform.platform_id)]
                                                    : current.filter((value) => value !== String(platform.platform_id)))}
                                                className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                            />
                                            <span className="text-sm font-medium text-slate-700">{platformLabel(platform)}</span>
                                        </label>
                                    );
                                })}
                            </div>
                        </section>
                    ) : null}

                    <section id="preview" className="grid gap-5 2xl:grid-cols-[minmax(0,0.95fr)_minmax(380px,0.85fr)]">
                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                    <div>
                                        <h3 className="text-lg font-semibold text-slate-900">Preview queue</h3>
                                        <p className="mt-1 text-sm text-slate-500">Inspect the saved draft run package, expand it when you need the full queue, and swap profiles before the next push batch goes live.</p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <button
                                            type="button"
                                            onClick={handleSavePreviewDraft}
                                            disabled={!form.id || previewItems.length === 0 || saveDraftPackageMutation.isPending || !previewDirty}
                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {saveDraftPackageMutation.isPending ? 'Saving draft...' : previewDirty ? 'Save draft package' : 'Draft saved'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => shuffleDraftPackageMutation.mutate()}
                                            disabled={!form.id || shuffleDraftPackageMutation.isPending}
                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {shuffleDraftPackageMutation.isPending ? 'Shuffling...' : 'Shuffle all'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={handlePreview}
                                            disabled={!form.id || previewMutation.isPending}
                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {previewMutation.isPending ? 'Refreshing...' : 'Refresh from plan'}
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPreviewDevice('mobile')}
                                            className={`crm-btn-secondary px-3 py-1.5 text-xs ${previewDevice === 'mobile' ? 'border-teal-400 bg-teal-50 text-teal-700' : ''}`}
                                        >
                                            Mobile
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setPreviewDevice('desktop')}
                                            className={`crm-btn-secondary px-3 py-1.5 text-xs ${previewDevice === 'desktop' ? 'border-teal-400 bg-teal-50 text-teal-700' : ''}`}
                                        >
                                            Desktop
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-4 px-5 py-5">
                                {preview?.selection ? (
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <div className="rounded-2xl bg-slate-50 px-4 py-4">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Primary</p>
                                            <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(preview.selection.primary_count || 0).toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-2xl bg-slate-50 px-4 py-4">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Reserve</p>
                                            <p className="mt-1 text-2xl font-semibold text-slate-900">{Number(preview.selection.reserve_count || 0).toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-2xl bg-slate-50 px-4 py-4">
                                            <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Buckets</p>
                                            <p className="mt-1 text-2xl font-semibold text-slate-900">{Object.keys(preview.selection.bucket_counts || {}).length}</p>
                                        </div>
                                    </div>
                                ) : null}

                                {(preview?.engagement?.top_profiles?.length || preview?.engagement?.bottom_profiles?.length) ? (
                                    <div className="grid gap-4 xl:grid-cols-2">
                                        <div className="rounded-2xl border border-emerald-100 bg-emerald-50/70 p-4">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-emerald-900">Top 3 engagement signals</p>
                                                    <p className="mt-1 text-xs text-emerald-700">Pulled from the same profile engagement ranking used in Reports.</p>
                                                </div>
                                            </div>
                                            <div className="mt-4 space-y-3">
                                                {(preview?.engagement?.top_profiles || []).map((profile) => (
                                                    <div key={`top-${profile.client_id || profile.post_id}`} className="flex items-center gap-3 rounded-2xl bg-white/90 px-3 py-3 shadow-sm">
                                                        <div className="h-12 w-12 overflow-hidden rounded-2xl bg-emerald-100">
                                                            {resolvePreviewImage(profile) ? (
                                                                <img src={resolvePreviewImage(profile)} alt={profile.name || 'Top profile'} className="h-full w-full object-cover" />
                                                            ) : (
                                                                <div className="flex h-full w-full items-center justify-center text-sm font-semibold text-emerald-700">
                                                                    {(profile.name || 'T').charAt(0).toUpperCase()}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-semibold text-slate-900">{profile.name}</p>
                                                            <p className="truncate text-xs text-slate-500">
                                                                {profile.city || 'Unknown location'} • {Number(profile.contact_rate_percent || 0).toFixed(1)}% contact rate
                                                            </p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => replacePreviewItemMutation.mutate({ previewId: activePreviewId || selectedPreviewItem?.preview_id, clientId: profile.client_id })}
                                                            disabled={!profile.client_id || !selectedPreviewItem || replacePreviewItemMutation.isPending}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            Use
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        <div className="rounded-2xl border border-amber-100 bg-amber-50/70 p-4">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-amber-900">Bottom 3 engagement signals</p>
                                                    <p className="mt-1 text-xs text-amber-700">Useful when the team wants to test recovery or reactivation-style copy.</p>
                                                </div>
                                            </div>
                                            <div className="mt-4 space-y-3">
                                                {(preview?.engagement?.bottom_profiles || []).map((profile) => (
                                                    <div key={`bottom-${profile.client_id || profile.post_id}`} className="flex items-center gap-3 rounded-2xl bg-white/90 px-3 py-3 shadow-sm">
                                                        <div className="h-12 w-12 overflow-hidden rounded-2xl bg-amber-100">
                                                            {resolvePreviewImage(profile) ? (
                                                                <img src={resolvePreviewImage(profile)} alt={profile.name || 'Bottom profile'} className="h-full w-full object-cover" />
                                                            ) : (
                                                                <div className="flex h-full w-full items-center justify-center text-sm font-semibold text-amber-700">
                                                                    {(profile.name || 'B').charAt(0).toUpperCase()}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <p className="truncate text-sm font-semibold text-slate-900">{profile.name}</p>
                                                            <p className="truncate text-xs text-slate-500">
                                                                {profile.city || 'Unknown location'} • {Number(profile.contact_rate_percent || 0).toFixed(1)}% contact rate
                                                            </p>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            onClick={() => replacePreviewItemMutation.mutate({ previewId: activePreviewId || selectedPreviewItem?.preview_id, clientId: profile.client_id })}
                                                            disabled={!profile.client_id || !selectedPreviewItem || replacePreviewItemMutation.isPending}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            Use
                                                        </button>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    </div>
                                ) : null}

                                {previewItems.length > 0 ? (
                                    <div className="space-y-3">
                                        {visiblePreviewItems.map((item, index) => {
                                            const active = item.preview_id === activePreviewId;
                                            return (
                                                <div
                                                    key={item.preview_id}
                                                    className={`rounded-2xl border px-4 py-4 transition ${
                                                        active ? 'border-teal-300 bg-teal-50/70 shadow-sm' : 'border-slate-200 hover:bg-slate-50'
                                                    }`}
                                                >
                                                    <div className="flex items-start gap-3">
                                                        <div className="h-14 w-14 shrink-0 overflow-hidden rounded-2xl bg-slate-100">
                                                            {resolvePreviewImage(item) ? (
                                                                <img src={resolvePreviewImage(item)} alt={item.name || 'Preview'} className="h-full w-full object-cover" />
                                                            ) : (
                                                                <div className="flex h-full w-full items-center justify-center text-lg font-semibold text-slate-500">
                                                                    {(item.name || 'E').charAt(0).toUpperCase()}
                                                                </div>
                                                            )}
                                                        </div>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex flex-wrap items-start justify-between gap-2">
                                                                <div>
                                                                    <p className="truncate font-semibold text-slate-900">{item.name || `Preview ${index + 1}`}</p>
                                                                    <p className="truncate text-sm text-slate-500">{item.city || 'Unknown location'}</p>
                                                                </div>
                                                                <div className="flex flex-wrap items-center gap-2">
                                                                    <span className="rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-600 shadow-sm">
                                                                        {prettyLabel(item.message_source)}
                                                                    </span>
                                                                    <span className="rounded-full bg-white px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-500 shadow-sm">
                                                                        slot {Number(item.slot_index || index) + 1}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <p className="mt-2 line-clamp-2 text-sm leading-6 text-slate-700">{item.message}</p>
                                                            <div className="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                                                <span className="rounded-full bg-white px-2.5 py-1 shadow-sm">{formatPreviewTime(item.scheduled_at_market || item.scheduled_at)}</span>
                                                                <span className="rounded-full bg-white px-2.5 py-1 shadow-sm">{item.profile_url ? 'Profile linked' : 'No profile link'}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div className="mt-4 flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => setActivePreviewId(item.preview_id)}
                                                            className={`crm-btn-secondary px-3 py-1.5 text-xs ${active ? 'border-teal-400 bg-teal-50 text-teal-700' : ''}`}
                                                        >
                                                            {active ? 'Editing' : 'Open in studio'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => replacePreviewItemMutation.mutate({ previewId: item.preview_id })}
                                                            disabled={replacePreviewItemMutation.isPending}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {replacePreviewItemMutation.isPending && active ? 'Replacing...' : 'Replace client'}
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })}

                                        {previewItems.length > 5 ? (
                                            <button
                                                type="button"
                                                onClick={() => setPreviewExpanded((current) => !current)}
                                                className="crm-btn-secondary w-full px-3 py-2 text-sm"
                                            >
                                                {previewExpanded ? 'Show fewer preview cards' : `Show ${hiddenPreviewCount} more preview card${hiddenPreviewCount === 1 ? '' : 's'}`}
                                            </button>
                                        ) : null}
                                    </div>
                                ) : (
                                    <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-12 text-center text-sm text-slate-500">
                                        {draftPackageQuery.isLoading ? 'Loading saved preview package...' : 'Refresh preview to generate realistic sample notifications for this plan.'}
                                    </div>
                                )}
                            </div>
                        </section>

                        <div className="space-y-5">
                            <section className="crm-surface overflow-hidden">
                                <div className="border-b border-slate-200 px-5 py-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <h3 className="text-lg font-semibold text-slate-900">Preview studio</h3>
                                            <p className="mt-1 text-sm text-slate-500">See the notification as a real push surface, then tweak the visible fields card by card.</p>
                                        </div>
                                        {selectedPreviewItem ? (
                                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-slate-600">
                                                {selectedPreviewItem.name || 'Selected preview'}
                                            </span>
                                        ) : null}
                                    </div>
                                </div>
                                <div className="space-y-5 px-5 py-5">
                                    {selectedPreviewItem ? (
                                        <>
                                            <PreviewNotification item={selectedPreviewItem} device={previewDevice} />

                                            <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4">
                                                <div className="mb-4 flex items-center justify-between gap-3">
                                                    <div>
                                                        <h4 className="text-sm font-semibold text-slate-900">Edit visible preview</h4>
                                                        <p className="mt-1 text-xs text-slate-500">Tighten the client-facing presentation, then save it into the draft run package so the team sees the same preview later.</p>
                                                    </div>
                                                    <div className="flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            onClick={() => replacePreviewItemMutation.mutate({ previewId: selectedPreviewItem.preview_id })}
                                                            disabled={replacePreviewItemMutation.isPending}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            Replace client
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => updatePreviewItem(selectedPreviewItem.preview_id, (item) => ({
                                                                ...item,
                                                                city: item.original_city,
                                                                message: item.original_message,
                                                                scheduled_at_input: item.original_scheduled_at_input,
                                                                scheduled_at_market: item.original_scheduled_at_input ? new Date(item.original_scheduled_at_input).toISOString() : item.scheduled_at_market,
                                                                profile_image_url: item.original_profile_image_url,
                                                            }))}
                                                            className="crm-btn-secondary px-3 py-1.5 text-xs"
                                                        >
                                                            Reset card
                                                        </button>
                                                    </div>
                                                </div>
                                                <div className="grid gap-4">
                                                    <div className="grid gap-4 md:grid-cols-2">
                                                        <label className="space-y-1">
                                                            <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Location</span>
                                                            <input
                                                                className="crm-input"
                                                                value={selectedPreviewItem.city || ''}
                                                                onChange={(event) => updatePreviewItem(selectedPreviewItem.preview_id, { city: event.target.value })}
                                                            />
                                                        </label>
                                                        <label className="space-y-1">
                                                            <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Image URL</span>
                                                            <input
                                                                className="crm-input"
                                                                value={selectedPreviewItem.profile_image_url || ''}
                                                                onChange={(event) => updatePreviewItem(selectedPreviewItem.preview_id, { profile_image_url: event.target.value })}
                                                                placeholder="https://..."
                                                            />
                                                        </label>
                                                    </div>
                                                    <div className="grid gap-4 md:grid-cols-2">
                                                        <label className="space-y-1">
                                                            <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Scheduled time</span>
                                                            <input
                                                                type="datetime-local"
                                                                className="crm-input"
                                                                value={selectedPreviewItem.scheduled_at_input || ''}
                                                                onChange={(event) => updatePreviewItem(selectedPreviewItem.preview_id, {
                                                                    scheduled_at_input: event.target.value,
                                                                    scheduled_at_market: event.target.value ? new Date(event.target.value).toISOString() : selectedPreviewItem.scheduled_at_market,
                                                                })}
                                                            />
                                                        </label>
                                                        <label className="space-y-1">
                                                            <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Profile name</span>
                                                            <input
                                                                className="crm-input"
                                                                value={selectedPreviewItem.name || ''}
                                                                onChange={(event) => updatePreviewItem(selectedPreviewItem.preview_id, { name: event.target.value })}
                                                            />
                                                        </label>
                                                    </div>
                                                    <label className="space-y-1">
                                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Message</span>
                                                        <textarea
                                                            className="crm-input min-h-[120px] resize-y"
                                                            value={selectedPreviewItem.message || ''}
                                                            onChange={(event) => updatePreviewItem(selectedPreviewItem.preview_id, { message: event.target.value })}
                                                        />
                                                    </label>
                                                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white px-4 py-3">
                                                        <p className="text-xs text-slate-500">
                                                            {previewDirty ? 'You have unsaved preview changes.' : 'This preview is saved into the current draft package.'}
                                                        </p>
                                                        <button
                                                            type="button"
                                                            onClick={handleSavePreviewDraft}
                                                            disabled={!previewDirty || saveDraftPackageMutation.isPending}
                                                            className="crm-btn-primary px-3 py-1.5 text-xs disabled:cursor-not-allowed disabled:opacity-60"
                                                        >
                                                            {saveDraftPackageMutation.isPending ? 'Saving...' : 'Save preview draft'}
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </>
                                    ) : (
                                        <div className="rounded-2xl border border-dashed border-slate-200 px-4 py-12 text-center text-sm text-slate-500">
                                            {draftPackageQuery.isLoading ? 'Loading preview studio...' : 'Pick a preview card to open the device preview and inline editor.'}
                                        </div>
                                    )}
                                </div>
                            </section>
                        </div>
                    </section>

                    <section id="operations" className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-lg font-semibold text-slate-900">Recent runs</h3>
                                <p className="mt-1 text-sm text-slate-500">Execution attempts for the selected plan, including reserve use and replacement volume.</p>
                            </div>
                            <DataTable
                                columns={runColumns}
                                data={runRows}
                                pagination={{ current_page: 1, last_page: 1, total: runRows.length, per_page: runRows.length || 10 }}
                                isLoading={runsQuery.isLoading}
                                emptyMessage="No runs yet for this plan."
                                compact
                            />
                        </section>

                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-5 py-4">
                                <h3 className="text-lg font-semibold text-slate-900">Alerts</h3>
                                <p className="mt-1 text-sm text-slate-500">Operational issues, failovers, and replacement exhaustion that need a human decision.</p>
                            </div>
                            <DataTable
                                columns={alertColumns}
                                data={alertRows}
                                pagination={{ current_page: 1, last_page: 1, total: alertRows.length, per_page: alertRows.length || 10 }}
                                isLoading={alertsQuery.isLoading}
                                emptyMessage="No alerts for this plan."
                                compact
                            />
                        </section>
                    </section>
                </div>
            </div>
        </div>
    );
}
