import React, { useState } from 'react';
import BioPreviewModal from './BioPreviewModal';

export default function GenerateBioButton({
    clientId = null,
    platformId,
    snapshot = {},
    forceProvider = null,
    onAccept,
}) {
    const [loading, setLoading] = useState(false);
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
    });

    const handleGenerate = async () => {
        setLoading(true);
        setError(null);

        try {
            const body = {
                profile_snapshot: snapshot,
                save: false,
            };

            if (clientId) body.client_id = clientId;
            if (platformId) body.platform_id = platformId;
            if (forceProvider) body.force_provider = forceProvider;

            const overrides = Object.fromEntries(
                Object.entries(generationOptions).filter(([, value]) => value !== '' && value !== null && value !== undefined)
            );
            if (Object.keys(overrides).length > 0) body.generation_options = overrides;

            const resp = await fetch('/api/crm/seo/generate-bio', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'same-origin',
                body: JSON.stringify(body),
            });

            const data = await resp.json().catch(() => ({}));

            if (!resp.ok) {
                throw new Error(data.message || data.error || data.detail || `Server error ${resp.status}`);
            }

            setPreview(data);
        } catch (e) {
            setError(e.message || 'Failed to generate bio.');
        } finally {
            setLoading(false);
        }
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

                <button type="button" onClick={() => setShowOptions((value) => !value)} className="text-xs font-medium text-slate-500 underline decoration-dotted underline-offset-4 hover:text-slate-800">
                    {showOptions ? 'Hide options' : 'Bio options'}
                </button>

                {error ? (
                    <span className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-sm font-medium text-rose-700" role="alert">
                        {error}
                    </span>
                ) : null}
            </div>

            {showOptions ? (
                <div className="mt-2 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm md:grid-cols-3">
                    <input className="rounded-md border-slate-300 text-sm" value={generationOptions.tone} onChange={(e) => updateOption('tone', e.target.value)} placeholder="Tone override" />
                    <input className="rounded-md border-slate-300 text-sm" value={generationOptions.temperament} onChange={(e) => updateOption('temperament', e.target.value)} placeholder="Temperament" />
                    <select className="rounded-md border-slate-300 text-sm" value={generationOptions.contact_channel} onChange={(e) => updateOption('contact_channel', e.target.value)}>
                        <option value="">Default contact</option>
                        <option value="whatsapp">WhatsApp</option>
                        <option value="phone">Phone</option>
                        <option value="both">Phone & WhatsApp</option>
                        <option value="none">No contact mention</option>
                    </select>
                    <input type="number" min="25" className="rounded-md border-slate-300 text-sm" value={generationOptions.min_words} onChange={(e) => updateOption('min_words', e.target.value ? Number(e.target.value) : '')} placeholder="Min words" />
                    <input type="number" min="40" className="rounded-md border-slate-300 text-sm" value={generationOptions.max_words} onChange={(e) => updateOption('max_words', e.target.value ? Number(e.target.value) : '')} placeholder="Max words" />
                    <input type="number" min="200" className="rounded-md border-slate-300 text-sm" value={generationOptions.max_characters} onChange={(e) => updateOption('max_characters', e.target.value ? Number(e.target.value) : '')} placeholder="Character limit" />
                </div>
            ) : null}

            <BioPreviewModal
                open={preview !== null}
                bioHtml={preview?.bio_html ?? ''}
                score={preview?.score ?? null}
                breakdown={preview?.breakdown ?? null}
                providerUsed={preview?.provider_used ?? ''}
                usage={preview?.usage ?? null}
                onAccept={handleAccept}
                onDiscard={() => setPreview(null)}
            />
        </>
    );
}

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
