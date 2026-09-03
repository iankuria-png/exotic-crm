import React from 'react';
import SectionFrame from '../SectionFrame';
import {
    LEVEL_TONE,
    SIGNAL_STATE_TONE,
    capabilityLabel,
    formatAgo,
    formatDateTime,
    formatDuration,
    formatSignalValue,
    signalLabel,
} from './opsFormat';

/**
 * A tile shows its live value against the threshold that governs it, so a green
 * badge is a claim the reader can check rather than one they have to trust.
 */
function SignalTile({ signal, ceilingVerified }) {
    const tone = SIGNAL_STATE_TONE[signal.state] || SIGNAL_STATE_TONE.ok;
    const ratioBase = signal.ceiling ?? signal.shed;
    const ratio = signal.available && ratioBase
        ? Math.min(100, Math.round((Number(signal.value) / Number(ratioBase)) * 100))
        : 0;

    const barTone = signal.state === 'shed' ? 'bg-rose-500' : signal.state === 'watch' ? 'bg-amber-400' : 'bg-teal-500';

    return (
        <div className={`rounded-lg border bg-white p-3 ${tone}`}>
            <p className="text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">{signal.label}</p>
            <p className="mt-1 text-2xl font-semibold text-slate-900">
                {formatSignalValue(signal)}
                {signal.available && signal.ceiling ? (
                    <span className="ml-1 text-sm font-normal text-slate-500">/ {signal.ceiling}</span>
                ) : null}
            </p>

            {signal.available ? (
                <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                    <div className={`h-full rounded-full ${barTone}`} style={{ width: `${Math.max(2, ratio)}%` }} />
                </div>
            ) : (
                <p className="mt-2 text-[11px] text-slate-500">
                    This host does not expose the reading. The rules fall back to tick duration and queue depth.
                </p>
            )}

            <p className="mt-2 text-[11px] text-slate-500">
                Watch at {signal.watch} · shed at {signal.shed}
            </p>

            {signal.key === 'php_processes' && signal.ceiling && !ceilingVerified ? (
                <p className="mt-1 inline-flex rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800">
                    Ceiling unverified
                </p>
            ) : null}
        </div>
    );
}

export default function VitalsBoard({ vitals, isLoading, error, onForce, onRelease, canOverride, isMutating }) {
    if (isLoading) {
        return (
            <SectionFrame title="System health" subtitle="Loading the last sample…">
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    {[0, 1, 2, 3].map((key) => (
                        <div key={key} className="h-28 animate-pulse rounded-lg border border-slate-200 bg-slate-50" />
                    ))}
                </div>
            </SectionFrame>
        );
    }

    if (error) {
        return (
            <SectionFrame title="System health">
                <div className="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                    The vitals endpoint could not be reached. That is itself worth investigating — check the Laravel log and
                    whether the app is serving dynamic routes at all.
                </div>
            </SectionFrame>
        );
    }

    const level = vitals?.level ?? 0;
    const tone = LEVEL_TONE[level] || LEVEL_TONE[0];
    const signals = vitals?.signals || [];
    const paused = vitals?.paused_capabilities || [];

    return (
        <SectionFrame
            title="System health"
            subtitle={
                vitals?.sampled_at
                    ? `Sampled every minute · last ${formatDateTime(vitals.sampled_at)} (${formatAgo(vitals.sampled_at)})`
                    : 'The sampler has not run yet.'
            }
            action={
                <div className="flex items-center gap-2">
                    {!vitals?.enforcement_enabled ? (
                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
                            Observe only
                        </span>
                    ) : null}
                    <span className={`rounded-full px-3 py-1 text-xs font-semibold ${tone.pill}`}>
                        {vitals?.level_label || 'Normal'}
                    </span>
                </div>
            }
        >
            <div className="space-y-4">
                {vitals?.sampler_stale ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                        <p className="font-semibold">The sampler has not reported recently.</p>
                        <p className="mt-1 text-[13px]">
                            {vitals?.sampled_at
                                ? `The last sample was ${formatAgo(vitals.sampled_at)}. Everything below describes that moment, not now.`
                                : 'No sample has ever been recorded. Check that the scheduler cron is installed and that crm:sample-vitals is running.'}
                        </p>
                    </div>
                ) : null}

                {vitals?.forced ? (
                    <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3">
                        <div className="text-sm text-indigo-900">
                            <p className="font-semibold">
                                Level manually held at {vitals.level_label}
                                {vitals.forced_expires_at ? ` until ${formatDateTime(vitals.forced_expires_at)}` : ''}
                            </p>
                            <p className="mt-1 text-[13px]">
                                {vitals.forced_reason || 'No reason recorded.'} When it lapses, automatic evaluation resumes —
                                it does not drop to Normal.
                            </p>
                        </div>
                        {canOverride ? (
                            <button type="button" onClick={onRelease} disabled={isMutating} className="crm-btn-secondary px-3 py-2 text-xs">
                                Resume normal operation
                            </button>
                        ) : null}
                    </div>
                ) : null}

                {level > 0 && vitals?.trigger_signal ? (
                    <div className={`rounded-lg border bg-white px-4 py-3 text-sm ${tone.ring} ring-1`}>
                        <p className="font-semibold text-slate-900">
                            {signalLabel(vitals.trigger_signal)} tripped this level
                            {vitals.trigger_value !== null && vitals.trigger_value !== undefined
                                ? ` — read ${vitals.trigger_value}${vitals.threshold ? ` against a threshold of ${vitals.threshold}` : ''}`
                                : ''}
                            .
                        </p>
                        <p className="mt-1 text-[13px] text-slate-600">
                            {paused.length === 0
                                ? 'Nothing is paused at this level.'
                                : vitals.enforcement_enabled
                                    ? `Paused: ${paused.map(capabilityLabel).join(', ')}.`
                                    : `Would be paused if enforcement were on: ${paused.map(capabilityLabel).join(', ')}.`}
                        </p>
                    </div>
                ) : null}

                {signals.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-8 text-center">
                        <p className="text-sm font-semibold text-slate-700">No vitals have been sampled yet.</p>
                        <p className="mx-auto mt-1 max-w-md text-[13px] text-slate-500">
                            The board fills in within a minute of the scheduler running. If it stays empty, the scheduler cron
                            is not installed — the Cron Heartbeat card on System Health says which.
                        </p>
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
                        {signals.map((signal) => (
                            <SignalTile key={signal.key} signal={signal} ceilingVerified={vitals?.process_ceiling_verified} />
                        ))}
                    </div>
                )}

                {(vitals?.markets_down_names || []).length > 0 ? (
                    <p className="text-xs text-slate-500">
                        Markets reporting unhealthy: {vitals.markets_down_names.join(', ')}
                    </p>
                ) : null}

                {(vitals?.stalled_runs || []).length > 0 ? (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3">
                        <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">Stalled sync slices</p>
                        <ul className="mt-1 space-y-0.5 text-[13px] text-amber-900">
                            {vitals.stalled_runs.map((run) => (
                                <li key={`${run.market}-${run.last_heartbeat_at}`}>
                                    {run.market} · {run.phase || 'unknown phase'} · {run.slices} slices · last heartbeat{' '}
                                    {formatAgo(run.last_heartbeat_at)}
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}

                {canOverride ? (
                    <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                        <span className="text-xs font-semibold text-slate-600">Manual override</span>
                        {[0, 1, 2, 3].map((candidate) => (
                            <button
                                key={candidate}
                                type="button"
                                disabled={isMutating}
                                onClick={() => onForce(candidate)}
                                className="rounded border border-slate-300 px-2.5 py-1 text-[11px] font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                            >
                                Hold at {LEVEL_TONE[candidate] ? ['Normal', 'Cautious', 'Limp', 'Critical'][candidate] : candidate}
                            </button>
                        ))}
                        <span className="text-[11px] text-slate-500">Overrides always expire — you will be asked for how long.</span>
                    </div>
                ) : null}

                {vitals?.scheduler ? (
                    <p className="text-[11px] text-slate-500">
                        Scheduler: {vitals.scheduler.concurrent ?? '—'} tick(s) in flight, last tick{' '}
                        {vitals.scheduler.tick_seconds === null || vitals.scheduler.tick_seconds === undefined
                            ? 'not yet measured'
                            : formatDuration(vitals.scheduler.tick_seconds)}
                        , {vitals.scheduler.due_count ?? '—'} tasks due · source {vitals.scheduler.source}
                    </p>
                ) : null}
            </div>
        </SectionFrame>
    );
}
