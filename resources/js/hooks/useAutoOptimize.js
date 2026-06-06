import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../services/api';
import { useToast } from '../components/ToastProvider';

// ─── Query key factories ───────────────────────────────────────────────────

export const autoOptimizeKeys = {
    plans:   ()                       => ['auto-optimize-plans'],
    items:   (platformId, status)     => ['auto-optimize-items', platformId, status],
    item:    (id)                     => ['auto-optimize-item', id],
    metrics: (platformId)             => ['auto-optimize-metrics', platformId],
    runs:    (planId)                 => ['auto-optimize-runs', planId],
    alerts:  ()                       => ['auto-optimize-alerts'],
};

// ─── Queries ───────────────────────────────────────────────────────────────

// queryFn returns the full response { data: [...plans], platforms: [...] }
// so that useAutoOptimizePlatforms can share the same cache entry via select.
const plansFetcher = () => api.get('/crm/auto-optimize/plans').then((r) => r.data);

export function useAutoOptimizePlans() {
    return useQuery({
        queryKey: autoOptimizeKeys.plans(),
        queryFn: plansFetcher,
        staleTime: 60_000,
        select: (d) => d.data ?? [],
    });
}

// Same cache key + queryFn as useAutoOptimizePlans → one HTTP request, two consumers.
export function useAutoOptimizePlatforms() {
    return useQuery({
        queryKey: autoOptimizeKeys.plans(),
        queryFn: plansFetcher,
        staleTime: 60_000,
        select: (d) => d.platforms ?? [],
    });
}

export function useAutoOptimizeItems({ platformId, status, planId } = {}) {
    return useQuery({
        queryKey: autoOptimizeKeys.items(platformId, status),
        queryFn: () =>
            api.get('/crm/auto-optimize/items', {
                params: { platform_id: platformId, status, plan_id: planId, per_page: 50 },
            }).then((r) => r.data),
        staleTime: 30_000,
    });
}

export function useAutoOptimizeItem(id) {
    return useQuery({
        queryKey: autoOptimizeKeys.item(id),
        queryFn: () => api.get(`/crm/auto-optimize/items/${id}`).then((r) => r.data.item),
        enabled: !!id,
        staleTime: 30_000,
    });
}

export function useAutoOptimizeMetrics(platformId) {
    return useQuery({
        queryKey: autoOptimizeKeys.metrics(platformId),
        queryFn: () =>
            api.get('/crm/auto-optimize/metrics', {
                params: { platform_id: platformId },
            }).then((r) => r.data),
        staleTime: 60_000,
        refetchInterval: 120_000,
    });
}

export function useAutoOptimizeRuns(planId) {
    return useQuery({
        queryKey: autoOptimizeKeys.runs(planId),
        queryFn: () =>
            api.get('/crm/auto-optimize/runs', { params: { plan_id: planId } }).then((r) => r.data.data ?? []),
        enabled: !!planId,
        staleTime: 60_000,
    });
}

export function useAutoOptimizeAlerts() {
    return useQuery({
        queryKey: autoOptimizeKeys.alerts(),
        queryFn: () => api.get('/crm/auto-optimize/alerts', { params: { resolved: false } }).then((r) => r.data.data ?? []),
        staleTime: 60_000,
    });
}

// ─── Mutations ─────────────────────────────────────────────────────────────

export function useAutoOptimizeMutations() {
    const toast = useToast();
    const qc = useQueryClient();

    const invalidateAll = () => {
        qc.invalidateQueries({ queryKey: autoOptimizeKeys.plans() });
        qc.invalidateQueries({ queryKey: ['auto-optimize-items'] });
        qc.invalidateQueries({ queryKey: ['auto-optimize-metrics'] });
        qc.invalidateQueries({ queryKey: ['auto-optimize-alerts'] });
    };

    const savePlan = useMutation({
        mutationFn: (payload) =>
            payload.id
                ? api.patch(`/crm/auto-optimize/plans/${payload.id}`, payload).then((r) => r.data)
                : api.post('/crm/auto-optimize/plans', payload).then((r) => r.data),
        onSuccess: (_, payload) => {
            toast.success(payload.id ? 'Plan updated.' : 'Plan created.');
            invalidateAll();
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Failed to save plan.'),
    });

    const togglePlan = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-optimize/plans/${planId}/toggle`).then((r) => r.data),
        onSuccess: () => { toast.success('Plan toggled.'); invalidateAll(); },
        onError: (err) => toast.error(err?.response?.data?.message || 'Failed to toggle plan.'),
    });

    const autopilotPlan = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-optimize/plans/${planId}/autopilot`).then((r) => r.data),
        onSuccess: () => { toast.success('Autopilot toggled.'); invalidateAll(); },
        onError: (err) => toast.error(err?.response?.data?.message || 'Failed to toggle autopilot.'),
    });

    const runNow = useMutation({
        mutationFn: (planId) => api.post(`/crm/auto-optimize/plans/${planId}/run-now`).then((r) => r.data),
        onSuccess: () => { toast.success('Run dispatched.'); invalidateAll(); },
        onError: (err) => toast.error(err?.response?.data?.message || 'Run failed.'),
    });

    const approve = useMutation({
        mutationFn: (itemId) => api.post(`/crm/auto-optimize/items/${itemId}/approve`).then((r) => r.data),
        onSuccess: (_, itemId) => {
            toast.success('Apply job queued.');
            qc.invalidateQueries({ queryKey: ['auto-optimize-items'] });
            qc.invalidateQueries({ queryKey: autoOptimizeKeys.item(itemId) });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Approve failed.'),
    });

    const approveAll = useMutation({
        mutationFn: (planId) => api.post('/crm/auto-optimize/items/approve-all', { plan_id: planId }).then((r) => r.data),
        onSuccess: (data) => {
            toast.success(data.message || 'All apply jobs queued.');
            qc.invalidateQueries({ queryKey: ['auto-optimize-items'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Approve all failed.'),
    });

    const revert = useMutation({
        mutationFn: ({ itemId, force = false }) =>
            api.post(`/crm/auto-optimize/items/${itemId}/revert`, { force }).then((r) => r.data),
        onSuccess: (_, { itemId }) => {
            toast.success('Reverted successfully.');
            qc.invalidateQueries({ queryKey: ['auto-optimize-items'] });
            qc.invalidateQueries({ queryKey: autoOptimizeKeys.item(itemId) });
            qc.invalidateQueries({ queryKey: ['auto-optimize-metrics'] });
        },
        onError: (err) => {
            if (err?.response?.data?.conflict) {
                toast.error('Revert conflict: WP content changed since apply. Use force to override.');
            } else {
                toast.error(err?.response?.data?.message || 'Revert failed.');
            }
        },
    });

    const skip = useMutation({
        mutationFn: (itemId) => api.post(`/crm/auto-optimize/items/${itemId}/skip`).then((r) => r.data),
        onSuccess: () => {
            toast.success('Item skipped.');
            qc.invalidateQueries({ queryKey: ['auto-optimize-items'] });
        },
        onError: (err) => toast.error(err?.response?.data?.message || 'Skip failed.'),
    });

    const resolveAlert = useMutation({
        mutationFn: (alertId) => api.post(`/crm/auto-optimize/alerts/${alertId}/resolve`).then((r) => r.data),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: autoOptimizeKeys.alerts() });
        },
    });

    return { savePlan, togglePlan, autopilotPlan, runNow, approve, approveAll, revert, skip, resolveAlert, invalidateAll };
}
