import React from 'react';

const TONES = {
    market: 'border-teal-200 bg-teal-50 text-teal-900',
    agent: 'border-indigo-200 bg-indigo-50 text-indigo-900',
    positive: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warning: 'border-amber-200 bg-amber-50 text-amber-900',
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
                const handleClick = () => {
                    if (insight.platform_id) onMarketClick?.(insight.platform_id);
                    if (insight.agent_id) onAgentClick?.(insight.agent_id);
                };
                const className = `rounded-lg border px-4 py-3 text-left shadow-sm ${TONES[insight.tone] || TONES.positive} ${
                    interactive ? 'cursor-pointer transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-teal-500' : ''
                }`;

                const content = (
                    <>
                        <p className="text-[10px] font-semibold uppercase tracking-[0.14em] opacity-70">{insight.label}</p>
                        <p className="mt-1.5 text-sm font-semibold leading-5">{insight.message}</p>
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
