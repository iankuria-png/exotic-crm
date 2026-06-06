import React, { useState } from 'react';
import { useAutoOptimizeItems, useAutoOptimizeMetrics, useAutoOptimizeMutations, useAutoOptimizePlans } from '../../hooks/useAutoOptimize';
import MetricCard from '../MetricCard';
import StatusBadge from '../StatusBadge';
import ConfirmDialog from '../ConfirmDialog';
import { useAuth } from '../../hooks/useAuth';

// ─── Score ring ───────────────────────────────────────────────────────────

function ScoreRing({ score, size = 44 }) {
    const r = 16;
    const circ = 2 * Math.PI * r;
    const pct = Math.min(100, Math.max(0, score ?? 0));
    const dash = (pct / 100) * circ;
    const color = pct >= 70 ? '#10b981' : pct >= 40 ? '#f59e0b' : '#f43f5e';
    return (
        <svg width={size} height={size} viewBox="0 0 36 36" aria-label={`Score ${pct}`}>
            <circle cx="18" cy="18" r={r} fill="none" stroke="#e2e8f0" strokeWidth="3" />
            <circle
                cx="18" cy="18" r={r} fill="none" stroke={color} strokeWidth="3"
                strokeDasharray={`${dash} ${circ}`} strokeLinecap="round"
                transform="rotate(-90 18 18)"
                style={{ transition: 'stroke-dasharray 0.6s ease' }}
            />
            <text x="18" y="22" textAnchor="middle" fontSize="9" fontWeight="600" fill={color}>
                {pct}
            </text>
        </svg>
    );
}

// ─── Score delta chip ─────────────────────────────────────────────────────

function ScoreDelta({ prev, next }) {
    if (prev == null || next == null) return null;
    const delta = next - prev;
    const color = delta > 0 ? 'text-emerald-600' : delta < 0 ? 'text-rose-500' : 'text-slate-400';
    const arrow = delta > 0 ? '▲' : delta < 0 ? '▼' : '—';
    return <span className={`text-xs font-semibold ${color}`}>{arrow} {Math.abs(delta)}</span>;
}

// ─── Impact chip ─────────────────────────────────────────────────────────

function ImpactChip({ impact }) {
    if (!impact) {
        return <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-400">Measuring…</span>;
    }
    const improved = impact.improved;
    const viewsDelta = impact.views_delta_pct;
    const label = improved
        ? `▲ ${viewsDelta != null ? `+${viewsDelta.toFixed(0)}% views` : 'improved'}`
        : `▼ declined`;
    return (
        <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold ${improved ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-600'}`}>
            {label}
        </span>
    );
}

// ─── Bio diff toggle ──────────────────────────────────────────────────────

function BioDiff({ prev, next, language }) {
    const [show, setShow] = useState(false);
    if (!prev && !next) return null;
    const prevText = prev ? prev.replace(/<[^>]+>/g, '') : '';
    const nextText = next ? next.replace(/<[^>]+>/g, '') : '';
    return (
        <div className="mt-2">
            <button
                type="button"
                onClick={() => setShow((s) => !s)}
                className="text-[11px] font-medium text-teal-600 hover:underline focus-visible:outline-none"
                aria-expanded={show}
            >
                {show ? 'Hide bio diff' : 'Show bio diff'}
            </button>
            {show && (
                <div className="mt-2 grid gap-2 sm:grid-cols-2" role="group" aria-label="Bio comparison">
                    <div className="rounded-md border border-slate-200 bg-slate-50 p-3">
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-slate-400">Previous</p>
                        <p className="text-xs leading-relaxed text-slate-600 line-clamp-6">{prevText || '—'}</p>
                    </div>
                    <div className="rounded-md border border-teal-200 bg-teal-50 p-3">
                        <p className="mb-1 text-[10px] font-semibold uppercase tracking-wider text-teal-500">
                            New {language ? `· ${language.toUpperCase()}` : ''}
                        </p>
                        <p className="text-xs leading-relaxed text-slate-700 line-clamp-6">{nextText || '—'}</p>
                    </div>
                </div>
            )}
        </div>
    );
}

// ─── Image diff ───────────────────────────────────────────────────────────

function ImageDiff({ prevUrl, nextUrl }) {
    if (!prevUrl && !nextUrl) return null;
    return (
        <div className="mt-2 flex items-center gap-3">
            {prevUrl && (
                <div className="relative" title="Previous main image">
                    <img src={prevUrl} alt="Previous main" className="h-14 w-14 rounded-md object-cover ring-1 ring-slate-200 transition hover:scale-110" loading="lazy" />
                    <span className="absolute -top-1.5 -left-1.5 rounded-full bg-slate-500 px-1.5 py-0.5 text-[9px] font-bold text-white">OLD</span>
                </div>
            )}
            {prevUrl && nextUrl && <span className="text-slate-300 text-sm">→</span>}
            {nextUrl && (
                <div className="relative" title="New main image">
                    <img src={nextUrl} alt="New main" className="h-14 w-14 rounded-md object-cover ring-2 ring-teal-400 transition hover:scale-110" loading="lazy" />
                    <span className="absolute -top-1.5 -left-1.5 rounded-full bg-teal-500 px-1.5 py-0.5 text-[9px] font-bold text-white">NEW</span>
                </div>
            )}
        </div>
    );
}

// ─── Queue item card ──────────────────────────────────────────────────────

const STATUS_TONE = {
    queued: 'default', building: 'accent', pending: 'warning',
    applying: 'accent', applied: 'success', skipped: 'neutral',
    reverted: 'neutral', failed: 'danger',
};

function ItemCard({ item, canApply, onApprove, onRevert, onSkip }) {
    const [confirmRevert, setConfirmRevert] = useState(false);
    const client = item.client;
    const name = client?.name ?? `Client #${item.client_id}`;
    const actionsApplied = item.actions_applied ?? {};

    return (
        <article
            className="crm-surface group relative rounded-xl border border-slate-200 bg-white p-4 transition hover:border-slate-300 hover:shadow-sm"
            aria-label={`Optimization item for ${name}`}
        >
            {/* Header row */}
            <div className="flex items-start gap-3">
                {/* Avatar */}
                {client?.display_image_url ? (
                    <img src={client.display_image_url} alt={name}
                        className="h-10 w-10 shrink-0 rounded-full object-cover ring-1 ring-slate-200" loading="lazy" />
                ) : (
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold text-slate-500">
                        {name[0]?.toUpperCase()}
                    </div>
                )}

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="truncate text-sm font-semibold text-slate-900">{name}</span>
                        <StatusBadge status={item.status} />
                        {item.language_used && (
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-500 uppercase">
                                {item.language_used}
                            </span>
                        )}
                    </div>
                    {client?.city && <p className="mt-0.5 text-[11px] text-slate-400">{client.city}</p>}
                    {item.reason && <p className="mt-0.5 text-[11px] italic text-slate-400">{item.reason}</p>}
                </div>

                {/* Score before → after */}
                <div className="flex items-center gap-2 shrink-0" aria-label="Score change">
                    <div className="flex flex-col items-center">
                        <ScoreRing score={item.previous_score} size={40} />
                        <span className="mt-0.5 text-[9px] text-slate-400">Before</span>
                    </div>
                    <div className="flex flex-col items-center gap-1">
                        <ScoreDelta prev={item.previous_score} next={item.new_score} />
                        <span className="text-slate-300">→</span>
                    </div>
                    <div className="flex flex-col items-center">
                        <ScoreRing score={item.new_score} size={40} />
                        <span className="mt-0.5 text-[9px] text-slate-400">After</span>
                    </div>
                </div>
            </div>

            {/* Actions applied chips */}
            {Object.keys(actionsApplied).filter((k) => actionsApplied[k] && k !== 'score').length > 0 && (
                <div className="mt-2 flex flex-wrap gap-1">
                    {actionsApplied.bio && (
                        <span className="rounded-full border border-teal-200 bg-teal-50 px-2 py-0.5 text-[10px] font-medium text-teal-700">Bio optimized</span>
                    )}
                    {actionsApplied.image && (
                        <span className="rounded-full border border-purple-200 bg-purple-50 px-2 py-0.5 text-[10px] font-medium text-purple-700">Image swapped</span>
                    )}
                </div>
            )}

            {/* Impact chip */}
            {item.status === 'applied' && (
                <div className="mt-2">
                    <ImpactChip impact={item.impact} />
                </div>
            )}

            {/* AI cost */}
            {Number(item.ai_cost_usd) > 0 && (
                <p className="mt-1 text-[10px] text-slate-400">Cost: ${Number(item.ai_cost_usd).toFixed(4)}</p>
            )}

            {/* Bio diff */}
            <BioDiff prev={item.previous_bio_html} next={item.new_bio_html} language={item.language_used} />

            {/* Image diff */}
            <ImageDiff prevUrl={item.previous_main_image_url} nextUrl={item.new_main_image_url} />

            {/* Action buttons */}
            {canApply && (
                <div className="mt-3 flex flex-wrap gap-2 border-t border-slate-100 pt-3">
                    {item.status === 'pending' && (
                        <>
                            <button
                                type="button"
                                onClick={() => onApprove(item.id)}
                                className="rounded-md bg-teal-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-teal-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                            >
                                Approve
                            </button>
                            <button
                                type="button"
                                onClick={() => onSkip(item.id)}
                                className="rounded-md border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:border-slate-300 hover:bg-slate-50"
                            >
                                Skip
                            </button>
                        </>
                    )}
                    {item.status === 'applied' && (
                        <button
                            type="button"
                            onClick={() => setConfirmRevert(true)}
                            className="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-medium text-rose-600 transition hover:bg-rose-50"
                        >
                            Revert
                        </button>
                    )}
                </div>
            )}

            <ConfirmDialog
                open={confirmRevert}
                title="Revert optimization?"
                message="This will restore the previous bio and main image. The operation writes to WordPress immediately."
                confirmLabel="Revert"
                cancelLabel="Cancel"
                tone="danger"
                onConfirm={() => { setConfirmRevert(false); onRevert(item.id); }}
                onCancel={() => setConfirmRevert(false)}
            />
        </article>
    );
}

// ─── Skeleton card ────────────────────────────────────────────────────────

function SkeletonCard() {
    return (
        <div className="crm-surface rounded-xl border border-slate-100 bg-white p-4" aria-hidden="true">
            <div className="flex items-start gap-3">
                <div className="h-10 w-10 rounded-full bg-slate-100 animate-pulse" />
                <div className="flex-1 space-y-2">
                    <div className="h-4 w-2/3 rounded bg-slate-100 animate-pulse" />
                    <div className="h-3 w-1/3 rounded bg-slate-100 animate-pulse" />
                </div>
                <div className="h-10 w-24 rounded bg-slate-100 animate-pulse" />
            </div>
            <div className="mt-4 h-3 w-full rounded bg-slate-100 animate-pulse" />
            <div className="mt-2 h-3 w-4/5 rounded bg-slate-100 animate-pulse" />
        </div>
    );
}

// ─── Main view ────────────────────────────────────────────────────────────

export default function AutoOptimizeView({ platformId }) {
    const { user } = useAuth();
    const canApply = ['admin', 'sub_admin', 'sales'].includes(user?.role);
    const canConfigure = ['admin', 'sub_admin', 'marketing'].includes(user?.role);

    const [statusFilter, setStatusFilter] = useState('');
    const [selectedPlanId, setSelectedPlanId] = useState(null);

    const plansQuery = useAutoOptimizePlans();
    const itemsQuery = useAutoOptimizeItems({ platformId, status: statusFilter || undefined });
    const metricsQuery = useAutoOptimizeMetrics(platformId);
    const { approve, approveAll, revert, skip, runNow } = useAutoOptimizeMutations();

    const plans = plansQuery.data ?? [];
    const items = itemsQuery.data?.data ?? [];
    const metrics = metricsQuery.data ?? {};

    const pendingCount = metrics.pending ?? 0;
    const pctImproved = metrics.pct_improved != null ? `${metrics.pct_improved}%` : '—';

    // Active plan for current platform
    const activePlan = plans.find((p) => (!platformId || p.platform_id === Number(platformId)) && p.enabled);

    const STATUS_FILTERS = [
        { label: 'All', value: '' },
        { label: 'Pending', value: 'pending' },
        { label: 'Applied', value: 'applied' },
        { label: 'Skipped', value: 'skipped' },
        { label: 'Failed', value: 'failed' },
        { label: 'Reverted', value: 'reverted' },
    ];

    return (
        <div className="space-y-5" role="region" aria-label="Auto Optimize queue">
            {/* Metric cards */}
            <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Optimizer metrics">
                <MetricCard
                    label="Optimized"
                    value={(metrics.optimized ?? 0).toLocaleString()}
                    tone="success"
                    hint={`${pctImproved} improved`}
                    isLoading={metricsQuery.isLoading}
                />
                <MetricCard
                    label="Cost"
                    value={metrics.cost_usd != null ? `$${Number(metrics.cost_usd).toFixed(2)}` : '—'}
                    tone="accent"
                    hint="AI generation spend"
                    isLoading={metricsQuery.isLoading}
                />
                <MetricCard
                    label="Pending"
                    value={pendingCount.toLocaleString()}
                    tone={pendingCount > 0 ? 'warning' : 'default'}
                    hint={canApply && pendingCount > 0 ? 'Awaiting approval' : undefined}
                    isLoading={metricsQuery.isLoading}
                />
                <MetricCard
                    label="Skipped"
                    value={(metrics.skipped ?? 0).toLocaleString()}
                    tone="neutral"
                    hint="Below gain threshold or similar"
                    isLoading={metricsQuery.isLoading}
                />
            </section>

            {/* Toolbar */}
            <div className="flex flex-wrap items-center gap-3">
                {/* Status filter chips */}
                <div className="flex flex-wrap gap-1" role="group" aria-label="Filter by status">
                    {STATUS_FILTERS.map(({ label, value }) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setStatusFilter(value)}
                            className={`rounded-full px-3 py-1 text-xs font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                statusFilter === value
                                    ? 'bg-teal-600 text-white'
                                    : 'border border-slate-200 bg-white text-slate-600 hover:border-teal-300 hover:bg-teal-50'
                            }`}
                            aria-pressed={statusFilter === value}
                        >
                            {label}
                        </button>
                    ))}
                </div>

                <div className="flex-1" />

                {/* Approve all */}
                {canApply && pendingCount > 0 && activePlan && (
                    <button
                        type="button"
                        onClick={() => approveAll.mutate(activePlan.id)}
                        disabled={approveAll.isPending}
                        className="rounded-md bg-teal-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-teal-700 disabled:opacity-50"
                    >
                        {approveAll.isPending ? 'Queueing…' : `Approve all (${pendingCount})`}
                    </button>
                )}

                {/* Manual run */}
                {canConfigure && activePlan && (
                    <button
                        type="button"
                        onClick={() => runNow.mutate(activePlan.id)}
                        disabled={runNow.isPending}
                        className="rounded-md border border-slate-200 px-4 py-2 text-sm font-medium text-slate-600 transition hover:border-teal-300 hover:bg-teal-50 disabled:opacity-50"
                    >
                        {runNow.isPending ? 'Running…' : 'Run now'}
                    </button>
                )}
            </div>

            {/* Autopilot notice */}
            {activePlan && !activePlan.autopilot && pendingCount > 0 && (
                <div className="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                    <span className="text-amber-500" aria-hidden="true">⚠</span>
                    <p className="text-sm text-amber-800">
                        <strong>Approval mode active.</strong> {pendingCount} profile{pendingCount > 1 ? 's' : ''} staged — review and approve below, or enable autopilot in Settings.
                    </p>
                </div>
            )}

            {/* Queue list */}
            {itemsQuery.isLoading ? (
                <div className="space-y-3">
                    {[...Array(3)].map((_, i) => <SkeletonCard key={i} />)}
                </div>
            ) : items.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-20 text-center">
                    <div className="mb-3 text-4xl" aria-hidden="true">✨</div>
                    <p className="text-base font-semibold text-slate-700">
                        {statusFilter ? `No ${statusFilter} items` : 'No profiles need attention right now'}
                    </p>
                    <p className="mt-1 text-sm text-slate-400">
                        {statusFilter ? 'Try a different filter.' : 'The engine will queue profiles on its next run.'}
                    </p>
                </div>
            ) : (
                <div className="space-y-3">
                    {items.map((item) => (
                        <ItemCard
                            key={item.id}
                            item={item}
                            canApply={canApply}
                            onApprove={(id) => approve.mutate(id)}
                            onRevert={(id) => revert.mutate({ itemId: id })}
                            onSkip={(id) => skip.mutate(id)}
                        />
                    ))}
                </div>
            )}

            {/* Error state */}
            {itemsQuery.isError && (
                <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                    Failed to load items. {itemsQuery.error?.message}
                </div>
            )}
        </div>
    );
}
