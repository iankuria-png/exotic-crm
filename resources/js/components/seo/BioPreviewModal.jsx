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
    overuseScore = null,
    aiSlopScore = null,
    uniquenessScore = null,
    corpusSampleSize = null,
    rewrittenForUniqueness = false,
    language = 'en',           // language the bio was generated in
    loading = false,
    regenerating = false,
    error = null,
    providerOptions = [],
    providerOptionsLoading = false,
    selectedModelKey = '',
    selectedModel = null,
    forceProvider = null,
    onSelectedModelChange,
    onGenerateDraft,
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
    const [editableText, setEditableText] = useState('');
    const [acceptAttempted, setAcceptAttempted] = useState(false);

    // Translation peek state
    const [showTranslation, setShowTranslation] = useState(false);
    const [translationHtml, setTranslationHtml] = useState(null);
    const [translationCached, setTranslationCached] = useState(false);
    const [translating, setTranslating] = useState(false);
    const [translationError, setTranslationError] = useState(null);

    const hasDraft = !!bioHtml;
    const isNonEnglish = language && language !== 'en';
    const protectedLinks = useMemo(() => extractProtectedLinks(bioHtml), [bioHtml]);
    const missingProtectedLinks = useMemo(
        () => protectedLinks.filter((link) => !includesText(editableText, link.text)),
        [editableText, protectedLinks],
    );
    const editedBioHtml = useMemo(
        () => plainTextToSeoHtml(editableText, protectedLinks),
        [editableText, protectedLinks],
    );

    // Reset feedback + translation state when a new bio arrives
    useEffect(() => {
        if (open && bioHtml) {
            setEditableText(htmlToPlainText(bioHtml));
            setRating(null);
            setTag(null);
            setComment('');
            setFeedbackSent(false);
            setActiveRefinements([]);
            setAcceptAttempted(false);
            setShowTranslation(false);
            setTranslationHtml(null);
            setTranslationCached(false);
            setTranslationError(null);
        } else if (open && !bioHtml) {
            setRating(null);
            setTag(null);
            setComment('');
            setFeedbackSent(false);
            setActiveRefinements([]);
            setAcceptAttempted(false);
            setShowTranslation(false);
            setTranslationHtml(null);
            setTranslationCached(false);
            setTranslationError(null);
        }
    }, [open, bioHtml]);

    useEffect(() => {
        setAcceptAttempted(false);
        setShowTranslation(false);
        setTranslationHtml(null);
        setTranslationCached(false);
        setTranslationError(null);
    }, [editableText]);

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
            const data = await onTranslate(editedBioHtml);
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
            bio_html: editedBioHtml,
        };
        // Skip if there's literally nothing to send
        if (payload.rating === null && !payload.tag && !payload.comment && !payload.accepted) {
            return;
        }
        onFeedback(payload);
        setFeedbackSent(true);
    };

    const handleAccept = () => {
        if (!hasDraft) return;

        if (missingProtectedLinks.length > 0 && !acceptAttempted) {
            setAcceptAttempted(true);
            return;
        }

        // Send acceptance feedback before propagating (best-effort, fire-and-forget)
        sendFeedback({ accepted: true });
        onAccept?.(editedBioHtml);
    };

    const handleRegenerate = (refinementKey) => {
        if (!onRegenerate || !hasDraft) return;
        // Toggle behaviour: clicking a chip a second time deselects it before regen.
        let next;
        if (activeRefinements.includes(refinementKey)) {
            next = activeRefinements.filter((k) => k !== refinementKey);
        } else {
            next = [...activeRefinements, refinementKey];
        }
        setActiveRefinements(next);
        // Auto-tag negative feedback if user is iterating on the bio
        const feedbackContext = { rating: -1, tag: REFINEMENT_TAG_HINT[refinementKey] || tag, comment: comment.trim() };
        sendFeedback(feedbackContext);
        onRegenerate(next, feedbackContext, editedBioHtml);
    };

    const handleRegenerateAndClear = () => {
        if (!onRegenerate || !hasDraft) return;
        const feedbackContext = { rating: -1, comment: comment.trim() };
        sendFeedback(feedbackContext);
        setActiveRefinements([]);
        onRegenerate([], feedbackContext, editedBioHtml);
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
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-teal-700">
                                {hasDraft ? 'Generated draft' : 'SEO bio draft'}
                            </p>
                            <h3 className="mt-1 text-lg font-semibold text-slate-950">
                                {hasDraft ? 'Review the SEO bio before using it' : 'Choose the AI model for this bio'}
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                {hasDraft
                                    ? 'Accepting only fills the form. Give feedback so the AI learns your taste.'
                                    : 'Generate inside this review step, then edit before it touches the profile form.'}
                            </p>
                        </div>
                        {hasDraft ? (
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
                        ) : null}
                    </div>
                </header>

                {/* ── Body (scrollable) ── */}
                <div className="flex-1 overflow-y-auto px-5 py-4">
                    {onGenerateDraft || onSelectedModelChange || providerOptions.length > 0 || forceProvider ? (
                        <ModelChooser
                            hasDraft={hasDraft}
                            loading={loading}
                            regenerating={regenerating}
                            providerOptions={providerOptions}
                            providerOptionsLoading={providerOptionsLoading}
                            selectedModelKey={selectedModelKey}
                            selectedModel={selectedModel}
                            forceProvider={forceProvider}
                            onSelectedModelChange={onSelectedModelChange}
                            onGenerateDraft={onGenerateDraft}
                        />
                    ) : null}

                    {error ? (
                        <div className="mb-4 rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm font-medium text-rose-800" role="alert">
                            {error}
                        </div>
                    ) : null}

                    {!hasDraft ? (
                        <div className="rounded-xl border border-dashed border-teal-200 bg-teal-50/50 px-4 py-5">
                            <p className="text-sm font-semibold text-slate-900">Ready to draft from the current profile fields.</p>
                            <p className="mt-1 text-sm text-slate-600">
                                The generated copy will appear here for editing, SEO-link review, and feedback.
                            </p>
                        </div>
                    ) : (
                        <>
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
                        <div className={`rounded-xl border border-slate-200 bg-white transition ${
                                regenerating ? 'opacity-40' : ''
                            }`}>
                            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Draft editor</p>
                                    <p className="mt-0.5 text-xs text-slate-500">Edits stay in this review step until you use the bio.</p>
                                </div>
                                {protectedLinks.length > 0 ? (
                                    <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                                        missingProtectedLinks.length > 0
                                            ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-200'
                                            : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100'
                                    }`}>
                                        {protectedLinks.length - missingProtectedLinks.length}/{protectedLinks.length} SEO links kept
                                    </span>
                                ) : null}
                            </div>
                            <textarea
                                value={editableText}
                                onChange={(event) => setEditableText(event.target.value)}
                                rows={8}
                                className="block min-h-[190px] w-full resize-y border-0 bg-slate-50/60 p-4 text-[15px] leading-7 text-slate-800 outline-none placeholder:text-slate-400 focus:ring-0"
                                placeholder="Generated bio copy"
                            />
                        </div>
                        {regenerating ? (
                            <div className="pointer-events-none absolute inset-0 flex items-center justify-center">
                                <div className="flex items-center gap-2 rounded-full bg-white px-3 py-1.5 text-xs font-semibold text-teal-700 shadow ring-1 ring-teal-200">
                                    <Spinner /> Regenerating…
                                </div>
                            </div>
                        ) : null}
                    </div>

                    {protectedLinks.length > 0 ? (
                        <div className={`mt-3 rounded-xl border px-3 py-3 ${
                            missingProtectedLinks.length > 0
                                ? 'border-amber-200 bg-amber-50'
                                : 'border-emerald-100 bg-emerald-50/60'
                        }`}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className={`text-xs font-semibold uppercase tracking-[0.1em] ${
                                    missingProtectedLinks.length > 0 ? 'text-amber-800' : 'text-emerald-800'
                                }`}>
                                    Protected SEO links
                                </p>
                                {missingProtectedLinks.length > 0 ? (
                                    <span className="text-xs font-medium text-amber-800">
                                        Restore the missing anchor text or click Use again to continue.
                                    </span>
                                ) : null}
                            </div>
                            <div className="mt-2 flex flex-wrap gap-1.5">
                                {protectedLinks.map((link) => {
                                    const missing = missingProtectedLinks.some((item) => item.key === link.key);
                                    return (
                                        <span
                                            key={link.key}
                                            title={link.href}
                                            className={`inline-flex max-w-full items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-semibold ${
                                                missing
                                                    ? 'bg-white text-amber-800 ring-1 ring-amber-300'
                                                    : 'bg-white text-emerald-800 ring-1 ring-emerald-200'
                                            }`}
                                        >
                                            <span aria-hidden="true">{missing ? '!' : '✓'}</span>
                                            <span className="max-w-[190px] truncate">{link.text}</span>
                                        </span>
                                    );
                                })}
                            </div>
                        </div>
                    ) : null}

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
                            Estimated cost: <strong>{usage.estimated_cost_label}</strong> · Tokens: {usage.input_tokens ?? 0} in / {usage.output_tokens ?? 0} out · Score before edits
                        </div>
                    ) : null}

                    {uniquenessScore !== null || overuseScore !== null || aiSlopScore !== null ? (
                        <div className="mt-3 flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-600">
                            {uniquenessScore !== null ? <span><strong>Uniqueness:</strong> {uniquenessScore}/100</span> : null}
                            {overuseScore !== null ? <span><strong>Overuse:</strong> {overuseScore}/100</span> : null}
                            {aiSlopScore !== null ? <span><strong>AI-slop:</strong> {aiSlopScore}/100</span> : null}
                            {corpusSampleSize !== null ? <span><strong>Corpus:</strong> {corpusSampleSize}</span> : null}
                            {rewrittenForUniqueness ? (
                                <span className="rounded-full bg-teal-50 px-2 py-0.5 font-semibold text-teal-700">rewritten for uniqueness</span>
                            ) : null}
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
                        </>
                    )}
                </div>

                {/* ── Footer ── */}
                <footer className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 bg-slate-50 px-5 py-4">
                    <p className="text-xs text-slate-500">
                        {!hasDraft
                            ? 'Default waterfall uses the saved provider order from SEO Engine settings.'
                            : missingProtectedLinks.length > 0
                            ? `${missingProtectedLinks.length} protected SEO link${missingProtectedLinks.length === 1 ? '' : 's'} missing from the edited draft.`
                            : 'Tip: you can edit the copy before saving.'}
                    </p>
                    <div className="flex items-center gap-2">
                        <button type="button" className="crm-btn-secondary" onClick={onDiscard} disabled={loading || regenerating}>
                            {hasDraft ? 'Discard' : 'Close'}
                        </button>
                        {hasDraft ? (
                            <button
                                type="button"
                                className={missingProtectedLinks.length > 0 && !acceptAttempted ? 'crm-btn-secondary' : 'crm-btn-primary'}
                                onClick={handleAccept}
                                disabled={loading || regenerating}
                            >
                                {missingProtectedLinks.length > 0 && acceptAttempted ? 'Use anyway' : 'Use this bio'}
                            </button>
                        ) : (
                            <button
                                type="button"
                                className="crm-btn-primary inline-flex items-center gap-2"
                                onClick={onGenerateDraft}
                                disabled={loading || regenerating || !onGenerateDraft}
                            >
                                {loading ? <><Spinner /> Generating...</> : 'Generate draft'}
                            </button>
                        )}
                    </div>
                </footer>
            </div>
        </div>
    );
}

function ModelChooser({
    hasDraft,
    loading,
    regenerating,
    providerOptions,
    providerOptionsLoading,
    selectedModelKey,
    selectedModel,
    forceProvider,
    onSelectedModelChange,
    onGenerateDraft,
}) {
    const locked = !!forceProvider;
    const selectDisabled = loading || regenerating || providerOptionsLoading || locked || providerOptions.length === 0;
    const actionDisabled = loading || regenerating || !onGenerateDraft;
    const routeLabel = locked
        ? `Locked to ${forceProvider}`
        : selectedModel
            ? `${selectedModel.provider_label} / ${selectedModel.label}`
            : 'Default waterfall';
    const routeModel = locked
        ? forceProvider
        : selectedModel?.model || 'Saved provider order';

    return (
        <section className="mb-4 rounded-xl border border-slate-200 bg-slate-50/80 p-3">
            <div className="flex flex-wrap items-end gap-3">
                <div className="min-w-[240px] flex-1">
                    <label className="text-[11px] font-semibold uppercase tracking-[0.12em] text-slate-500" htmlFor="seo-bio-ai-model">
                        AI route
                    </label>
                    <select
                        id="seo-bio-ai-model"
                        value={selectedModelKey}
                        onChange={(event) => onSelectedModelChange?.(event.target.value)}
                        disabled={selectDisabled}
                        className="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 shadow-sm transition focus:border-teal-500 focus:ring-teal-500 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-400"
                    >
                        <option value="">
                            {providerOptionsLoading ? 'Loading configured models...' : locked ? `Locked: ${forceProvider}` : 'Default waterfall'}
                        </option>
                        {providerOptions.map((option) => (
                            <option key={modelOptionKey(option)} value={modelOptionKey(option)}>
                                {option.provider_label} · {option.label}: {option.model}
                            </option>
                        ))}
                    </select>
                </div>
                {hasDraft && onGenerateDraft ? (
                    <button
                        type="button"
                        onClick={onGenerateDraft}
                        disabled={actionDisabled}
                        className="inline-flex min-h-[40px] items-center gap-2 rounded-lg border border-teal-200 bg-white px-3 py-2 text-sm font-semibold text-teal-800 shadow-sm transition hover:border-teal-300 hover:bg-teal-50 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {loading ? <><Spinner /> Generating...</> : 'Generate new draft'}
                    </button>
                ) : null}
            </div>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
                <span className="rounded-full bg-white px-2.5 py-1 font-semibold text-slate-700 ring-1 ring-slate-200">
                    {routeLabel}
                </span>
                <span className="max-w-full truncate text-slate-500">
                    {routeModel}
                </span>
            </div>
        </section>
    );
}

function extractProtectedLinks(html = '') {
    if (typeof window === 'undefined' || !html) return [];

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const seen = new Set();

    return Array.from(doc.querySelectorAll('a[href]'))
        .map((anchor) => ({
            text: normalizeWhitespace(anchor.textContent || ''),
            href: anchor.getAttribute('href') || '',
        }))
        .filter((link) => link.text && link.href)
        .filter((link) => {
            const key = link.text.toLowerCase();
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        })
        .map((link, index) => ({ ...link, key: `${index}-${link.href}-${link.text}` }));
}

function htmlToPlainText(html = '') {
    if (typeof window === 'undefined' || !html) return '';

    const doc = new DOMParser().parseFromString(html, 'text/html');
    const blocks = Array.from(doc.body.querySelectorAll('p, li, div'))
        .map((node) => normalizeWhitespace(node.textContent || ''))
        .filter(Boolean);

    if (blocks.length > 0) {
        return blocks.join('\n\n');
    }

    return normalizeWhitespace(doc.body.textContent || '');
}

function plainTextToSeoHtml(text = '', protectedLinks = []) {
    const paragraphs = String(text || '')
        .split(/\n{2,}/)
        .map((part) => part.trim())
        .filter(Boolean);

    if (paragraphs.length === 0) {
        return '';
    }

    const remainingLinks = protectedLinks.map((link) => ({ ...link }));

    return paragraphs
        .map((paragraph) => `<p>${applyProtectedLinks(escapeHtml(paragraph), remainingLinks)}</p>`)
        .join('');
}

function applyProtectedLinks(html, remainingLinks) {
    let output = html;

    remainingLinks.forEach((link) => {
        if (link.used) return;

        const anchorText = escapeHtml(link.text);
        const index = output.toLowerCase().indexOf(anchorText.toLowerCase());
        if (index === -1) return;

        const matchedText = output.slice(index, index + anchorText.length);
        output = `${output.slice(0, index)}<a href="${escapeAttribute(link.href)}">${matchedText}</a>${output.slice(index + anchorText.length)}`;
        link.used = true;
    });

    return output;
}

function includesText(haystack = '', needle = '') {
    return normalizeWhitespace(haystack)
        .toLowerCase()
        .includes(normalizeWhitespace(needle).toLowerCase());
}

function normalizeWhitespace(value = '') {
    return String(value).replace(/\s+/g, ' ').trim();
}

function escapeHtml(value = '') {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function escapeAttribute(value = '') {
    return escapeHtml(value).replace(/`/g, '&#096;');
}

function modelOptionKey(option) {
    return `${option?.provider || ''}|||${option?.model || ''}`;
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
