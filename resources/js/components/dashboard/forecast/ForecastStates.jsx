import React, { useEffect, useState } from 'react';

function useElapsedSeconds(startedAt) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    if (!startedAt) return null;
    const started = new Date(startedAt).getTime();
    if (!Number.isFinite(started)) return null;

    return Math.max(0, Math.round((now - started) / 1000));
}

function humanSeconds(value) {
    if (!Number.isFinite(value)) return null;
    if (value < 60) return `${value}s`;
    const minutes = Math.floor(value / 60);
    const seconds = value % 60;
    return seconds ? `${minutes}m ${seconds}s` : `${minutes}m`;
}

/** Shell shared by every non-ready state so they read as one family. */
function StatePanel({ tone = 'neutral', title, children, actions }) {
    const ring = {
        neutral: 'border-slate-200',
        warn: 'border-amber-200 bg-amber-50/40',
        error: 'border-rose-200 bg-rose-50/40',
    }[tone];

    return (
        <div className="p-6">
            <div className={`mx-auto max-w-2xl rounded-lg border ${ring} bg-white p-5 shadow-sm`}>
                <p className="text-sm font-semibold text-slate-950">{title}</p>
                <div className="mt-2 text-sm text-slate-600">{children}</div>
                {actions ? <div className="mt-4 flex flex-wrap gap-2">{actions}</div> : null}
            </div>
        </div>
    );
}

function ActionButton({ onClick, children, primary = false }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={primary
                ? 'h-9 rounded-md bg-teal-700 px-3 text-xs font-semibold text-white transition hover:bg-teal-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500'
                : 'h-9 rounded-md border border-slate-300 bg-white px-3 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500'}
        >
            {children}
        </button>
    );
}

/**
 * Building state. Determinate once the job reports market counts, indeterminate
 * before that - never a bar that pretends to know progress it has not been told.
 */
export function ForecastBuildingState({ data, onCancel }) {
    const progress = data?.progress || {};
    const done = Number(progress.markets_done || 0);
    const total = Number(progress.markets_total || 0);
    const determinate = total > 0 && done > 0;
    const percent = determinate ? Math.min(100, Math.round((done / total) * 100)) : null;

    const elapsed = useElapsedSeconds(data?.started_at);
    const estimate = Number(data?.estimated_seconds);
    const hasEstimate = Number.isFinite(estimate) && estimate > 0;
    const remaining = hasEstimate && elapsed !== null ? Math.max(0, estimate - elapsed) : null;
    const overrun = hasEstimate && elapsed !== null && elapsed > estimate * 1.5;

    return (
        <StatePanel
            title="Building your forecast baseline"
            actions={onCancel ? [<ActionButton key="cancel" onClick={onCancel}>Close and keep building</ActionButton>] : null}
        >
            <p className="text-slate-600">
                {progress.phase || 'Starting'}
                {determinate ? <span className="text-slate-400"> · market {done} of {total}</span> : null}
            </p>

            <div className="mt-3 h-1.5 overflow-hidden rounded-sm bg-slate-100" role="progressbar" aria-valuenow={percent ?? undefined} aria-valuemin={0} aria-valuemax={100}>
                {determinate ? (
                    <div
                        className="h-1.5 rounded-sm bg-teal-600 transition-[width] duration-500 ease-out"
                        style={{ width: `${percent}%` }}
                    />
                ) : (
                    <div className="h-1.5 w-1/3 animate-[forecastSlide_1.4s_ease-in-out_infinite] rounded-sm bg-teal-600/70" />
                )}
            </div>

            <dl className="mt-3 grid grid-cols-3 gap-3 text-xs">
                <div>
                    <dt className="text-slate-400">Elapsed</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-slate-900">{humanSeconds(elapsed) || '—'}</dd>
                </div>
                <div>
                    <dt className="text-slate-400">{hasEstimate ? 'Typically takes' : 'Estimate'}</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-slate-900">
                        {hasEstimate ? humanSeconds(estimate) : 'Learning'}
                    </dd>
                </div>
                <div>
                    <dt className="text-slate-400">Remaining</dt>
                    <dd className="mt-0.5 font-semibold tabular-nums text-slate-900">
                        {remaining !== null ? (remaining > 0 ? `~${humanSeconds(remaining)}` : 'Any moment') : '—'}
                    </dd>
                </div>
            </dl>

            {overrun ? (
                <p className="mt-3 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-800">
                    This is taking longer than the last build of this size. It is still running — if it does not finish,
                    check that the heavy queue lane is active in Settings.
                </p>
            ) : (
                <p className="mt-3 text-xs text-slate-400">
                    {hasEstimate
                        ? 'Estimated from the last build of this size. Results are cached, so the next open is instant.'
                        : 'First build of this size, so there is nothing to estimate from yet. It will be cached afterwards.'}
                </p>
            )}
        </StatePanel>
    );
}

export function ForecastFailedState({ data, onRetry }) {
    return (
        <StatePanel
            tone="error"
            title="The baseline did not finish building"
            actions={[
                <ActionButton key="retry" primary onClick={onRetry}>Try again</ActionButton>,
            ]}
        >
            <p>{data?.message || 'Nothing was cached, so retrying is safe.'}</p>
            <p className="mt-2 text-xs text-slate-500">
                If it fails again, narrow the window or pick a single market — a smaller build runs immediately
                instead of queueing.
            </p>
        </StatePanel>
    );
}

export function ForecastRefusedState({ data, onUseSuggested }) {
    const suggested = data?.suggested;
    const rows = Number(data?.rows_estimated);
    const budget = Number(data?.row_budget);

    return (
        <StatePanel
            tone="warn"
            title="That window is too wide to measure in one pass"
            actions={suggested && onUseSuggested
                ? [<ActionButton key="use" primary onClick={() => onUseSuggested(suggested)}>Use {suggested.from} to {suggested.to}</ActionButton>]
                : null}
        >
            <p>
                {Number.isFinite(rows) && Number.isFinite(budget)
                    ? <>It covers <span className="font-semibold tabular-nums">{rows.toLocaleString()}</span> payments, above the{' '}
                        <span className="font-semibold tabular-nums">{budget.toLocaleString()}</span> this can measure at once.</>
                    : 'It covers more payments than this can measure at once.'}
            </p>
            <p className="mt-2 text-xs text-slate-500">A narrower window returns immediately and can still be projected forward.</p>
        </StatePanel>
    );
}

export function ForecastLoadingState() {
    return (
        <StatePanel title="Opening the forecast">
            <div className="mt-1 h-1.5 overflow-hidden rounded-sm bg-slate-100">
                <div className="h-1.5 w-1/3 animate-[forecastSlide_1.4s_ease-in-out_infinite] rounded-sm bg-teal-600/70" />
            </div>
            <p className="mt-3 text-xs text-slate-400">Checking for a cached baseline.</p>
        </StatePanel>
    );
}
