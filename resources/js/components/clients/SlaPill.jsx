import React from 'react';

const BUCKET_STYLES = {
    green: { dot: 'bg-emerald-500', text: 'text-emerald-700', border: 'border-l-emerald-400', label: 'Fresh' },
    yellow: { dot: 'bg-amber-500', text: 'text-amber-700', border: 'border-l-amber-400', label: 'Warming' },
    orange: { dot: 'bg-orange-500', text: 'text-orange-700', border: 'border-l-orange-400', label: 'Cooling' },
    red: { dot: 'bg-rose-600', text: 'text-rose-700', border: 'border-l-rose-500', label: 'Cold' },
};

function formatAge(seconds) {
    if (seconds == null || Number.isNaN(seconds)) return '—';
    if (seconds < 60) return `${Math.max(1, Math.round(seconds))}s ago`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

export default function SlaPill({ bucket = 'red', ageSeconds = null, className = '' }) {
    const style = BUCKET_STYLES[bucket] || BUCKET_STYLES.red;
    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full bg-white px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset ring-slate-200 ${style.text} ${className}`}
            aria-label={`Age: ${formatAge(ageSeconds)} (${style.label})`}
        >
            <span className={`h-1.5 w-1.5 rounded-full ${style.dot}`} aria-hidden="true" />
            {formatAge(ageSeconds)}
        </span>
    );
}

export const BUCKET_BORDER = (bucket) => (BUCKET_STYLES[bucket] || BUCKET_STYLES.red).border;
