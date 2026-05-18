import React from 'react';
import SeoScoreBadge from './SeoScoreBadge';

/**
 * Modal that previews the generated bio before the user accepts/discards.
 *
 * Props:
 *   open        — bool
 *   bioHtml     — string (generated bio HTML)
 *   score       — int or null
 *   breakdown   — object or null
 *   providerUsed — string
 *   onAccept    — fn(bioHtml) — called when user clicks "Accept"
 *   onDiscard   — fn() — called when user clicks "Discard"
 */
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

    return (
        <div className="bio-preview-modal-overlay" role="dialog" aria-modal="true" aria-label="Generated Bio Preview">
            <div className="bio-preview-modal">
                <div className="bio-preview-modal__header">
                    <h3 className="bio-preview-modal__title">Generated Bio Preview</h3>
                    <div className="bio-preview-modal__meta">
                        <SeoScoreBadge score={score} />
                        {providerUsed && (
                            <span className="bio-preview-modal__provider">
                                via {providerUsed}
                            </span>
                        )}
                    </div>
                </div>

                <div
                    className="bio-preview-modal__body"
                    dangerouslySetInnerHTML={{ __html: bioHtml }}
                />

                {breakdown && (
                    <div className="bio-preview-modal__breakdown">
                        <span title="Bio length">Words: {breakdown.word_count}/25</span>
                        <span title="Internal links">Links: {breakdown.links}/25</span>
                        <span title="Completeness">Profile: {breakdown.completeness}/25</span>
                        <span title="Media">Media: {breakdown.media}/25</span>
                    </div>
                )}

                <div className="bio-preview-modal__actions">
                    <button
                        type="button"
                        className="btn btn--secondary"
                        onClick={onDiscard}
                    >
                        Discard
                    </button>
                    <button
                        type="button"
                        className="btn btn--primary"
                        onClick={() => onAccept(bioHtml)}
                    >
                        Accept & use this bio
                    </button>
                </div>
            </div>
        </div>
    );
}
