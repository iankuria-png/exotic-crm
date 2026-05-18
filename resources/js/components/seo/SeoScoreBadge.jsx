import React from 'react';

/**
 * SEO Quality score chip.
 * Shows green (≥70), amber (40–69), red (<40), or a neutral "Pending rescore" state.
 *
 * Props:
 *   score   — integer 0–100, or null if no score yet
 *   stale   — bool; when true shows "Pending rescore" regardless of score value
 */
export default function SeoScoreBadge({ score, stale = false }) {
    if (stale) {
        return (
            <span className="seo-score-badge seo-score-badge--stale" title="Bio was edited; score will be refreshed on next sync">
                Pending rescore
            </span>
        );
    }

    if (score === null || score === undefined) {
        return (
            <span className="seo-score-badge seo-score-badge--none" title="No SEO score yet">
                No score
            </span>
        );
    }

    const band = score >= 70 ? 'green' : score >= 40 ? 'amber' : 'red';

    return (
        <span className={`seo-score-badge seo-score-badge--${band}`} title={`SEO Quality: ${score}/100`}>
            {score}/100
        </span>
    );
}
