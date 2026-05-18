import React from 'react';
import SeoScoreBadge from './SeoScoreBadge';

/**
 * SEO Quality breakdown panel for the Edit Profile tab.
 * Surfaces the four score components with a visual progress bar per component.
 *
 * Props:
 *   score      — integer 0–100 or null
 *   breakdown  — { word_count, links, completeness, media } or null
 *   stale      — bool
 */
export default function SeoQualityPanel({ score, breakdown, stale = false }) {
    const components = [
        { key: 'word_count', label: 'Bio length' },
        { key: 'links', label: 'Internal links' },
        { key: 'completeness', label: 'Profile completeness' },
        { key: 'media', label: 'Media quality' },
    ];

    return (
        <div className="seo-quality-panel">
            <div className="seo-quality-panel__header">
                <span className="seo-quality-panel__title">Profile Quality (SEO)</span>
                <SeoScoreBadge score={score} stale={stale} />
            </div>

            {!stale && breakdown && (
                <div className="seo-quality-panel__breakdown">
                    {components.map(({ key, label }) => {
                        const val = breakdown[key] ?? 0;
                        const pct = Math.min(100, Math.round((val / 25) * 100));
                        return (
                            <div key={key} className="seo-quality-panel__row">
                                <span className="seo-quality-panel__label">{label}</span>
                                <div className="seo-quality-panel__bar-track">
                                    <div
                                        className="seo-quality-panel__bar-fill"
                                        style={{ width: `${pct}%` }}
                                    />
                                </div>
                                <span className="seo-quality-panel__val">{val}/25</span>
                            </div>
                        );
                    })}
                </div>
            )}

            {stale && (
                <p className="seo-quality-panel__stale-note">
                    The bio was edited directly on the site. Score will be refreshed automatically on the next sync.
                </p>
            )}

            {!stale && !breakdown && (
                <p className="seo-quality-panel__empty-note">
                    Generate a bio to see the SEO quality breakdown.
                </p>
            )}
        </div>
    );
}
