import React from 'react';

const TONES = {
    market: {
        border: 'border-teal-200/80',
        dot: 'bg-teal-500',
        label: 'text-teal-700',
    },
    agent: {
        border: 'border-indigo-200/80',
        dot: 'bg-indigo-500',
        label: 'text-indigo-700',
    },
    positive: {
        border: 'border-emerald-200/80',
        dot: 'bg-emerald-500',
        label: 'text-emerald-700',
    },
    warning: {
        border: 'border-amber-200/80',
        dot: 'bg-amber-500',
        label: 'text-amber-700',
    },
    default: {
        border: 'border-slate-200',
        dot: 'bg-slate-400',
        label: 'text-slate-500',
    },
};

export default function InsightStrip({ insights = [], isLoading, onMarketClick, onAgentClick }) {
    if (isLoading) {
        return (
            <section className="grid gap-3 md:grid-cols-4">
                {Array.from({ length: 4 }).map((_, index) => (
                    <div key={index} className="h-20 animate-pulse rounded-lg bg-slate-100" />
                ))}
            </section>
        );
    }

    if (!insights.length) {
        return (
            <section className="rounded-lg border border-dashed border-slate-200 bg-white px-4 py-5 text-sm text-slate-500">
                No movement surfaced for this window yet.
            </section>
        );
    }

    return (
        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {insights.map((insight) => {
                const interactive = insight.platform_id || insight.agent_id;
                const tone = TONES[insight.tone] || TONES.default;
                const handleClick = () => {
                    if (insight.platform_id) onMarketClick?.(insight.platform_id);
                    if (insight.agent_id) onAgentClick?.(insight.agent_id);
                };
                const className = `rounded-lg border bg-white px-4 py-3 text-left shadow-sm ${tone.border} ${
                    interactive ? 'cursor-pointer transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500' : ''
                }`;

                const content = (
                    <>
                        <p className={`flex items-center gap-1.5 text-[10px] font-semibold uppercase tracking-[0.14em] ${tone.label}`}>
                            <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${tone.dot}`} aria-hidden="true" />
                            {insight.label}
                        </p>
                        <p className="mt-1.5 text-sm font-semibold leading-5 text-slate-900">{insight.message}</p>
                    </>
                );

                return interactive ? (
                    <button key={insight.key} type="button" onClick={handleClick} className={className}>
                        {content}
                    </button>
                ) : (
                    <article key={insight.key} className={className}>
                        {content}
                    </article>
                );
            })}
        </section>
    );
}
