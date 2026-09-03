import React, { useMemo, useState } from 'react';
import SectionFrame from '../SectionFrame';
import FilterSelect from '../FilterSelect';
import exportRowsToCsv from '../../utils/csvExport';
import { LEVEL_TONE, formatDateTime, formatDuration, signalLabel } from './opsFormat';

const ORIGIN_OPTIONS = [
    { value: '', label: 'All origins' },
    { value: 'automatic', label: 'Automatic' },
    { value: 'manual', label: 'Manual override' },
];

const STATE_OPTIONS = [
    { value: '', label: 'All' },
    { value: 'open', label: 'Still elevated' },
    { value: 'resolved', label: 'Resolved' },
];

const LEVEL_OPTIONS = [
    { value: '', label: 'All levels' },
    { value: '1', label: 'Cautious' },
    { value: '2', label: 'Limp' },
    { value: '3', label: 'Critical' },
];

/**
 * One row per transition, never per sample — a short list even after a bad
 * week, and each row carries the reading and the threshold it was measured
 * against so it still explains itself months later.
 */
export default function IncidentTimeline({ incidents, isLoading, error }) {
    const [origin, setOrigin] = useState('');
    const [state, setState] = useState('');
    const [level, setLevel] = useState('');

    const rows = useMemo(() => (incidents || []).filter((incident) => {
        if (origin && incident.origin !== origin) return false;
        if (level && String(incident.to_level) !== level) return false;
        if (state === 'open' && incident.resolved_at) return false;
        if (state === 'resolved' && !incident.resolved_at) return false;
        return true;
    }), [incidents, origin, state, level]);

    const handleExport = () => {
        exportRowsToCsv('system-incidents', [
            { label: 'Reference', value: (row) => row.reference },
            { label: 'From', value: (row) => row.from_level_label },
            { label: 'To', value: (row) => row.to_level_label },
            { label: 'Signal', value: (row) => row.trigger_signal },
            { label: 'Reading', value: (row) => row.trigger_value },
            { label: 'Threshold', value: (row) => row.threshold },
            { label: 'Origin', value: (row) => row.origin },
            { label: 'Actor', value: (row) => row.actor_name },
            { label: 'Started', value: (row) => row.started_at },
            { label: 'Resolved', value: (row) => row.resolved_at },
        ], rows);
    };

    return (
        <SectionFrame
            title="Incident timeline"
            subtitle="Every level change, with the reading that caused it and what it was measured against."
            action={
                <div className="flex flex-wrap items-end gap-2">
                    <FilterSelect label="Origin" value={origin} onChange={(e) => setOrigin(e.target.value)} options={ORIGIN_OPTIONS} />
                    <FilterSelect label="Level" value={level} onChange={(e) => setLevel(e.target.value)} options={LEVEL_OPTIONS} />
                    <FilterSelect label="State" value={state} onChange={(e) => setState(e.target.value)} options={STATE_OPTIONS} />
                    <button
                        type="button"
                        onClick={handleExport}
                        disabled={rows.length === 0}
                        className="crm-btn-secondary px-3 py-2 text-xs disabled:opacity-50"
                    >
                        Export CSV
                    </button>
                </div>
            }
        >
            {isLoading ? (
                <div className="space-y-2">
                    {[0, 1, 2].map((key) => <div key={key} className="h-10 animate-pulse rounded bg-slate-50" />)}
                </div>
            ) : error ? (
                <p className="py-6 text-center text-sm text-rose-600">The incident timeline could not be loaded.</p>
            ) : rows.length === 0 ? (
                <div className="py-8 text-center">
                    <p className="text-sm font-semibold text-slate-700">
                        {(incidents || []).length === 0 ? 'No degradation has been recorded.' : 'No incident matches these filters.'}
                    </p>
                    <p className="mx-auto mt-1 max-w-md text-[13px] text-slate-500">
                        {(incidents || []).length === 0
                            ? 'That is the expected state. A row appears here only when the level actually moves — not on every sample.'
                            : 'Clear a filter to see the rest.'}
                    </p>
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                <th className="py-2 pr-3">Reference</th>
                                <th className="py-2 pr-3">Transition</th>
                                <th className="py-2 pr-3">Trigger</th>
                                <th className="py-2 pr-3">Origin</th>
                                <th className="py-2 pr-3">Started</th>
                                <th className="py-2">Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((incident) => (
                                <tr key={incident.reference} className="border-b border-slate-100 last:border-0">
                                    <td className="py-2.5 pr-3 font-mono text-[11px] text-slate-500">{incident.reference}</td>
                                    <td className="py-2.5 pr-3">
                                        <span className="text-slate-500">{incident.from_level_label}</span>
                                        <span className="mx-1 text-slate-400">→</span>
                                        <span className={`rounded px-1.5 py-0.5 text-[11px] font-semibold ${(LEVEL_TONE[incident.to_level] || LEVEL_TONE[0]).pill}`}>
                                            {incident.to_level_label}
                                        </span>
                                    </td>
                                    <td className="py-2.5 pr-3 text-slate-700">
                                        {signalLabel(incident.trigger_signal)}
                                        {incident.trigger_value !== null && incident.trigger_value !== undefined ? (
                                            <span className="text-slate-500">
                                                {' '}· {incident.trigger_value}
                                                {incident.threshold !== null && incident.threshold !== undefined
                                                    ? ` vs ${incident.threshold}`
                                                    : ''}
                                            </span>
                                        ) : null}
                                    </td>
                                    <td className="py-2.5 pr-3 capitalize text-slate-600">
                                        {incident.origin}
                                        {incident.actor_name ? <span className="text-slate-400"> · {incident.actor_name}</span> : null}
                                    </td>
                                    <td className="py-2.5 pr-3 text-slate-600">{formatDateTime(incident.started_at)}</td>
                                    <td className="py-2.5 text-slate-600">
                                        {incident.resolved_at
                                            ? formatDuration(incident.duration_seconds)
                                            : <span className="font-medium text-amber-600">Still elevated</span>}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </SectionFrame>
    );
}
