import React from 'react';

export default function MetricCard({ label, value, meta, tone = 'default' }) {
    const toneMap = {
        default: 'bg-slate-300',
        accent: 'bg-teal-600',
        success: 'bg-emerald-500',
        warning: 'bg-amber-500',
        danger: 'bg-rose-500',
    };

    return (
        <article className="crm-kpi">
            <p className="crm-kpi-label flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${toneMap[tone] || toneMap.default}`} aria-hidden="true" />
                {label}
            </p>
            <p className="crm-kpi-value">{value}</p>
            {meta ? <p className="crm-kpi-meta">{meta}</p> : null}
        </article>
    );
}
