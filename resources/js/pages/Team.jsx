import React, { startTransition, useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api from '../services/api';
import PageHeader from '../components/PageHeader';
import MetricCard from '../components/MetricCard';
import SectionFrame from '../components/SectionFrame';
import DataTable from '../components/DataTable';
import FilterSelect from '../components/FilterSelect';
import ConfirmDialog from '../components/ConfirmDialog';
import { useToast } from '../components/ToastProvider';
import { useAuth } from '../hooks/useAuth';
import { getCountryFlag, platformOptionsWithFlags } from '../utils/flags';

const TEAM_PERIOD_STORAGE_KEY = 'exoticcrm.team.period';
const DEFAULT_PERIOD = 'week';
const DEFAULT_GOAL_PERIOD = 'weekly';
const DEFAULT_GOAL_ROLE_SCOPE = 'sales';
const DEFAULT_LEADERBOARD_ROLE_FILTER = 'all';
const PERIOD_OPTIONS = [
    { value: 'today', label: 'Today' },
    { value: 'week', label: 'This Week' },
    { value: 'month', label: 'This Month' },
];
const GOAL_PERIOD_OPTIONS = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'monthly', label: 'Monthly' },
];
const GOAL_ROLE_SCOPE_OPTIONS = [
    { value: 'sales', label: 'Sales only' },
    { value: 'marketing', label: 'Marketing only' },
    { value: 'all', label: 'Everyone' },
];
const LEADERBOARD_ROLE_FILTER_OPTIONS = [
    { value: 'all', label: 'All roles' },
    { value: 'admin', label: 'Admin' },
    { value: 'sub_admin', label: 'Sub-admin' },
    { value: 'sales', label: 'Sales' },
    { value: 'marketing', label: 'Marketing' },
];
const SUB_ADMIN_LEADERBOARD_ROLE_FILTER_OPTIONS = LEADERBOARD_ROLE_FILTER_OPTIONS.filter(
    (option) => ['all', 'sales', 'marketing'].includes(option.value),
);

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function normalizePeriod(value) {
    return PERIOD_OPTIONS.some((option) => option.value === value) ? value : DEFAULT_PERIOD;
}

function normalizeGoalPeriod(value) {
    return GOAL_PERIOD_OPTIONS.some((option) => option.value === value) ? value : DEFAULT_GOAL_PERIOD;
}

function normalizeGoalRoleScope(value) {
    return GOAL_ROLE_SCOPE_OPTIONS.some((option) => option.value === value) ? value : DEFAULT_GOAL_ROLE_SCOPE;
}

function normalizeLeaderboardRoleFilter(value, options = LEADERBOARD_ROLE_FILTER_OPTIONS) {
    return options.some((option) => option.value === value) ? value : DEFAULT_LEADERBOARD_ROLE_FILTER;
}

function normalizePlatformFilter(value) {
    const raw = String(value ?? '').trim();
    if (raw === '') {
        return '';
    }

    return /^\d+$/.test(raw) ? raw : '';
}

function toInputDateString(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function getPeriodDateRange(period) {
    const now = new Date();
    const end = new Date(now);
    const start = new Date(now);

    if (period === 'today') {
        return {
            from: toInputDateString(start),
            to: toInputDateString(end),
        };
    }

    if (period === 'month') {
        start.setDate(1);
        return {
            from: toInputDateString(start),
            to: toInputDateString(end),
        };
    }

    const day = start.getDay();
    const offset = day === 0 ? 6 : day - 1;
    start.setDate(start.getDate() - offset);

    return {
        from: toInputDateString(start),
        to: toInputDateString(end),
    };
}

function periodLabel(period) {
    return PERIOD_OPTIONS.find((option) => option.value === period)?.label || 'This Week';
}

function goalPeriodLabel(period) {
    return GOAL_PERIOD_OPTIONS.find((option) => option.value === period)?.label || 'Weekly';
}

function leaderboardRoleFilterLabel(filter, options = LEADERBOARD_ROLE_FILTER_OPTIONS) {
    return options.find((option) => option.value === filter)?.label || 'All roles';
}

function comparisonLabel(period) {
    if (period === 'today') {
        return 'earlier today';
    }
    if (period === 'month') {
        return 'last month';
    }

    return 'last week';
}

function formatDuration(seconds) {
    const total = asNumber(seconds);
    if (total <= 0) {
        return '0m';
    }

    const hours = Math.floor(total / 3600);
    const minutes = Math.floor((total % 3600) / 60);

    if (hours > 0) {
        return `${hours}h ${minutes}m`;
    }

    if (minutes > 0) {
        return `${minutes}m`;
    }

    return `${total}s`;
}

function formatRelativeTime(value) {
    if (!value) {
        return 'Never';
    }

    const timestamp = new Date(value).getTime();
    if (Number.isNaN(timestamp)) {
        return 'Unknown';
    }

    const deltaSeconds = Math.max(0, Math.floor((Date.now() - timestamp) / 1000));
    if (deltaSeconds < 60) {
        return 'Just now';
    }

    const deltaMinutes = Math.floor(deltaSeconds / 60);
    if (deltaMinutes < 60) {
        return `${deltaMinutes}m ago`;
    }

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) {
        return `${deltaHours}h ago`;
    }

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}

function formatDateTime(value) {
    if (!value) {
        return '--';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '--';
    }

    return date.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

function formatRole(role) {
    return String(role || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (match) => match.toUpperCase()) || 'Team member';
}

function formatCount(value) {
    return asNumber(value).toLocaleString();
}

function formatTrendMetricValue(metricKey, value) {
    if (metricKey === 'active_seconds') {
        return formatDuration(value);
    }

    return formatCount(value);
}

function formatCurrencyRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return ['--'];
    }

    return rows.map((row) => `${row.currency} ${asNumber(row.amount).toLocaleString()}`);
}

function formatTrendText(trend, period) {
    if (!trend) {
        return `-- vs ${comparisonLabel(period)}`;
    }

    const percentageChange = asNumber(trend.percentage_change);

    if (trend.direction === 'flat') {
        return `Flat vs ${comparisonLabel(period)}`;
    }

    return `${percentageChange > 0 ? '+' : ''}${percentageChange}% vs ${comparisonLabel(period)}`;
}

function trendTone(direction) {
    if (direction === 'up') {
        return 'text-emerald-700 bg-emerald-50 border-emerald-200';
    }
    if (direction === 'down') {
        return 'text-rose-700 bg-rose-50 border-rose-200';
    }

    return 'text-slate-600 bg-slate-50 border-slate-200';
}

function trendArrow(direction) {
    if (direction === 'up') {
        return '↑';
    }
    if (direction === 'down') {
        return '↓';
    }

    return '→';
}

function percentage(current, target) {
    if (!target) {
        return 0;
    }

    return Math.max(0, Math.min(100, Math.round((asNumber(current) / asNumber(target)) * 100)));
}

function averageGoalCompletion(goals) {
    const progressRows = goalProgressRows(goals);
    if (progressRows.length === 0) {
        return null;
    }

    const total = progressRows.reduce((sum, row) => sum + asNumber(row.percentage), 0);
    return Math.round(total / progressRows.length);
}

function goalCoverage(goals) {
    const progressRows = goalProgressRows(goals);
    if (progressRows.length === 0) {
        return 'No goals set';
    }

    const completed = progressRows.filter((row) => asNumber(row.current) >= asNumber(row.target)).length;
    return `${completed}/${progressRows.length} targets met`;
}

function goalProgressRows(goals) {
    return (goals || []).flatMap((goal) => {
        if (Array.isArray(goal.progress)) {
            return goal.progress;
        }

        return goal.progress ? [goal.progress] : [];
    });
}

function goalSourceLabel(sourceType) {
    if (sourceType === 'override') {
        return 'Manager override';
    }

    return 'Team default';
}

function roleScopeLabel(goal) {
    return goal?.role_scope_label || '';
}

function getApiErrorMessage(error, fallback) {
    return error?.response?.data?.message || fallback;
}

function toCsvCell(value) {
    const stringValue = String(value ?? '');
    if (/[",\n]/.test(stringValue)) {
        return `"${stringValue.replace(/"/g, '""')}"`;
    }

    return stringValue;
}

function toCsvRow(values) {
    return values.map((value) => toCsvCell(value)).join(',');
}

function downloadCsv(filename, rows) {
    const csvContent = rows.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.setAttribute('download', filename);
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function TeamEmptyState({ title, message }) {
    return (
        <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
            <p className="text-sm font-semibold text-slate-700">{title}</p>
            <p className="mt-1 text-sm text-slate-500">{message}</p>
        </div>
    );
}

function TeamErrorState({ message, onRetry }) {
    return (
        <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-4">
            <p className="text-sm font-semibold text-rose-800">We couldn’t load this section.</p>
            <p className="mt-1 text-sm text-rose-700">{message}</p>
            {onRetry ? (
                <button type="button" onClick={onRetry} className="mt-3 crm-btn-secondary px-3 py-1.5 text-xs">
                    Try again
                </button>
            ) : null}
        </div>
    );
}

function TeamSkeletonCards({ count = 4 }) {
    return (
        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {Array.from({ length: count }).map((_, index) => (
                <div key={index} className="crm-kpi animate-pulse">
                    <div className="h-3 w-24 rounded bg-slate-200" />
                    <div className="mt-3 h-8 w-16 rounded bg-slate-200" />
                    <div className="mt-3 h-3 w-32 rounded bg-slate-100" />
                </div>
            ))}
        </div>
    );
}

function TabButton({ active, children, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`rounded-full border px-3.5 py-2 text-sm font-medium transition ${
                active
                    ? 'border-teal-300 bg-teal-50 text-teal-800 shadow-sm'
                    : 'border-slate-200 bg-white text-slate-600 hover:border-slate-300 hover:text-slate-900'
            }`}
        >
            {children}
        </button>
    );
}

function PresenceStatus({ isOnline, lastSeenAt }) {
    if (isOnline) {
        return (
            <span className="inline-flex items-center gap-2 text-sm font-medium text-emerald-700">
                <span className="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500 animate-pulse" aria-hidden="true" />
                Online now
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-2 text-sm text-slate-500">
            <span className="inline-flex h-2.5 w-2.5 rounded-full bg-slate-300" aria-hidden="true" />
            Last seen {formatRelativeTime(lastSeenAt)}
        </span>
    );
}

function TrendBadge({ trend, period }) {
    return (
        <div className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-medium ${trendTone(trend?.direction)}`}>
            <span aria-hidden="true">{trendArrow(trend?.direction)}</span>
            <span>{formatTrendText(trend, period)}</span>
        </div>
    );
}

function GoalProgressBar({ current, target }) {
    const progress = percentage(current, target);

    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-3 text-xs text-slate-500">
                <span>{formatCount(current)}/{formatCount(target)}</span>
                <span>{progress}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full bg-teal-600" style={{ width: `${progress}%` }} />
            </div>
        </div>
    );
}

function ActivityList({ items, emptyTitle, emptyMessage }) {
    if (!items?.length) {
        return <TeamEmptyState title={emptyTitle} message={emptyMessage} />;
    }

    return (
        <div className="space-y-3">
            {items.map((item) => (
                <article key={item.id} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                    <div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-slate-900">{item.label}</p>
                            <p className="mt-1 text-xs text-slate-500">
                                {item.entity_type ? `${item.entity_type} #${item.entity_id}` : 'Operational activity'}
                                {item.reason ? ` • ${item.reason}` : ''}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                            <span>{formatDateTime(item.created_at)}</span>
                            {item.entity_url ? (
                                <Link className="font-semibold text-teal-700 hover:text-teal-800" to={item.entity_url}>
                                    Open record
                                </Link>
                            ) : null}
                        </div>
                    </div>
                </article>
            ))}
        </div>
    );
}

function createLeaderboardRowsCsv(rows) {
    const csvRows = [
        toCsvRow([
            'Rank',
            'Agent',
            'Role',
            'Revenue',
            'Revenue Breakdown JSON',
            'Subs Activated',
            'Subs Renewed',
            'Payments Matched',
            'Leads Contacted',
            'Chats',
            'SMS',
            'Total Actions',
        ]),
    ];

    rows.forEach((row) => {
        csvRows.push(toCsvRow([
            row.rank,
            row.name,
            formatRole(row.role),
            row.revenue_display,
            JSON.stringify(row.revenue_by_currency || []),
            row.subs_activated,
            row.subs_renewed,
            row.payments_matched,
            row.leads_contacted,
            row.chats_replied,
            row.sms_sent,
            row.total_actions,
        ]));
    });

    return csvRows;
}

export default function Team() {
    const { user } = useAuth();
    const toast = useToast();
    const queryClient = useQueryClient();
    const isManager = user?.role === 'admin' || user?.role === 'sub_admin';
    const isAdmin = user?.role === 'admin';
    const [period, setPeriod] = useState(() => {
        if (typeof window === 'undefined') {
            return DEFAULT_PERIOD;
        }

        return normalizePeriod(window.localStorage.getItem(TEAM_PERIOD_STORAGE_KEY));
    });
    const [platformFilter, setPlatformFilter] = useState('');
    const [activeTab, setActiveTab] = useState(() => (isManager ? 'presence' : 'my-stats'));
    const [leaderboardRoleFilter, setLeaderboardRoleFilter] = useState(DEFAULT_LEADERBOARD_ROLE_FILTER);
    const [goalPeriod, setGoalPeriod] = useState(DEFAULT_GOAL_PERIOD);
    const [goalRoleScope, setGoalRoleScope] = useState(DEFAULT_GOAL_ROLE_SCOPE);
    const [goalMetric, setGoalMetric] = useState('subs_activated');
    const [goalTarget, setGoalTarget] = useState('');
    const [goalOverrideAssigneeId, setGoalOverrideAssigneeId] = useState('');
    const [goalOverrideMetric, setGoalOverrideMetric] = useState('subs_activated');
    const [goalOverrideTarget, setGoalOverrideTarget] = useState('');
    const [selectedAgent, setSelectedAgent] = useState(null);
    const [goalToDelete, setGoalToDelete] = useState(null);
    const [goalOverrideToDelete, setGoalOverrideToDelete] = useState(null);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        window.localStorage.setItem(TEAM_PERIOD_STORAGE_KEY, period);
    }, [period]);

    useEffect(() => {
        if (!isManager) {
            setActiveTab('my-stats');
        }
    }, [isManager]);

    const availableLeaderboardRoleFilters = useMemo(
        () => (isAdmin ? LEADERBOARD_ROLE_FILTER_OPTIONS : SUB_ADMIN_LEADERBOARD_ROLE_FILTER_OPTIONS),
        [isAdmin],
    );

    useEffect(() => {
        setLeaderboardRoleFilter((currentValue) => normalizeLeaderboardRoleFilter(currentValue, availableLeaderboardRoleFilters));
    }, [availableLeaderboardRoleFilters]);

    useEffect(() => {
        if (activeTab === 'agent-detail' && !selectedAgent) {
            setActiveTab(isManager ? 'presence' : 'my-stats');
        }
    }, [activeTab, isManager, selectedAgent]);

    const myStatsQuery = useQuery({
        queryKey: ['team', 'me', period, platformFilter || 'all'],
        queryFn: () =>
            api.get('/crm/team/me', {
                params: {
                    period,
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchOnWindowFocus: false,
    });

    const platformOptions = myStatsQuery.data?.platforms || [];
    const managerPlatformOptions = useMemo(
        () => platformOptionsWithFlags(platformOptions, 'All accessible markets'),
        [platformOptions],
    );

    useEffect(() => {
        if (!platformFilter || !platformOptions.length) {
            return;
        }

        const stillAccessible = platformOptions.some(
            (platform) => String(platform.platform_id) === String(platformFilter),
        );

        if (!stillAccessible) {
            setPlatformFilter('');
        }
    }, [platformFilter, platformOptions]);

    const presenceQuery = useQuery({
        enabled: isManager,
        queryKey: ['team', 'presence', platformFilter || 'all'],
        queryFn: () =>
            api.get('/crm/team/presence', {
                params: {
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchInterval: isManager ? 30_000 : false,
        refetchOnWindowFocus: false,
    });

    const leaderboardQuery = useQuery({
        enabled: isManager && activeTab === 'leaderboard',
        queryKey: ['team', 'leaderboard', period, platformFilter || 'all', leaderboardRoleFilter],
        queryFn: () =>
            api.get('/crm/team/leaderboard', {
                params: {
                    period,
                    role_filter: leaderboardRoleFilter,
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchInterval: isManager && activeTab === 'leaderboard' && period === 'today' ? 30_000 : false,
        refetchOnWindowFocus: false,
    });

    const goalsQuery = useQuery({
        enabled: isManager,
        queryKey: ['team', 'goals', goalPeriod, platformFilter || 'all'],
        queryFn: () =>
            api.get('/crm/team/goals', {
                params: {
                    period: goalPeriod,
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchOnWindowFocus: false,
    });

    const availableGoalMetrics = goalsQuery.data?.available_metrics || [];
    const availableRoleScopes = goalsQuery.data?.role_scopes || GOAL_ROLE_SCOPE_OPTIONS;
    const assignableAgents = goalsQuery.data?.assignable_agents || [];
    const defaultGoals = goalsQuery.data?.defaults || goalsQuery.data?.data || [];
    const individualGoals = goalsQuery.data?.overrides || [];

    const defaultGoalMetricOptions = useMemo(
        () => availableGoalMetrics.filter((metric) => (metric.allowed_role_scopes || []).includes(goalRoleScope)),
        [availableGoalMetrics, goalRoleScope],
    );

    const selectedOverrideAssignee = useMemo(
        () => assignableAgents.find((agent) => String(agent.user_id) === String(goalOverrideAssigneeId)) || null,
        [assignableAgents, goalOverrideAssigneeId],
    );

    const individualGoalMetricOptions = useMemo(() => {
        if (!selectedOverrideAssignee) {
            return availableGoalMetrics.filter((metric) => (metric.allowed_role_scopes || []).includes('sales'));
        }

        return availableGoalMetrics.filter((metric) => {
            const scopes = metric.allowed_role_scopes || [];
            return scopes.includes(selectedOverrideAssignee.role) || scopes.includes('all');
        });
    }, [availableGoalMetrics, selectedOverrideAssignee]);

    useEffect(() => {
        if (!defaultGoalMetricOptions.length) {
            return;
        }

        const isValid = defaultGoalMetricOptions.some((metric) => metric.value === goalMetric);
        if (!isValid) {
            setGoalMetric(defaultGoalMetricOptions[0].value);
        }
    }, [defaultGoalMetricOptions, goalMetric]);

    useEffect(() => {
        if (!individualGoalMetricOptions.length) {
            return;
        }

        const isValid = individualGoalMetricOptions.some((metric) => metric.value === goalOverrideMetric);
        if (!isValid) {
            setGoalOverrideMetric(individualGoalMetricOptions[0].value);
        }
    }, [goalOverrideMetric, individualGoalMetricOptions]);

    useEffect(() => {
        if (goalOverrideAssigneeId || !assignableAgents.length) {
            return;
        }

        setGoalOverrideAssigneeId(String(assignableAgents[0].user_id));
    }, [assignableAgents, goalOverrideAssigneeId]);

    const agentDateRange = useMemo(() => getPeriodDateRange(period), [period]);

    const agentStatsQuery = useQuery({
        enabled: isManager && activeTab === 'agent-detail' && Boolean(selectedAgent?.user_id),
        queryKey: ['team', 'agent-detail', selectedAgent?.user_id, period, platformFilter || 'all'],
        queryFn: () =>
            api.get(`/crm/team/${selectedAgent.user_id}/stats`, {
                params: {
                    from: agentDateRange.from,
                    to: agentDateRange.to,
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchOnWindowFocus: false,
    });

    const agentActivityQuery = useQuery({
        enabled: isManager && activeTab === 'agent-detail' && Boolean(selectedAgent?.user_id),
        queryKey: ['team', 'agent-activity', selectedAgent?.user_id, period, agentDateRange.from, agentDateRange.to, platformFilter || 'all'],
        queryFn: () =>
            api.get(`/crm/team/${selectedAgent.user_id}/activity`, {
                params: {
                    from: agentDateRange.from,
                    to: agentDateRange.to,
                    ...(platformFilter ? { platform_id: Number(platformFilter) } : {}),
                },
            }).then((response) => response.data),
        placeholderData: keepPreviousData,
        refetchInterval: activeTab === 'agent-detail' && period === 'today' ? 30_000 : false,
        refetchOnWindowFocus: false,
    });

    const createGoalMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/team/goals', payload).then((response) => response.data),
        onSuccess: () => {
            toast.success('Default goal saved successfully.');
            setGoalTarget('');
            queryClient.invalidateQueries({ queryKey: ['team', 'goals'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'me'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'agent-detail'] });
        },
        onError: (error) => {
            toast.error(getApiErrorMessage(error, 'We could not save that goal.'));
        },
    });

    const createGoalOverrideMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/team/goals/overrides', payload).then((response) => response.data),
        onSuccess: () => {
            toast.success('Individual goal saved successfully.');
            setGoalOverrideTarget('');
            queryClient.invalidateQueries({ queryKey: ['team', 'goals'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'me'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'agent-detail'] });
        },
        onError: (error) => {
            toast.error(getApiErrorMessage(error, 'We could not save that individual goal.'));
        },
    });

    const deleteGoalMutation = useMutation({
        mutationFn: (goalId) => api.delete(`/crm/team/goals/${goalId}`),
        onSuccess: () => {
            toast.success('Default goal removed.');
            setGoalToDelete(null);
            queryClient.invalidateQueries({ queryKey: ['team', 'goals'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'me'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'agent-detail'] });
        },
        onError: (error) => {
            toast.error(getApiErrorMessage(error, 'We could not delete that goal.'));
        },
    });

    const deleteGoalOverrideMutation = useMutation({
        mutationFn: (goalId) => api.delete(`/crm/team/goals/overrides/${goalId}`),
        onSuccess: () => {
            toast.success('Individual goal removed.');
            setGoalOverrideToDelete(null);
            queryClient.invalidateQueries({ queryKey: ['team', 'goals'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'me'] });
            queryClient.invalidateQueries({ queryKey: ['team', 'agent-detail'] });
        },
        onError: (error) => {
            toast.error(getApiErrorMessage(error, 'We could not delete that individual goal.'));
        },
    });

    const selectedPlatformLabel = useMemo(() => {
        if (!platformFilter) {
            return 'All accessible markets';
        }

        const platform = platformOptions.find(
            (item) => String(item.platform_id) === String(platformFilter),
        );

        if (!platform) {
            return 'Selected market';
        }

        return `${getCountryFlag(platform.country)} ${platform.platform_name}`;
    }, [platformFilter, platformOptions]);
    const selectedLeaderboardRoleLabel = useMemo(
        () => leaderboardRoleFilterLabel(leaderboardRoleFilter, availableLeaderboardRoleFilters),
        [availableLeaderboardRoleFilters, leaderboardRoleFilter],
    );

    const presenceRows = presenceQuery.data?.data || [];
    const leaderboardRows = leaderboardQuery.data?.data || [];
    const mySummary = myStatsQuery.data?.summary || {};
    const myTrend = myStatsQuery.data?.trend || {};
    const myGoals = myStatsQuery.data?.goals || [];
    const myActivity = myStatsQuery.data?.activity || [];
    const agentSummary = agentStatsQuery.data?.summary || {};
    const agentTrend = agentStatsQuery.data?.trend || {};
    const agentGoals = agentStatsQuery.data?.goals || [];
    const agentActivity = agentActivityQuery.data?.data || [];
    const managerGoals = useMemo(() => [...defaultGoals, ...individualGoals], [defaultGoals, individualGoals]);

    const topLevelManagerMetrics = useMemo(() => [
        {
            label: 'Online Now',
            value: formatCount(presenceQuery.data?.summary?.online_now),
            meta: `${formatCount(presenceQuery.data?.summary?.active_today)} active today`,
            tone: 'success',
        },
        {
            label: 'Active Today',
            value: formatCount(presenceQuery.data?.summary?.active_today),
            meta: 'Agents with tracked work today',
            tone: 'accent',
        },
        {
            label: 'Total Actions Today',
            value: formatCount(presenceQuery.data?.summary?.total_actions_today),
            meta: 'Live action count across visible team members',
            tone: 'warning',
        },
        {
            label: 'Goal Completion',
            value: averageGoalCompletion(managerGoals) === null ? '--' : `${averageGoalCompletion(managerGoals)}%`,
            meta: goalCoverage(managerGoals),
            tone: 'slate',
        },
    ], [managerGoals, presenceQuery.data]);

    const myMetricCards = useMemo(() => [
        {
            label: 'Revenue',
            value: mySummary.revenue_display || '--',
            meta: 'Activated subscription value',
            tone: 'accent',
        },
        {
            label: 'Total Actions',
            value: formatCount(mySummary.total_actions),
            meta: formatTrendText(myTrend.total_actions, period),
            tone: 'slate',
        },
        {
            label: 'Subs Activated',
            value: formatCount(mySummary.subs_activated),
            meta: formatTrendText(myTrend.subs_activated, period),
            tone: 'success',
        },
        {
            label: 'Payments Matched',
            value: formatCount(mySummary.payments_matched),
            meta: formatTrendText(myTrend.payments_matched, period),
            tone: 'warning',
        },
        {
            label: 'Leads Contacted',
            value: formatCount(mySummary.leads_contacted),
            meta: formatTrendText(myTrend.leads_contacted, period),
            tone: 'accent',
        },
        {
            label: 'Active Time',
            value: formatDuration(mySummary.active_seconds),
            meta: formatTrendText(myTrend.active_seconds, period),
            tone: 'neutral',
        },
    ], [mySummary, myTrend, period]);

    const agentMetricCards = useMemo(() => [
        {
            label: 'Revenue',
            value: agentSummary.revenue_display || '--',
            meta: 'Activated subscription value',
            tone: 'accent',
        },
        {
            label: 'Total Actions',
            value: formatCount(agentSummary.total_actions),
            meta: formatTrendText(agentTrend.total_actions, period),
            tone: 'slate',
        },
        {
            label: 'Subs Activated',
            value: formatCount(agentSummary.subs_activated),
            meta: formatTrendText(agentTrend.subs_activated, period),
            tone: 'success',
        },
        {
            label: 'Payments Matched',
            value: formatCount(agentSummary.payments_matched),
            meta: formatTrendText(agentTrend.payments_matched, period),
            tone: 'warning',
        },
        {
            label: 'Leads Contacted',
            value: formatCount(agentSummary.leads_contacted),
            meta: formatTrendText(agentTrend.leads_contacted, period),
            tone: 'accent',
        },
        {
            label: 'Active Time',
            value: formatDuration(agentSummary.active_seconds),
            meta: formatTrendText(agentTrend.active_seconds, period),
            tone: 'neutral',
        },
    ], [agentSummary, agentTrend, period]);

    const trendHighlights = useMemo(() => [
        {
            key: 'total_actions',
            label: 'Total Actions',
        },
        {
            key: 'subs_activated',
            label: 'Subs Activated',
        },
        {
            key: 'payments_matched',
            label: 'Payments Matched',
        },
        {
            key: 'active_seconds',
            label: 'Active Time',
        },
    ], []);

    const onlineAgents = presenceRows.filter((row) => row.is_online);
    const offlineAgents = presenceRows.filter((row) => !row.is_online);

    const leaderboardColumns = useMemo(() => [
        {
            key: 'rank',
            label: 'Rank',
            width: 84,
            render: (row) => {
                const styles = [
                    'inline-flex h-9 w-9 items-center justify-center rounded-full border text-sm font-semibold',
                    row.rank === 1 ? 'border-amber-300 bg-amber-50 text-amber-800' : '',
                    row.rank === 2 ? 'border-slate-300 bg-slate-50 text-slate-700' : '',
                    row.rank === 3 ? 'border-orange-300 bg-orange-50 text-orange-800' : '',
                    row.rank > 3 ? 'border-slate-200 bg-white text-slate-700' : '',
                ].filter(Boolean).join(' ');

                return <span className={styles}>{row.rank}</span>;
            },
        },
        {
            key: 'name',
            label: 'Agent',
            headerClassName: 'min-w-[220px]',
            cellClassName: '!whitespace-normal',
            render: (row) => (
                <div className="flex items-start gap-3">
                    <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-teal-500 to-cyan-500 text-sm font-semibold text-white">
                        {row.name?.charAt(0) || 'A'}
                    </span>
                    <div className="min-w-0">
                        <p className="font-semibold text-slate-900">{row.name}</p>
                        <p className="mt-1 text-xs text-slate-500">{formatRole(row.role)}</p>
                        {period === 'today' ? (
                            <div className="mt-1">
                                <PresenceStatus isOnline={row.is_online} />
                            </div>
                        ) : null}
                    </div>
                </div>
            ),
        },
        {
            key: 'revenue_display',
            label: 'Revenue',
            headerClassName: 'text-right',
            cellClassName: '!whitespace-normal text-right',
            render: (row) => (
                <div className="space-y-1 text-right">
                    {formatCurrencyRows(row.revenue_by_currency).map((value) => (
                        <p key={value} className="crm-mono text-sm font-semibold text-slate-800">{value}</p>
                    ))}
                </div>
            ),
        },
        {
            key: 'subs_activated',
            label: 'Subs Activated',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.subs_activated),
        },
        {
            key: 'subs_renewed',
            label: 'Subs Renewed',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.subs_renewed),
        },
        {
            key: 'payments_matched',
            label: 'Payments Matched',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.payments_matched),
        },
        {
            key: 'leads_contacted',
            label: 'Leads Contacted',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.leads_contacted),
        },
        {
            key: 'chats_replied',
            label: 'Chats',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.chats_replied),
        },
        {
            key: 'sms_sent',
            label: 'SMS',
            headerClassName: 'hidden lg:table-cell text-right',
            cellClassName: 'hidden lg:table-cell text-right crm-mono font-medium',
            render: (row) => formatCount(row.sms_sent),
        },
        {
            key: 'total_actions',
            label: 'Total Actions',
            headerClassName: 'text-right',
            cellClassName: 'text-right crm-mono font-semibold text-slate-900',
            render: (row) => formatCount(row.total_actions),
        },
    ], [period]);

    const managerTabs = useMemo(() => {
        const items = [
            { key: 'presence', label: 'Presence' },
            { key: 'leaderboard', label: 'Leaderboard' },
            { key: 'my-stats', label: 'My Stats' },
            { key: 'goals', label: 'Goals' },
        ];

        if (selectedAgent) {
            items.push({ key: 'agent-detail', label: 'Member Detail' });
        }

        return items;
    }, [selectedAgent]);

    const handleSelectAgent = (agent) => {
        startTransition(() => {
            setSelectedAgent(agent);
            setActiveTab('agent-detail');
        });
    };

    const handleExportLeaderboard = () => {
        if (!leaderboardRows.length) {
            return;
        }

        const filename = `team-leaderboard-${period}-${platformFilter || 'all'}-${leaderboardRoleFilter}.csv`;
        downloadCsv(filename, createLeaderboardRowsCsv(leaderboardRows));
    };

    const handleCreateGoal = () => {
        const target = Number(goalTarget);
        if (!goalMetric || !Number.isFinite(target) || target < 1) {
            toast.warning('Set a metric and a target greater than zero.');
            return;
        }

        createGoalMutation.mutate({
            metric: goalMetric,
            target,
            period: goalPeriod,
            platform_id: platformFilter ? Number(platformFilter) : null,
            role_scope: goalRoleScope,
        });
    };

    const handleCreateGoalOverride = () => {
        const target = Number(goalOverrideTarget);
        if (!goalOverrideAssigneeId) {
            toast.warning('Select a team member for the individual goal.');
            return;
        }

        if (!platformFilter) {
            toast.warning('Choose a market before assigning an individual goal.');
            return;
        }

        if (!goalOverrideMetric || !Number.isFinite(target) || target < 1) {
            toast.warning('Set a metric and a target greater than zero.');
            return;
        }

        createGoalOverrideMutation.mutate({
            user_id: Number(goalOverrideAssigneeId),
            metric: goalOverrideMetric,
            target,
            period: goalPeriod,
            platform_id: Number(platformFilter),
        });
    };

    const pageActions = (
        <>
            <FilterSelect
                label="Period"
                value={period}
                onChange={(event) => setPeriod(normalizePeriod(event.target.value))}
                options={PERIOD_OPTIONS}
                className="min-w-[11rem]"
            />
            {isManager ? (
                <FilterSelect
                    label="Market"
                    value={platformFilter}
                    onChange={(event) => setPlatformFilter(normalizePlatformFilter(event.target.value))}
                    options={managerPlatformOptions}
                    className="min-w-[14rem]"
                />
            ) : null}
        </>
    );

    return (
        <div className="space-y-6">
            <PageHeader
                title="Team"
                subtitle={isManager
                    ? 'Live presence, performance, and coaching signals across your team.'
                    : 'Track your progress, goals, and recent activity.'}
                actions={pageActions}
            />

            {isManager ? (
                presenceQuery.isLoading && !presenceQuery.data ? (
                    <TeamSkeletonCards />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {topLevelManagerMetrics.map((metric) => (
                            <MetricCard
                                key={metric.label}
                                label={metric.label}
                                value={metric.value}
                                meta={metric.meta}
                                tone={metric.tone}
                            />
                        ))}
                    </div>
                )
            ) : (
                myStatsQuery.isLoading && !myStatsQuery.data ? (
                    <TeamSkeletonCards count={4} />
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {myMetricCards.slice(0, 4).map((metric) => (
                            <MetricCard
                                key={metric.label}
                                label={metric.label}
                                value={metric.value}
                                meta={metric.meta}
                                tone={metric.tone}
                            />
                        ))}
                    </div>
                )
            )}

            {isManager ? (
                <section className="crm-surface overflow-hidden">
                    <div className="flex gap-2 overflow-x-auto px-4 py-3">
                        {managerTabs.map((tab) => (
                            <TabButton
                                key={tab.key}
                                active={activeTab === tab.key}
                                onClick={() => setActiveTab(tab.key)}
                            >
                                {tab.label}
                            </TabButton>
                        ))}
                    </div>
                </section>
            ) : (
                <section className="crm-surface px-4 py-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">My Stats</p>
                </section>
            )}

            {activeTab === 'presence' ? (
                <>
                    <SectionFrame
                        title="Online now"
                        subtitle={`Team members with a visible CRM session in ${selectedPlatformLabel}.`}
                    >
                        {presenceQuery.isLoading && !presenceQuery.data ? (
                            <TeamSkeletonCards count={3} />
                        ) : presenceQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(presenceQuery.error, 'Presence could not be loaded.')}
                                onRetry={() => presenceQuery.refetch()}
                            />
                        ) : onlineAgents.length === 0 ? (
                            <TeamEmptyState
                                title="No one is online right now"
                                message="Active sessions will appear here when team members have the CRM open in a visible window."
                            />
                        ) : (
                            <div className="grid gap-3 lg:grid-cols-2">
                                {onlineAgents.map((agent) => (
                                    <button
                                        key={agent.user_id}
                                        type="button"
                                        onClick={() => handleSelectAgent(agent)}
                                        className="rounded-xl border border-slate-200 bg-white p-4 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    >
                                        <div className="flex items-start gap-3">
                                            <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-teal-500 to-cyan-500 text-base font-semibold text-white">
                                                {agent.name?.charAt(0) || 'A'}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <div>
                                                        <p className="font-semibold text-slate-900">{agent.name}</p>
                                                        <p className="text-xs text-slate-500">{formatRole(agent.role)}</p>
                                                    </div>
                                                    <PresenceStatus isOnline={agent.is_online} lastSeenAt={agent.last_seen_at} />
                                                </div>
                                                <div className="mt-3 grid gap-2 text-sm text-slate-600 sm:grid-cols-2">
                                                    <p><span className="font-medium text-slate-800">Sessions:</span> {formatCount(agent.session_count)}</p>
                                                    <p><span className="font-medium text-slate-800">Current:</span> {formatDuration(agent.current_session_duration_seconds)}</p>
                                                </div>
                                                <p className="mt-3 text-sm text-slate-600">
                                                    {agent.last_action?.label || 'No recent tracked action'}
                                                </p>
                                                <p className="mt-1 text-xs text-slate-500">
                                                    {agent.last_action?.created_at
                                                        ? `Last action ${formatRelativeTime(agent.last_action.created_at)}`
                                                        : 'Recent work will appear here once actions are logged.'}
                                                </p>
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Recently seen"
                        subtitle="Offline or stale sessions across your currently visible team members."
                    >
                        {presenceQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(presenceQuery.error, 'Presence could not be loaded.')}
                                onRetry={() => presenceQuery.refetch()}
                            />
                        ) : offlineAgents.length === 0 ? (
                            <TeamEmptyState
                                title="Everyone visible is online"
                                message="Offline agents will appear here once their sessions go stale or they sign out."
                            />
                        ) : (
                            <div className="space-y-3">
                                {offlineAgents.map((agent) => (
                                    <button
                                        key={agent.user_id}
                                        type="button"
                                        onClick={() => handleSelectAgent(agent)}
                                        className="flex w-full items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                    >
                                        <div className="min-w-0">
                                            <p className="font-semibold text-slate-900">{agent.name}</p>
                                            <p className="text-xs text-slate-500">{formatRole(agent.role)}</p>
                                        </div>
                                        <div className="text-right">
                                            <PresenceStatus isOnline={false} lastSeenAt={agent.last_seen_at} />
                                            <p className="mt-1 text-xs text-slate-500">{agent.last_action?.label || 'No tracked action yet'}</p>
                                        </div>
                                    </button>
                                ))}
                            </div>
                        )}
                    </SectionFrame>
                </>
            ) : null}

            {activeTab === 'leaderboard' ? (
                <SectionFrame
                    title={`Leaderboard • ${periodLabel(period)}`}
                    subtitle={`Performance ranking for ${selectedPlatformLabel} • ${selectedLeaderboardRoleLabel}.`}
                    action={(
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-end">
                            <FilterSelect
                                label="Role"
                                value={leaderboardRoleFilter}
                                onChange={(event) => setLeaderboardRoleFilter(normalizeLeaderboardRoleFilter(event.target.value, availableLeaderboardRoleFilters))}
                                options={availableLeaderboardRoleFilters}
                                className="min-w-[10rem]"
                            />
                            {leaderboardRows.length ? (
                                <button type="button" onClick={handleExportLeaderboard} className="crm-btn-secondary px-3 py-2 text-xs">
                                    Export CSV
                                </button>
                            ) : null}
                        </div>
                    )}
                >
                    {leaderboardQuery.isLoading && !leaderboardQuery.data ? (
                        <div className="space-y-3">
                            <div className="h-12 animate-pulse rounded-lg bg-slate-100" />
                            <div className="h-12 animate-pulse rounded-lg bg-slate-100" />
                            <div className="h-12 animate-pulse rounded-lg bg-slate-100" />
                        </div>
                    ) : leaderboardQuery.isError ? (
                        <TeamErrorState
                            message={getApiErrorMessage(leaderboardQuery.error, 'The leaderboard could not be loaded.')}
                            onRetry={() => leaderboardQuery.refetch()}
                        />
                    ) : (
                        <DataTable
                            columns={leaderboardColumns}
                            data={leaderboardRows}
                            rowIdKey="user_id"
                            onRowClick={handleSelectAgent}
                            emptyMessage={`No tracked activity for ${selectedLeaderboardRoleLabel.toLowerCase()} in this period. Try switching to Today or clearing the market filter.`}
                        />
                    )}
                </SectionFrame>
            ) : null}

            {activeTab === 'my-stats' ? (
                <>
                    <SectionFrame
                        title="Current progress"
                        subtitle={`${periodLabel(period)} for ${selectedPlatformLabel}.`}
                    >
                        {myStatsQuery.isLoading && !myStatsQuery.data ? (
                            <TeamSkeletonCards count={6} />
                        ) : myStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(myStatsQuery.error, 'Your team stats could not be loaded.')}
                                onRetry={() => myStatsQuery.refetch()}
                            />
                        ) : (
                            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                {myMetricCards.map((metric) => (
                                    <MetricCard
                                        key={metric.label}
                                        label={metric.label}
                                        value={metric.value}
                                        meta={metric.meta}
                                        tone={metric.tone}
                                    />
                                ))}
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Goals and momentum"
                        subtitle="Progress bars show absolute completion and trend cards compare against the previous window."
                    >
                        {myStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(myStatsQuery.error, 'Goal progress could not be loaded.')}
                                onRetry={() => myStatsQuery.refetch()}
                            />
                        ) : (
                            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)]">
                                <div className="space-y-3">
                                    {myGoals.length === 0 ? (
                                        <TeamEmptyState
                                            title="No goals have been assigned for this period."
                                            message="Once a manager sets targets for your scope, progress will appear here."
                                        />
                                    ) : (
                                        myGoals.map((goal) => (
                                            <article key={goal.goal_id} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                                <div className="flex flex-wrap items-center justify-between gap-3">
                                                    <div>
                                                        <p className="font-semibold text-slate-900">{goal.label}</p>
                                                        <p className="text-xs text-slate-500">
                                                            {goalPeriodLabel(goal.period)} goal
                                                            {goal.platform_name ? ` • ${goal.platform_name}` : ' • All markets'}
                                                        </p>
                                                        <div className="mt-2 flex flex-wrap gap-2">
                                                            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                                                {goalSourceLabel(goal.source_type)}
                                                            </span>
                                                            {roleScopeLabel(goal) ? (
                                                                <span className="rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-medium text-teal-700">
                                                                    {roleScopeLabel(goal)}
                                                                </span>
                                                            ) : null}
                                                        </div>
                                                    </div>
                                                    <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                                                        {formatCount(goal.current)}/{formatCount(goal.target)} ({goal.percentage}%)
                                                    </span>
                                                </div>
                                                <div className="mt-3">
                                                    <GoalProgressBar current={goal.current} target={goal.target} />
                                                </div>
                                            </article>
                                        ))
                                    )}
                                </div>

                                <div className="space-y-3">
                                    {trendHighlights.map((item) => (
                                        <article key={item.key} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                            <div className="flex items-center justify-between gap-3">
                                                <div>
                                                    <p className="text-sm font-semibold text-slate-900">{item.label}</p>
                                                    <p className="text-xs text-slate-500">
                                                        {formatTrendMetricValue(item.key, myTrend[item.key]?.current)} now vs {formatTrendMetricValue(item.key, myTrend[item.key]?.previous)} before
                                                    </p>
                                                </div>
                                                <TrendBadge trend={myTrend[item.key]} period={period} />
                                            </div>
                                        </article>
                                    ))}
                                </div>
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Recent activity"
                        subtitle={`Most recent work captured in ${periodLabel(period).toLowerCase()}. Contextual links make it easy to jump back into the relevant client, lead, or deal.`}
                    >
                        {myStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(myStatsQuery.error, 'Recent activity could not be loaded.')}
                                onRetry={() => myStatsQuery.refetch()}
                            />
                        ) : (
                            <ActivityList
                                items={myActivity}
                                emptyTitle="No activity yet"
                                emptyMessage="Your calls, chats, leads, and subscription actions will appear here once you start working."
                            />
                        )}
                    </SectionFrame>
                </>
            ) : null}

            {activeTab === 'goals' ? (
                <>
                    <SectionFrame
                        title="Create default goal"
                        subtitle={`Defaults apply to ${selectedPlatformLabel}. Role scope keeps each target aligned to the right team.`}
                        action={
                            <FilterSelect
                                label="Goal period"
                                value={goalPeriod}
                                onChange={(event) => setGoalPeriod(normalizeGoalPeriod(event.target.value))}
                                options={GOAL_PERIOD_OPTIONS}
                                className="min-w-[9rem]"
                            />
                        }
                    >
                        <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)_minmax(0,0.8fr)_auto]">
                            <FilterSelect
                                label="Role scope"
                                value={goalRoleScope}
                                onChange={(event) => setGoalRoleScope(normalizeGoalRoleScope(event.target.value))}
                                options={availableRoleScopes}
                            />
                            <FilterSelect
                                label="Metric"
                                value={goalMetric}
                                onChange={(event) => setGoalMetric(event.target.value)}
                                options={defaultGoalMetricOptions.map((metric) => ({
                                    value: metric.value,
                                    label: metric.label,
                                }))}
                            />
                            <label className="flex flex-col gap-1">
                                <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">
                                    Target
                                </span>
                                <input
                                    type="number"
                                    min="1"
                                    value={goalTarget}
                                    onChange={(event) => setGoalTarget(event.target.value)}
                                    className="crm-select-enhanced"
                                    placeholder="15"
                                />
                            </label>
                            <div className="flex items-end">
                                <button
                                    type="button"
                                    onClick={handleCreateGoal}
                                    disabled={createGoalMutation.isPending || !defaultGoalMetricOptions.length}
                                    className="crm-btn-primary w-full px-4 py-2.5 text-sm disabled:cursor-not-allowed disabled:opacity-50 md:w-auto"
                                >
                                    {createGoalMutation.isPending ? 'Saving...' : 'Save goal'}
                                </button>
                            </div>
                        </div>
                        {!defaultGoalMetricOptions.length ? (
                            <p className="mt-3 text-sm text-slate-500">
                                No goal metrics are available for this role scope yet.
                            </p>
                        ) : null}
                    </SectionFrame>

                    <SectionFrame
                        title="Assign individual goal"
                        subtitle={platformFilter
                            ? `Overrides apply only to the selected market, ${selectedPlatformLabel}, and replace the matching default for that teammate.`
                            : 'Choose a market first. Individual goals are market-specific overrides.'}
                    >
                        {!platformFilter ? (
                            <TeamEmptyState
                                title="Choose a market to assign individual goals"
                                message="Individual goals are tied to a specific market so the right target follows the right revenue and activity."
                            />
                        ) : assignableAgents.length === 0 ? (
                            <TeamEmptyState
                                title="No visible team members in this market"
                                message="Once sales or marketing teammates are assigned to this market, you can create individual overrides here."
                            />
                        ) : (
                            <div className="grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,0.8fr)_auto]">
                                <FilterSelect
                                    label="Assignee"
                                    value={goalOverrideAssigneeId}
                                    onChange={(event) => setGoalOverrideAssigneeId(event.target.value)}
                                    options={assignableAgents.map((agent) => ({
                                        value: String(agent.user_id),
                                        label: `${agent.name} • ${formatRole(agent.role)}`,
                                    }))}
                                />
                                <FilterSelect
                                    label="Metric"
                                    value={goalOverrideMetric}
                                    onChange={(event) => setGoalOverrideMetric(event.target.value)}
                                    options={individualGoalMetricOptions.map((metric) => ({
                                        value: metric.value,
                                        label: metric.label,
                                    }))}
                                />
                                <label className="flex flex-col gap-1">
                                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-400">
                                        Target
                                    </span>
                                    <input
                                        type="number"
                                        min="1"
                                        value={goalOverrideTarget}
                                        onChange={(event) => setGoalOverrideTarget(event.target.value)}
                                        className="crm-select-enhanced"
                                        placeholder="15"
                                    />
                                </label>
                                <div className="flex items-end">
                                    <button
                                        type="button"
                                        onClick={handleCreateGoalOverride}
                                        disabled={createGoalOverrideMutation.isPending || !individualGoalMetricOptions.length}
                                        className="crm-btn-primary w-full px-4 py-2.5 text-sm disabled:cursor-not-allowed disabled:opacity-50 md:w-auto"
                                    >
                                        {createGoalOverrideMutation.isPending ? 'Saving...' : 'Assign goal'}
                                    </button>
                                </div>
                            </div>
                        )}
                        {platformFilter && !individualGoalMetricOptions.length ? (
                            <p className="mt-3 text-sm text-slate-500">
                                No goal metrics are available for the selected teammate.
                            </p>
                        ) : null}
                    </SectionFrame>

                    <SectionFrame
                        title={`${goalPeriodLabel(goalPeriod)} default goals`}
                        subtitle={`Shared goals for ${selectedPlatformLabel}. Each default only appears for the roles it targets.`}
                    >
                        {goalsQuery.isLoading && !goalsQuery.data ? (
                            <div className="space-y-3">
                                <div className="h-24 animate-pulse rounded-xl bg-slate-100" />
                                <div className="h-24 animate-pulse rounded-xl bg-slate-100" />
                            </div>
                        ) : goalsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(goalsQuery.error, 'Goals could not be loaded.')}
                                onRetry={() => goalsQuery.refetch()}
                            />
                        ) : defaultGoals.length === 0 ? (
                            <TeamEmptyState
                                title="No default goals have been set for this scope yet."
                                message="Create one above to give the right team a clear target for this period."
                            />
                        ) : (
                            <div className="space-y-4">
                                {defaultGoals.map((goal) => (
                                    <article key={goal.id} className="rounded-xl border border-slate-200 bg-white">
                                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                            <div>
                                                <p className="font-semibold text-slate-900">{goal.label}</p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                    <span>{goal.platform_name || 'All markets'}</span>
                                                    <span>•</span>
                                                    <span>{roleScopeLabel(goal)}</span>
                                                    <span>•</span>
                                                    <span>Target {formatCount(goal.target)}</span>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setGoalToDelete(goal)}
                                                className="crm-btn-secondary px-3 py-1.5 text-xs text-rose-700 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-800"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <div className="space-y-3 px-4 py-4">
                                            {goal.progress.map((row) => (
                                                <button
                                                    key={`${goal.id}-${row.user_id}`}
                                                    type="button"
                                                    onClick={() => handleSelectAgent(row)}
                                                    className="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <p className="font-semibold text-slate-900">{row.name}</p>
                                                            <p className="text-xs text-slate-500">{formatRole(row.role)}</p>
                                                        </div>
                                                        <span className="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                                                            {formatCount(row.current)}/{formatCount(row.target)} ({row.percentage}%)
                                                        </span>
                                                    </div>
                                                    <div className="mt-3">
                                                        <GoalProgressBar current={row.current} target={row.target} />
                                                    </div>
                                                </button>
                                            ))}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title={`${goalPeriodLabel(goalPeriod)} individual goals`}
                        subtitle={platformFilter
                            ? `Manager overrides for ${selectedPlatformLabel}. These take precedence over the matching default goal.`
                            : 'Choose a market above to review individual overrides for that market.'}
                    >
                        {goalsQuery.isLoading && !goalsQuery.data ? (
                            <div className="space-y-3">
                                <div className="h-20 animate-pulse rounded-xl bg-slate-100" />
                                <div className="h-20 animate-pulse rounded-xl bg-slate-100" />
                            </div>
                        ) : goalsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(goalsQuery.error, 'Individual goals could not be loaded.')}
                                onRetry={() => goalsQuery.refetch()}
                            />
                        ) : !platformFilter ? (
                            <TeamEmptyState
                                title="No market selected"
                                message="Use the market filter at the top of the page to review or edit individual goal overrides."
                            />
                        ) : individualGoals.length === 0 ? (
                            <TeamEmptyState
                                title="No individual overrides yet"
                                message="When one teammate needs a different target than the team default, assign it above and it will appear here."
                            />
                        ) : (
                            <div className="space-y-4">
                                {individualGoals.map((goal) => (
                                    <article key={goal.id} className="rounded-xl border border-slate-200 bg-white">
                                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
                                            <div>
                                                <p className="font-semibold text-slate-900">{goal.label}</p>
                                                <div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                                    <span>{goal.user?.name || 'Unknown teammate'}</span>
                                                    {goal.user?.role ? (
                                                        <>
                                                            <span>•</span>
                                                            <span>{formatRole(goal.user.role)}</span>
                                                        </>
                                                    ) : null}
                                                    <span>•</span>
                                                    <span>{goal.platform_name || 'Selected market'}</span>
                                                    <span>•</span>
                                                    <span>Target {formatCount(goal.target)}</span>
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setGoalOverrideToDelete(goal)}
                                                className="crm-btn-secondary px-3 py-1.5 text-xs text-rose-700 hover:border-rose-200 hover:bg-rose-50 hover:text-rose-800"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                        <div className="px-4 py-4">
                                            {goal.progress ? (
                                                <button
                                                    type="button"
                                                    onClick={() => handleSelectAgent(goal.progress)}
                                                    className="w-full rounded-xl border border-slate-200 bg-slate-50/70 px-4 py-3 text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                                                >
                                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                                        <div>
                                                            <p className="font-semibold text-slate-900">{goal.progress.name}</p>
                                                            <p className="text-xs text-slate-500">{formatRole(goal.progress.role)}</p>
                                                        </div>
                                                        <span className="rounded-full bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                                                            {formatCount(goal.progress.current)}/{formatCount(goal.progress.target)} ({goal.progress.percentage}%)
                                                        </span>
                                                    </div>
                                                    <div className="mt-3">
                                                        <GoalProgressBar current={goal.progress.current} target={goal.progress.target} />
                                                    </div>
                                                </button>
                                            ) : (
                                                <TeamEmptyState
                                                    title="This teammate is no longer available"
                                                    message="The override is still stored, but the assignee is not currently visible in this scope."
                                                />
                                            )}
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </SectionFrame>
                </>
            ) : null}

            {activeTab === 'agent-detail' ? (
                <>
                    <SectionFrame
                        title={selectedAgent ? `${selectedAgent.name} • Member Detail` : 'Member Detail'}
                        subtitle={selectedAgent
                            ? `${formatRole(selectedAgent.role)} • ${periodLabel(period)} • ${selectedPlatformLabel}`
                            : 'Choose someone from Presence or Leaderboard to inspect their detailed activity.'}
                        action={
                            selectedAgent ? (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSelectedAgent(null);
                                        setActiveTab(isManager ? 'presence' : 'my-stats');
                                    }}
                                    className="crm-btn-secondary px-3 py-2 text-xs"
                                >
                                    Close detail
                                </button>
                            ) : null
                        }
                    >
                        {!selectedAgent ? (
                            <TeamEmptyState
                                title="Select a team member"
                                message="Choose someone from Presence or Leaderboard to inspect their detailed activity."
                            />
                        ) : agentStatsQuery.isLoading && !agentStatsQuery.data ? (
                            <TeamSkeletonCards count={6} />
                        ) : agentStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(agentStatsQuery.error, 'This agent could not be loaded.')}
                                onRetry={() => agentStatsQuery.refetch()}
                            />
                        ) : (
                            <div className="space-y-4">
                                <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p className="text-lg font-semibold text-slate-900">{agentStatsQuery.data?.agent?.name}</p>
                                            <p className="mt-1 text-sm text-slate-500">{formatRole(agentStatsQuery.data?.agent?.role)}</p>
                                        </div>
                                        <PresenceStatus isOnline={selectedAgent.is_online} lastSeenAt={selectedAgent.last_seen_at} />
                                    </div>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {agentMetricCards.map((metric) => (
                                        <MetricCard
                                            key={metric.label}
                                            label={metric.label}
                                            value={metric.value}
                                            meta={metric.meta}
                                            tone={metric.tone}
                                        />
                                    ))}
                                </div>
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Trend summary"
                        subtitle="Use this to compare the selected agent against the previous window."
                    >
                        {agentStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(agentStatsQuery.error, 'Trend data could not be loaded.')}
                                onRetry={() => agentStatsQuery.refetch()}
                            />
                        ) : (
                            <div className="grid gap-3 md:grid-cols-2">
                                {trendHighlights.map((item) => (
                                    <article key={item.key} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                        <div className="flex items-center justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-slate-900">{item.label}</p>
                                                <p className="text-xs text-slate-500">
                                                    {formatTrendMetricValue(item.key, agentTrend[item.key]?.current)} now vs {formatTrendMetricValue(item.key, agentTrend[item.key]?.previous)} before
                                                </p>
                                            </div>
                                            <TrendBadge trend={agentTrend[item.key]} period={period} />
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Goal progress"
                        subtitle="Current target progress for the selected agent."
                    >
                        {agentStatsQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(agentStatsQuery.error, 'Goal progress could not be loaded.')}
                                onRetry={() => agentStatsQuery.refetch()}
                            />
                        ) : agentGoals.length === 0 ? (
                            <TeamEmptyState
                                title="No goals assigned"
                                message="This agent does not have an active goal in the current scope."
                            />
                        ) : (
                            <div className="space-y-3">
                                {agentGoals.map((goal) => (
                                    <article key={goal.goal_id} className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="font-semibold text-slate-900">{goal.label}</p>
                                                <p className="text-xs text-slate-500">
                                                    {goalPeriodLabel(goal.period)} goal
                                                    {goal.platform_name ? ` • ${goal.platform_name}` : ' • All markets'}
                                                </p>
                                                <div className="mt-2 flex flex-wrap gap-2">
                                                    <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-medium text-slate-600">
                                                        {goalSourceLabel(goal.source_type)}
                                                    </span>
                                                    {roleScopeLabel(goal) ? (
                                                        <span className="rounded-full bg-teal-50 px-2.5 py-1 text-[11px] font-medium text-teal-700">
                                                            {roleScopeLabel(goal)}
                                                        </span>
                                                    ) : null}
                                                </div>
                                            </div>
                                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-medium text-slate-600">
                                                {formatCount(goal.current)}/{formatCount(goal.target)} ({goal.percentage}%)
                                            </span>
                                        </div>
                                        <div className="mt-3">
                                            <GoalProgressBar current={goal.current} target={goal.target} />
                                        </div>
                                    </article>
                                ))}
                            </div>
                        )}
                    </SectionFrame>

                    <SectionFrame
                        title="Recent activity"
                        subtitle={`Recent timeline for the selected agent in ${periodLabel(period).toLowerCase()}.`}
                    >
                        {agentActivityQuery.isError ? (
                            <TeamErrorState
                                message={getApiErrorMessage(agentActivityQuery.error, 'Recent activity could not be loaded.')}
                                onRetry={() => agentActivityQuery.refetch()}
                            />
                        ) : (
                            <ActivityList
                                items={agentActivity}
                                emptyTitle="No activity yet"
                                emptyMessage={`No tracked activity has been recorded for this agent in ${periodLabel(period).toLowerCase()}.`}
                            />
                        )}
                    </SectionFrame>
                </>
            ) : null}

            <ConfirmDialog
                open={Boolean(goalToDelete)}
                title="Remove goal?"
                message={goalToDelete ? `This will remove the ${goalToDelete.label} goal for ${goalToDelete.platform_name || 'all markets'}.` : ''}
                confirmLabel="Remove goal"
                cancelLabel="Keep goal"
                tone="danger"
                isPending={deleteGoalMutation.isPending}
                onCancel={() => setGoalToDelete(null)}
                onConfirm={() => {
                    if (goalToDelete) {
                        deleteGoalMutation.mutate(goalToDelete.id);
                    }
                }}
            />

            <ConfirmDialog
                open={Boolean(goalOverrideToDelete)}
                title="Remove individual goal?"
                message={goalOverrideToDelete
                    ? `This will remove the ${goalOverrideToDelete.label} override for ${goalOverrideToDelete.user?.name || 'this teammate'} in ${goalOverrideToDelete.platform_name || 'the selected market'}.`
                    : ''}
                confirmLabel="Remove override"
                cancelLabel="Keep override"
                tone="danger"
                isPending={deleteGoalOverrideMutation.isPending}
                onCancel={() => setGoalOverrideToDelete(null)}
                onConfirm={() => {
                    if (goalOverrideToDelete) {
                        deleteGoalOverrideMutation.mutate(goalOverrideToDelete.id);
                    }
                }}
            />
        </div>
    );
}
