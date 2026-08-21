import React, { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import SectionFrame from '../SectionFrame';
import { useToast } from '../ToastProvider';

const METRIC_OPTIONS = [
    { value: '', label: 'Manual completion' },
    { value: 'revenue', label: 'Revenue' },
    { value: 'average_daily_revenue', label: 'Daily average revenue' },
    { value: 'payment_recovery_rate', label: 'Payment recovery rate' },
    { value: 'new_paid_customers', label: 'New paid customers' },
    { value: 'active_subscriber_snapshot', label: 'User snapshot' },
    { value: 'churned_profiles', label: 'Churned profiles' },
    { value: 'lost_value_to_churn', label: 'Lost value to churn' },
    { value: 'team_active_hours', label: 'Team active hours' },
];

const AUDIENCE_OPTIONS = [
    { value: 'all', label: 'Everyone' },
    { value: 'sales', label: 'Sales' },
    { value: 'admin', label: 'Admin' },
    { value: 'ceo', label: 'CEO' },
];

function compactDate(value) {
    if (!value) return 'No date';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'No date';
    return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short' }).format(date);
}

function formatValue(priority) {
    const value = Number(priority.current_value ?? 0);
    const target = Number(priority.target_value ?? 0);
    const metric = priority.metric_key || '';
    const suffix = metric.includes('rate') ? '%' : metric.includes('hours') ? 'h' : '';
    const prefix = metric.includes('revenue') || metric.includes('lost_value') ? `${priority.target_currency || 'USD'} ` : '';

    if (!priority.metric_key || priority.target_value === null || priority.target_value === undefined) {
        return 'Manual';
    }

    return `${prefix}${value.toLocaleString(undefined, { maximumFractionDigits: 1 })}${suffix} / ${prefix}${target.toLocaleString(undefined, { maximumFractionDigits: 1 })}${suffix}`;
}

function toneClasses(level, status, overdue) {
    if (status === 'completed') return 'border-emerald-200 bg-emerald-50/70';
    if (overdue) return 'border-rose-200 bg-rose-50/70';
    if (level === 'critical') return 'border-rose-200 bg-white';
    if (level === 'high') return 'border-amber-200 bg-white';
    return 'border-slate-200 bg-white';
}

function levelDot(level, overdue, completed) {
    if (completed) return 'bg-emerald-500';
    if (overdue || level === 'critical') return 'bg-rose-500';
    if (level === 'high') return 'bg-amber-500';
    return 'bg-teal-600';
}

function priorityTabCounts(priorities) {
    return {
        pending: priorities.filter((item) => item.status === 'pending' && !item.is_overdue).length,
        overdue: priorities.filter((item) => item.is_overdue).length,
        done: priorities.filter((item) => item.status === 'completed').length,
        all: priorities.length,
    };
}

function SelectControl({ className = '', children, ...props }) {
    return (
        <span className="relative block w-full">
            <select
                {...props}
                className={`w-full appearance-none pr-10 ${className}`}
            >
                {children}
            </select>
            <span
                aria-hidden="true"
                className="pointer-events-none absolute right-3 top-1/2 h-2 w-2 -translate-y-[60%] rotate-45 border-b-2 border-r-2 border-slate-500"
            />
        </span>
    );
}

export default function WeeklyPrioritiesPanel({
    title = 'Weekly Priorities',
    subtitle = 'CEO-set operating priorities for this week.',
    audience = 'all',
    allowCreate = false,
    createEndpoint = '/crm/priorities',
    markets = [],
    sourceSuggestion = '',
    compact = false,
}) {
    const queryClient = useQueryClient();
    const toast = useToast();
    const [activeTab, setActiveTab] = useState('pending');
    const [draft, setDraft] = useState({
        title: sourceSuggestion,
        description: '',
        priority_level: 'high',
        audience: audience === 'ceo' ? 'all' : audience,
        platform_id: '',
        completion_mode: 'manual',
        metric_key: '',
        target_operator: 'gte',
        target_value: '',
        target_currency: 'USD',
        due_at: '',
    });

    const query = useQuery({
        queryKey: ['weekly-priorities', audience],
        queryFn: () => api.get('/crm/priorities', { params: { audience } }).then((response) => response.data),
        staleTime: 30_000,
    });

    const createMutation = useMutation({
        mutationFn: (payload) => api.post(createEndpoint, payload).then((response) => response.data?.priority),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['weekly-priorities'] });
            setDraft((current) => ({
                ...current,
                title: '',
                description: '',
                platform_id: '',
                metric_key: '',
                completion_mode: 'manual',
                target_value: '',
                due_at: '',
            }));
            toast.success('Weekly priority created.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to create priority.');
        },
    });

    const updateMutation = useMutation({
        mutationFn: ({ id, payload }) => api.patch(`/crm/priorities/${id}`, payload).then((response) => response.data?.priority),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['weekly-priorities'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to update priority.');
        },
    });

    const priorities = Array.isArray(query.data?.data) ? query.data.data : [];
    const counts = priorityTabCounts(priorities);
    const filtered = useMemo(() => priorities.filter((priority) => {
        if (activeTab === 'all') return priority.status !== 'archived';
        if (activeTab === 'overdue') return priority.is_overdue;
        if (activeTab === 'done') return priority.status === 'completed';
        return priority.status === 'pending' && !priority.is_overdue;
    }), [activeTab, priorities]);

    const submit = (event) => {
        event.preventDefault();
        const titleValue = draft.title.trim();
        if (!titleValue) return;

        const hasMetric = draft.metric_key !== '';
        createMutation.mutate({
            title: titleValue,
            description: draft.description.trim() || null,
            priority_level: draft.priority_level,
            audience: draft.audience || 'all',
            platform_id: draft.platform_id ? Number(draft.platform_id) : null,
            completion_mode: hasMetric ? draft.completion_mode : 'manual',
            metric_key: hasMetric ? draft.metric_key : null,
            target_operator: hasMetric ? draft.target_operator : null,
            target_value: hasMetric && draft.target_value !== '' ? Number(draft.target_value) : null,
            target_currency: hasMetric && (draft.metric_key.includes('revenue') || draft.metric_key.includes('lost_value')) ? draft.target_currency : null,
            due_at: draft.due_at || null,
        });
    };

    const headerAction = allowCreate ? (
        <span className="rounded-full border border-teal-200 bg-teal-50 px-3 py-1 text-xs font-semibold text-teal-700">
            {counts.pending + counts.overdue} open
        </span>
    ) : null;

    return (
        <SectionFrame
            title={title}
            subtitle={subtitle}
            action={headerAction}
            className={compact ? 'crm-sales-panel' : ''}
            contentClassName="space-y-4"
        >
            <div className="flex flex-wrap gap-2">
                {[
                    ['pending', 'Pending', counts.pending],
                    ['overdue', 'Overdue', counts.overdue],
                    ['done', 'Done', counts.done],
                    ['all', 'All', counts.all],
                ].map(([key, label, count]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setActiveTab(key)}
                        className={`rounded-md border px-3 py-1.5 text-sm font-semibold transition active:scale-[0.98] ${activeTab === key ? 'border-slate-900 bg-slate-950 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300'}`}
                    >
                        {label} <span className={activeTab === key ? 'text-slate-300' : 'text-slate-400'}>{count}</span>
                    </button>
                ))}
            </div>

            {allowCreate ? (
                <form onSubmit={submit} className="rounded-xl border border-slate-200 bg-slate-50/80 p-3 sm:p-4">
                    <div className="grid gap-4">
                        <label className="space-y-1">
                            <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Priority</span>
                            <input
                                value={draft.title}
                                onChange={(event) => setDraft((current) => ({ ...current, title: event.target.value }))}
                                className="crm-input bg-white"
                                placeholder="Investigate Ghana decline and assign recovery owner"
                                disabled={createMutation.isPending}
                            />
                        </label>
                        <div className="grid gap-3 lg:grid-cols-2 xl:grid-cols-[minmax(14rem,1.2fr)_minmax(10rem,0.8fr)_minmax(9rem,0.65fr)_minmax(10rem,0.8fr)]">
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Metric link</span>
                                <SelectControl
                                    value={draft.metric_key}
                                    onChange={(event) => setDraft((current) => ({
                                        ...current,
                                        metric_key: event.target.value,
                                        completion_mode: event.target.value ? 'hybrid' : 'manual',
                                    }))}
                                    className="crm-select bg-white"
                                    disabled={createMutation.isPending}
                                >
                                    {METRIC_OPTIONS.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </SelectControl>
                            </label>
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Audience</span>
                                <SelectControl
                                    value={draft.audience}
                                    onChange={(event) => setDraft((current) => ({ ...current, audience: event.target.value }))}
                                    className="crm-select bg-white"
                                    disabled={createMutation.isPending}
                                >
                                    {AUDIENCE_OPTIONS.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </SelectControl>
                            </label>
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Level</span>
                                <SelectControl
                                    value={draft.priority_level}
                                    onChange={(event) => setDraft((current) => ({ ...current, priority_level: event.target.value }))}
                                    className="crm-select bg-white"
                                    disabled={createMutation.isPending}
                                >
                                    <option value="critical">Critical</option>
                                    <option value="high">High</option>
                                    <option value="normal">Normal</option>
                                </SelectControl>
                            </label>
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Due</span>
                                <input
                                    type="date"
                                    value={draft.due_at}
                                    onChange={(event) => setDraft((current) => ({ ...current, due_at: event.target.value }))}
                                    className="crm-input bg-white"
                                    disabled={createMutation.isPending}
                                />
                            </label>
                        </div>
                        <div className="grid gap-3 lg:grid-cols-[minmax(14rem,1fr)_minmax(8rem,0.4fr)_minmax(9rem,0.45fr)_auto] lg:items-end">
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Market</span>
                                <SelectControl
                                    value={draft.platform_id}
                                    onChange={(event) => setDraft((current) => ({ ...current, platform_id: event.target.value }))}
                                    className="crm-select bg-white"
                                    disabled={createMutation.isPending || markets.length === 0}
                                    aria-label="Priority market"
                                >
                                    <option value="">All markets</option>
                                    {markets.map((market) => (
                                        <option key={market.id || market.platform_id} value={market.id || market.platform_id}>
                                            {market.name || market.platform_name}
                                        </option>
                                    ))}
                                </SelectControl>
                            </label>
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Target rule</span>
                                <SelectControl
                                    value={draft.target_operator}
                                    onChange={(event) => setDraft((current) => ({ ...current, target_operator: event.target.value }))}
                                    className="crm-select bg-white"
                                    disabled={createMutation.isPending || !draft.metric_key}
                                    aria-label="Target operator"
                                >
                                    <option value="gte">At least</option>
                                    <option value="lte">At most</option>
                                </SelectControl>
                            </label>
                            <label className="space-y-1">
                                <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Target</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value={draft.target_value}
                                    onChange={(event) => setDraft((current) => ({ ...current, target_value: event.target.value }))}
                                    className="crm-input bg-white"
                                    placeholder="Target"
                                    disabled={createMutation.isPending || !draft.metric_key}
                                />
                            </label>
                            <button type="submit" className="crm-btn-primary min-h-[42px]" disabled={createMutation.isPending || !draft.title.trim()}>
                                {createMutation.isPending ? 'Saving...' : 'Set priority'}
                            </button>
                        </div>
                    </div>
                </form>
            ) : null}

            {query.isLoading ? (
                <div className="space-y-3">
                    {[1, 2, 3].map((item) => <div key={item} className="h-24 animate-pulse rounded-xl bg-slate-100" />)}
                </div>
            ) : filtered.length === 0 ? (
                <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-8 text-center text-sm text-slate-500">
                    No priorities in this tab.
                </div>
            ) : (
                <div className="space-y-3">
                    {filtered.map((priority) => {
                        const completed = priority.status === 'completed';
                        const progress = Number(priority.progress_percent ?? 0);
                        return (
                            <article key={priority.id} className={`rounded-xl border p-4 transition ${toneClasses(priority.priority_level, priority.status, priority.is_overdue)}`}>
                                <div className="flex items-start gap-3">
                                    <button
                                        type="button"
                                        onClick={() => updateMutation.mutate({
                                            id: priority.id,
                                            payload: { status: completed ? 'pending' : 'completed' },
                                        })}
                                        className={`mt-1 h-5 w-5 shrink-0 rounded-full border transition active:scale-[0.92] ${completed ? 'border-emerald-600 bg-emerald-600' : 'border-slate-300 bg-white hover:border-teal-600'}`}
                                        aria-label={completed ? 'Mark priority pending' : 'Mark priority done'}
                                    />
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className={`h-2 w-2 rounded-full ${levelDot(priority.priority_level, priority.is_overdue, completed)}`} />
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-600">
                                                {priority.priority_level}
                                            </span>
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                                {priority.platform?.name || 'All markets'}
                                            </span>
                                            {priority.is_overdue ? (
                                                <span className="rounded-full border border-rose-200 bg-rose-50 px-2.5 py-1 text-[11px] font-semibold text-rose-700">
                                                    Overdue
                                                </span>
                                            ) : null}
                                        </div>
                                        <h4 className={`mt-2 text-base font-semibold leading-6 ${completed ? 'text-slate-500 line-through' : 'text-slate-950'}`}>
                                            {priority.title}
                                        </h4>
                                        {priority.description ? <p className="mt-1 text-sm leading-6 text-slate-600">{priority.description}</p> : null}
                                        <div className="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                            <span>{priority.audience === 'all' ? 'Everyone' : priority.audience}</span>
                                            <span>Due {compactDate(priority.due_at || priority.week_end)}</span>
                                            <span>{formatValue(priority)}</span>
                                        </div>
                                        {priority.metric_key ? (
                                            <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                                <div className="h-full rounded-full bg-teal-600" style={{ width: `${Math.max(4, Math.min(100, progress))}%` }} />
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
        </SectionFrame>
    );
}
