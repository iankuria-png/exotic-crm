import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
    retentionBandClasses,
    retentionBandAccent,
    salesBandLabel,
    salesBandBorderColor,
    mapDriverToAction,
    isRetentionWatchBand,
} from '../utils/retention';

const AREA_LABELS = {
    payments: 'Payments',
    subscription_lifecycle: 'Subscription',
    engagement_recency: 'Activity',
    reminder_responsiveness: 'Reminders',
    notification_responsiveness: 'Notifications',
    market_baseline: 'Market Average',
};

const CURATED_SIGNALS = [
    { key: 'days_to_expiry', label: 'Subscription ends in', component: 'subscription_lifecycle', format: (v) => v === null || v === undefined ? '—' : v < 0 ? `Overdue by ${Math.abs(v)} days` : v === 0 ? 'Today' : `${v} days` },
    { key: 'days_since_online', label: 'Last active', component: 'engagement_recency', format: (v) => v === null || v === undefined ? '—' : v === 0 ? 'Today' : `${v} days ago` },
    { key: 'completed_count', label: 'Successful payments', component: 'payments', format: (v) => v ?? '—' },
    { key: 'failed_count', label: 'Failed payments', component: 'payments', format: (v) => v ?? '—', warn: (v) => v > 0 },
    { key: 'pending_count', label: 'Pending payments', component: 'payments', format: (v) => v ?? '—', warn: (v) => v > 0 },
    { key: 'awaiting_payment_count', label: 'Awaiting payment', component: 'subscription_lifecycle', format: (v) => v ?? '—', warn: (v) => v > 0 },
    { key: 'recent_cancellations', label: 'Recent cancellations', component: 'subscription_lifecycle', format: (v) => v ?? '—', warn: (v) => v > 0 },
    { key: 'sent_count', label: 'Reminders sent', component: 'reminder_responsiveness', format: (v) => v ?? '—' },
    { key: 'response_received', label: 'Renewed after reminder', component: 'reminder_responsiveness', format: (v) => v === true ? 'Yes' : v === false ? 'No' : '—' },
];

function HealthBandBadge({ band }) {
    return (
        <span className={`inline-flex items-center rounded-md px-2.5 py-1 text-xs font-semibold ring-1 ring-inset ${retentionBandClasses(band)}`}>
            {salesBandLabel(band)}
        </span>
    );
}

function formatDaysAgo(days) {
    if (days === null || days === undefined) return '—';
    if (days === 0) return 'Today';
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    if (days < 30) return `${Math.floor(days / 7)} weeks ago`;
    return `${Math.floor(days / 30)} months ago`;
}

function SeverityDots({ score, band }) {
    const filled = score <= 20 ? 1 : score <= 40 ? 2 : score <= 60 ? 3 : score <= 80 ? 4 : 5;
    const accentColor = retentionBandAccent(band);
    return (
        <div className="mt-1.5 flex gap-1">
            {[1, 2, 3, 4, 5].map((i) => (
                <span
                    key={i}
                    className={`h-1.5 w-1.5 rounded-full ${i <= filled ? accentColor : 'bg-slate-200'}`}
                />
            ))}
        </div>
    );
}

function AreaStatusDot({ score }) {
    if (score < 30) return <span className="flex items-center gap-1.5 text-xs font-medium text-emerald-600"><span className="h-2 w-2 rounded-full bg-emerald-500" />Good</span>;
    if (score <= 60) return <span className="flex items-center gap-1.5 text-xs font-medium text-amber-600"><span className="h-2 w-2 rounded-full bg-amber-500" />Watch</span>;
    return <span className="flex items-center gap-1.5 text-xs font-medium text-rose-600"><span className="h-2 w-2 rounded-full bg-rose-500" />Issue</span>;
}

export default function ClientHealthSection({
    completenessData,
    retentionInsight,
    retentionLoading,
    clientId,
    onSwitchTab,
    onOpenActivationDialog,
    activeDeal,
}) {
    const navigate = useNavigate();
    const band = retentionInsight?.band || '';
    const isWatch = isRetentionWatchBand(band);
    const [actionsExpanded, setActionsExpanded] = useState(isWatch);
    const [breakdownExpanded, setBreakdownExpanded] = useState(false);

    // Sync auto-expand when band data loads
    React.useEffect(() => {
        if (retentionInsight?.band) {
            setActionsExpanded(isRetentionWatchBand(retentionInsight.band));
        }
    }, [retentionInsight?.band]);

    const components = retentionInsight?.component_scores || {};
    const signals = retentionInsight?.signals || {};
    const daysOnline = signals?.engagement_recency?.days_since_online;

    // Build action cards from drivers + profile completeness (skip nulls = positive drivers)
    const actionCards = [];
    if (retentionInsight?.top_drivers) {
        for (const driver of retentionInsight.top_drivers) {
            const action = mapDriverToAction(driver);
            if (action) {
                actionCards.push({ ...action, detail: driver.detail, severity: driver.severity });
            }
        }
    }
    if (completenessData && completenessData.score < 80 && completenessData.missing?.length > 0) {
        actionCards.push({
            headline: 'Incomplete profile',
            detail: `${completenessData.missing.length} fields missing: ${completenessData.missing.join(', ')}`,
            suggestion: 'Edit profile',
            actionType: 'edit_profile',
            borderColor: 'border-amber-400',
            iconBg: 'bg-amber-100 text-amber-600',
            severity: 40,
        });
    }

    const handleAction = (actionType) => {
        switch (actionType) {
        case 'campaign':
            navigate('/campaigns');
            break;
        case 'conversation':
            navigate(`/conversations?client=${clientId}`);
            break;
        case 'deals':
            if (onSwitchTab) onSwitchTab('deals');
            break;
        case 'edit_profile':
            if (onSwitchTab) onSwitchTab('edit_profile');
            break;
        case 'none':
            break;
        default:
            navigate(`/conversations?client=${clientId}`);
        }
    };

    const pct = completenessData?.score;
    const profileBarColor = pct >= 80 ? 'bg-emerald-500' : pct >= 50 ? 'bg-amber-500' : 'bg-rose-500';

    return (
        <section className="crm-surface mt-3 overflow-hidden">
            {/* Header */}
            <header className="crm-panel-header">
                <div>
                    <h3 className="crm-panel-title">Client Health</h3>
                    <p className="crm-panel-subtitle">How this client is doing across payments, activity &amp; profile.</p>
                </div>
                {band ? <HealthBandBadge band={band} /> : null}
            </header>

            {/* Layer 1: KPI Metric Tiles */}
            <div className="border-b border-slate-100 px-4 py-4">
                {retentionLoading ? (
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        {[1, 2, 3, 4].map((i) => (
                            <div key={i} className="h-20 animate-pulse rounded-xl bg-slate-100" />
                        ))}
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        {/* Health Score Tile */}
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p className="text-xs font-medium text-slate-500">Health Score</p>
                            <p className="mt-1 text-2xl font-bold tracking-tight text-slate-900">
                                {retentionInsight?.score ?? '—'}
                            </p>
                            {retentionInsight?.score != null && (
                                <SeverityDots score={retentionInsight.score} band={band} />
                            )}
                        </div>

                        {/* Behavior Tile */}
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p className="text-xs font-medium text-slate-500">Behavior</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">
                                {retentionInsight?.primary_tag || '—'}
                            </p>
                            {retentionInsight?.secondary_tags?.length > 0 && (
                                <div className="mt-1.5 flex flex-wrap gap-1">
                                    {retentionInsight.secondary_tags.map((tag) => (
                                        <span key={tag} className="inline-flex items-center rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
                                            {tag}
                                        </span>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Last Active Tile */}
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p className="text-xs font-medium text-slate-500">Last Active</p>
                            <p className="mt-1 text-sm font-semibold text-slate-900">
                                {formatDaysAgo(daysOnline)}
                            </p>
                        </div>

                        {/* Profile Complete Tile */}
                        <div className="rounded-xl border border-slate-200 bg-white px-4 py-3">
                            <p className="text-xs font-medium text-slate-500">Profile Complete</p>
                            <p className="mt-1 text-2xl font-bold tracking-tight text-slate-900">
                                {pct != null ? `${pct}%` : '—'}
                            </p>
                            {pct != null && (
                                <div className="mt-1.5 h-1.5 w-full rounded-full bg-slate-200">
                                    <div className={`h-1.5 rounded-full transition-all ${profileBarColor}`} style={{ width: `${pct}%` }} />
                                </div>
                            )}
                        </div>
                    </div>
                )}
            </div>

            {/* Layer 2: Action Cards */}
            {!retentionLoading && (
                <div className="border-b border-slate-100">
                    <button
                        type="button"
                        onClick={() => setActionsExpanded((prev) => !prev)}
                        className="flex w-full items-center justify-between px-4 py-2.5 text-left text-xs font-semibold text-slate-600 hover:bg-slate-50 transition"
                    >
                        <span className="flex items-center gap-2">
                            What needs attention
                            {actionCards.length > 0 && (
                                <span className="inline-flex items-center justify-center rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-bold text-amber-700">
                                    {actionCards.length}
                                </span>
                            )}
                        </span>
                        <svg
                            className={`h-4 w-4 text-slate-400 transition-transform ${actionsExpanded ? 'rotate-180' : ''}`}
                            fill="none"
                            viewBox="0 0 24 24"
                            strokeWidth={2}
                            stroke="currentColor"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    {actionsExpanded && (
                        <div className="space-y-2 px-4 pb-4">
                            {actionCards.length > 0 ? actionCards.map((card, i) => (
                                <div
                                    key={`${card.headline}-${i}`}
                                    className={`flex items-center justify-between gap-3 rounded-lg border-l-[3px] ${card.borderColor} bg-slate-50 px-4 py-3`}
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold text-slate-900">{card.headline}</p>
                                        <p className="mt-0.5 text-sm text-slate-600">{card.detail}</p>
                                    </div>
                                    {card.actionType !== 'none' && (
                                        <button
                                            type="button"
                                            onClick={() => handleAction(card.actionType)}
                                            className="shrink-0 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-slate-700"
                                        >
                                            {card.suggestion}
                                        </button>
                                    )}
                                </div>
                            )) : (
                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3">
                                    <p className="text-sm font-medium text-emerald-800">This client is in good shape. No action needed.</p>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}

            {/* Layer 3: Detailed Breakdown */}
            {!retentionLoading && retentionInsight && (
                <div>
                    <button
                        type="button"
                        onClick={() => setBreakdownExpanded((prev) => !prev)}
                        className="flex w-full items-center justify-between px-4 py-2.5 text-left text-xs font-semibold text-slate-500 hover:bg-slate-50 transition"
                    >
                        <span>{breakdownExpanded ? 'Hide details' : 'Show details'}</span>
                        <svg
                            className={`h-4 w-4 text-slate-400 transition-transform ${breakdownExpanded ? 'rotate-180' : ''}`}
                            fill="none"
                            viewBox="0 0 24 24"
                            strokeWidth={2}
                            stroke="currentColor"
                        >
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </button>

                    {breakdownExpanded && (
                        <div className="grid gap-4 px-4 pb-4 md:grid-cols-2">
                            {/* Health by Area */}
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Health by Area</p>
                                <div className="space-y-2">
                                    {Object.entries(components).map(([key, comp]) => (
                                        <div key={key} className="flex items-start justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2.5">
                                            <div className="min-w-0">
                                                <p className="text-sm font-medium text-slate-900">{AREA_LABELS[key] || comp?.label || key}</p>
                                                <p className="mt-0.5 text-xs text-slate-500">{comp?.summary || '—'}</p>
                                            </div>
                                            <AreaStatusDot score={Number(comp?.score || 0)} />
                                        </div>
                                    ))}
                                </div>
                            </div>

                            {/* Key Metrics */}
                            <div>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">Key Metrics</p>
                                <div className="rounded-lg border border-slate-200 bg-white px-3 py-3">
                                    <dl className="space-y-2.5">
                                        {CURATED_SIGNALS.map(({ key, label, component, format, warn }) => {
                                            const value = signals?.[component]?.[key];
                                            if (value === null || value === undefined) return null;
                                            const isWarning = warn && warn(value);
                                            return (
                                                <div key={key} className="flex items-center justify-between gap-3 text-sm">
                                                    <dt className="text-slate-500">{label}</dt>
                                                    <dd className={`text-right font-medium ${isWarning ? 'text-rose-600' : 'text-slate-900'}`}>
                                                        {format(value)}
                                                    </dd>
                                                </div>
                                            );
                                        })}
                                    </dl>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Empty state */}
            {!retentionLoading && !retentionInsight && !completenessData && (
                <div className="px-4 py-6">
                    <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-5 text-sm text-slate-500">
                        Client health data will appear here once enough history is available.
                    </div>
                </div>
            )}
        </section>
    );
}
