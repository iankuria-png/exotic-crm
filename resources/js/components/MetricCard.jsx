import React from 'react';

export default function MetricCard({ label, value, meta, tone = 'default' }) {
    const toneMap = {
        default: 'border-t-slate-300',
        accent: 'border-t-teal-600',
        success: 'border-t-emerald-500',
        warning: 'border-t-amber-500',
        danger: 'border-t-rose-500',
    };

    return (
        <article className={`crm-kpi border-t-[3px] ${toneMap[tone] || toneMap.default}`}>
            <p className="crm-kpi-label">{label}</p>
            <p className="crm-kpi-value">{value}</p>
            {meta ? <p className="crm-kpi-meta">{meta}</p> : null}
        </article>
    );
}
