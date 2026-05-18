import React from 'react';

export default function SeoScoreBadge({ score, stale = false }) {
    if (stale) {
        return (
            <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200" title="Bio was edited; score will be refreshed on next sync">
                Pending rescore
            </span>
        );
    }

    if (score === null || score === undefined) {
        return (
            <span className="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500 ring-1 ring-slate-200" title="No SEO score yet">
                No score
            </span>
        );
    }

    const numericScore = Number(score);
    const tone = numericScore >= 70
        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200'
        : numericScore >= 40
            ? 'bg-amber-50 text-amber-700 ring-amber-200'
            : 'bg-rose-50 text-rose-700 ring-rose-200';

    return (
        <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${tone}`} title={`SEO Quality: ${numericScore}/100`}>
            {numericScore}/100
        </span>
    );
}
