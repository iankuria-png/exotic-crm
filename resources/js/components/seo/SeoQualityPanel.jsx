import React from 'react';
import SeoScoreBadge from './SeoScoreBadge';

export default function SeoQualityPanel({ score, breakdown, stale = false }) {
    const components = [
        { key: 'word_count', label: 'Bio length', helper: '120–300 words is the target.' },
        { key: 'links', label: 'Internal links', helper: '3–6 contextual links is ideal.' },
        { key: 'completeness', label: 'Profile data', helper: 'Uses age, city, services, rates and attributes.' },
        { key: 'media', label: 'Media quality', helper: 'Rewards photos, main image and video.' },
    ];

    return (
        <section className="rounded-xl border border-teal-100 bg-gradient-to-br from-teal-50/80 via-white to-white p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2">
                        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-teal-600 text-white shadow-sm">✦</span>
                        <div>
                            <h3 className="text-sm font-semibold text-slate-900">Profile Quality · SEO</h3>
                            <p className="text-xs text-slate-500">Bio strength, linking, profile detail and media readiness.</p>
                        </div>
                    </div>
                </div>
                <SeoScoreBadge score={score} stale={stale} />
            </div>

            {!stale && breakdown ? (
                <div className="mt-4 grid gap-3 md:grid-cols-2">
                    {components.map(({ key, label, helper }) => {
                        const val = Number(breakdown[key] ?? 0);
                        const pct = Math.min(100, Math.max(0, Math.round((val / 25) * 100)));
                        return (
                            <div key={key} className="rounded-lg border border-slate-200 bg-white/80 p-3">
                                <div className="flex items-center justify-between gap-2">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
                                        <p className="mt-0.5 text-[11px] text-slate-400">{helper}</p>
                                    </div>
                                    <span className="text-xs font-semibold text-slate-700">{val}/25</span>
                                </div>
                                <div className="mt-3 h-2 overflow-hidden rounded-full bg-slate-100">
                                    <div className="h-full rounded-full bg-teal-500 transition-all" style={{ width: `${pct}%` }} />
                                </div>
                            </div>
                        );
                    })}
                </div>
            ) : null}

            {stale ? (
                <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                    This bio changed after the last score. It will be refreshed automatically on the next sync.
                </p>
            ) : null}

            {!stale && !breakdown ? (
                <p className="mt-4 rounded-lg border border-dashed border-teal-200 bg-white/70 px-3 py-3 text-sm text-slate-600">
                    Generate a bio to preview SEO quality before saving it to WordPress.
                </p>
            ) : null}
        </section>
    );
}
