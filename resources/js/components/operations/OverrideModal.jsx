import React, { useEffect, useState } from 'react';
import { capabilityLabel } from './opsFormat';

const LEVELS = [
    { value: 0, label: 'Normal', hint: 'Nothing is paused.' },
    { value: 1, label: 'Cautious', hint: 'Auto Optimize, bulk bio, PBN seeding and geocoding stand down.' },
    { value: 2, label: 'Limp', hint: 'Also push campaigns, AI briefings, retention insights and Support Board sync.' },
    { value: 3, label: 'Critical', hint: 'Also the optimize and heavy queue workers are not started at all.' },
];

const DURATIONS = [15, 30, 60, 120, 240, 480];

/**
 * One form for a manual override, replacing two chained window.prompt dialogs.
 *
 * The expiry is a required field rather than a default, and it is stated in the
 * copy: a forced level with no end is how a platform ends up quietly shed for a
 * week after an incident nobody closed out.
 */
export default function OverrideModal({ open, onClose, onSubmit, isSubmitting, pausedPreview = [] }) {
    const [level, setLevel] = useState(2);
    const [minutes, setMinutes] = useState(60);
    const [reason, setReason] = useState('');

    useEffect(() => {
        if (open) {
            setLevel(2);
            setMinutes(60);
            setReason('');
        }
    }, [open]);

    useEffect(() => {
        if (!open) return undefined;
        const onKey = (e) => { if (e.key === 'Escape' && !isSubmitting) onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [open, onClose, isSubmitting]);

    if (!open) return null;

    const trimmed = reason.trim();
    const valid = trimmed.length >= 3;

    return (
        <div className="fixed inset-0 z-[120] flex items-center justify-center bg-slate-900/45 p-4" onClick={isSubmitting ? undefined : onClose}>
            <div role="dialog" aria-modal="true" aria-labelledby="override-title" className="w-full max-w-lg rounded-lg border border-slate-200 bg-white shadow-xl" onClick={(e) => e.stopPropagation()}>
                <header className="border-b border-slate-100 px-5 py-4">
                    <h3 id="override-title" className="text-base font-semibold text-slate-900">Hold the platform at a level</h3>
                    <p className="mt-1 text-[13px] text-slate-500">
                        This overrides automatic evaluation until it expires, then hands control back to the sampler — it does not drop to Normal.
                    </p>
                </header>

                <div className="space-y-4 px-5 py-4">
                    <div>
                        <p className="mb-1.5 text-xs font-semibold uppercase tracking-wide text-slate-500">Level</p>
                        <div className="grid grid-cols-2 gap-2">
                            {LEVELS.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setLevel(option.value)}
                                    className={`rounded border px-3 py-2 text-left text-sm ${level === option.value ? 'border-teal-500 bg-teal-50 font-semibold text-teal-900' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}
                                >
                                    {option.label}
                                </button>
                            ))}
                        </div>
                        <p className="mt-1.5 text-[12px] text-slate-500">{LEVELS.find((l) => l.value === level)?.hint}</p>
                        {pausedPreview.length > 0 && level > 0 ? (
                            <p className="mt-1 text-[12px] text-amber-700">
                                Paused at this level: {pausedPreview.map(capabilityLabel).join(', ')}.
                            </p>
                        ) : null}
                    </div>

                    <div>
                        <label htmlFor="override-minutes" className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Expires after
                        </label>
                        <div className="flex flex-wrap gap-2">
                            {DURATIONS.map((option) => (
                                <button
                                    key={option}
                                    type="button"
                                    onClick={() => setMinutes(option)}
                                    className={`rounded border px-2.5 py-1.5 text-xs ${minutes === option ? 'border-teal-500 bg-teal-50 font-semibold text-teal-900' : 'border-slate-200 text-slate-700 hover:bg-slate-50'}`}
                                >
                                    {option < 60 ? `${option}m` : `${option / 60}h`}
                                </button>
                            ))}
                            <input
                                id="override-minutes"
                                type="number"
                                min={5}
                                max={1440}
                                value={minutes}
                                onChange={(e) => setMinutes(Number(e.target.value))}
                                className="crm-input w-24 px-2 py-1 text-sm"
                                aria-label="Custom duration in minutes"
                            />
                        </div>
                        <p className="mt-1 text-[11px] text-slate-400">Between 5 and 1440 minutes. An override always expires.</p>
                    </div>

                    <div>
                        <label htmlFor="override-reason" className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Reason
                        </label>
                        <input
                            id="override-reason"
                            type="text"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder="e.g. Provider incident, holding while we investigate"
                            className="crm-input w-full px-3 py-2 text-sm"
                        />
                        <p className="mt-1 text-[11px] text-slate-400">Recorded on the incident row so this is traceable afterwards.</p>
                    </div>
                </div>

                <footer className="flex justify-end gap-2 border-t border-slate-100 px-5 py-3">
                    <button type="button" onClick={onClose} disabled={isSubmitting} className="crm-btn-secondary px-3 py-2 text-sm">Cancel</button>
                    <button
                        type="button"
                        disabled={!valid || isSubmitting || minutes < 5 || minutes > 1440}
                        onClick={() => onSubmit({ level, reason: trimmed, expires_in_minutes: minutes })}
                        className="crm-btn-primary px-3 py-2 text-sm disabled:opacity-50"
                    >
                        {isSubmitting ? 'Applying…' : `Hold at ${LEVELS.find((l) => l.value === level)?.label}`}
                    </button>
                </footer>
            </div>
        </div>
    );
}
