import React from 'react';

export default function Walkthroughs({ walkthroughs = [] }) {
    return (
        <section className="crm-surface space-y-4 px-5 py-5">
            <div>
                <p className="text-sm font-semibold text-slate-900">Walkthroughs</p>
                <p className="text-sm text-slate-500">Driver.js steps attached to CTA buttons on the article surface.</p>
            </div>
            <div className="space-y-3">
                {walkthroughs.map((walkthrough) => (
                    <div key={walkthrough.slug} className="rounded-2xl border border-slate-200 px-4 py-4">
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm font-semibold text-slate-900">{walkthrough.name}</p>
                            <span className="text-xs text-slate-500">{(walkthrough.steps || []).length} steps</span>
                        </div>
                        <p className="mt-2 text-sm text-slate-500">{walkthrough.slug}</p>
                    </div>
                ))}
            </div>
        </section>
    );
}
