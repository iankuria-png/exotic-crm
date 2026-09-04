import React, { useEffect, useMemo, useState } from 'react';
import SectionFrame from '../SectionFrame';

const RISK_TONE = {
    low: 'bg-slate-100 text-slate-600',
    medium: 'bg-amber-100 text-amber-800',
    high: 'bg-rose-100 text-rose-800',
};

/**
 * One group of tunables. Bounds come from the server's registry and are shown
 * inline, so the form never offers a range the server would reject.
 */
export default function TuningPanel({ group, onSave, onReset, isSaving, fieldError, query = '', changedOnly = false }) {
    const [draft, setDraft] = useState({});

    const serverValues = useMemo(
        () => Object.fromEntries(group.settings.map((setting) => [setting.key, setting.value])),
        [group.settings]
    );

    useEffect(() => {
        setDraft({});
    }, [serverValues]);

    const valueOf = (setting) => (Object.prototype.hasOwnProperty.call(draft, setting.key) ? draft[setting.key] : setting.value);

    const needle = query.trim().toLowerCase();
    const visibleSettings = group.settings.filter((setting) => {
        if (changedOnly && setting.is_default) return false;
        if (needle === '') return true;
        return `${setting.label} ${setting.description} ${setting.key}`.toLowerCase().includes(needle);
    });

    const dirty = group.settings.filter((setting) => {
        if (!Object.prototype.hasOwnProperty.call(draft, setting.key)) return false;
        return String(draft[setting.key]) !== String(setting.value);
    });

    const handleSave = () => {
        onSave(dirty.map((setting) => ({
            key: setting.key,
            value: setting.type === 'integer' ? Number(draft[setting.key]) : draft[setting.key],
        })));
    };

    // A group with nothing to show under the current filter is noise; hide it
    // rather than rendering an empty frame, unless the group has been narrowed
    // to nothing by "changed only", which is itself worth stating once.
    if (visibleSettings.length === 0 && needle !== '') {
        return null;
    }

    return (
        <SectionFrame
            title={group.label}
            subtitle={group.description}
            action={
                <div className="flex items-center gap-2">
                    {!group.writable ? (
                        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[11px] font-semibold text-slate-500 ring-1 ring-slate-200">
                            Read only for your role
                        </span>
                    ) : (
                        <>
                            {dirty.length > 0 ? (
                                <button type="button" onClick={() => setDraft({})} className="crm-btn-secondary px-3 py-2 text-xs">
                                    Discard
                                </button>
                            ) : null}
                            <button
                                type="button"
                                onClick={handleSave}
                                disabled={dirty.length === 0 || isSaving}
                                className="crm-btn-primary px-3 py-2 text-xs disabled:opacity-50"
                            >
                                {isSaving ? 'Saving…' : `Save ${dirty.length || ''}`.trim()}
                            </button>
                        </>
                    )}
                </div>
            }
            footer={
                dirty.length > 0 ? (
                    <p className="text-[12px] text-slate-500">
                        Takes effect on the next scheduler tick — no deploy and no <span className="font-mono">config:cache</span>.
                    </p>
                ) : null
            }
        >
            <div className="grid gap-3 lg:grid-cols-2">
                {visibleSettings.length === 0 ? (
                    <p className="col-span-full py-4 text-center text-[13px] text-slate-500">
                        {changedOnly ? 'Nothing in this group differs from its default.' : 'No setting here matches that search.'}
                    </p>
                ) : null}
                {visibleSettings.map((setting) => {
                    const isDirty = dirty.some((entry) => entry.key === setting.key);
                    const error = fieldError?.key === setting.key ? fieldError.message : null;

                    return (
                        <div
                            key={setting.key}
                            className={`rounded-lg border px-3 py-2.5 ${error ? 'border-rose-300 bg-rose-50' : isDirty ? 'border-teal-300 bg-teal-50/40' : 'border-slate-200 bg-white'}`}
                        >
                            <div className="flex items-start justify-between gap-2">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium text-slate-900">{setting.label}</p>
                                    <p className="mt-0.5 text-[12px] leading-snug text-slate-500">{setting.description}</p>
                                </div>
                                {setting.risk !== 'low' ? (
                                    <span className={`shrink-0 rounded px-1.5 py-0.5 text-[10px] font-semibold uppercase ${RISK_TONE[setting.risk]}`}>
                                        {setting.risk}
                                    </span>
                                ) : null}
                            </div>

                            <div className="mt-2 flex items-center gap-2">
                                {setting.type === 'boolean' ? (
                                    <label className="inline-flex items-center gap-2 text-sm text-slate-700">
                                        <input
                                            type="checkbox"
                                            checked={Boolean(valueOf(setting))}
                                            disabled={!group.writable}
                                            onChange={(e) => setDraft((prev) => ({ ...prev, [setting.key]: e.target.checked }))}
                                            className="h-4 w-4 rounded border-slate-300 text-teal-600"
                                        />
                                        {valueOf(setting) ? 'On' : 'Off'}
                                    </label>
                                ) : (
                                    <input
                                        type={setting.type === 'time' ? 'time' : 'number'}
                                        value={valueOf(setting) ?? ''}
                                        min={setting.min ?? undefined}
                                        max={setting.max ?? undefined}
                                        disabled={!group.writable}
                                        onChange={(e) => setDraft((prev) => ({ ...prev, [setting.key]: e.target.value }))}
                                        className="crm-input w-32 px-2 py-1 text-sm"
                                    />
                                )}

                                {setting.unit ? <span className="text-[12px] text-slate-500">{setting.unit}</span> : null}

                                {group.writable && !setting.is_default ? (
                                    <button
                                        type="button"
                                        onClick={() => onReset(setting.key)}
                                        className="ml-auto text-[11px] font-medium text-teal-700 hover:underline"
                                    >
                                        Reset to {String(setting.default)}
                                    </button>
                                ) : null}
                            </div>

                            {setting.min !== null && setting.min !== undefined ? (
                                <p className="mt-1.5 text-[11px] text-slate-400">
                                    Allowed {setting.min}–{setting.max}
                                    {setting.unit ? ` ${setting.unit}` : ''} · default {String(setting.default)}
                                </p>
                            ) : (
                                <p className="mt-1.5 text-[11px] text-slate-400">Default {String(setting.default)}</p>
                            )}

                            {error ? <p className="mt-1 text-[12px] font-medium text-rose-700">{error}</p> : null}
                        </div>
                    );
                })}
            </div>
        </SectionFrame>
    );
}
