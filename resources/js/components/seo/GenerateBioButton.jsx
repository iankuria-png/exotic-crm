import React, { useState } from 'react';
import BioPreviewModal from './BioPreviewModal';

/**
 * "Generate Bio" button with preview modal.
 *
 * Props:
 *   clientId      — int | null    (present on Edit Profile)
 *   platformId    — int           (always required for preview-only mode)
 *   snapshot      — object        (current unsaved form state — overlaid on persisted data)
 *   mode          — 'preview' | 'preview-and-save'
 *                   'preview'          → never persists (Add Client, WP edit-profile)
 *                   'preview-and-save' → can send save=true (CRM Edit Profile accept)
 *   forceProvider — string | null
 *   onAccept      — fn(bioHtml) — called when user accepts the preview
 */
export default function GenerateBioButton({
    clientId = null,
    platformId,
    snapshot = {},
    mode = 'preview',
    forceProvider = null,
    onAccept,
}) {
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [preview, setPreview] = useState(null); // { bioHtml, score, breakdown, providerUsed }

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

            const data = await resp.json();

            if (!resp.ok) {
                throw new Error(data.message || `Server error ${resp.status}`);
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
        if (onAccept) {
            onAccept(bioHtml);
        }
    };

    return (
        <>
            <button
                type="button"
                className="btn btn--sm btn--outline-primary generate-bio-btn"
                onClick={handleGenerate}
                disabled={loading}
                title="Generate an SEO-optimised bio from this profile's data"
            >
                {loading ? 'Generating…' : '✨ Generate Bio'}
            </button>

            {error && (
                <span className="generate-bio-btn__error" role="alert">
                    {error}
                </span>
            )}

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
    // Laravel Sanctum SPA: read the XSRF-TOKEN cookie
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
