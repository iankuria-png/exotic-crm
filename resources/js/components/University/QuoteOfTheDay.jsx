import React, { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import universityApi from '../../services/universityApi';

const ACCENT = {
    teal:    { bg: 'from-teal-600 via-teal-700 to-slate-900',     pill: 'bg-teal-400/20 text-teal-100',     mark: 'text-teal-300/30' },
    amber:   { bg: 'from-amber-600 via-orange-700 to-slate-900',  pill: 'bg-amber-400/20 text-amber-100',   mark: 'text-amber-300/30' },
    emerald: { bg: 'from-emerald-600 via-emerald-700 to-slate-900', pill: 'bg-emerald-400/20 text-emerald-100', mark: 'text-emerald-300/30' },
    rose:    { bg: 'from-rose-600 via-rose-700 to-slate-900',     pill: 'bg-rose-400/20 text-rose-100',     mark: 'text-rose-300/30' },
    indigo:  { bg: 'from-indigo-600 via-indigo-700 to-slate-900', pill: 'bg-indigo-400/20 text-indigo-100', mark: 'text-indigo-300/30' },
};

export default function QuoteOfTheDay() {
    const [copied, setCopied] = useState(false);
    const { data, isLoading } = useQuery({
        queryKey: ['university-quote-of-day'],
        queryFn: () => universityApi.quoteOfTheDay(),
        staleTime: 1000 * 60 * 30, // 30 min
    });

    if (isLoading) {
        return (
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">Loading the quote of the day…</div>
        );
    }

    const quote = data?.quote;
    if (!quote) return null;
    const style = ACCENT[quote.accent] || ACCENT.teal;

    function copy() {
        const text = `"${quote.quote}" — ${quote.author}`;
        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(text);
            setCopied(true);
            setTimeout(() => setCopied(false), 1800);
        }
    }

    return (
        <section className={`relative overflow-hidden rounded-2xl border border-white/10 bg-gradient-to-br ${style.bg} px-6 py-6 text-white shadow-md`}>
            {/* Decorative giant quote marks */}
            <svg aria-hidden="true" className={`pointer-events-none absolute -left-3 -top-6 h-32 w-32 ${style.mark}`} viewBox="0 0 32 32" fill="currentColor">
                <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36 1 24.832 4.288 28 8.464 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L9.352 4Zm17.92 0c-4.864 3.456-8.32 9.12-8.32 15.36C18.952 24.832 22.24 28 26.416 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L27.272 4Z" />
            </svg>
            <svg aria-hidden="true" className={`pointer-events-none absolute -right-3 -bottom-6 h-32 w-32 rotate-180 ${style.mark}`} viewBox="0 0 32 32" fill="currentColor">
                <path d="M9.352 4C4.456 7.456 1 13.12 1 19.36 1 24.832 4.288 28 8.464 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L9.352 4Zm17.92 0c-4.864 3.456-8.32 9.12-8.32 15.36C18.952 24.832 22.24 28 26.416 28c3.616 0 6.112-2.528 6.112-5.792 0-3.072-2.112-5.504-5.024-5.504-.704 0-1.376.16-1.984.32.32-2.88 2.624-6.336 5.6-8.64L27.272 4Z" />
            </svg>

            <div className="relative flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-[0.18em] ${style.pill}`}>
                        <svg className="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5Z" /></svg>
                        Quote of the day
                    </span>
                    <span className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-white/70">{quote.kind}</span>
                </div>
                <button
                    type="button"
                    onClick={copy}
                    className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/80 transition hover:bg-white/20"
                    aria-label="Copy quote"
                >
                    {copied ? (
                        <><svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.4} d="m4.5 12.75 6 6 9-13.5" /></svg>Copied</>
                    ) : (
                        <><svg className="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 0 1-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 0 1 1.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 0 0-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 0 1-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 0 0-3.375-3.375h-1.5a1.125 1.125 0 0 1-1.125-1.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H9.75" /></svg>Copy</>
                    )}
                </button>
            </div>

            <blockquote className="relative mt-4">
                <p className="text-balance font-serif text-2xl leading-relaxed text-white md:text-[26px] md:leading-relaxed">
                    “{quote.quote}”
                </p>
                <footer className="mt-4 flex items-center gap-2 text-sm font-semibold text-white/80">
                    <span className="h-px w-6 bg-white/40" />
                    <span className="uppercase tracking-[0.14em]">{quote.author}</span>
                </footer>
            </blockquote>
        </section>
    );
}
