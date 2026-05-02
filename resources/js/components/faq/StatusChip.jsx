import React from 'react';

const tones = {
    new: 'bg-sky-50 text-sky-700 ring-sky-200',
    triaged: 'bg-amber-50 text-amber-700 ring-amber-200',
    planned: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    in_progress: 'bg-violet-50 text-violet-700 ring-violet-200',
    shipped: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    resolved: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    wontfix: 'bg-slate-100 text-slate-700 ring-slate-200',
    duplicate: 'bg-rose-50 text-rose-700 ring-rose-200',
    draft: 'bg-amber-50 text-amber-700 ring-amber-200',
    published: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    archived: 'bg-slate-100 text-slate-700 ring-slate-200',
};

export default function StatusChip({ status }) {
    const normalized = String(status || '').trim().toLowerCase();
    const className = tones[normalized] || 'bg-slate-100 text-slate-700 ring-slate-200';

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ${className}`}>
            {normalized.replaceAll('_', ' ') || 'unknown'}
        </span>
    );
}
