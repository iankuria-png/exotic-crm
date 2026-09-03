import React, { useMemo, useState } from 'react';
import SectionFrame from '../SectionFrame';
import FilterSelect from '../FilterSelect';
import exportRowsToCsv from '../../utils/csvExport';
import { formatDuration } from './opsFormat';

const STATE_TONE = {
    idle: 'text-slate-500',
    draining: 'text-emerald-600',
    backlogged: 'text-amber-600',
    unavailable: 'text-slate-400',
};

const STATE_OPTIONS = [
    { value: '', label: 'All states' },
    { value: 'draining', label: 'Draining' },
    { value: 'backlogged', label: 'Backlogged' },
    { value: 'idle', label: 'Idle' },
];

/**
 * Per-lane depth, which the single global job count on System Health cannot
 * say. The oldest-job column is what separates a busy lane from a stalled one.
 */
export default function QueueLanesPanel({ lanes, isLoading }) {
    const [stateFilter, setStateFilter] = useState('');

    const rows = useMemo(
        () => (lanes || []).filter((lane) => (stateFilter ? lane.state === stateFilter : true)),
        [lanes, stateFilter]
    );

    const handleExport = () => {
        exportRowsToCsv('queue-lanes', [
            { label: 'Lane', value: (row) => row.lane },
            { label: 'Queues', value: (row) => (row.queues || []).join(' ') },
            { label: 'Pending', value: (row) => row.pending },
            { label: 'Reserved', value: (row) => row.reserved },
            { label: 'Oldest (seconds)', value: (row) => row.oldest_seconds },
            { label: 'State', value: (row) => row.state },
        ], rows);
    };

    return (
        <SectionFrame
            title="Queue lanes"
            subtitle="Depth and wait time per lane, so a backed-up market cannot hide behind a healthy global count."
            action={
                <div className="flex items-end gap-2">
                    <FilterSelect label="State" value={stateFilter} onChange={(e) => setStateFilter(e.target.value)} options={STATE_OPTIONS} />
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
                    {[0, 1, 2, 3].map((key) => (
                        <div key={key} className="h-9 animate-pulse rounded bg-slate-50" />
                    ))}
                </div>
            ) : rows.length === 0 ? (
                <p className="py-6 text-center text-sm text-slate-500">
                    {(lanes || []).length === 0
                        ? 'No lane data in the last sample. The queue connection may be set to sync.'
                        : 'No lane matches this filter.'}
                </p>
            ) : (
                <div className="overflow-x-auto">
                    <table className="min-w-full text-sm">
                        <thead>
                            <tr className="border-b border-slate-200 text-left text-[10px] font-semibold uppercase tracking-[0.1em] text-slate-500">
                                <th className="py-2 pr-3">Lane</th>
                                <th className="py-2 pr-3">Queues</th>
                                <th className="py-2 pr-3">Pending</th>
                                <th className="py-2 pr-3">In flight</th>
                                <th className="py-2 pr-3">Oldest waiting</th>
                                <th className="py-2">State</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((lane) => (
                                <tr key={lane.lane} className="border-b border-slate-100 last:border-0">
                                    <td className="py-2.5 pr-3 font-medium text-slate-900">{lane.lane}</td>
                                    <td className="py-2.5 pr-3 font-mono text-[11px] text-slate-500">{(lane.queues || []).join(', ')}</td>
                                    <td className="py-2.5 pr-3 text-slate-700">{lane.pending ?? '—'}</td>
                                    <td className="py-2.5 pr-3 text-slate-700">{lane.reserved ?? '—'}</td>
                                    <td className="py-2.5 pr-3 text-slate-700">
                                        {lane.oldest_seconds === null || lane.oldest_seconds === undefined
                                            ? '—'
                                            : formatDuration(lane.oldest_seconds)}
                                    </td>
                                    <td className={`py-2.5 font-medium capitalize ${STATE_TONE[lane.state] || 'text-slate-600'}`}>
                                        {lane.state}
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
