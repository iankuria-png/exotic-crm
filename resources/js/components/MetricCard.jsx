import React from 'react';

export default function MetricCard({
    label,
    value,
    meta,
    tone = 'default',
    onClick,
    active = false,
}) {
    const toneMap = {
        default: 'bg-slate-300',
        neutral: 'bg-slate-400',
        slate: 'bg-slate-500',
        accent: 'bg-teal-600',
        success: 'bg-emerald-500',
        warning: 'bg-amber-500',
        danger: 'bg-rose-500',
    };

    const interactive = typeof onClick === 'function';
    const cardClassName = [
        'crm-kpi',
        interactive ? 'w-full cursor-pointer text-left transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500' : '',
        active ? 'border-teal-300 bg-teal-50/60' : '',
    ].filter(Boolean).join(' ');

    const content = (
        <>
            <p className="crm-kpi-label flex items-center gap-2">
                <span className={`h-2 w-2 rounded-full ${toneMap[tone] || toneMap.default}`} aria-hidden="true" />
                {label}
            </p>
            <p className="crm-kpi-value">{value}</p>
            {meta ? <p className="crm-kpi-meta">{meta}</p> : null}
        </>
    );

    if (interactive) {
        return (
            <button type="button" onClick={onClick} className={cardClassName}>
                {content}
            </button>
        );
    }

    return <article className={cardClassName}>{content}</article>;
}
