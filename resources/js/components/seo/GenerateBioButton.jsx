import React, { useState } from 'react';
import BioPreviewModal from './BioPreviewModal';

/**
 * "Generate SEO Bio" button — entry point to the preview/iteration loop.
 *
 * Responsibilities:
 *   - Initial generation call to /api/crm/seo/generate-bio
 *   - Inline "Bio options" panel for one-off overrides (tone, lengths, contact)
 *   - Iteration: on Refine, calls the same endpoint with `refinements` and
 *     `previous_bio` so the LLM sees the prior draft and a delta directive.
 *   - Feedback: posts editor reactions to /api/crm/seo/feedback. These rows
 *     feed back into future system prompts (FeedbackInsightService).
 *
 * The parent owns the form. We only call `onAccept(bioHtml)` once the editor
 * clicks "Use this bio".
 */
export default function GenerateBioButton({
    clientId = null,
    platformId,
    wpPostId = null,
    snapshot = {},
    forceProvider = null,
    onAccept,
}) {
    const [loading, setLoading] = useState(false);
    const [regenerating, setRegenerating] = useState(false);
    const [error, setError] = useState(null);
    const [preview, setPreview] = useState(null);
    const [showOptions, setShowOptions] = useState(false);
    const [generationOptions, setGenerationOptions] = useState({
        tone: '',
        temperament: '',
        min_words: '',
        max_words: '',
        max_characters: '',
        max_services: '',
        contact_channel: '',
        language: '',
    });

    const buildOverrides = () => Object.fromEntries(
        Object.entries(generationOptions).filter(([, v]) => v !== '' && v !== null && v !== undefined),
    );

    const callGenerate = async ({ refinements = [], previousBio = '' } = {}) => {
        const body = {
            profile_snapshot: snapshot,
            save: false,
        };
        if (clientId) body.client_id = clientId;
        if (platformId) body.platform_id = platformId;
        if (wpPostId) body.wp_post_id = wpPostId;
        if (forceProvider) body.force_provider = forceProvider;

        const overrides = buildOverrides();
        if (Object.keys(overrides).length > 0) body.generation_options = overrides;
        if (refinements.length > 0) body.refinements = refinements;
        if (previousBio) body.previous_bio = previousBio;

        const resp = await fetch('/api/crm/seo/generate-bio', {
            method: 'POST',
            headers: authHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            throw new Error(data.message || data.error || data.detail || `Server error ${resp.status}`);
        }
        return data;
    };

    const handleGenerate = async () => {
        setLoading(true);
        setError(null);
        try {
            const data = await callGenerate();
            setPreview(data);
        } catch (e) {
            setError(e.message || 'Failed to generate bio.');
        } finally {
            setLoading(false);
        }
    };

    const handleRegenerate = async (refinements) => {
        if (!preview) return;
        setRegenerating(true);
        try {
            const data = await callGenerate({
                refinements,
                previousBio: preview.bio_html || '',
            });
            // Preserve the modal — just swap the content.
            setPreview(data);
        } catch (e) {
            setError(e.message || 'Failed to regenerate bio.');
        } finally {
            setRegenerating(false);
        }
    };

    const requestTranslation = async (bioHtml) => {
        if (!bioHtml) throw new Error('No bio to translate.');
        const lang = preview?.generation_options?.language || generationOptions.language || 'en';
        if (lang === 'en') return { translation_html: bioHtml, cached: true };

        const resp = await fetch('/api/crm/seo/translate-bio', {
            method: 'POST',
            headers: authHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify({ bio_html: bioHtml, from_language: lang }),
        });
        const data = await resp.json().catch(() => ({}));
        if (!resp.ok) {
            throw new Error(data.message || data.error || `Translate failed (${resp.status}).`);
        }
        return data;
    };

    const sendFeedback = (feedback) => {
        // Fire-and-forget; UI shows "Saved" optimistically.
        const body = {
            platform_id: platformId,
            client_id: clientId || undefined,
            wp_post_id: wpPostId || undefined,
            provider_used: preview?.provider_used || undefined,
            rating: feedback.rating ?? 0,
            tag: feedback.tag || undefined,
            comment: feedback.comment || undefined,
            accepted: !!feedback.accepted,
            score: preview?.score ?? undefined,
            generation_options: preview?.generation_options ?? undefined,
            bio_html: preview?.bio_html ?? undefined,
        };
        fetch('/api/crm/seo/feedback', {
            method: 'POST',
            headers: authHeaders(),
            credentials: 'same-origin',
            body: JSON.stringify(body),
        }).catch(() => {
            // Silent — feedback failure should never break the editorial flow.
        });
    };

    const handleAccept = (bioHtml) => {
        setPreview(null);
        onAccept?.(bioHtml);
    };

    const updateOption = (field, value) => {
        setGenerationOptions((current) => ({ ...current, [field]: value }));
    };

    return (
        <>
            <div className="flex flex-wrap items-center gap-2">
                <button
                    type="button"
                    className="inline-flex items-center gap-2 rounded-lg border border-teal-200 bg-teal-50 px-3 py-2 text-sm font-semibold text-teal-800 shadow-sm transition hover:border-teal-300 hover:bg-teal-100 disabled:cursor-not-allowed disabled:opacity-60"
                    onClick={handleGenerate}
                    disabled={loading}
                    title="Generate an SEO-optimised bio from this profile's data"
                >
                    <span aria-hidden="true">✨</span>
                    <span>{loading ? 'Generating bio…' : 'Generate SEO Bio'}</span>
                </button>

                <button
                    type="button"
                    onClick={() => setShowOptions((v) => !v)}
                    className="text-xs font-medium text-slate-500 underline decoration-dotted underline-offset-4 hover:text-slate-800"
                >
                    {showOptions ? 'Hide options' : 'Bio options'}
                </button>

                {error ? (
                    <span
                        className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-sm font-medium text-rose-700"
                        role="alert"
                    >
                        {error}
                    </span>
                ) : null}
            </div>

            {showOptions ? (
                <div className="mt-2 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm md:grid-cols-3">
                    <select
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.language}
                        onChange={(e) => updateOption('language', e.target.value)}
                        aria-label="Bio language"
                    >
                        <option value="">Default language</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="fr">🇫🇷 French</option>
                        <option value="pt">🇵🇹 Portuguese</option>
                        <option value="sw">🇰🇪 Swahili</option>
                    </select>
                    <input
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.tone}
                        onChange={(e) => updateOption('tone', e.target.value)}
                        placeholder="Tone override"
                    />
                    <input
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.temperament}
                        onChange={(e) => updateOption('temperament', e.target.value)}
                        placeholder="Temperament"
                    />
                    <select
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.contact_channel}
                        onChange={(e) => updateOption('contact_channel', e.target.value)}
                    >
                        <option value="">Default contact</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="phone">Phone</option>
                        <option value="both">Phone & WhatsApp</option>
                        <option value="none">No contact mention</option>
                    </select>
                    <input
                        type="number"
                        min="25"
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.min_words}
                        onChange={(e) => updateOption('min_words', e.target.value ? Number(e.target.value) : '')}
                        placeholder="Min words"
                    />
                    <input
                        type="number"
                        min="40"
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.max_words}
                        onChange={(e) => updateOption('max_words', e.target.value ? Number(e.target.value) : '')}
                        placeholder="Max words"
                    />
                    <input
                        type="number"
                        min="200"
                        className="rounded-md border-slate-300 text-sm"
                        value={generationOptions.max_characters}
                        onChange={(e) => updateOption('max_characters', e.target.value ? Number(e.target.value) : '')}
                        placeholder="Character limit"
                    />
                </div>
            ) : null}

            <BioPreviewModal
                open={preview !== null}
                bioHtml={preview?.bio_html ?? ''}
                score={preview?.score ?? null}
                breakdown={preview?.breakdown ?? null}
                providerUsed={preview?.provider_used ?? ''}
                usage={preview?.usage ?? null}
                language={preview?.generation_options?.language || generationOptions.language || 'en'}
                regenerating={regenerating}
                onAccept={handleAccept}
                onDiscard={() => {
                    // Treat discard as soft negative signal (no rating, no tag) only if we never recorded anything.
                    setPreview(null);
                }}
                onRegenerate={handleRegenerate}
                onFeedback={sendFeedback}
                onTranslate={requestTranslation}
            />
        </>
    );
}

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

// These endpoints are auth:sanctum protected. The CRM /api surface is bearer-token
// only, so every request must carry the token from localStorage (the same one the
// shared axios client attaches) — the session cookie alone no longer authenticates.
function authHeaders() {
    const token = localStorage.getItem('crm_token');
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': getCsrfToken(),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
    };
}
