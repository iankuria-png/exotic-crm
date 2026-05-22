import React, { useEffect, useRef, useState } from 'react';

const PRESETS = [
    { key: '24h', label: '24h', hours: 24 },
    { key: '48h', label: '48h', hours: 48 },
    { key: '7d', label: '7 days', hours: 168 },
    { key: '30d', label: '30 days', hours: 720 },
];

const TODAY_ISO = () => new Date().toISOString().slice(0, 10);

function isoMinusDays(days) {
    const d = new Date();
    d.setDate(d.getDate() - days);
    return d.toISOString().slice(0, 10);
}

/**
 * Compact preset chip row with an inline "Custom" date-range expander.
 *
 * Props:
 *   value: { mode: 'preset'|'custom', hours?: number, from?: string, to?: string }
 *   onChange(value)
 *   currentLabel: string from API to echo back ("Last 48 hours", "Custom · …")
 */
export default function QueueRangeSelector({ value, onChange, currentLabel = '' }) {
    const [customOpen, setCustomOpen] = useState(value?.mode === 'custom');
    const [from, setFrom] = useState(value?.from || isoMinusDays(7));
    const [to, setTo] = useState(value?.to || TODAY_ISO());
    const wrapperRef = useRef(null);

    useEffect(() => {
        if (!customOpen) return undefined;
        const handle = (e) => {
            if (wrapperRef.current && !wrapperRef.current.contains(e.target)) {
                setCustomOpen(false);
            }
        };
        document.addEventListener('mousedown', handle);
        return () => document.removeEventListener('mousedown', handle);
    }, [customOpen]);

    const isPreset = value?.mode !== 'custom';
    const activeHours = isPreset ? (value?.hours ?? 48) : null;

    const selectPreset = (hours) => {
        setCustomOpen(false);
        onChange({ mode: 'preset', hours });
    };

    const applyCustom = () => {
        if (!from && !to) return;
        onChange({ mode: 'custom', from, to });
        setCustomOpen(false);
    };

    return (
        <div className="flex flex-wrap items-center gap-3" ref={wrapperRef}>
            <div className="inline-flex items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1" role="tablist" aria-label="Queue date range">
                {PRESETS.map((p) => {
                    const isActive = isPreset && activeHours === p.hours;
                    return (
                        <button
                            key={p.key}
                            type="button"
                            role="tab"
                            aria-selected={isActive}
                            onClick={() => selectPreset(p.hours)}
                            className={`rounded-md px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                                isActive
                                    ? 'bg-white text-teal-700 shadow-sm'
                                    : 'text-slate-600 hover:text-slate-800'
                            }`}
                        >
                            {p.label}
                        </button>
                    );
                })}
                <button
                    type="button"
                    onClick={() => setCustomOpen((v) => !v)}
                    aria-expanded={customOpen}
                    className={`flex items-center gap-1 rounded-md px-3 py-1 text-xs font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500 ${
                        !isPreset
                            ? 'bg-white text-teal-700 shadow-sm'
                            : 'text-slate-600 hover:text-slate-800'
                    }`}
                >
                    Custom
                    <svg className={`h-3 w-3 transition-transform ${customOpen ? 'rotate-180' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M19 9l-7 7-7-7" />
                    </svg>
                </button>
            </div>

            <span className="text-xs text-slate-500">
                Showing items from <span className="font-semibold text-slate-700">{currentLabel || 'the last 48 hours'}</span>.
                <span className="ml-1 text-slate-400">New signups and failed payments only — stalled keeps its own 72h cutoff.</span>
            </span>

            {customOpen ? (
                <div className="flex w-full flex-wrap items-end gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                    <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">From</span>
                        <input
                            type="date"
                            value={from}
                            onChange={(e) => setFrom(e.target.value)}
                            max={to || TODAY_ISO()}
                            className="rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                        />
                    </label>
                    <label className="flex flex-col gap-1">
                        <span className="text-[10px] font-semibold uppercase tracking-wide text-slate-500">To</span>
                        <input
                            type="date"
                            value={to}
                            onChange={(e) => setTo(e.target.value)}
                            min={from || ''}
                            max={TODAY_ISO()}
                            className="rounded-md border border-slate-300 px-2 py-1 text-sm focus:border-teal-500 focus:outline-none focus:ring-1 focus:ring-teal-500"
                        />
                    </label>
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setCustomOpen(false)} className="rounded-md border border-slate-300 bg-white px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            Cancel
                        </button>
                        <button type="button" onClick={applyCustom} className="rounded-md bg-teal-700 px-3 py-1 text-xs font-semibold text-white hover:bg-teal-800">
                            Apply
                        </button>
                    </div>
                </div>
            ) : null}
        </div>
    );
}
