import React from 'react';

const tones = {
    empty: {
        wrapper: 'border-slate-200 bg-white',
        title: 'text-slate-900',
        body: 'text-slate-600',
        badge: 'border-slate-200 text-slate-600',
        dot: 'bg-slate-400',
    },
    degraded: {
        wrapper: 'border-amber-200 bg-white',
        title: 'text-amber-900',
        body: 'text-amber-800',
        badge: 'border-amber-200 text-amber-700',
        dot: 'bg-amber-500',
    },
    forbidden: {
        wrapper: 'border-rose-200 bg-white',
        title: 'text-rose-900',
        body: 'text-rose-700',
        badge: 'border-rose-200 text-rose-700',
        dot: 'bg-rose-500',
    },
};

export default function BillingStateNotice({
    state = 'empty',
    eyebrow = 'Billing',
    title,
    message,
}) {
    const tone = tones[state] || tones.empty;

    return (
        <section className={`rounded-lg border p-5 shadow-sm shadow-slate-950/[0.02] ${tone.wrapper}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className={`text-[11px] font-semibold uppercase tracking-[0.08em] ${tone.body}`}>{eyebrow}</p>
                    <h4 className={`mt-2 text-base font-semibold ${tone.title}`}>{title}</h4>
                </div>
                <span className={`inline-flex items-center gap-2 rounded-full border px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.08em] ${tone.badge}`}>
                    <span className={`h-2 w-2 rounded-full ${tone.dot}`} />
                    {state}
                </span>
            </div>
            <p className={`mt-3 text-sm ${tone.body}`}>{message}</p>
        </section>
    );
}
