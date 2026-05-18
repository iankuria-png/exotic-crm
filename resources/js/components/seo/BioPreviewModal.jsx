import React from 'react';
import SeoScoreBadge from './SeoScoreBadge';

export default function BioPreviewModal({
    open,
    bioHtml,
    score,
    breakdown,
    providerUsed,
    onAccept,
    onDiscard,
}) {
    if (!open) return null;

    const rows = [
        ['Bio length', breakdown?.word_count ?? 0],
        ['Internal links', breakdown?.links ?? 0],
        ['Profile data', breakdown?.completeness ?? 0],
        ['Media quality', breakdown?.media ?? 0],
    ];

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Generated Bio Preview">
            <div className="w-full max-w-3xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                <header className="border-b border-slate-100 bg-gradient-to-r from-teal-50 to-white px-5 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Generated draft</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-950">Review the SEO bio before using it</h3>
                            <p className="mt-1 text-sm text-slate-500">Accepting only fills the form. You still control the final profile save.</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <SeoScoreBadge score={score} />
                            {providerUsed ? <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">{providerUsed}</span> : null}
                        </div>
                    </div>
                </header>

                <div className="max-h-[65vh] overflow-y-auto p-5">
                    <div className="prose prose-sm max-w-none rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-slate-800" dangerouslySetInnerHTML={{ __html: bioHtml }} />

                    {breakdown ? (
                        <div className="mt-4 grid gap-2 sm:grid-cols-4">
                            {rows.map(([label, val]) => (
                                <div key={label} className="rounded-lg border border-slate-200 bg-white p-3">
                                    <p className="text-[11px] font-semibold uppercase tracking-[0.08em] text-slate-500">{label}</p>
                                    <p className="mt-1 text-sm font-semibold text-slate-900">{val}/25</p>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>

                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4">
                    <p className="text-xs text-slate-500">Tip: you can edit the generated copy before saving.</p>
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onDiscard}>Discard</button>
                        <button type="button" className="crm-btn-primary" onClick={() => onAccept(bioHtml)}>Use this bio</button>
                    </div>
                </footer>
            </div>
        </div>
    );
}
