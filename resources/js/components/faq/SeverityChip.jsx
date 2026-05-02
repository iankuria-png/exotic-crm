import React from 'react';

const tones = {
    low: 'bg-slate-100 text-slate-700 ring-slate-200',
    medium: 'bg-amber-50 text-amber-700 ring-amber-200',
    high: 'bg-orange-50 text-orange-700 ring-orange-200',
    critical: 'bg-rose-50 text-rose-700 ring-rose-200',
};

export default function SeverityChip({ severity }) {
    if (!severity) {
        return null;
    }

    const normalized = String(severity).trim().toLowerCase();
    const className = tones[normalized] || tones.low;

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ${className}`}>
            {normalized}
        </span>
    );
}
