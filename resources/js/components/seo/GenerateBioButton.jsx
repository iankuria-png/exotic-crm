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

                {error ? (
                    <span className="rounded-md border border-rose-200 bg-rose-50 px-2.5 py-1.5 text-sm font-medium text-rose-700" role="alert">
                        {error}
                    </span>
                ) : null}
            </div>

            <BioPreviewModal
                open={preview !== null}
                bioHtml={preview?.bio_html ?? ''}
                score={preview?.score ?? null}
                breakdown={preview?.breakdown ?? null}
                providerUsed={preview?.provider_used ?? ''}
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
