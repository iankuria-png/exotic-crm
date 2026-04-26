import React from 'react';

const TONE_DOT = {
    accent: 'bg-teal-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
    default: 'bg-slate-400',
    neutral: 'bg-slate-300',
    slate: 'bg-slate-500',
};

const TONE_HINT = {
    accent: 'text-teal-700',
    success: 'text-emerald-700',
    warning: 'text-amber-600',
    danger: 'text-rose-600',
    default: 'text-slate-500',
    neutral: 'text-slate-500',
    slate: 'text-slate-600',
};

export default function MetricCard({
    label,
    value,
    hint,
    meta,       // legacy alias for hint (used by Reports.jsx)
    subHint,
    tone = 'default',
    onClick,
    active = false,
    isLoading = false,
}) {
    const resolvedHint = hint ?? meta;
    const dot = TONE_DOT[tone] ?? TONE_DOT.default;
    const hintColor = TONE_HINT[tone] ?? TONE_HINT.default;
    const interactive = typeof onClick === 'function';

    const cardClass = [
        'crm-kpi text-left',
        interactive
            ? 'w-full cursor-pointer transition hover:border-slate-300 hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500'
            : '',
        active ? 'border-teal-300 bg-teal-50/60' : '',
    ].filter(Boolean).join(' ');

    const content = (
        <>
            <p className="flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.10em] text-slate-500">
                <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${dot}`} aria-hidden="true" />
                {label}
            </p>

            <p className="mt-2 text-2xl leading-tight font-semibold tracking-tight text-slate-900">
                {isLoading
                    ? <span className="inline-block h-8 w-24 animate-pulse rounded bg-slate-100" />
                    : value}
            </p>

            {resolvedHint != null && resolvedHint !== '' ? (
                <p
                    className={`mt-2 truncate text-xs font-medium ${hintColor}`}
                    title={typeof resolvedHint === 'string' ? resolvedHint : undefined}
                >
                    {resolvedHint}
                </p>
            ) : null}

            {subHint ? (
                <p className="mt-0.5 line-clamp-2 text-[11px] text-slate-400">{subHint}</p>
            ) : null}
        </>
    );

    if (interactive) {
        return (
            <button type="button" onClick={onClick} className={cardClass}>
                {content}
            </button>
        );
    }

    return <article className={cardClass}>{content}</article>;
}
