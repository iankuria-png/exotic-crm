import React, { useEffect } from 'react';

function formatNumber(value, options = {}) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'Not ready';

    return new Intl.NumberFormat(undefined, {
        maximumFractionDigits: Math.abs(numeric) >= 1000 ? 0 : 1,
        ...options,
    }).format(numeric);
}

function formatMetric(metric) {
    if (!metric) return 'Not ready';

    if (metric.unit === 'money') {
        return `${metric.currency || 'USD'} ${formatNumber(metric.current)}`;
    }

    if (metric.unit === 'percent') {
        return `${formatNumber(metric.current)}%`;
    }

    return formatNumber(metric.current);
}

function formatDelta(metric) {
    const value = Number(metric?.delta_percent);
    if (!Number.isFinite(value)) return null;

    const prefix = value > 0 ? '+' : '';
    return `${prefix}${formatNumber(value)}%`;
}

function generatedLabel(value) {
    if (!value) return 'Not generated';

    const date = new Date(value);
    if (!Number.isFinite(date.getTime())) return 'Generated';

    return `Generated ${date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })}`;
}

function MetricPill({ label, metric }) {
    const delta = formatDelta(metric);
    const positive = Number(metric?.delta_percent) >= 0;

    return (
        <div className="rounded-lg border border-slate-200 bg-white px-3 py-2">
            <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-slate-400">{label}</p>
            <p className="mt-1 text-sm font-semibold text-slate-950">{formatMetric(metric)}</p>
            {delta ? (
                <p className={`mt-0.5 text-[11px] font-semibold ${positive ? 'text-emerald-700' : 'text-rose-600'}`}>
                    {delta} vs prior
                </p>
            ) : (
                <p className="mt-0.5 text-[11px] text-slate-400">No baseline</p>
            )}
        </div>
    );
}

function WeekRow({ week, onOpen, onGenerate, generating }) {
    const hasLink = Boolean(week?.share_url);

    return (
        <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
            <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-slate-950">{week.week_label}</p>
                    <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-600">{week.display}</span>
                    {week.exists ? (
                        <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">Ready</span>
                    ) : (
                        <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-semibold text-amber-700">Missing</span>
                    )}
                </div>
                <p className="mt-1 line-clamp-2 text-sm text-slate-600">
                    {week.headline || week.summary_sms || 'Generate this week to create the executive scorecard and share link.'}
                </p>
                <div className="mt-3 grid gap-2 sm:grid-cols-3">
                    <MetricPill label="Revenue" metric={week.metrics?.revenue} />
                    <MetricPill label="Recovery" metric={week.metrics?.recovery} />
                    <MetricPill label="Churn" metric={week.metrics?.churn} />
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 md:justify-end">
                <button
                    type="button"
                    onClick={() => onOpen(week.share_url)}
                    disabled={!hasLink}
                    className="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-45"
                >
                    Open scorecard
                </button>
                {!week.exists ? (
                    <button
                        type="button"
                        onClick={() => onGenerate(week.week_start)}
                        disabled={generating}
                        className="h-10 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-wait disabled:opacity-65"
                    >
                        {generating ? 'Generating...' : 'Generate'}
                    </button>
                ) : null}
            </div>
        </div>
    );
}

export default function ScorecardArchiveModal({
    open,
    data,
    isLoading,
    isError,
    onClose,
    onRetry,
    onOpenScorecard,
    onGenerate,
    generatingWeek,
}) {
    useEffect(() => {
        if (!open) return undefined;

        const onKeyDown = (event) => {
            if (event.key === 'Escape') {
                onClose?.();
            }
        };

        document.body.classList.add('overflow-hidden');
        window.addEventListener('keydown', onKeyDown);

        return () => {
            document.body.classList.remove('overflow-hidden');
            window.removeEventListener('keydown', onKeyDown);
        };
    }, [onClose, open]);

    if (!open) return null;

    const weeks = Array.isArray(data?.weeks) ? data.weeks : [];
    const latest = data?.latest || weeks[0] || null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/45 p-3 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="scorecard-archive-title">
            <div className="max-h-[92vh] w-full max-w-5xl overflow-hidden rounded-2xl border border-white/70 bg-slate-50 shadow-2xl">
                <div className="border-b border-slate-200 bg-white px-5 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-teal-700">Executive scorecards</p>
                            <h2 id="scorecard-archive-title" className="mt-1 text-2xl font-semibold tracking-tight text-slate-950">
                                Weekly scorecard archive
                            </h2>
                            <p className="mt-1 max-w-2xl text-sm text-slate-500">
                                Open a ready scorecard or backfill a missing week without sending SMS.
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={onClose}
                            className="h-10 rounded-lg border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500"
                        >
                            Close
                        </button>
                    </div>
                </div>

                <div className="max-h-[calc(92vh-110px)] overflow-y-auto p-5">
                    {isLoading ? (
                        <div className="grid gap-3">
                            {[0, 1, 2].map((item) => (
                                <div key={item} className="h-28 animate-pulse rounded-xl border border-slate-200 bg-white" />
                            ))}
                        </div>
                    ) : null}

                    {!isLoading && isError ? (
                        <div className="rounded-xl border border-rose-200 bg-rose-50 p-4">
                            <p className="font-semibold text-rose-900">Scorecards could not be loaded.</p>
                            <button
                                type="button"
                                onClick={onRetry}
                                className="mt-3 h-10 rounded-lg bg-rose-900 px-4 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500"
                            >
                                Retry
                            </button>
                        </div>
                    ) : null}

                    {!isLoading && !isError && latest ? (
                        <div className="mb-4 grid gap-3 rounded-2xl border border-teal-200 bg-teal-50 p-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-center">
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="rounded-full bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-teal-700">
                                        Latest completed week
                                    </span>
                                    <span className="text-sm font-semibold text-slate-950">{latest.week_label}</span>
                                    <span className="text-sm text-slate-600">{latest.display}</span>
                                </div>
                                <p className="mt-2 max-w-3xl text-base font-semibold text-slate-950">
                                    {latest.headline || latest.summary_sms || 'No scorecard has been generated for this week yet.'}
                                </p>
                                <p className="mt-1 text-sm text-slate-600">{generatedLabel(latest.generated_at)}</p>
                            </div>
                            {latest.exists ? (
                                <button
                                    type="button"
                                    onClick={() => onOpenScorecard(latest.share_url)}
                                    disabled={!latest.share_url}
                                    className="h-11 rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    View {latest.week_label}
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => onGenerate(latest.week_start)}
                                    disabled={generatingWeek === latest.week_start}
                                    className="h-11 rounded-lg bg-slate-950 px-5 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 disabled:cursor-wait disabled:opacity-65"
                                >
                                    {generatingWeek === latest.week_start ? 'Generating...' : 'Generate latest'}
                                </button>
                            )}
                        </div>
                    ) : null}

                    {!isLoading && !isError ? (
                        <div className="space-y-3">
                            {weeks.map((week) => (
                                <WeekRow
                                    key={week.week_start}
                                    week={week}
                                    onOpen={onOpenScorecard}
                                    onGenerate={onGenerate}
                                    generating={generatingWeek === week.week_start}
                                />
                            ))}
                            {weeks.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-sm text-slate-500">
                                    No scorecard weeks are available yet.
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                </div>
            </div>
        </div>
    );
}
