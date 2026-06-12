import React from 'react';
import { CHANNEL_META, CHANNEL_ORDER } from './locationMeta';

function asNumber(value) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : 0;
}

export default function ChannelMixBar({ channels, compact = false, placeholder = 'Channels unavailable' }) {
    if (!channels) {
        return <span className="text-xs text-slate-400">{placeholder}</span>;
    }

    const total = CHANNEL_ORDER.reduce((sum, key) => sum + asNumber(channels[key]), 0);
    const barClass = compact ? 'h-2' : 'h-3';

    if (total <= 0) {
        return (
            <div className="space-y-2">
                <div className={`${barClass} overflow-hidden rounded-full bg-slate-100`} />
                {!compact ? <p className="text-xs text-slate-400">No contact data</p> : null}
            </div>
        );
    }

    return (
        <div className="space-y-2">
            <div className={`flex overflow-hidden rounded-full bg-slate-100 ${barClass}`}>
                {CHANNEL_ORDER.map((key) => {
                    const value = asNumber(channels[key]);
                    const width = `${(value / total) * 100}%`;

                    return value > 0 ? (
                        <span
                            key={key}
                            className="h-full"
                            style={{ width, backgroundColor: CHANNEL_META[key].color }}
                            aria-hidden="true"
                        />
                    ) : null;
                })}
            </div>

            {!compact ? (
                <div className="flex flex-wrap gap-3 text-xs text-slate-500">
                    {CHANNEL_ORDER.map((key) => (
                        <span key={key} className="inline-flex items-center gap-1.5">
                            <span className={`h-2 w-2 rounded-full ${CHANNEL_META[key].dot}`} aria-hidden="true" />
                            <span>{CHANNEL_META[key].label}</span>
                            <span className="crm-mono">{asNumber(channels[key]).toLocaleString()}</span>
                        </span>
                    ))}
                </div>
            ) : null}
        </div>
    );
}
