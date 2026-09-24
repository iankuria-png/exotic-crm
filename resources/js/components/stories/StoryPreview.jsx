import React, { useCallback, useEffect, useRef, useState } from 'react';

// Story media for staff review. Files are public attachment URLs on the
// market site and are never proxied. Videos have no poster image, so a card
// shows the video's own first frame: only its metadata is fetched, and only
// once the card is near the viewport. Nothing autoplays in a list.

const NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

/**
 * WordPress stores captions HTML-escaped ("&amp;", "&#8217;"); React would
 * print those literally. Decode to plain text (React still escapes output).
 */
export function decodeEntities(value) {
    return String(value || '').replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (match, entity) => {
        if (entity[0] === '#') {
            const code = entity[1].toLowerCase() === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10);
            return Number.isFinite(code) ? String.fromCodePoint(code) : match;
        }
        return NAMED_ENTITIES[entity.toLowerCase()] ?? match;
    });
}

function useNearViewport(ref) {
    const [near, setNear] = useState(false);
    useEffect(() => {
        const node = ref.current;
        if (!node || near) return undefined;
        // Cards already on screen load straight away: observers can deliver
        // late (or not at all) while the tab is in the background.
        const rect = node.getBoundingClientRect();
        if (rect.top < window.innerHeight + 300 && rect.bottom > -300) {
            setNear(true);
            return undefined;
        }
        if (typeof IntersectionObserver === 'undefined') {
            setNear(true);
            return undefined;
        }
        const observer = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting)) {
                setNear(true);
                observer.disconnect();
            }
        }, { rootMargin: '300px' });
        observer.observe(node);
        return () => observer.disconnect();
    }, [ref, near]);
    return near;
}

function clipSeconds(story) {
    const start = Number(story.trim_start || 0);
    const end = Number(story.trim_end || 0);
    return end > start ? end - start : 0;
}

function formatClip(seconds) {
    const total = Math.round(seconds);
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

// The frame to show: the start of the section the site plays (never 0, which
// some browsers leave black).
function frameUrl(story) {
    const start = Math.max(0.1, Number(story.trim_start || 0));
    return `${story.media_url}#t=${start}`;
}

export function StoryMedia({ story, onOpen }) {
    const ref = useRef(null);
    const near = useNearViewport(ref);
    const [failed, setFailed] = useState(false);
    const isVideo = story.media_type === 'video';
    // Full-size photo, lazy-loaded: the 150px thumbnail is too blurry to judge.
    const photo = story.preview_url || story.media_url || story.thumb_url;
    const clip = clipSeconds(story);

    let content;
    if (failed || !story.media_url) {
        content = (
            <span className="flex h-full w-full flex-col items-center justify-center gap-2 bg-slate-800 px-3 text-center text-xs text-slate-300">
                <span>Could not load this {isVideo ? 'video' : 'photo'}.</span>
                {story.media_url ? <a href={story.media_url} target="_blank" rel="noopener noreferrer" className="font-semibold text-white underline" onClick={(event) => event.stopPropagation()}>Open the file ↗</a> : null}
            </span>
        );
    } else if (isVideo) {
        content = story.poster_url ? (
            <img src={story.poster_url} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" />
        ) : near ? (
            <video
                src={frameUrl(story)}
                className="pointer-events-none h-full w-full object-cover"
                preload="metadata"
                muted
                playsInline
                disablePictureInPicture
                onError={() => setFailed(true)}
            />
        ) : (
            <span className="block h-full w-full bg-slate-800" />
        );
    } else {
        content = near
            ? <img src={photo} alt="" loading="lazy" decoding="async" className="h-full w-full object-cover" onError={() => setFailed(true)} />
            : <span className="block h-full w-full bg-slate-800" />;
    }

    return (
        <button
            ref={ref}
            type="button"
            className="group relative block h-full w-full overflow-hidden"
            onClick={() => onOpen?.(story)}
            aria-label={`Open ${isVideo ? 'video' : 'photo'} story for review`}
        >
            {content}
            {!failed && story.media_url ? (
                <span className="pointer-events-none absolute inset-0 flex items-center justify-center bg-black/0 transition group-hover:bg-black/20">
                    {isVideo ? (
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-black/55 text-white ring-1 ring-white/40 transition group-hover:scale-110">
                            <svg className="ml-0.5 h-5 w-5" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5.5v13l11-6.5-11-6.5Z" /></svg>
                        </span>
                    ) : (
                        <span className="rounded-full bg-black/55 px-3 py-1 text-xs font-semibold text-white opacity-0 transition group-hover:opacity-100">View</span>
                    )}
                </span>
            ) : null}
            {isVideo && clip > 0 && !failed ? (
                <span className="pointer-events-none absolute bottom-2.5 right-2 rounded bg-black/65 px-1.5 py-0.5 font-mono text-[10px] font-semibold text-white">{formatClip(clip)}</span>
            ) : null}
        </button>
    );
}

/**
 * Full-size reviewer: plays exactly the section visitors see (the file is
 * never cut; each story is a start/end within it), with moderation actions
 * and keyboard navigation. `renderActions(story)` supplies the buttons.
 */
export function StoryLightbox({ stories, index, onIndex, onClose, renderActions, onKey }) {
    const story = stories[index];
    const videoRef = useRef(null);
    const [failed, setFailed] = useState(false);
    const [muted, setMuted] = useState(true);

    useEffect(() => {
        setFailed(false);
    }, [story?.id]);

    // React does not keep the `muted` property in sync after mount.
    useEffect(() => {
        if (videoRef.current) videoRef.current.muted = muted;
    }, [muted, story?.id]);

    const go = useCallback((delta) => {
        if (!stories.length) return;
        onIndex((index + delta + stories.length) % stories.length);
    }, [index, onIndex, stories.length]);

    useEffect(() => {
        if (!story) return undefined;
        const handler = (event) => {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName)) return;
            if (event.key === 'Escape') onClose();
            else if (event.key === 'ArrowRight') go(1);
            else if (event.key === 'ArrowLeft') go(-1);
            else if (event.key.toLowerCase() === 'm') setMuted((value) => !value);
            else onKey?.(event, story);
        };
        window.addEventListener('keydown', handler);
        return () => window.removeEventListener('keydown', handler);
    }, [story, go, onClose, onKey]);

    useEffect(() => {
        const previous = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => { document.body.style.overflow = previous; };
    }, []);

    if (!story) return null;

    const isVideo = story.media_type === 'video';
    const start = Number(story.trim_start || 0);
    const end = Number(story.trim_end || 0);

    // Loop the story's own section, as the site's viewer does.
    const onTimeUpdate = (event) => {
        const video = event.currentTarget;
        if (end > start && video.currentTime >= end) {
            video.currentTime = start;
            video.play().catch(() => {});
        }
    };

    return (
        <div className="fixed inset-0 z-50 flex bg-slate-950/95 text-white" role="dialog" aria-modal="true" aria-label="Story review">
            <button type="button" className="absolute right-4 top-4 z-10 rounded-full bg-white/10 p-2 hover:bg-white/20" onClick={onClose} aria-label="Close (Esc)">
                <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={2} d="M6 18 18 6M6 6l12 12" /></svg>
            </button>

            <div className="flex min-w-0 flex-1 items-center justify-center gap-3 p-4 sm:gap-6">
                <button type="button" className="hidden shrink-0 rounded-full bg-white/10 p-3 hover:bg-white/20 disabled:opacity-30 sm:block" onClick={() => go(-1)} disabled={stories.length < 2} aria-label="Previous story (←)">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={2} d="m15 18-6-6 6-6" /></svg>
                </button>

                <div className="relative aspect-[9/16] h-full max-h-[88vh] overflow-hidden rounded-2xl bg-black shadow-2xl">
                    {failed ? (
                        <div className="flex h-full w-full flex-col items-center justify-center gap-3 p-6 text-center text-sm text-slate-300">
                            <p>This {isVideo ? 'video' : 'photo'} could not be played here.</p>
                            <a href={story.media_url} target="_blank" rel="noopener noreferrer" className="rounded-full bg-white px-4 py-2 text-xs font-semibold text-slate-900">Open the file ↗</a>
                        </div>
                    ) : isVideo ? (
                        <video
                            key={story.id}
                            ref={videoRef}
                            src={start > 0 ? `${story.media_url}#t=${start}` : story.media_url}
                            className="h-full w-full object-contain"
                            autoPlay
                            muted={muted}
                            playsInline
                            controls
                            preload="auto"
                            onTimeUpdate={onTimeUpdate}
                            onError={() => setFailed(true)}
                        />
                    ) : (
                        <img key={story.id} src={story.preview_url || story.media_url || story.thumb_url} alt="Story" className="h-full w-full object-contain" onError={() => setFailed(true)} />
                    )}
                    {story.caption ? (
                        <p className="pointer-events-none absolute inset-x-0 bottom-14 px-5 text-center text-sm font-medium text-white drop-shadow-[0_1px_3px_rgba(0,0,0,0.9)]">{decodeEntities(story.caption)}</p>
                    ) : null}
                </div>

                <button type="button" className="hidden shrink-0 rounded-full bg-white/10 p-3 hover:bg-white/20 disabled:opacity-30 sm:block" onClick={() => go(1)} disabled={stories.length < 2} aria-label="Next story (→)">
                    <svg className="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path strokeLinecap="round" strokeWidth={2} d="m9 18 6-6-6-6" /></svg>
                </button>
            </div>

            <aside className="hidden w-80 shrink-0 flex-col gap-4 overflow-y-auto border-l border-white/10 bg-slate-900 p-5 md:flex">
                <div>
                    <p className="text-xs text-slate-400">Story {index + 1} of {stories.length}</p>
                    <p className="mt-1 text-lg font-semibold">{story.profile?.title || 'This client'}</p>
                    {story.profile?.url ? <a href={story.profile.url} target="_blank" rel="noopener noreferrer" className="text-xs text-teal-300 hover:underline">View profile on site ↗</a> : null}
                </div>
                <dl className="grid grid-cols-2 gap-3 text-sm">
                    <div><dt className="text-xs text-slate-400">Views</dt><dd className="font-semibold">{Number(story.view_count || 0).toLocaleString()}</dd></div>
                    <div><dt className="text-xs text-slate-400">Likes</dt><dd className="font-semibold">{Number(story.like_count || 0).toLocaleString()}</dd></div>
                    <div><dt className="text-xs text-slate-400">Review</dt><dd className="font-semibold capitalize">{story.review_state}</dd></div>
                    <div><dt className="text-xs text-slate-400">Type</dt><dd className="font-semibold">{isVideo ? `Video${end > start ? ` · ${formatClip(end - start)}` : ''}` : 'Photo'}</dd></div>
                    {story.group_size > 1 ? <div className="col-span-2"><dt className="text-xs text-slate-400">Split video</dt><dd className="font-semibold">Part {story.group_part} of {story.group_size}</dd></div> : null}
                    {story.posted_via === 'crm' ? <div className="col-span-2"><dt className="text-xs text-slate-400">Posted by support</dt><dd className="font-semibold">{story.posted_by || 'CRM'}</dd></div> : null}
                </dl>
                {renderActions ? <div className="space-y-2">{renderActions(story)}</div> : null}
                <p className="mt-auto text-[11px] leading-relaxed text-slate-500">
                    <kbd className="rounded border border-slate-600 px-1">←</kbd> <kbd className="rounded border border-slate-600 px-1">→</kbd> move · <kbd className="rounded border border-slate-600 px-1">M</kbd> sound · <kbd className="rounded border border-slate-600 px-1">Esc</kbd> close
                    {renderActions ? <> · <kbd className="rounded border border-slate-600 px-1">A</kbd> approve · <kbd className="rounded border border-slate-600 px-1">H</kbd> hide · <kbd className="rounded border border-slate-600 px-1">X</kbd> delete</> : null}
                </p>
            </aside>

            {/* Phones: actions under the player. */}
            {renderActions ? (
                <div className="absolute inset-x-0 bottom-0 flex gap-2 bg-slate-900/95 p-3 md:hidden">{renderActions(story)}</div>
            ) : null}
        </div>
    );
}
