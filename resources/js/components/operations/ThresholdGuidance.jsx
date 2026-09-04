import React from 'react';
import { LEVEL_TONE } from './opsFormat';

/**
 * Explains a threshold conflict rather than just reporting one.
 *
 * The previous version stated the problem accurately and assumed the reader
 * already knew what watch, shed and ceiling meant and how they compose. This
 * draws the ladder with the actual configured numbers, marks the rung that
 * cannot be reached, and offers the specific edits that would fix it.
 */
export default function ThresholdGuidance({ warnings = [], ladder = [], onApply, isApplying }) {
    if (warnings.length === 0) return null;

    const hasError = warnings.some((w) => w.severity === 'error');

    return (
        <div className={`rounded-lg border px-4 py-3 ${hasError ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-slate-50'}`}>
            <p className={`text-sm font-semibold ${hasError ? 'text-amber-900' : 'text-slate-800'}`}>
                {hasError ? 'These thresholds do not make a working ladder' : 'Worth knowing about your thresholds'}
            </p>

            {ladder.length > 0 ? (
                <div className="mt-3 space-y-1.5">
                    <p className="text-[11px] font-semibold uppercase tracking-wide text-slate-500">
                        How PHP processes move the level
                    </p>
                    {ladder.map((rung) => (
                        <div
                            key={rung.level}
                            className={`flex flex-wrap items-center gap-2 rounded border px-2.5 py-1.5 text-[12px] ${rung.reachable ? 'border-slate-200 bg-white' : 'border-rose-200 bg-rose-50'}`}
                        >
                            <span className={`rounded px-1.5 py-0.5 text-[10px] font-semibold ${(LEVEL_TONE[rung.level] || LEVEL_TONE[0]).pill}`}>
                                {rung.label}
                            </span>
                            <span className="text-slate-600">
                                {rung.level === 0 ? 'below every threshold' : `at ${rung.enters_at} processes or more`}
                            </span>
                            <span className="text-slate-400">·</span>
                            <span className="text-slate-500">{rung.note}</span>
                            {!rung.reachable ? (
                                <span className="ml-auto rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-semibold text-rose-700">
                                    Can never be reached
                                </span>
                            ) : null}
                        </div>
                    ))}
                    <p className="text-[11px] text-slate-500">
                        Each rung has to sit higher than the one below it, or the level above swallows it.
                    </p>
                </div>
            ) : null}

            <div className="mt-3 space-y-3">
                {warnings.map((warning) => (
                    <div key={warning.key} className="rounded border border-slate-200 bg-white px-3 py-2.5">
                        <p className={`text-[13px] font-semibold ${warning.severity === 'error' ? 'text-rose-800' : 'text-slate-800'}`}>
                            {warning.title || warning.message}
                        </p>
                        {warning.why ? <p className="mt-1 text-[12px] leading-snug text-slate-600">{warning.why}</p> : null}
                        {warning.fix ? <p className="mt-1 text-[12px] font-medium text-slate-800">{warning.fix}</p> : null}

                        {(warning.suggestions || []).length > 0 ? (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {warning.suggestions.map((suggestion) => (
                                    <button
                                        key={suggestion.label}
                                        type="button"
                                        disabled={isApplying}
                                        onClick={() => onApply(suggestion.updates)}
                                        title={suggestion.detail}
                                        className="rounded border border-teal-300 bg-teal-50 px-2.5 py-1.5 text-[12px] font-medium text-teal-800 hover:bg-teal-100 disabled:opacity-50"
                                    >
                                        {suggestion.label}
                                    </button>
                                ))}
                            </div>
                        ) : null}
                        {(warning.suggestions || []).length > 0 ? (
                            <p className="mt-1.5 text-[11px] text-slate-500">
                                {warning.suggestions.map((s) => s.detail).filter(Boolean).join(' · ')}
                            </p>
                        ) : null}
                    </div>
                ))}
            </div>
        </div>
    );
}
