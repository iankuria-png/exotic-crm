import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api from '../../services/api';
import SectionFrame from '../SectionFrame';
import CurrencyAmount from '../CurrencyAmount';
import ProfileEngagementWidget from './ProfileEngagementWidget';
import useSalesWidgetConfig from '../../hooks/useSalesWidgetConfig';
import { useToast } from '../ToastProvider';
import { getCountryFlag } from '../../utils/flags';

const DASHBOARD_REFRESH_MS = 30_000;
const SALES_MARKET_STORAGE_KEY = 'exoticcrm.sales_dashboard.market_filter';
const SALES_RANGE_STORAGE_KEY = 'exoticcrm.sales_dashboard.range';
const SALES_DASHBOARD_CACHE_PREFIX = 'exoticcrm.sales_dashboard.cache';
const SALES_DASHBOARD_CACHE_TTL = 1000 * 60 * 60 * 24;
const SALES_RANGE_OPTIONS = [
    { key: '7d', label: '7 days', days: 7 },
    { key: '30d', label: '30 days', days: 30 },
    { key: '90d', label: '90 days', days: 90 },
];

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

function clampPercent(value) {
    return Math.max(0, Math.min(100, Number(value) || 0));
}

function normalizeMarketFilter(value) {
    const raw = String(value ?? '').trim();
    return /^\d+$/.test(raw) ? raw : '';
}

function resolveRangeOption(rangeKey) {
    return SALES_RANGE_OPTIONS.find((option) => option.key === rangeKey) || SALES_RANGE_OPTIONS[1];
}

function makeDashboardCacheKey(user, scope) {
    const userScope = user?.id || user?.email || 'sales';
    return `${SALES_DASHBOARD_CACHE_PREFIX}.${userScope}.${scope}`;
}

function readDashboardCache(cacheKey) {
    if (typeof window === 'undefined' || !cacheKey) {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(cacheKey);
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object' || !('data' in parsed) || !parsed.updatedAt) {
            return null;
        }

        if ((Date.now() - Number(parsed.updatedAt)) > SALES_DASHBOARD_CACHE_TTL) {
            window.localStorage.removeItem(cacheKey);
            return null;
        }

        return parsed;
    } catch (error) {
        return null;
    }
}

function writeDashboardCache(cacheKey, data) {
    if (typeof window === 'undefined' || !cacheKey || data === undefined) {
        return;
    }

    try {
        window.localStorage.setItem(cacheKey, JSON.stringify({
            data,
            updatedAt: Date.now(),
        }));
    } catch (error) {
        // Ignore cache write failures and keep the live dashboard working.
    }
}

function isoDateDaysAgo(days) {
    const date = new Date();
    date.setDate(date.getDate() - days);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function formatLongDate(value = new Date()) {
    return new Intl.DateTimeFormat('en-KE', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
    }).format(value);
}

function formatDate(value) {
    if (!value) {
        return '--';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '--';
    }

    return date.toLocaleDateString();
}

function formatDateTime(value) {
    if (!value) {
        return 'Never';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return 'Never';
    }

    return date.toLocaleString();
}

function formatRelativeTime(value) {
    if (!value) {
        return 'No timestamp';
    }

    const timestamp = new Date(value).getTime();
    if (Number.isNaN(timestamp)) {
        return 'No timestamp';
    }

    const deltaMinutes = Math.floor((Date.now() - timestamp) / 60_000);
    if (deltaMinutes < 1) return 'just now';
    if (deltaMinutes < 60) return `${deltaMinutes}m ago`;

    const deltaHours = Math.floor(deltaMinutes / 60);
    if (deltaHours < 24) return `${deltaHours}h ago`;

    const deltaDays = Math.floor(deltaHours / 24);
    return `${deltaDays}d ago`;
}

function formatExpiryWindow(value) {
    if (!value) return 'No expiry date';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'No expiry date';

    const daysDiff = Math.ceil((date.getTime() - Date.now()) / 86_400_000);
    if (daysDiff < 0) return `${Math.abs(daysDiff)}d overdue`;
    if (daysDiff === 0) return 'Due today';
    return `${daysDiff}d left`;
}

function pluralize(count, singular, plural = `${singular}s`) {
    return `${count} ${count === 1 ? singular : plural}`;
}

function greetingForHour() {
    const hour = new Date().getHours();

    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
}

function firstName(name) {
    return String(name || '').trim().split(/\s+/)[0] || 'there';
}

function progressTone(percentage) {
    if (percentage >= 90) return 'bg-emerald-500';
    if (percentage >= 65) return 'bg-teal-500';
    if (percentage >= 40) return 'bg-amber-500';
    return 'bg-rose-500';
}

function todoStatusTone(status) {
    return status === 'done'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : 'border-amber-200 bg-amber-50 text-amber-700';
}

function syncStatusTone(status) {
    if (status === 'success') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }
    if (status === 'queued' || status === 'running') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }
    if (status === 'error') {
        return 'border-rose-200 bg-rose-50 text-rose-700';
    }

    return 'border-slate-200 bg-slate-100 text-slate-600';
}

function EmptyState({ message }) {
    return (
        <div className="rounded-2xl border border-dashed border-slate-200 bg-slate-50/80 px-4 py-10 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function LoadingStack({ count = 3, cardClassName = 'h-16 rounded-2xl bg-slate-100' }) {
    return (
        <div className="space-y-3">
            {Array.from({ length: count }).map((_, index) => (
                <div key={index} className={`animate-pulse ${cardClassName}`} />
            ))}
        </div>
    );
}

function Badge({ label, tone = 'neutral' }) {
    const toneMap = {
        neutral: 'crm-sales-chip-neutral',
        success: 'crm-sales-chip-success',
        warning: 'crm-sales-chip-warning',
        danger: 'crm-sales-chip-danger',
    };

    return (
        <span className={`crm-sales-chip ${toneMap[tone] || toneMap.neutral}`}>
            {label}
        </span>
    );
}

function ActionButton({ label, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="crm-sales-action"
        >
            {label}
        </button>
    );
}

function SalesHero({
    user,
    selectedMarket,
    marketFilter,
    onMarketChange,
    markets,
    rangeKey,
    onRangeChange,
    summary,
    myStats,
    isLoading,
    onOpenRecovery,
    onOpenRenewals,
}) {
    const summaryRow = myStats?.summary || {};
    const totalActions = asNumber(summaryRow.total_actions);
    const leadContacts = asNumber(summaryRow.leads_contacted);
    const activations = asNumber(summaryRow.subs_activated);
    const recoveryQueue = asNumber(summary?.kpis?.payment_recovery_queue_total);
    const renewalWorkload = asNumber(summary?.kpis?.renewal_workload_14d);
    const activeGoals = Array.isArray(myStats?.goals) ? myStats.goals : [];
    const goalsBehind = activeGoals.filter((goal) => asNumber(goal.percentage) < 60).length;

    const focusMessage = renewalWorkload > 0
        ? `${pluralize(renewalWorkload, 'renewal')} needs attention in the next 14 days.`
        : recoveryQueue > 0
            ? `${pluralize(recoveryQueue, 'payment')} is waiting in recovery.`
            : goalsBehind > 0
                ? `${pluralize(goalsBehind, 'goal')} needs pace support.`
                : 'Queues are under control.';
    const heroMessage = isLoading
        ? 'Loading your live market data. The board will fill in as the latest snapshot arrives.'
        : focusMessage;

    return (
        <section className="crm-sales-hero px-6 py-6 sm:px-7 sm:py-7">
            <div className="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(320px,0.95fr)]">
                <div className="space-y-5">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="crm-sales-pill bg-white/14 text-white ring-1 ring-white/18">
                            Customer Service Center
                        </span>
                        <span className="crm-sales-pill bg-white/10 text-slate-100 ring-1 ring-white/12">
                            {formatLongDate()}
                        </span>
                        {selectedMarket ? (
                            <span className="crm-sales-pill bg-emerald-400/14 text-emerald-50 ring-1 ring-emerald-200/25">
                                {getCountryFlag(selectedMarket.country)} {selectedMarket.name}
                            </span>
                        ) : null}
                    </div>

                    <div>
                        <p className="text-sm font-medium text-slate-200">
                            {greetingForHour()}, {firstName(user?.name)}.
                        </p>
                        <h2 className="mt-1 max-w-3xl text-[2.1rem] leading-[1.02] font-semibold tracking-[-0.04em] text-white sm:text-[2.9rem]">
                            Customer Service Center
                        </h2>
                        <p className="mt-3 max-w-2xl text-[0.98rem] leading-7 text-slate-200/92">{heroMessage}</p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="rounded-2xl border border-white/12 bg-white/10 px-4 py-4 backdrop-blur-sm">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-200">This period</p>
                            <p className="mt-2 text-3xl font-semibold tracking-tight text-white">{isLoading ? '—' : totalActions.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-200">tracked actions by you</p>
                        </div>
                        <div className="rounded-2xl border border-white/12 bg-white/10 px-4 py-4 backdrop-blur-sm">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-200">Lead outreach</p>
                            <p className="mt-2 text-3xl font-semibold tracking-tight text-white">{isLoading ? '—' : leadContacts.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-200">contacts logged this period</p>
                        </div>
                        <div className="rounded-2xl border border-white/12 bg-white/10 px-4 py-4 backdrop-blur-sm">
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-200">Activations</p>
                            <p className="mt-2 text-3xl font-semibold tracking-tight text-white">{isLoading ? '—' : activations.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-200">subscriptions activated</p>
                        </div>
                    </div>
                </div>

                <div className="space-y-4 rounded-[26px] border border-white/14 bg-slate-950/14 p-5 shadow-[inset_0_1px_0_rgba(255,255,255,0.08)] backdrop-blur-sm">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-200">View controls</p>
                        <p className="mt-2 text-sm leading-6 text-slate-100/88">
                            Tighten the board around one market, or zoom out and read the wider sales rhythm across every market you can touch.
                        </p>
                    </div>

                    <label className="space-y-2">
                        <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-200">Market scope</span>
                        <span className="flex items-center gap-3 rounded-2xl border border-white/10 bg-white/9 px-4 py-3">
                            <span className={`h-2.5 w-2.5 rounded-full ${marketFilter ? 'bg-emerald-400' : 'bg-slate-300'}`} aria-hidden="true" />
                            <select
                                value={marketFilter}
                                onChange={(event) => onMarketChange(event.target.value)}
                                className="w-full border-0 bg-transparent text-sm font-medium text-white focus:outline-none"
                            >
                                <option value="" className="text-slate-900">All accessible markets</option>
                                {markets.map((market) => (
                                    <option key={market.id} value={market.id} className="text-slate-900">
                                        {market.name}{market.is_active ? '' : ' (inactive)'}
                                    </option>
                                ))}
                            </select>
                        </span>
                    </label>

                    <div className="space-y-2">
                        <span className="block text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-200">Time window</span>
                        <div className="grid grid-cols-3 gap-2">
                            {SALES_RANGE_OPTIONS.map((option) => (
                                <button
                                    key={option.key}
                                    type="button"
                                    onClick={() => onRangeChange(option.key)}
                                    className={`rounded-2xl px-3 py-3 text-sm font-semibold transition ${rangeKey === option.key ? 'bg-white text-slate-900 shadow-sm' : 'bg-white/8 text-slate-100 hover:bg-white/14'}`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="grid gap-2 sm:grid-cols-2">
                        <button
                            type="button"
                            onClick={onOpenRecovery}
                            className="rounded-2xl border border-white/12 bg-white/10 px-4 py-3 text-left transition hover:bg-white/14"
                        >
                            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-200">Recovery queue</p>
                            <p className="mt-1 text-2xl font-semibold tracking-tight text-white">{recoveryQueue.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-200">payments waiting</p>
                        </button>
                        <button
                            type="button"
                            onClick={onOpenRenewals}
                            className="rounded-2xl border border-white/12 bg-white/10 px-4 py-3 text-left transition hover:bg-white/14"
                        >
                            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-200">Renewal workload</p>
                            <p className="mt-1 text-2xl font-semibold tracking-tight text-white">{renewalWorkload.toLocaleString()}</p>
                            <p className="mt-1 text-sm text-slate-200">due in 14 days</p>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    );
}

function SalesKpiCard({ label, value, meta, subMeta, featured = false, actionLabel, onClick, badge, className = '' }) {
    return (
        <article className={`crm-sales-panel bg-white ${featured ? 'crm-sales-panel-prominent border-teal-200/80' : ''} ${className}`}>
            <div className="flex h-full flex-col justify-between gap-5 px-5 py-5">
                <div className="space-y-3">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{label}</p>
                            <div className={`mt-2 ${featured ? 'text-[2.25rem] sm:text-[2.8rem]' : 'text-[2rem]'} leading-none font-semibold tracking-[-0.04em] text-slate-950`}>
                                {value}
                            </div>
                        </div>
                        {badge}
                    </div>
                    {meta ? <p className="text-sm font-medium text-slate-700">{meta}</p> : null}
                    {subMeta ? <p className="text-sm leading-6 text-slate-500">{subMeta}</p> : null}
                </div>

                {onClick ? (
                    <ActionButton label={actionLabel || 'Open queue'} onClick={onClick} />
                ) : null}
            </div>
        </article>
    );
}

function TodoComposer({
    draftTodo,
    setDraftTodo,
    draftGoalId,
    setDraftGoalId,
    draftDueAt,
    setDraftDueAt,
    goalOptions,
    onSubmit,
    isPending,
}) {
    return (
        <form onSubmit={onSubmit} className="rounded-[22px] border border-teal-100 bg-[linear-gradient(180deg,#f4fffd_0%,#ecfeff_100%)] p-4">
            <label className="block text-[11px] font-semibold uppercase tracking-[0.16em] text-teal-700">
                Add next action
            </label>
            <div className="mt-2 grid gap-3">
                <input
                    value={draftTodo}
                    onChange={(event) => setDraftTodo(event.target.value)}
                    placeholder="Call Nairobi trial lead about renewal plan"
                    className="crm-input border-teal-200 bg-white"
                    disabled={isPending}
                />
                <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_160px_auto]">
                    <select
                        value={draftGoalId}
                        onChange={(event) => setDraftGoalId(event.target.value)}
                        className="crm-select"
                        disabled={isPending}
                    >
                        <option value="">Optional goal link</option>
                        {goalOptions.map((goal) => (
                            <option key={`${goal.goal_id}-${goal.metric}-${goal.period}`} value={goal.goal_id}>
                                {goal.label}{goal.platform_name ? ` • ${goal.platform_name}` : ''} ({goal.current}/{goal.target})
                            </option>
                        ))}
                    </select>
                    <input
                        type="date"
                        value={draftDueAt}
                        onChange={(event) => setDraftDueAt(event.target.value)}
                        className="crm-input"
                        disabled={isPending}
                    />
                    <button type="submit" className="crm-btn-primary" disabled={isPending || !draftTodo.trim()}>
                        {isPending ? 'Saving…' : 'Add'}
                    </button>
                </div>
            </div>
        </form>
    );
}

function TodoListWidget({
    todos,
    goals,
    marketFilter,
    isLoading,
    isSaving,
    draftTodo,
    setDraftTodo,
    draftGoalId,
    setDraftGoalId,
    draftDueAt,
    setDraftDueAt,
    onSubmit,
    onToggleStatus,
    onDelete,
}) {
    const goalOptions = goals.filter((goal) => !marketFilter || goal.platform_id === null || String(goal.platform_id) === String(marketFilter));
    const pendingCount = todos.filter((todo) => todo.status !== 'done').length;

    return (
        <SectionFrame
            title="Action List"
            subtitle={pendingCount > 0 ? `${pluralize(pendingCount, 'open task')} waiting for follow-through.` : 'Your personal queue for high-intent follow-up and clean next steps.'}
            className="crm-sales-panel"
            contentClassName="space-y-4"
        >
            <TodoComposer
                draftTodo={draftTodo}
                setDraftTodo={setDraftTodo}
                draftGoalId={draftGoalId}
                setDraftGoalId={setDraftGoalId}
                draftDueAt={draftDueAt}
                setDraftDueAt={setDraftDueAt}
                goalOptions={goalOptions}
                onSubmit={onSubmit}
                isPending={isSaving}
            />

            {isLoading ? (
                <LoadingStack count={4} />
            ) : todos.length === 0 ? (
                <EmptyState message="No tasks yet. Capture the next meaningful follow-up so this dashboard stays personal and actionable." />
            ) : (
                <div className="space-y-3">
                    {todos.map((todo) => (
                        <div
                            key={todo.id}
                            className={`rounded-[22px] border px-4 py-4 transition ${todo.status === 'done' ? 'border-emerald-200 bg-emerald-50/70' : 'border-slate-200 bg-white hover:border-slate-300'}`}
                        >
                            <div className="flex items-start gap-3">
                                <button
                                    type="button"
                                    onClick={() => onToggleStatus(todo)}
                                    className={`mt-0.5 inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition ${todo.status === 'done' ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-slate-300 bg-white text-slate-400 hover:border-teal-500 hover:text-teal-600'}`}
                                    aria-label={todo.status === 'done' ? 'Mark task as pending' : 'Mark task as done'}
                                >
                                    {todo.status === 'done' ? '✓' : ''}
                                </button>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${todoStatusTone(todo.status)}`}>
                                            {todo.status === 'done' ? 'Done' : 'Pending'}
                                        </span>
                                        {todo.goal?.label ? (
                                            <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-600">
                                                {todo.goal.label}
                                            </span>
                                        ) : null}
                                        {todo.goal?.platform_name ? (
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                                {todo.goal.platform_name}
                                            </span>
                                        ) : null}
                                    </div>
                                    <p className={`mt-3 text-[1rem] leading-7 ${todo.status === 'done' ? 'text-slate-500 line-through' : 'text-slate-900'}`}>
                                        {todo.content}
                                    </p>
                                    <div className="mt-3 flex flex-wrap items-center gap-3 text-xs text-slate-500">
                                        {todo.due_at ? <span>Due {formatDate(todo.due_at)}</span> : <span>No due date</span>}
                                        {todo.updated_at ? <span>Updated {formatRelativeTime(todo.updated_at)}</span> : null}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onDelete(todo)}
                                    className="shrink-0 rounded-full border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-500 transition hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
        </SectionFrame>
    );
}

function GoalsWidget({ goals, isLoading }) {
    const focusGoals = [...goals].sort((left, right) => {
        const leftScore = asNumber(left.percentage);
        const rightScore = asNumber(right.percentage);

        if (leftScore !== rightScore) {
            return leftScore - rightScore;
        }

        return asNumber(right.target) - asNumber(left.target);
    });

    const closeCount = focusGoals.filter((goal) => asNumber(goal.percentage) >= 85).length;

    return (
        <SectionFrame
            title="Current Goals"
            subtitle={focusGoals.length ? `${pluralize(closeCount, 'goal')} is close to target. Keep the board focused on pace, not just totals.` : 'No active goals are assigned to this sales view yet.'}
            className="crm-sales-panel"
            contentClassName="space-y-3"
        >
            {isLoading ? (
                <LoadingStack count={3} cardClassName="h-28 rounded-[22px] bg-slate-100" />
            ) : focusGoals.length === 0 ? (
                <EmptyState message="Goals will appear here once targets are assigned to this user or market." />
            ) : (
                focusGoals.map((goal) => {
                    const percentage = clampPercent(goal.percentage);
                    return (
                        <div key={`${goal.goal_id}-${goal.metric}-${goal.period}`} className="rounded-[22px] border border-slate-200 bg-[linear-gradient(180deg,#ffffff_0%,#f8fafc_100%)] p-4">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <p className="text-base font-semibold text-slate-900">{goal.label}</p>
                                        <span className="rounded-full border border-slate-200 bg-slate-50 px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                            {goal.period}
                                        </span>
                                        {goal.platform_name ? (
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                                {goal.platform_name}
                                            </span>
                                        ) : (
                                            <span className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-500">
                                                All markets
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm text-slate-600">
                                        {goal.current.toLocaleString()} of {goal.target.toLocaleString()} completed
                                    </p>
                                </div>
                                <div className="text-right">
                                    <p className="crm-mono text-2xl font-semibold tracking-tight text-slate-950">{percentage}%</p>
                                    <p className="text-xs text-slate-500">{goal.current}/{goal.target}</p>
                                </div>
                            </div>
                            <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-100">
                                <div className={`h-full rounded-full ${progressTone(percentage)}`} style={{ width: `${Math.max(percentage, goal.current > 0 ? 4 : 0)}%` }} />
                            </div>
                        </div>
                    );
                })
            )}
        </SectionFrame>
    );
}

function InsightRow({ label, meta, valueNode, barWidth, accentClass = 'bg-teal-500', trailingNode }) {
    return (
        <div className="space-y-2 rounded-[20px] border border-slate-200 bg-white/80 p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-slate-900">{label}</p>
                    {meta ? <p className="mt-1 text-xs text-slate-500">{meta}</p> : null}
                </div>
                <div className="shrink-0 text-right">
                    {valueNode}
                    {trailingNode}
                </div>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className={`h-full rounded-full ${accentClass}`} style={{ width: `${Math.max(barWidth, 6)}%` }} />
            </div>
        </div>
    );
}

function MomentumCanvas({ countries, packages, isLoading }) {
    const topCountryRevenue = Math.max(...countries.map((country) => {
        const values = Object.values(country.current_revenue_breakdown || {});
        return values.reduce((sum, amount) => sum + Number(amount || 0), 0);
    }), 0);
    const topPackageCount = Math.max(...packages.map((entry) => asNumber(entry.activation_count)), 0);

    return (
        <SectionFrame
            title="Market Momentum"
            subtitle="Countries and packages live together here so you can read demand, revenue, and activation shape in one glance."
            className="crm-sales-panel"
            contentClassName="grid gap-4 xl:grid-cols-2"
        >
            <div className="space-y-3 rounded-[24px] border border-slate-200 bg-[linear-gradient(180deg,#f8fffd_0%,#f0fdfa_100%)] p-4">
                <div className="flex items-center justify-between gap-3">
                    <div>
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-teal-700">Top countries</p>
                        <p className="mt-1 text-sm text-slate-600">Revenue rhythm by market</p>
                    </div>
                </div>
                {isLoading ? (
                    <LoadingStack count={4} cardClassName="h-20 rounded-[20px] bg-white" />
                ) : countries.length === 0 ? (
                    <EmptyState message="No country revenue is available for the current scope." />
                ) : (
                    countries.slice(0, 5).map((country) => {
                        const currentTotal = Object.values(country.current_revenue_breakdown || {})
                            .reduce((sum, amount) => sum + Number(amount || 0), 0);
                        const barWidth = topCountryRevenue > 0 ? (currentTotal / topCountryRevenue) * 100 : 0;

                        return (
                            <InsightRow
                                key={country.platform_id}
                                label={`${getCountryFlag(country.country)} ${country.country || country.name}`}
                                meta={country.name}
                                barWidth={barWidth}
                                valueNode={(
                                    <CurrencyAmount
                                        breakdown={country.current_revenue_breakdown}
                                        scalarAmount={country.current_revenue}
                                        fallbackCurrency={country.currency}
                                        className="crm-mono text-sm font-semibold text-slate-900"
                                        stackClassName="crm-mono text-sm font-semibold text-slate-900"
                                    />
                                )}
                                trailingNode={country.trend !== null ? (
                                    <p className={`mt-1 text-xs font-semibold ${country.trend >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}>
                                        {country.trend > 0 ? '+' : ''}{country.trend}% vs prior
                                    </p>
                                ) : (
                                    <p className="mt-1 text-xs font-semibold text-slate-400">Mixed currencies</p>
                                )}
                            />
                        );
                    })
                )}
            </div>

            <div className="space-y-3 rounded-[24px] border border-slate-200 bg-[linear-gradient(180deg,#fffaf3_0%,#fff7ed_100%)] p-4">
                <div>
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-amber-700">Top packages</p>
                    <p className="mt-1 text-sm text-slate-600">What is converting most often right now</p>
                </div>
                {isLoading ? (
                    <LoadingStack count={4} cardClassName="h-20 rounded-[20px] bg-white" />
                ) : packages.length === 0 ? (
                    <EmptyState message="Package activations will appear once payments land in the selected window." />
                ) : (
                    packages.map((entry) => (
                        <InsightRow
                            key={entry.package_name}
                            label={entry.package_name}
                            meta={`${pluralize(asNumber(entry.activation_count), 'activation')}`}
                            barWidth={topPackageCount > 0 ? (asNumber(entry.activation_count) / topPackageCount) * 100 : 0}
                            accentClass="bg-amber-500"
                            valueNode={<p className="crm-mono text-lg font-semibold text-slate-900">{asNumber(entry.activation_count).toLocaleString()}</p>}
                            trailingNode={<p className="mt-1 text-xs text-slate-500">successful activations</p>}
                        />
                    ))
                )}
            </div>
        </SectionFrame>
    );
}

function UtilityCard({ eyebrow, title, meta, children, action }) {
    return (
        <SectionFrame
            title={title}
            subtitle={meta}
            action={action}
            className="crm-sales-panel"
            contentClassName="space-y-4"
        >
            <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-500">{eyebrow}</p>
            {children}
        </SectionFrame>
    );
}

function ExpiringSubscriptionsCard({ deals, isLoading, onOpen }) {
    const preview = deals.slice(0, 4);

    return (
        <UtilityCard
            eyebrow="Renewals"
            title="Expiring subscriptions"
            meta="Earliest renewals first so follow-up timing stays tight."
            action={<ActionButton label="Open all" onClick={() => onOpen()} />}
        >
            {isLoading ? (
                <LoadingStack count={3} cardClassName="h-16 rounded-[18px] bg-slate-100" />
            ) : preview.length === 0 ? (
                <EmptyState message="No subscriptions are expiring soon." />
            ) : (
                <div className="grid gap-3 lg:grid-cols-2">
                    {preview.map((deal) => (
                        <button
                            key={deal.id}
                            type="button"
                            onClick={() => deal.client?.id && onOpen(deal.client.id)}
                            className="flex w-full items-center justify-between gap-3 rounded-[18px] border border-slate-200 bg-white px-4 py-3 text-left transition hover:border-slate-300"
                        >
                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-slate-900">{deal.client?.name || 'Unknown client'}</p>
                                <p className="truncate text-xs text-slate-500">{deal.product?.name || deal.plan_type || 'Subscription'}</p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="text-xs font-semibold text-amber-700">{formatExpiryWindow(deal.expires_at)}</p>
                                <p className="mt-1 text-xs text-slate-400">{formatDate(deal.expires_at)}</p>
                            </div>
                        </button>
                    ))}
                </div>
            )}
        </UtilityCard>
    );
}

function PaymentRecoveryCard({ kpis, onOpen, isLoading }) {
    const queueTotal = asNumber(kpis.payment_recovery_queue_total);
    const failed = asNumber(kpis.payment_recovery_failed);
    const pending = asNumber(kpis.payment_recovery_pending);
    const unmatched = asNumber(kpis.payment_recovery_unmatched);
    const breakdownRows = [
        {
            label: 'Failed payments',
            hint: 'Priority follow-up',
            value: failed,
            badge: 'Priority',
            tone: failed > 0 ? 'danger' : 'neutral',
        },
        {
            label: 'Pending payments',
            hint: 'Awaiting completion',
            value: pending,
            badge: 'Awaiting',
            tone: pending > 0 ? 'warning' : 'neutral',
        },
        {
            label: 'Unmatched payments',
            hint: 'Needs reconciliation',
            value: unmatched,
            badge: 'Manual',
            tone: unmatched > 0 ? 'warning' : 'neutral',
        },
    ];
    const primaryTone = failed > 0 ? 'danger' : queueTotal > 0 ? 'warning' : 'success';
    const primaryLabel = failed > 0
        ? `${failed} failed need action`
        : queueTotal > 0
            ? 'Queue needs review'
            : 'Queue clear';

    return (
        <UtilityCard
            eyebrow="Payments"
            title="Recovery queue"
            meta="Keep failed, pending, and unmatched payments moving."
            action={<ActionButton label="Open queue" onClick={onOpen} />}
        >
            {isLoading ? (
                <div className="overflow-hidden rounded-[20px] border border-slate-200 bg-white">
                    <div className="border-b border-slate-200 px-4 py-4">
                        <div className="animate-pulse">
                            <div className="h-3 w-24 rounded bg-slate-100" />
                            <div className="mt-3 h-10 w-24 rounded bg-slate-100" />
                            <div className="mt-3 h-3 w-48 rounded bg-slate-100" />
                        </div>
                    </div>
                    <div className="divide-y divide-slate-200">
                        {Array.from({ length: 3 }).map((_, index) => (
                            <div key={index} className="flex items-center justify-between gap-4 px-4 py-3.5">
                                <div className="animate-pulse">
                                    <div className="h-4 w-28 rounded bg-slate-100" />
                                    <div className="mt-2 h-3 w-24 rounded bg-slate-100" />
                                </div>
                                <div className="animate-pulse text-right">
                                    <div className="ml-auto h-8 w-10 rounded bg-slate-100" />
                                    <div className="mt-2 ml-auto h-7 w-16 rounded-xl bg-slate-100" />
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            ) : (
            <div className="overflow-hidden rounded-[20px] border border-slate-200 bg-white">
                <div className="border-b border-slate-200 px-4 py-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Queue total</p>
                            <p className="mt-2 text-[2.4rem] leading-none font-semibold tracking-[-0.04em] text-slate-950">{queueTotal.toLocaleString()}</p>
                            <p className="mt-2 text-sm text-slate-500">payments waiting for review or reconciliation</p>
                        </div>
                        <Badge label={primaryLabel} tone={primaryTone} />
                    </div>
                </div>
                <div className="divide-y divide-slate-200">
                    {breakdownRows.map((row) => (
                        <div key={row.label} className="flex items-center justify-between gap-4 px-4 py-3.5">
                            <div className="min-w-0">
                                <p className="text-sm font-semibold text-slate-800">{row.label}</p>
                                <p className="mt-1 text-xs text-slate-500">{row.hint}</p>
                            </div>
                            <div className="shrink-0 text-right">
                                <p className="text-2xl leading-none font-semibold tracking-[-0.03em] text-slate-950">{row.value.toLocaleString()}</p>
                                <div className="mt-2 flex justify-end">
                                    <Badge label={row.badge} tone={row.tone} />
                                </div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            )}
        </UtilityCard>
    );
}

function MissedChatsCard({ count, onOpen }) {
    const unavailable = count === null || count === undefined;

    return (
        <UtilityCard
            eyebrow="Support board"
            title="Missed chats"
            meta="A count-first signal for open conversations across configured markets."
            action={onOpen ? <ActionButton label="Open chats" onClick={onOpen} /> : null}
        >
            <div className="grid gap-4 rounded-[20px] border border-slate-200 bg-white px-4 py-5 lg:grid-cols-[180px_minmax(0,1fr)]">
                <div className="rounded-[18px] border border-slate-200 bg-slate-50/70 px-4 py-4">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Open conversation count</p>
                    <p className="mt-3 text-[2.3rem] leading-none font-semibold tracking-[-0.04em] text-slate-950">
                        {unavailable ? '—' : asNumber(count).toLocaleString()}
                    </p>
                </div>
                <div className="flex flex-col justify-center">
                    <p className="text-sm leading-7 text-slate-500">
                        {unavailable
                            ? 'Support board data is not configured or is temporarily unavailable for this scope.'
                            : 'Use this as a prompt to clear open conversation backlog before response quality starts slipping.'}
                    </p>
                </div>
            </div>
        </UtilityCard>
    );
}

function MarketSyncPanel({ markets, onSync, isPending, syncingMarketId }) {
    return (
        <SectionFrame
            title="Market Sync Utility"
            subtitle="A quieter utility area for delta syncs when you need to refresh WordPress profile state without leaving the sales board."
            className="crm-sales-panel crm-sales-panel-muted"
            contentClassName="space-y-4"
        >
            {markets.length === 0 ? (
                <EmptyState message="No accessible markets were returned for sync operations." />
            ) : (
                <div className="grid gap-3 xl:grid-cols-2">
                    {markets.map((market) => {
                        const syncResult = market.sync_last_result?.clients || {};
                        const lastDelta = market.last_delta || syncResult || {};
                        const latestRun = market.client_sync?.latest_run || null;
                        const isSyncing = latestRun?.in_progress || (isPending && syncingMarketId === market.id);
                        const statusLabel = latestRun?.status || market.sync_last_status || 'unknown';
                        return (
                            <div key={market.id} className="rounded-[22px] border border-slate-200 bg-white/90 p-4 shadow-sm">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
                                            <span className="text-lg" aria-hidden="true">{getCountryFlag(market.country)}</span>
                                            <p className="truncate text-base font-semibold text-slate-900">{market.name}</p>
                                        </div>
                                        <p className="mt-1 text-sm text-slate-500">
                                            {market.country || 'Unknown country'} • {market.currency || 'KES'}
                                        </p>
                                    </div>
                                    <span className={`crm-sales-chip ${syncStatusTone(statusLabel)}`}>
                                        {statusLabel}
                                    </span>
                                </div>

                                <div className="mt-4 grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Profiles</p>
                                        <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{market.profiles_total?.toLocaleString?.() || '0'}</p>
                                        <p className="mt-1 text-xs text-slate-500">current CRM total</p>
                                    </div>
                                    <div className="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Last created</p>
                                        <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{asNumber(lastDelta.created).toLocaleString()}</p>
                                        <p className="mt-1 text-xs text-slate-500">last delta sync</p>
                                    </div>
                                    <div className="rounded-xl border border-slate-200 bg-white px-3 py-3">
                                        <p className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500">Last updated</p>
                                        <p className="mt-2 text-xl font-semibold tracking-tight text-slate-950">{asNumber(lastDelta.updated).toLocaleString()}</p>
                                        <p className="mt-1 text-xs text-slate-500">last delta sync</p>
                                    </div>
                                </div>

                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
                                    <div className="text-sm text-slate-500">
                                        <p>Last sync: <span className="font-medium text-slate-700">{formatDateTime(market.sync_last_synced_at)}</span></p>
                                        {latestRun?.in_progress ? (
                                            <p className="mt-1 text-amber-700">
                                                Background {latestRun.mode || 'delta'} sync is running via {latestRun.protocol || market.client_sync?.protocol || 'pending'}.
                                            </p>
                                        ) : null}
                                        <p className="mt-1">
                                            {asNumber(lastDelta.total) > 0
                                                ? `Last delta processed ${asNumber(lastDelta.total).toLocaleString()} profile updates.`
                                                : 'Last delta completed with no profile changes.'}
                                        </p>
                                        {market.sync_last_error ? <p className="mt-1 text-rose-600">{market.sync_last_error}</p> : null}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => onSync(market)}
                                        disabled={isSyncing}
                                        className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${market.needs_sync ? 'bg-slate-950 text-white hover:bg-slate-800' : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'} disabled:cursor-not-allowed disabled:opacity-60`}
                                    >
                                        {isSyncing ? 'Syncing…' : market.needs_sync ? 'Refresh now' : 'Run delta sync'}
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </SectionFrame>
    );
}

export default function SalesDashboardView({ user, navigate }) {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [marketFilter, setMarketFilter] = useState(() => {
        if (typeof window === 'undefined') {
            return '';
        }

        return normalizeMarketFilter(window.localStorage.getItem(SALES_MARKET_STORAGE_KEY));
    });
    const [rangeKey, setRangeKey] = useState(() => {
        if (typeof window === 'undefined') {
            return SALES_RANGE_OPTIONS[1].key;
        }

        const stored = window.localStorage.getItem(SALES_RANGE_STORAGE_KEY);
        return resolveRangeOption(stored).key;
    });
    const [draftTodo, setDraftTodo] = useState('');
    const [draftGoalId, setDraftGoalId] = useState('');
    const [draftDueAt, setDraftDueAt] = useState('');

    const selectedRange = resolveRangeOption(rangeKey);
    const fromDate = isoDateDaysAgo(selectedRange.days - 1);
    const toDate = isoDateDaysAgo(0);
    const marketsCacheKey = makeDashboardCacheKey(user, 'markets');
    const summaryCacheKey = makeDashboardCacheKey(user, `summary.${marketFilter || 'all'}.${rangeKey}`);
    const myStatsCacheKey = makeDashboardCacheKey(user, `stats.${marketFilter || 'all'}.${selectedRange.days > 7 ? 'month' : 'week'}`);

    const { config: widgetConfig } = useSalesWidgetConfig();

    const marketsQuery = useQuery({
        queryKey: ['sales-dashboard-markets'],
        queryFn: () => api.get('/crm/dashboard/my-markets').then((response) => response.data),
        initialData: () => readDashboardCache(marketsCacheKey)?.data,
        initialDataUpdatedAt: () => readDashboardCache(marketsCacheKey)?.updatedAt,
        staleTime: 300_000,
        refetchOnWindowFocus: false,
        refetchInterval: (query) => Array.isArray(query.state.data)
            && query.state.data.some((market) => market?.client_sync?.latest_run?.in_progress)
            ? 4000
            : false,
    });

    const markets = marketsQuery.data || [];
    const selectedMarket = markets.find((market) => String(market.id) === String(marketFilter)) || null;

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        if (marketFilter) {
            window.localStorage.setItem(SALES_MARKET_STORAGE_KEY, marketFilter);
            return;
        }

        window.localStorage.removeItem(SALES_MARKET_STORAGE_KEY);
    }, [marketFilter]);

    useEffect(() => {
        if (typeof window === 'undefined') {
            return;
        }

        window.localStorage.setItem(SALES_RANGE_STORAGE_KEY, rangeKey);
    }, [rangeKey]);

    useEffect(() => {
        if (!marketFilter || markets.length === 0) {
            return;
        }

        const stillAccessible = markets.some((market) => String(market.id) === String(marketFilter));
        if (!stillAccessible) {
            setMarketFilter('');
        }
    }, [marketFilter, markets]);

    useEffect(() => {
        if (marketsQuery.data) {
            writeDashboardCache(marketsCacheKey, marketsQuery.data);
        }
    }, [marketsCacheKey, marketsQuery.data]);

    const summaryQuery = useQuery({
        queryKey: ['sales-dashboard-summary', marketFilter, rangeKey],
        queryFn: () => api.get('/crm/dashboard', {
            params: {
                sales_view: 1,
                from: fromDate,
                to: toDate,
                country_period: selectedRange.days > 7 ? 'month' : 'week',
                ...(marketFilter ? { platform_id: Number(marketFilter) } : {}),
            },
        }).then((response) => response.data),
        initialData: () => readDashboardCache(summaryCacheKey)?.data,
        initialDataUpdatedAt: () => readDashboardCache(summaryCacheKey)?.updatedAt,
        placeholderData: (previousData) => previousData,
        staleTime: 60_000,
        refetchInterval: DASHBOARD_REFRESH_MS,
        refetchOnWindowFocus: false,
    });

    const countriesQuery = useQuery({
        queryKey: ['sales-dashboard-country-revenue', marketFilter, rangeKey],
        queryFn: () => api.get('/crm/dashboard/country-revenue', {
            params: {
                from: fromDate,
                to: toDate,
                country_period: selectedRange.days > 7 ? 'month' : 'week',
                ...(marketFilter ? { platform_id: Number(marketFilter) } : {}),
            },
        }).then((response) => response.data),
        staleTime: 60_000,
        refetchInterval: DASHBOARD_REFRESH_MS,
        refetchOnWindowFocus: false,
    });

    const myStatsQuery = useQuery({
        queryKey: ['sales-dashboard-my-stats', marketFilter, selectedRange.days > 7 ? 'month' : 'week'],
        queryFn: () => api.get('/crm/team/me', {
            params: {
                period: selectedRange.days > 7 ? 'month' : 'week',
                ...(marketFilter ? { platform_id: Number(marketFilter) } : {}),
            },
        }).then((response) => response.data),
        initialData: () => readDashboardCache(myStatsCacheKey)?.data,
        initialDataUpdatedAt: () => readDashboardCache(myStatsCacheKey)?.updatedAt,
        placeholderData: (previousData) => previousData,
        staleTime: 60_000,
        refetchInterval: DASHBOARD_REFRESH_MS,
        refetchOnWindowFocus: false,
    });

    useEffect(() => {
        if (summaryQuery.data) {
            writeDashboardCache(summaryCacheKey, summaryQuery.data);
        }
    }, [summaryCacheKey, summaryQuery.data]);

    useEffect(() => {
        if (myStatsQuery.data) {
            writeDashboardCache(myStatsCacheKey, myStatsQuery.data);
        }
    }, [myStatsCacheKey, myStatsQuery.data]);

    const todosQuery = useQuery({
        queryKey: ['sales-dashboard-todos'],
        queryFn: () => api.get('/crm/todos').then((response) => response.data?.data || []),
        staleTime: 30_000,
    });

    const addTodoMutation = useMutation({
        mutationFn: (payload) => api.post('/crm/todos', payload).then((response) => response.data?.todo),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-todos'] });
            setDraftTodo('');
            setDraftGoalId('');
            setDraftDueAt('');
            toast.success('Task added to your action list.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to add task right now.');
        },
    });

    const updateTodoMutation = useMutation({
        mutationFn: ({ id, payload }) => api.patch(`/crm/todos/${id}`, payload).then((response) => response.data?.todo),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-todos'] });
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to update task right now.');
        },
    });

    const deleteTodoMutation = useMutation({
        mutationFn: (id) => api.delete(`/crm/todos/${id}`),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-todos'] });
            toast.info('Task removed from your action list.');
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || 'Unable to remove task right now.');
        },
    });

    const syncMutation = useMutation({
        mutationFn: (market) => api.post(`/crm/markets/${market.id}/sync`, {
            reason: 'Triggered from sales dashboard',
        }).then((response) => response.data),
        onSuccess: (data) => {
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-markets'] });
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-summary'] });
            queryClient.invalidateQueries({ queryKey: ['sales-dashboard-my-stats'] });
            toast[data?.reused_run ? 'warning' : 'success'](
                data?.message || (data?.reused_run
                    ? `${data?.platform?.platform_name || 'Market'} already has a sync in progress.`
                    : `${data?.platform?.platform_name || 'Market'} sync started in the background.`)
            );
        },
        onError: (error) => {
            toast.error(error?.response?.data?.message || error?.response?.data?.error || 'Market sync failed.');
        },
    });

    const summary = summaryQuery.data || {};
    const kpis = summary.kpis || {};
    const myStats = myStatsQuery.data || {};
    const goals = Array.isArray(myStats.goals) ? myStats.goals : [];
    const todos = todosQuery.data || [];
    const revenueBreakdown = kpis.revenue_window_breakdown || {};
    const revenueScalar = kpis.revenue_window ?? null;
    const revenueCurrencies = Object.keys(revenueBreakdown);
    const revenueCurrency = selectedMarket?.currency || revenueCurrencies[0] || 'KES';
    const revenueDelta = Number(kpis.revenue_delta_percent);
    const revenueDeltaLabel = Number.isFinite(revenueDelta)
        ? `${revenueDelta > 0 ? '+' : ''}${revenueDelta}% vs previous window`
        : 'No comparable baseline yet';
    const newUsers = kpis.new_users || { crm_created: 0, wp_organic: 0, total: 0 };
    const renewalWorkload = asNumber(kpis.renewal_workload_14d);
    const activeClients = asNumber(kpis.active_clients);
    const totalClients = asNumber(kpis.total_clients);
    const expiringDeals = summary.expiring_deals || [];
    const countries = Array.isArray(countriesQuery.data) ? countriesQuery.data : [];
    const packages = summary.top_packages || [];
    const missedChatsCount = kpis.missed_chats_count;
    const isHeroBooting = (!summaryQuery.data && summaryQuery.isLoading) || (!myStatsQuery.data && myStatsQuery.isLoading);
    const isPrimaryMetricsBooting = !summaryQuery.data && summaryQuery.isLoading;

    const submitTodo = (event) => {
        event.preventDefault();

        if (!draftTodo.trim()) {
            return;
        }

        addTodoMutation.mutate({
            content: draftTodo.trim(),
            ...(draftGoalId ? { goal_id: Number(draftGoalId) } : {}),
            ...(draftDueAt ? { due_at: draftDueAt } : {}),
        });
    };

    const handleTodoToggle = (todo) => {
        updateTodoMutation.mutate({
            id: todo.id,
            payload: {
                status: todo.status === 'done' ? 'pending' : 'done',
            },
        });
    };

    const handleTodoDelete = (todo) => {
        deleteTodoMutation.mutate(todo.id);
    };

    const handleSync = (market) => {
        syncMutation.mutate(market);
    };

    return (
        <div className="crm-sales-shell">
            <SalesHero
                user={user}
                selectedMarket={selectedMarket}
                marketFilter={marketFilter}
                onMarketChange={(value) => setMarketFilter(normalizeMarketFilter(value))}
                markets={markets}
                rangeKey={rangeKey}
                onRangeChange={setRangeKey}
                summary={summary}
                myStats={myStats}
                isLoading={isHeroBooting}
                onOpenRecovery={() => navigate(marketFilter ? `/payments?status=recovery_queue&platform_id=${marketFilter}` : '/payments?status=recovery_queue')}
                onOpenRenewals={() => navigate(marketFilter ? `/deals?bucket=workload&platform_id=${marketFilter}` : '/deals?bucket=workload')}
            />

            <section className="grid gap-4 xl:grid-cols-12">
                {isPrimaryMetricsBooting ? (
                    <>
                        <div className="crm-sales-panel bg-white xl:col-span-5">
                            <div className="animate-pulse space-y-4 px-5 py-5">
                                <div className="h-3 w-28 rounded bg-slate-100" />
                                <div className="h-14 w-48 rounded bg-slate-100" />
                                <div className="h-4 w-40 rounded bg-slate-100" />
                                <div className="h-10 w-32 rounded-xl bg-slate-100" />
                            </div>
                        </div>
                        {['xl:col-span-2', 'xl:col-span-2', 'xl:col-span-3'].map((spanClass, index) => (
                            <div key={index} className={`crm-sales-panel bg-white ${spanClass}`}>
                                <div className="animate-pulse space-y-4 px-5 py-5">
                                    <div className="h-3 w-24 rounded bg-slate-100" />
                                    <div className="h-12 w-24 rounded bg-slate-100" />
                                    <div className="h-4 w-36 rounded bg-slate-100" />
                                    <div className="h-10 w-28 rounded-xl bg-slate-100" />
                                </div>
                            </div>
                        ))}
                    </>
                ) : (
                    <>
                        <SalesKpiCard
                            label="Collected revenue"
                            value={(
                                <CurrencyAmount
                                    breakdown={revenueBreakdown}
                                    scalarAmount={revenueScalar}
                                    fallbackCurrency={revenueCurrency}
                                    className="crm-mono"
                                    stackClassName="crm-mono"
                                />
                            )}
                            meta={revenueCurrencies.length > 1 ? 'Mixed-currency revenue in this scope' : `Window anchored to ${selectedRange.label.toLowerCase()}`}
                            subMeta={revenueDeltaLabel}
                            featured
                            actionLabel="Open payments"
                            onClick={() => navigate(marketFilter ? `/payments?status=completed&platform_id=${marketFilter}` : '/payments?status=completed')}
                            className="xl:col-span-5"
                            badge={<Badge label={Number.isFinite(revenueDelta) && revenueDelta !== 0 ? `${revenueDelta > 0 ? '+' : ''}${revenueDelta}%` : 'No change'} tone={revenueDelta > 0 ? 'success' : revenueDelta < 0 ? 'danger' : 'neutral'} />}
                        />

                        <SalesKpiCard
                            label="Active clients"
                            value={activeClients.toLocaleString()}
                            meta={totalClients > 0 ? `${Math.round((activeClients / totalClients) * 100)}% of ${totalClients.toLocaleString()} profiles are active` : 'No profiles found in this scope'}
                            actionLabel="Open clients"
                            onClick={() => navigate(marketFilter ? `/clients?status=publish&platform_id=${marketFilter}` : '/clients?status=publish')}
                            className="xl:col-span-2"
                            badge={<Badge label={totalClients > 0 ? `${Math.round((activeClients / totalClients) * 100)}% active` : 'No profiles'} tone={activeClients > 0 ? 'success' : 'neutral'} />}
                        />

                        <SalesKpiCard
                            label="Renewal workload"
                            value={renewalWorkload.toLocaleString()}
                            meta={renewalWorkload > 0 ? `${asNumber(kpis.renewal_risk_72h).toLocaleString()} in 0-3 days • ${asNumber(kpis.renewal_pipeline_4_14d).toLocaleString()} in 4-14 days` : 'No renewals due in the next 14 days'}
                            actionLabel="Open renewals"
                            onClick={() => navigate(marketFilter ? `/deals?bucket=workload&platform_id=${marketFilter}` : '/deals?bucket=workload')}
                            className="xl:col-span-2"
                            badge={<Badge label={renewalWorkload > 0 ? 'Due soon' : 'Clear'} tone={renewalWorkload > 0 ? 'warning' : 'success'} />}
                        />

                        <SalesKpiCard
                            label="New users"
                            value={newUsers.total.toLocaleString()}
                            meta={`${newUsers.crm_created.toLocaleString()} CRM-created • ${newUsers.wp_organic.toLocaleString()} organic`}
                            subMeta="Trailing 7-day intake, split so sourcing stays visible."
                            actionLabel="Open leads"
                            onClick={() => navigate(marketFilter ? `/leads?platform_id=${marketFilter}` : '/leads')}
                            className="xl:col-span-3"
                            badge={<Badge label="7-day intake" tone="neutral" />}
                        />
                    </>
                )}
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                {widgetConfig.todos ? (
                    <div className={widgetConfig.goals ? 'xl:col-span-7' : 'xl:col-span-12'}>
                        <TodoListWidget
                            todos={todos}
                            goals={goals}
                            marketFilter={marketFilter}
                            isLoading={todosQuery.isLoading}
                            isSaving={addTodoMutation.isPending}
                            draftTodo={draftTodo}
                            setDraftTodo={setDraftTodo}
                            draftGoalId={draftGoalId}
                            setDraftGoalId={setDraftGoalId}
                            draftDueAt={draftDueAt}
                            setDraftDueAt={setDraftDueAt}
                            onSubmit={submitTodo}
                            onToggleStatus={handleTodoToggle}
                            onDelete={handleTodoDelete}
                        />
                    </div>
                ) : null}

                {widgetConfig.goals ? (
                    <div className={widgetConfig.todos ? 'xl:col-span-5' : 'xl:col-span-12'}>
                        <GoalsWidget goals={goals} isLoading={myStatsQuery.isLoading} />
                    </div>
                ) : null}
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                {(widgetConfig.top_countries || widgetConfig.top_packages) ? (
                    <div className="xl:col-span-8">
                        <MomentumCanvas
                            countries={widgetConfig.top_countries ? countries : []}
                            packages={widgetConfig.top_packages ? packages : []}
                            isLoading={summaryQuery.isLoading || countriesQuery.isLoading}
                        />
                    </div>
                ) : null}

                <div className={`${widgetConfig.top_countries || widgetConfig.top_packages ? 'xl:col-span-4' : 'xl:col-span-12'} space-y-4`}>
                    {widgetConfig.payment_recovery ? (
                        <PaymentRecoveryCard
                            kpis={kpis}
                            isLoading={isPrimaryMetricsBooting}
                            onOpen={() => navigate(marketFilter ? `/payments?status=recovery_queue&platform_id=${marketFilter}` : '/payments?status=recovery_queue')}
                        />
                    ) : null}
                </div>
            </section>

            <section className="grid gap-4 xl:grid-cols-12">
                {widgetConfig.missed_chats ? (
                    <div className={widgetConfig.expiring_subs ? 'xl:col-span-4' : 'xl:col-span-12'}>
                        <MissedChatsCard
                            count={missedChatsCount}
                            onOpen={() => navigate(marketFilter ? `/conversations?platform_id=${marketFilter}` : '/conversations')}
                        />
                    </div>
                ) : null}

                {widgetConfig.expiring_subs ? (
                    <div className={widgetConfig.missed_chats ? 'xl:col-span-8' : 'xl:col-span-12'}>
                        <ExpiringSubscriptionsCard
                            deals={expiringDeals}
                            isLoading={summaryQuery.isLoading}
                            onOpen={(clientId) => navigate(clientId ? `/clients/${clientId}` : (marketFilter ? `/deals?status=active&platform_id=${marketFilter}` : '/deals?status=active'))}
                        />
                    </div>
                ) : null}
            </section>

            {widgetConfig.profile_engagement ? (
                <ProfileEngagementWidget
                    platformFilter={marketFilter}
                    fromDate={fromDate}
                    toDate={toDate}
                    onOpenProfile={(clientId) => navigate(`/clients/${clientId}?tab=analytics`)}
                    onOpenReport={() => navigate(marketFilter ? `/reports?platform_id=${marketFilter}` : '/reports')}
                />
            ) : null}

            <MarketSyncPanel
                markets={markets}
                onSync={handleSync}
                isPending={syncMutation.isPending}
                syncingMarketId={syncMutation.variables?.id || null}
            />
        </div>
    );
}
