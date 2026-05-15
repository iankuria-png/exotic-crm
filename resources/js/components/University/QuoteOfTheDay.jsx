import React, { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import universityApi from '../../services/universityApi';
import { useToast } from '../ToastProvider';

const CATEGORY_ACCENT = {
    sales:      { bg: 'from-teal-600 via-teal-700 to-slate-900',          pill: 'bg-teal-400/20 text-teal-100',         mark: 'text-teal-300/30' },
    mindset:    { bg: 'from-indigo-600 via-indigo-700 to-slate-900',      pill: 'bg-indigo-400/20 text-indigo-100',     mark: 'text-indigo-300/30' },
    discipline: { bg: 'from-rose-600 via-rose-700 to-slate-900',          pill: 'bg-rose-400/20 text-rose-100',         mark: 'text-rose-300/30' },
    renewals:   { bg: 'from-emerald-600 via-emerald-700 to-slate-900',    pill: 'bg-emerald-400/20 text-emerald-100',   mark: 'text-emerald-300/30' },
    operations: { bg: 'from-slate-700 via-slate-800 to-slate-950',        pill: 'bg-slate-400/20 text-slate-100',       mark: 'text-slate-300/30' },
    service:    { bg: 'from-cyan-600 via-cyan-700 to-slate-900',          pill: 'bg-cyan-400/20 text-cyan-100',         mark: 'text-cyan-300/30' },
    training:   { bg: 'from-amber-600 via-orange-700 to-slate-900',       pill: 'bg-amber-400/20 text-amber-100',       mark: 'text-amber-300/30' },
};

const EMPTY_DRAFT = { quote: '', author: '', source_label: '', category: 'training' };

export default function QuoteOfTheDay() {
    const toast = useToast();
    const queryClient = useQueryClient();
    const [copied, setCopied] = useState(false);
    const [draftOpen, setDraftOpen] = useState(false);
    const [draft, setDraft] = useState(EMPTY_DRAFT);

    const { data, isLoading } = useQuery({
        queryKey: ['university-daily-quote'],
        queryFn: () => universityApi.getDailyQuote(),
        staleTime: 1000 * 60 * 30,
    });

    const refresh = useMutation({
        mutationFn: (excludeQuote) => universityApi.refreshDailyQuote({ exclude_quote: excludeQuote }),
        onSuccess: (resp) => {
            const suggestion = resp?.quote || {};
            setDraft({
                quote: suggestion.quote || '',
                author: suggestion.author || '',
                source_label: suggestion.source_label || '',
                category: suggestion.category || 'training',
            });
            setDraftOpen(true);
        },
    });

    const submitNextDay = useMutation({
        mutationFn: (payload) => universityApi.submitNextDayQuote(payload),
        onSuccess: () => {
            toast.success('Tomorrow’s quote scheduled.');
            queryClient.invalidateQueries({ queryKey: ['university-daily-quote'] });
            setDraftOpen(false);
            setDraft(EMPTY_DRAFT);
        },
        onError: () => toast.warning('Could not schedule tomorrow’s quote.'),
    });

    useEffect(() => { setCopied(false); }, [data?.quote?.quote]);

    if (isLoading) {
        return <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Loading the quote of the day…</div>;
    }

    const quote = data?.quote;
    if (!quote) return null;

    const canSubmit = !!data?.can_submit;
    const accent = CATEGORY_ACCENT[(quote.category || '').toLowerCase()] || CATEGORY_ACCENT.training;

    function copy() {
        const text = `“${quote.quote}” — ${quote.author || 'Exotic Online University'}`;
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        }
    }

    return (
        <section className={`relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br ${accent.bg} px-6 py-6 text-white shadow-md`}>
            <svg aria-hidden="true" className={`pointer-events-none absolute -left-3 -top-6 h-32 w-32 ${accent.mark}`} viewBox="0 0 32 32" fill="currentColor">
                <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36 1 24.832 4.288 28 8.464 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L9.352 4Zm17.92 0c-4.864 3.456-8.32 9.12-8.32 15.36C18.952 24.832 22.24 28 26.416 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L27.272 4Z" />
            </svg>
            <svg aria-hidden="true" className={`pointer-events-none absolute -right-3 -bottom-6 h-32 w-32 rotate-180 ${accent.mark}`} viewBox="0 0 32 32" fill="currentColor">
                <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36 1 24.832 4.288 28 8.464 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L9.352 4Zm17.92 0c-4.864 3.456-8.32 9.12-8.32 15.36C18.952 24.832 22.24 28 26.416 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L27.272 4Z" />
            </svg>

            <div className="relative flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.18em] ${accent.pill}`}>
                        <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg>
                        Quote of the day
                    </span>
                    {quote.source_label ? <span className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/70">{quote.source_label}</span> : null}
                    {quote.category ? <span className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/70">{quote.category}</span> : null}
                </div>
                <div className="flex items-center gap-2">
                    {canSubmit ? (
                        <button
                            type="button"
                            onClick={() => { setDraftOpen((v) => !v); refresh.mutate(quote.quote); }}
                            className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/90 transition hover:bg-white/20"
                        >
                            <svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Set tomorrow…
                        </button>
                    ) : null}
                    <button type="button" onClick={copy} className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/80 transition hover:bg-white/20" aria-label="Copy quote">
                        {copied ? (
                            <><svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="m4.5 12.75 6 6 9-13.5" /></svg>Copied</>
                        ) : (
                            <><svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>Copy</>
                        )}
                    </button>
                </div>
            </div>

            <blockquote className="relative mt-4">
                <p className="text-balance font-serif text-2xl leading-relaxed text-white md:text-[26px] md:leading-relaxed">
                    “{quote.quote}”
                </p>
                <footer className="mt-4 flex items-center gap-2 text-sm font-semibold text-white/80">
                    <span className="h-px w-6 bg-white/40" />
                    <span className="uppercase tracking-[0.14em]">{quote.author || 'Exotic Online University'}</span>
                </footer>
            </blockquote>

            {canSubmit && draftOpen ? (
                <div className="relative mt-5 rounded-xl border border-white/10 bg-black/20 p-4">
                    <div className="flex items-center justify-between gap-3">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-white/70">Schedule tomorrow’s quote</p>
                        <button type="button" onClick={() => refresh.mutate(draft.quote)} disabled={refresh.isPending} className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-white/80 hover:bg-white/20 disabled:opacity-50">
                            <svg className={`h-3 w-3 ${refresh.isPending ? 'animate-spin' : ''}`} fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                            Suggest another
                        </button>
                    </div>
                    <textarea value={draft.quote} onChange={(e) => setDraft((c) => ({ ...c, quote: e.target.value }))} className="mt-3 w-full rounded-md border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder-white/40" rows={3} placeholder="The quote text…" />
                    <div className="mt-2 grid gap-2 sm:grid-cols-3">
                        <input value={draft.author} onChange={(e) => setDraft((c) => ({ ...c, author: e.target.value }))} className="rounded-md border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder-white/40" placeholder="Author" />
                        <input value={draft.source_label} onChange={(e) => setDraft((c) => ({ ...c, source_label: e.target.value }))} className="rounded-md border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder-white/40" placeholder="Source label" />
                        <input value={draft.category} onChange={(e) => setDraft((c) => ({ ...c, category: e.target.value }))} className="rounded-md border border-white/10 bg-black/30 px-3 py-2 text-sm text-white placeholder-white/40" placeholder="Category" />
                    </div>
                    <div className="mt-3 flex justify-end gap-2">
                        <button type="button" onClick={() => { setDraftOpen(false); setDraft(EMPTY_DRAFT); }} className="rounded-md bg-white/10 px-3 py-1.5 text-xs font-semibold text-white/80 hover:bg-white/20">Cancel</button>
                        <button type="button" onClick={() => submitNextDay.mutate(draft)} disabled={!draft.quote.trim() || submitNextDay.isPending} className="rounded-md bg-white px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-slate-900 hover:bg-white/90 disabled:opacity-50">
                            {submitNextDay.isPending ? 'Saving…' : 'Schedule for tomorrow'}
                        </button>
                    </div>
                </div>
            ) : null}
        </section>
    );
}
