import React, { useEffect, useMemo, useState } from 'react';
import SeoScoreBadge from './SeoScoreBadge';

const LANGUAGE_LABEL = {
    en: 'English',
    fr: 'French',
    pt: 'Portuguese',
    sw: 'Swahili',
};
const LANGUAGE_FLAG = {
    en: '🇬🇧',
    fr: '🇫🇷',
    pt: '🇵🇹',
    sw: '🇰🇪',
};

/**
 * Enhanced preview modal for a generated bio.
 *
 * Adds two interactive surfaces on top of the original accept/discard flow:
 *   1. Feedback row — thumbs up/down, preset tags, free-text comment.
 *   2. Refinement chips — one-click regeneration with a delta (Longer,
 *      Shorter, More creative, Less generic, More direct, Warmer,
 *      Different angle).
 *
 * Parent owns API calls; this component only emits events and renders state.
 */
export default function BioPreviewModal({
    open,
    bioHtml,
    score,
    breakdown,
    providerUsed,
    usage = null,
    language = 'en',           // language the bio was generated in
    regenerating = false,
    onAccept,
    onDiscard,
    onRegenerate,              // (refinements: string[]) => void
    onFeedback,                // ({ rating, tag, comment, accepted }) => void
    onTranslate,               // (bioHtml: string) => Promise<{translation_html, cached}>
}) {
    const [rating, setRating] = useState(null); // 1, -1, or null
    const [tag, setTag] = useState(null);
    const [comment, setComment] = useState('');
    const [feedbackSent, setFeedbackSent] = useState(false);
    const [activeRefinements, setActiveRefinements] = useState([]);

    // Translation peek state
    const [showTranslation, setShowTranslation] = useState(false);
    const [translationHtml, setTranslationHtml] = useState(null);
    const [translationCached, setTranslationCached] = useState(false);
    const [translating, setTranslating] = useState(false);
    const [translationError, setTranslationError] = useState(null);

    const isNonEnglish = language && language !== 'en';

    // Reset feedback + translation state when a new bio arrives
    useEffect(() => {
        if (open && bioHtml) {
            setRating(null);
            setTag(null);
            setComment('');
            setFeedbackSent(false);
            setActiveRefinements([]);
            setShowTranslation(false);
            setTranslationHtml(null);
            setTranslationCached(false);
            setTranslationError(null);
        }
    }, [open, bioHtml]);

    const handleToggleTranslation = async () => {
        if (!onTranslate || !isNonEnglish) return;
        if (translationHtml) {
            // Already have it — just toggle visibility
            setShowTranslation((v) => !v);
            return;
        }
        setTranslating(true);
        setTranslationError(null);
        try {
            const data = await onTranslate(bioHtml);
            setTranslationHtml(data?.translation_html || '<p><em>Translation came back empty.</em></p>');
            setTranslationCached(!!data?.cached);
            setShowTranslation(true);
        } catch (err) {
            setTranslationError(err?.message || 'Could not translate.');
        } finally {
            setTranslating(false);
        }
    };

    const rows = useMemo(() => [
        ['Bio length',     breakdown?.word_count ?? 0],
        ['Internal links', breakdown?.links ?? 0],
        ['Profile data',   breakdown?.completeness ?? 0],
        ['Media quality',  breakdown?.media ?? 0],
    ], [breakdown]);

    if (!open) return null;

    const sendFeedback = (overrides = {}) => {
        if (!onFeedback) return;
        const payload = {
            rating: overrides.rating !== undefined ? overrides.rating : rating,
            tag: overrides.tag !== undefined ? overrides.tag : tag,
            comment: overrides.comment !== undefined ? overrides.comment : comment.trim(),
            accepted: !!overrides.accepted,
        };
        // Skip if there's literally nothing to send
        if (payload.rating === null && !payload.tag && !payload.comment && !payload.accepted) {
            return;
        }
        onFeedback(payload);
        setFeedbackSent(true);
    };

    const handleAccept = () => {
        // Send acceptance feedback before propagating (best-effort, fire-and-forget)
        sendFeedback({ accepted: true });
        onAccept?.(bioHtml);
    };

    const handleRegenerate = (refinementKey) => {
        if (!onRegenerate) return;
        // Toggle behaviour: clicking a chip a second time deselects it before regen.
        let next;
        if (activeRefinements.includes(refinementKey)) {
            next = activeRefinements.filter((k) => k !== refinementKey);
        } else {
            next = [...activeRefinements, refinementKey];
        }
        setActiveRefinements(next);
        // Auto-tag negative feedback if user is iterating on the bio
        sendFeedback({ rating: -1, tag: REFINEMENT_TAG_HINT[refinementKey] || tag });
        onRegenerate(next);
    };

    const handleRegenerateAndClear = () => {
        if (!onRegenerate) return;
        sendFeedback({ rating: -1 });
        setActiveRefinements([]);
        onRegenerate([]);
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/55 p-4 backdrop-blur-sm"
            role="dialog"
            aria-modal="true"
            aria-label="Generated Bio Preview"
        >
            <div className="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-2xl">
                {/* ── Header ── */}
                <header className="border-b border-slate-100 bg-gradient-to-r from-teal-50 via-white to-white px-5 py-4">
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">Generated draft</p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-950">Review the SEO bio before using it</h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Accepting only fills the form. Give feedback so the AI learns your taste.
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            <SeoScoreBadge score={score} />
                            {language ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700" title={`Bio language: ${LANGUAGE_LABEL[language] || language}`}>
                                    <span aria-hidden="true">{LANGUAGE_FLAG[language] || '🌐'}</span>
                                    {LANGUAGE_LABEL[language] || language.toUpperCase()}
                                </span>
                            ) : null}
                            {providerUsed ? (
                                <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600">
                                    {providerUsed}
                                </span>
                            ) : null}
                            {usage?.estimated_cost_label ? (
                                <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">
                                    {usage.estimated_cost_label}
                                </span>
                            ) : null}
                        </div>
                    </div>
                </header>

                {/* ── Body (scrollable) ── */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {/* Peek-English toggle bar for non-English bios */}
                    {isNonEnglish && onTranslate ? (
                        <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs">
                            <div className="flex items-center gap-2 text-slate-600">
                                <span aria-hidden="true">{LANGUAGE_FLAG[language]}</span>
                                <span className="font-semibold text-slate-800">{LANGUAGE_LABEL[language]} bio</span>
                                {translationCached && showTranslation ? (
                                    <span className="rounded-full bg-emerald-50 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-700">cached</span>
                                ) : null}
                            </div>
                            <button
                                type="button"
                                onClick={handleToggleTranslation}
                                disabled={translating}
                                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-60 ${
                                    showTranslation
                                        ? 'bg-teal-600 text-white shadow-sm'
                                        : 'border border-slate-300 bg-white text-slate-700 hover:bg-slate-50'
                                }`}
                            >
                                {translating ? (
                                    <><Spinner /> Translating…</>
                                ) : showTranslation ? (
                                    <><span aria-hidden="true">🇬🇧</span> Hide English</>
                                ) : (
                                    <><span aria-hidden="true">🇬🇧</span> Peek in English</>
                                )}
                            </button>
                        </div>
                    ) : null}
                    {translationError ? (
                        <div className="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                            {translationError}
                        </div>
                    ) : null}

                    {/* Bio body — fade overlay when regenerating */}
                    <div className="relative">
                        <div
                            className={`prose prose-sm max-w-none rounded-xl border border-slate-200 bg-slate-50/70 p-4 text-slate-800 transition ${
                                regenerating ? 'opacity-40' : ''
                            }`}
                            dangerouslySetInnerHTML={{ __html: bioHtml }}
                        />
                        {regenerating ? (
                            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <div className="flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 shadow ring-1 ring-teal-200">
                                    <Spinner /> Regenerating…
                                </div>
                            </div>
                        ) : null}
                    </div>

                    {/* English translation peek — slides in under the original */}
                    {isNonEnglish && showTranslation && translationHtml ? (
                        <div className="mt-3 overflow-hidden rounded-xl border border-teal-200 bg-teal-50/40">
                            <div className="flex items-center justify-between gap-2 border-b border-teal-100 bg-teal-50 px-3 py-1.5 text-[11px]">
                                <div className="flex items-center gap-1.5 font-semibold uppercase tracking-[0.08em] text-teal-800">
                                    <span aria-hidden="true">🇬🇧</span> English meaning (for editorial review only)
                                </div>
                                <span className="text-[10px] text-teal-600">Not saved to WP — original {LANGUAGE_LABEL[language]} bio is what gets used.</span>
                            </div>
                            <div
                                className="prose prose-sm max-w-none p-4 text-slate-800"
                                dangerouslySetInnerHTML={{ __html: translationHtml }}
                            />
                        </div>
                    ) : null}

                    {/* Usage line */}
                    {usage ? (
                        <div className="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                            Estimated cost: <strong>{usage.estimated_cost_label}</strong> · Tokens: {usage.input_tokens ?? 0} in / {usage.output_tokens ?? 0} out
                        </div>
                    ) : null}

                    {/* Score breakdown */}
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

                    {/* ── Refinement chips ── */}
                    {onRegenerate ? (
                        <div className="mt-5 rounded-xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-sm font-semibold text-slate-900">Refine this draft</p>
                                    <p className="text-xs text-slate-500">One-click tweaks. Combine multiple before regenerating.</p>
                                </div>
                                {activeRefinements.length > 0 ? (
                                    <button
                                        type="button"
                                        onClick={handleRegenerateAndClear}
                                        className="text-xs font-medium text-slate-500 underline decoration-dotted underline-offset-4 hover:text-slate-800"
                                    >
                                        Clear refinements
                                    </button>
                                ) : null}
                            </div>
                            <div className="mt-3 flex flex-wrap gap-2">
                                {REFINEMENTS.map((r) => {
                                    const active = activeRefinements.includes(r.key);
                                    return (
                                        <button
                                            key={r.key}
                                            type="button"
                                            disabled={regenerating}
                                            onClick={() => handleRegenerate(r.key)}
                                            title={r.tooltip}
                                            className={`inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-not-allowed disabled:opacity-50 ${
                                                active
                                                    ? 'border-teal-500 bg-teal-600 text-white shadow-sm'
                                                    : 'border-slate-200 bg-white text-slate-700 hover:border-teal-300 hover:bg-teal-50'
                                            }`}
                                        >
                                            <span aria-hidden="true">{r.emoji}</span>
                                            <span>{r.label}</span>
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ) : null}

                    {/* ── Feedback row ── */}
                    {onFeedback ? (
                        <div className="mt-3 rounded-xl border border-slate-200 bg-white p-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-sm font-semibold text-slate-900">
                                    Help the AI learn your taste
                                </p>
                                {feedbackSent ? (
                                    <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700">
                                        Saved
                                    </span>
                                ) : null}
                            </div>

                            <div className="mt-3 flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    onClick={() => { setRating(1); sendFeedback({ rating: 1 }); }}
                                    className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                                        rating === 1
                                            ? 'border-emerald-500 bg-emerald-50 text-emerald-700'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-emerald-300 hover:bg-emerald-50'
                                    }`}
                                >
                                    <span aria-hidden="true">👍</span> Looks great
                                </button>
                                <button
                                    type="button"
                                    onClick={() => { setRating(-1); sendFeedback({ rating: -1 }); }}
                                    className={`inline-flex items-center gap-1 rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                                        rating === -1
                                            ? 'border-rose-500 bg-rose-50 text-rose-700'
                                            : 'border-slate-200 bg-white text-slate-600 hover:border-rose-300 hover:bg-rose-50'
                                    }`}
                                >
                                    <span aria-hidden="true">👎</span> Not quite
                                </button>

                                <div className="mx-1 hidden h-6 w-px bg-slate-200 sm:block" />

                                {FEEDBACK_TAGS.map((t) => (
                                    <button
                                        key={t.key}
                                        type="button"
                                        onClick={() => {
                                            const next = tag === t.key ? null : t.key;
                                            setTag(next);
                                            sendFeedback({ tag: next });
                                        }}
                                        className={`rounded-full border px-2.5 py-1 text-[11px] font-medium transition ${
                                            tag === t.key
                                                ? 'border-teal-400 bg-teal-50 text-teal-700'
                                                : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'
                                        }`}
                                    >
                                        {t.label}
                                    </button>
                                ))}
                            </div>

                            <textarea
                                value={comment}
                                onChange={(e) => setComment(e.target.value)}
                                onBlur={() => comment.trim() && sendFeedback()}
                                rows={2}
                                placeholder="Optional: explain what to change next time…"
                                className="mt-3 w-full rounded-lg border-slate-300 text-sm placeholder:text-slate-400 focus:border-teal-500 focus:ring-teal-500"
                            />
                        </div>
                    ) : null}
                </div>

                {/* ── Footer ── */}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4">
                    <p className="text-xs text-slate-500">Tip: you can edit the copy before saving.</p>
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onDiscard} disabled={regenerating}>
                            Discard
                        </button>
                        <button
                            type="button"
                            className="crm-btn-primary"
                            onClick={handleAccept}
                            disabled={regenerating}
                        >
                            Use this bio
                        </button>
                    </div>
                </footer>
            </div>
        </div>
    );
}

/** Inline spinner — minimal, no library dep. */
function Spinner() {
    return (
        <svg className="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeOpacity="0.25" strokeWidth="4" />
            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" strokeWidth="4" strokeLinecap="round" />
        </svg>
    );
}

/** Quick-action refinements — keys mirror BioGenerationService::REFINEMENT_PRESETS. */
const REFINEMENTS = [
    { key: 'longer',          emoji: '➕', label: 'Longer',         tooltip: 'Add 30–50 more words' },
    { key: 'shorter',         emoji: '➖', label: 'Shorter',        tooltip: 'Trim the draft tighter' },
    { key: 'more_creative',   emoji: '✨', label: 'More creative',  tooltip: 'Fresher verbs, varied rhythm' },
    { key: 'less_generic',    emoji: '🎯', label: 'Less generic',   tooltip: 'Cut stock phrases' },
    { key: 'more_direct',     emoji: '🪧', label: 'More direct',    tooltip: 'Short declarative sentences' },
    { key: 'warmer',          emoji: '☕', label: 'Warmer',         tooltip: 'Friendlier, more personal' },
    { key: 'different_angle', emoji: '🔄', label: 'Different angle', tooltip: 'Try a fresh opening fact' },
];

/** Feedback tags — keys must be in SeoBioFeedback::ALLOWED_TAGS. */
const FEEDBACK_TAGS = [
    { key: 'too_generic',     label: 'Too generic' },
    { key: 'too_long',        label: 'Too long' },
    { key: 'too_short',       label: 'Too short' },
    { key: 'off_tone',        label: 'Off tone' },
    { key: 'repetitive',      label: 'Repetitive' },
    { key: 'missing_contact', label: 'Missing contact' },
    { key: 'too_formal',      label: 'Too formal' },
    { key: 'too_casual',      label: 'Too casual' },
    { key: 'inaccurate',      label: 'Inaccurate' },
];

/** Map refinement → an implicit negative-feedback tag. */
const REFINEMENT_TAG_HINT = {
    longer:        'too_short',
    shorter:       'too_long',
    less_generic:  'too_generic',
    more_creative: 'too_generic',
    more_direct:   'too_formal',
    warmer:        'off_tone',
};
