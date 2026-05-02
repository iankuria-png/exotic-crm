import React from 'react';

const tones = {
    helpful: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    unhelpful: 'bg-rose-50 text-rose-700 ring-rose-200',
    article_suggestion: 'bg-amber-50 text-amber-700 ring-amber-200',
    bug: 'bg-rose-50 text-rose-700 ring-rose-200',
    feature_request: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    general: 'bg-slate-100 text-slate-700 ring-slate-200',
};

export default function KindChip({ kind }) {
    const normalized = String(kind || '').trim().toLowerCase();
    const className = tones[normalized] || 'bg-slate-100 text-slate-700 ring-slate-200';

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.12em] ring-1 ${className}`}>
            {normalized.replaceAll('_', ' ') || 'unknown'}
        </span>
    );
}
