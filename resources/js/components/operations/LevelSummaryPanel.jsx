import React, { useState } from 'react';
import SectionFrame from '../SectionFrame';
import FilterSelect from '../FilterSelect';
import exportRowsToCsv from '../../utils/csvExport';
import { LEVEL_TONE, capabilityLabel, formatDuration } from './opsFormat';

const WINDOW_OPTIONS = [
    { value: '24', label: 'Last 24 hours' },
    { value: '72', label: 'Last 3 days' },
    { value: '168', label: 'Last 7 days' },
];

/**
 * What the levels cost, and what enforcement would have cost.
 *
 * This is the panel that makes observe-only mode worth running: it converts
 * "these capabilities would be paused" from a list into a measurement somebody
 * can act on.
 */
export default function LevelSummaryPanel({ summary, isLoading, error, hours, onHoursChange }) {
    const [showAll, setShowAll] = useState(false);

    const levels = summary?.levels || [];
    const capabilities = summary?.capabilities || [];
    const visible = showAll ? capabilities : capabilities.filter((c) => c.seconds > 0);
    const enforced = summary?.enforcement_enabled;

    const handleExport = () => {
        exportRowsToCsv('operations-impact', [
            { label: 'Capability', value: (row) => capabilityLabel(row.capability) },
            { label: 'Sheds at', value: (row) => row.sheds_at_label },
            { label: 'Seconds', value: (row) => row.seconds },
            { label: 'Share of window (%)', value: (row) => row.share },
            { label: 'Episodes', value: (row) => row.episodes },
        ], capabilities);
    };

    return (
        <SectionFrame
            title="Impact"
            subtitle={
                enforced
                    ? 'How long the platform spent at each level, and what shedding actually paused.'
                    : 'How long the platform spent at each level, and what shedding would have paused had enforcement been on.'
            }
            action={
                <div className="flex items-end gap-2">
                    <FilterSelect label="Window" value={String(hours)} onChange={(e) => onHoursChange(Number(e.target.value))} options={WINDOW_OPTIONS} />
                    <button type="button" onClick={handleExport} disabled={capabilities.length === 0} className="crm-btn-secondary px-3 py-2 text-xs disabled:opacity-50">
                        Export CSV
                    </button>
                </div>
            }
        >
            {isLoading ? (
                <div className="h-24 animate-pulse rounded bg-slate-50" />
            ) : error ? (
                <p className="py-6 text-center text-sm text-rose-600">The impact summary could not be loaded.</p>
            ) : (
                <div className="space-y-4">
                    <div>
                        <div className="flex h-3 w-full overflow-hidden rounded-full bg-slate-100">
                            {levels.map((level) => (
                                level.share > 0 ? (
                                    <div
                                        key={level.level}
                                        className={(LEVEL_TONE[level.level] || LEVEL_TONE[0]).pill.split(' ')[0]}
                                        style={{ width: `${level.share}%` }}
                                        title={`${level.label}: ${formatDuration(level.seconds)} (${level.share}%)`}
                                    />
                                ) : null
                            ))}
                        </div>
                        <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {levels.map((level) => (
                                <div key={level.level} className="rounded border border-slate-200 px-2.5 py-2">
                                    <p className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">{level.label}</p>
                                    <p className="mt-0.5 text-sm font-semibold text-slate-900">{formatDuration(level.seconds)}</p>
                                    <p className="text-[11px] text-slate-500">{level.share}% of window</p>
                                </div>
                            ))}
                        </div>
                        <p className="mt-2 text-[11px] text-slate-500">{summary?.transitions ?? 0} level change(s) in this window.</p>
                    </div>

                    {capabilities.length === 0 ? null : (
                        <div>
                            <div className="mb-2 flex items-center justify-between">
                                <div>
                                    <p className="text-xs font-semibold text-slate-700">
                                        {enforced ? 'Time actually paused' : 'Time that would have been paused'}
                                    </p>
                                    <p className="text-[11px] text-slate-500">
                                        Cost of shedding, not cause of load. A capability high on this list is shed early, not
                                        responsible for the pressure.
                                    </p>
                                </div>
                                <button type="button" onClick={() => setShowAll((v) => !v)} className="text-[11px] font-medium text-teal-700 hover:underline">
                                    {showAll ? 'Hide unaffected' : `Show all ${capabilities.length}`}
                                </button>
                            </div>
                            {visible.length === 0 ? (
                                <p className="rounded border border-dashed border-slate-300 bg-slate-50 px-3 py-4 text-center text-[13px] text-slate-500">
                                    Nothing would have been paused in this window — the platform stayed at Normal throughout.
                                </p>
                            ) : (
                                <div className="space-y-1.5">
                                    {visible.map((row) => (
                                        <div key={row.capability} className="flex items-center gap-3">
                                            <span className="w-44 shrink-0 truncate text-[13px] text-slate-700" title={capabilityLabel(row.capability)}>
                                                {capabilityLabel(row.capability)}
                                            </span>
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                                                <div className="h-full rounded-full bg-amber-400" style={{ width: `${Math.max(row.share > 0 ? 2 : 0, row.share)}%` }} />
                                            </div>
                                            <span className="w-40 shrink-0 text-right text-[12px] text-slate-600">
                                                {formatDuration(row.seconds)} · {row.episodes} episode{row.episodes === 1 ? '' : 's'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}
                </div>
            )}
        </SectionFrame>
    );
}
