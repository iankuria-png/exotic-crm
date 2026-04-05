import React from 'react';

const tones = {
    empty: {
        wrapper: 'border-slate-200 bg-slate-50',
        title: 'text-slate-900',
        body: 'text-slate-600',
        badge: 'bg-slate-200 text-slate-700',
    },
    degraded: {
        wrapper: 'border-amber-200 bg-amber-50',
        title: 'text-amber-900',
        body: 'text-amber-800',
        badge: 'bg-amber-100 text-amber-700',
    },
    forbidden: {
        wrapper: 'border-rose-200 bg-rose-50',
        title: 'text-rose-900',
        body: 'text-rose-700',
        badge: 'bg-rose-100 text-rose-700',
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
        <section className={`rounded-2xl border p-5 ${tone.wrapper}`}>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p className={`text-[11px] font-semibold uppercase tracking-[0.08em] ${tone.body}`}>{eyebrow}</p>
                    <h4 className={`mt-2 text-base font-semibold ${tone.title}`}>{title}</h4>
                </div>
                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase tracking-[0.08em] ${tone.badge}`}>
                    {state}
                </span>
            </div>
            <p className={`mt-3 text-sm ${tone.body}`}>{message}</p>
        </section>
    );
}
