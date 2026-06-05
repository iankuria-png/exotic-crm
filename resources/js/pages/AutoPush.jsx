import React, { useEffect, useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';
import DataTable from '../components/DataTable';
import { useToast } from '../components/ToastProvider';

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

export default function AutoPush() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [selectedPlanId, setSelectedPlanId] = useState(null);
    const [isCreatingNew, setIsCreatingNew] = useState(false);
    const [form, setForm] = useState(() => deepClone(EMPTY_FORM));
    const [clonePlatformIds, setClonePlatformIds] = useState([]);
    const [preview, setPreview] = useState(null);

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
        setClonePlatformIds([]);
    }, [selectedPlan]);

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
            }
            setPreview(previewPayload);
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Preview failed.');
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

    const handleSave = () => {
        savePlanMutation.mutate(planToPayload(form));
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
        setClonePlatformIds([]);
        setForm({
            ...deepClone(EMPTY_FORM),
            platform_id: platformOptions[0] ? String(platformOptions[0].platform_id) : '',
        });
    };

    return (
        <div className="space-y-4">
            <PageHeader
                title="Auto Push"
                subtitle="Manage market-specific autopilot plans, inspect runway, and keep replacements and alerts under control."
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
                            {previewMutation.isPending ? 'Previewing...' : 'Preview selection'}
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
                <MetricCard label="Due Now" value={metrics.dueNow.toLocaleString()} meta="ready for another run" tone="warning" />
                <MetricCard label="Open Alerts" value={metrics.openAlerts.toLocaleString()} meta="needs review" tone="danger" />
            </section>

            <div className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
                <section className="crm-surface overflow-hidden">
                    <div className="border-b border-slate-200 px-4 py-4">
                        <h3 className="text-sm font-semibold text-slate-900">Plans</h3>
                        <p className="mt-1 text-sm text-slate-500">One plan per market, with runway and approvals visible at a glance.</p>
                    </div>
                    <div className="max-h-[72vh] space-y-3 overflow-y-auto p-4">
                        {plansQuery.isLoading ? (
                            <div className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">Loading plans...</div>
                        ) : plans.length === 0 ? (
                            <div className="rounded-xl border border-dashed border-slate-200 px-4 py-8 text-center text-sm text-slate-500">No plans yet. Create the first market plan to start coverage.</div>
                        ) : plans.map((plan) => {
                            const active = Number(plan.id) === Number(selectedPlanId);
                            const statusTone = plan.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
                            return (
                                <button
                                    key={plan.id}
                                    type="button"
                                    onClick={() => setSelectedPlanId(plan.id)}
                                    className={`w-full rounded-xl border p-4 text-left transition ${
                                        active ? 'border-teal-300 bg-teal-50/70 shadow-sm' : 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'
                                    }`}
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div>
                                            <p className="font-semibold text-slate-900">{plan.name}</p>
                                            <p className="mt-1 text-sm text-slate-500">{plan.platform?.name || plan.platform?.country || 'Unknown market'}</p>
                                        </div>
                                        <span className={`rounded-md px-2 py-0.5 text-[11px] font-medium ${statusTone}`}>
                                            {plan.enabled ? 'enabled' : 'disabled'}
                                        </span>
                                    </div>
                                    <div className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                                        <div className="rounded-lg bg-white/80 px-3 py-2">
                                            <p className="font-medium text-slate-900">{Number(plan.coverage_count || 0).toLocaleString()}</p>
                                            <p>covered slots</p>
                                        </div>
                                        <div className="rounded-lg bg-white/80 px-3 py-2">
                                            <p className="font-medium text-slate-900">{plan.due_now ? 'Yes' : 'No'}</p>
                                            <p>due now</p>
                                        </div>
                                    </div>
                                    <div className="mt-3 flex flex-wrap items-center gap-2 text-[11px] text-slate-500">
                                        <span className="rounded-full bg-slate-100 px-2 py-1">{plan.autopilot ? 'autopilot on' : 'approval mode'}</span>
                                        <span className="rounded-full bg-slate-100 px-2 py-1">last run {formatDateTime(plan.last_run_at)}</span>
                                    </div>
                                </button>
                            );
                        })}
                    </div>
                </section>

                <div className="space-y-4">
                    <section className="grid gap-4 md:grid-cols-3">
                        <MetricCard label="Market" value={selectedPlanMarket} meta="selected plan" tone="default" />
                        <MetricCard label="Coverage" value={selectedPlanCoverage.toLocaleString()} meta={`threshold ${Number(selectedPlanRunway || 0).toLocaleString()}`} tone={selectedPlanCoverage >= selectedPlanRunway ? 'success' : 'warning'} />
                        <MetricCard label="Mode" value={form.autopilot ? 'Autopilot' : 'Approval'} meta={form.enabled ? 'scheduler active' : 'scheduler paused'} tone={form.autopilot ? 'accent' : 'default'} />
                    </section>

                    <section className="crm-surface p-5">
                        <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <h3 className="text-base font-semibold text-slate-900">Plan Settings</h3>
                                <p className="mt-1 text-sm text-slate-500">Tune selection buckets, schedule runway, copy strategy, and failover behavior.</p>
                            </div>
                            {form.id ? (
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={() => toggleMutation.mutate(form.id)}
                                        disabled={toggleMutation.isPending}
                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {form.enabled ? 'Disable' : 'Enable'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => autopilotMutation.mutate(form.id)}
                                        disabled={autopilotMutation.isPending}
                                        className="crm-btn-secondary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {form.autopilot ? 'Turn off autopilot' : 'Turn on autopilot'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => runNowMutation.mutate(form.id)}
                                        disabled={runNowMutation.isPending}
                                        className="crm-btn-primary disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {runNowMutation.isPending ? 'Running...' : 'Run now'}
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => deleteMutation.mutate(form.id)}
                                        disabled={deleteMutation.isPending}
                                        className="rounded-xl border border-rose-200 px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Delete
                                    </button>
                                </div>
                            ) : null}
                        </div>

                        <div className="mt-5 grid gap-4 md:grid-cols-2">
                            <label className="space-y-1">
                                <span className="text-sm font-medium text-slate-700">Plan name</span>
                                <input
                                    className="crm-input"
                                    value={form.name}
                                    onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))}
                                    placeholder="Kenya daily runway"
                                />
                            </label>
                            <label className="space-y-1">
                                <span className="text-sm font-medium text-slate-700">Market</span>
                                <select
                                    className="crm-select"
                                    value={form.platform_id}
                                    onChange={(event) => setForm((current) => ({ ...current, platform_id: event.target.value }))}
                                >
                                    <option value="">Select market</option>
                                    {platformOptions.map((platform) => (
                                        <option key={platform.platform_id} value={platform.platform_id}>
                                            {platform.platform_name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <div className="mt-4 grid gap-3 md:grid-cols-3">
                            <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-3">
                                <input
                                    type="checkbox"
                                    checked={form.enabled}
                                    onChange={(event) => setForm((current) => ({ ...current, enabled: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                <span>
                                    <span className="block text-sm font-medium text-slate-800">Enabled</span>
                                    <span className="block text-xs text-slate-500">Eligible for scheduler runs.</span>
                                </span>
                            </label>
                            <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-3">
                                <input
                                    type="checkbox"
                                    checked={form.autopilot}
                                    onChange={(event) => setForm((current) => ({ ...current, autopilot: event.target.checked }))}
                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                />
                                <span>
                                    <span className="block text-sm font-medium text-slate-800">Autopilot</span>
                                    <span className="block text-xs text-slate-500">Schedules campaigns immediately.</span>
                                </span>
                            </label>
                            <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-3">
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
                                    <span className="block text-sm font-medium text-slate-800">Count drafts as coverage</span>
                                    <span className="block text-xs text-slate-500">Prevents duplicate draft runway.</span>
                                </span>
                            </label>
                        </div>

                        <div className="mt-6 space-y-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <h4 className="text-sm font-semibold text-slate-900">Selection Buckets</h4>
                                    <p className="text-sm text-slate-500">Priority order matters. First matching bucket wins the client.</p>
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

                            <div className="space-y-3">
                                {form.buckets.map((bucket, index) => (
                                    <div key={`${bucket.type}-${index}`} className="rounded-xl border border-slate-200 p-4">
                                        <div className="grid gap-3 md:grid-cols-[1.4fr_110px_110px_auto]">
                                            <label className="space-y-1">
                                                <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Type</span>
                                                <select
                                                    className="crm-select"
                                                    value={bucket.type}
                                                    onChange={(event) => {
                                                        const nextBuckets = [...form.buckets];
                                                        nextBuckets[index] = {
                                                            ...bucket,
                                                            type: event.target.value,
                                                            params: {},
                                                        };
                                                        setForm((current) => ({ ...current, buckets: nextBuckets }));
                                                    }}
                                                >
                                                    {BUCKET_TYPE_OPTIONS.map((option) => (
                                                        <option key={option.value} value={option.value}>{option.label}</option>
                                                    ))}
                                                </select>
                                            </label>
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
                                            <label className="flex items-end gap-2 rounded-lg border border-slate-200 px-3 py-2.5">
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
                                                <span className="text-sm text-slate-700">Enabled</span>
                                            </label>
                                            <button
                                                type="button"
                                                onClick={() => setForm((current) => ({
                                                    ...current,
                                                    buckets: current.buckets.filter((_, bucketIndex) => bucketIndex !== index),
                                                }))}
                                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-50"
                                            >
                                                Remove
                                            </button>
                                        </div>

                                        <div className="mt-3 grid gap-3 md:grid-cols-2">
                                            {renderBucketParams(bucket, (nextBucket) => {
                                                const nextBuckets = [...form.buckets];
                                                nextBuckets[index] = nextBucket;
                                                setForm((current) => ({ ...current, buckets: nextBuckets }));
                                            })}
                                            <div className="rounded-lg bg-slate-50 px-3 py-3 text-sm text-slate-600">
                                                {bucketDescription(bucket)}
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>

                        <div className="mt-6 grid gap-4 xl:grid-cols-2">
                            <div className="space-y-4 rounded-xl border border-slate-200 p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Schedule</h4>
                                <div className="grid gap-3 md:grid-cols-2">
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Window Start</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Window End</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Interval Hours</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Lookahead Days</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max Items / Day</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Runway Threshold</span>
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
                                    <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Active Days</p>
                                    <div className="mt-2 grid grid-cols-4 gap-2 md:grid-cols-7">
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
                                                    className={`rounded-lg border px-2 py-2 text-sm font-medium transition ${
                                                        active
                                                            ? 'border-teal-300 bg-teal-50 text-teal-700'
                                                            : 'border-slate-200 text-slate-600 hover:bg-slate-50'
                                                    }`}
                                                >
                                                    {day.label}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>

                            <div className="space-y-4 rounded-xl border border-slate-200 p-4">
                                <h4 className="text-sm font-semibold text-slate-900">Copy + Reliability</h4>
                                <div className="grid gap-3 md:grid-cols-2">
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Message Mode</span>
                                        <select
                                            className="crm-select"
                                            value={form.message_strategy.mode}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                message_strategy: { ...current.message_strategy, mode: event.target.value },
                                            }))}
                                        >
                                            {MESSAGE_MODE_OPTIONS.map((option) => (
                                                <option key={option.value} value={option.value}>{option.label}</option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max Characters</span>
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
                                            placeholder="Playful but direct"
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
                                            placeholder="Calm"
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Reserve Multiplier</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Max Replacements</span>
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
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Exclude Pushed Within Days</span>
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
                                    <label className="space-y-1">
                                        <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Replacement Spillover</span>
                                        <select
                                            className="crm-select"
                                            value={form.reliability.replacement_spillover}
                                            onChange={(event) => setForm((current) => ({
                                                ...current,
                                                reliability: { ...current.reliability, replacement_spillover: event.target.value },
                                            }))}
                                        >
                                            {SPILLOVER_OPTIONS.map((option) => (
                                                <option key={option.value} value={option.value}>{option.label}</option>
                                            ))}
                                        </select>
                                    </label>
                                </div>

                                <label className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-3">
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
                                        <span className="block text-xs text-slate-500">Marks operational intent in the plan config.</span>
                                    </span>
                                </label>

                                <label className="space-y-1">
                                    <span className="text-[11px] font-medium uppercase tracking-[0.12em] text-slate-500">Seed Phrases</span>
                                    <textarea
                                        className="crm-input min-h-[110px] resize-y"
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
                        </div>

                        {form.id ? (
                            <div className="mt-6 rounded-xl border border-slate-200 p-4">
                                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div>
                                        <h4 className="text-sm font-semibold text-slate-900">Clone to Other Markets</h4>
                                        <p className="mt-1 text-sm text-slate-500">Copy the current plan shape into one or more other accessible markets.</p>
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
                                <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                                    {otherPlatforms.length === 0 ? (
                                        <p className="text-sm text-slate-500">No other accessible markets available.</p>
                                    ) : otherPlatforms.map((platform) => {
                                        const checked = clonePlatformIds.includes(String(platform.platform_id));
                                        return (
                                            <label key={platform.platform_id} className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2">
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={(event) => setClonePlatformIds((current) => event.target.checked
                                                        ? [...current, String(platform.platform_id)]
                                                        : current.filter((value) => value !== String(platform.platform_id)))}
                                                    className="h-4 w-4 rounded border-slate-300 text-teal-700 focus:ring-teal-200"
                                                />
                                                <span className="text-sm text-slate-700">{platform.platform_name}</span>
                                            </label>
                                        );
                                    })}
                                </div>
                            </div>
                        ) : null}
                    </section>

                    <section className="grid gap-4 2xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                        <section className="crm-surface overflow-hidden">
                            <div className="border-b border-slate-200 px-4 py-4">
                                <h3 className="text-sm font-semibold text-slate-900">Preview</h3>
                                <p className="mt-1 text-sm text-slate-500">Top candidate slice and generated copy before the next run is queued.</p>
                            </div>
                            <div className="space-y-3 p-4">
                                {preview?.selection ? (
                                    <div className="grid gap-3 md:grid-cols-3">
                                        <div className="rounded-xl bg-slate-50 px-3 py-3">
                                            <p className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Primary</p>
                                            <p className="mt-1 text-lg font-semibold text-slate-900">{Number(preview.selection.primary_count || 0).toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-xl bg-slate-50 px-3 py-3">
                                            <p className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Reserve</p>
                                            <p className="mt-1 text-lg font-semibold text-slate-900">{Number(preview.selection.reserve_count || 0).toLocaleString()}</p>
                                        </div>
                                        <div className="rounded-xl bg-slate-50 px-3 py-3">
                                            <p className="text-xs font-medium uppercase tracking-[0.12em] text-slate-500">Buckets</p>
                                            <p className="mt-1 text-lg font-semibold text-slate-900">{Object.keys(preview.selection.bucket_counts || {}).length}</p>
                                        </div>
                                    </div>
                                ) : null}

                                {preview?.items?.length > 0 ? preview.items.map((item) => (
                                    <article key={`${item.client_id}-${item.scheduled_at}`} className="rounded-xl border border-slate-200 p-4">
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <p className="font-medium text-slate-900">{item.name}</p>
                                                <p className="text-sm text-slate-500">{item.city || 'Unknown city'}</p>
                                            </div>
                                            <span className="rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-700">
                                                {prettyLabel(item.message_source)}
                                            </span>
                                        </div>
                                        <p className="mt-3 rounded-lg bg-slate-50 px-3 py-3 text-sm leading-6 text-slate-700">{item.message}</p>
                                        <div className="mt-3 flex flex-wrap gap-2 text-xs text-slate-500">
                                            <span className="rounded-full bg-slate-100 px-2 py-1">{formatDateTime(item.scheduled_at_market || item.scheduled_at)}</span>
                                            {item.profile_url ? (
                                                <a href={item.profile_url} target="_blank" rel="noreferrer" className="rounded-full bg-slate-100 px-2 py-1 text-teal-700 hover:bg-teal-50">
                                                    Open profile
                                                </a>
                                            ) : null}
                                        </div>
                                    </article>
                                )) : (
                                    <div className="rounded-xl border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-500">
                                        Run a preview to inspect selected clients, scheduled slots, and generated push copy.
                                    </div>
                                )}
                            </div>
                        </section>

                        <div className="space-y-4">
                            <section className="crm-surface overflow-hidden">
                                <div className="border-b border-slate-200 px-4 py-4">
                                    <h3 className="text-sm font-semibold text-slate-900">Recent Runs</h3>
                                    <p className="mt-1 text-sm text-slate-500">Latest execution attempts for this plan.</p>
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
                                <div className="border-b border-slate-200 px-4 py-4">
                                    <h3 className="text-sm font-semibold text-slate-900">Alerts</h3>
                                    <p className="mt-1 text-sm text-slate-500">Operational issues, failovers, and replacement exhaustion.</p>
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
                        </div>
                    </section>
                </div>
            </div>
        </div>
    );
}
